<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * AI guide generation pipeline (Phase 2).
 * Builds a context bundle from an existing directory game, asks the AI adapter for
 * an original guide, and creates a linked game_guide draft. Exposes a single-game
 * admin button and a WP-CLI batch command.
 */
final class LGD_Guide_Generator {

	const TYPES = array( 'Beginner Guide', 'Walkthrough', 'Tips & Tricks', 'Strategy', 'FAQ' );

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'admin_post_lgd_generate_guide', array( $this, 'handle_generate' ) );
		add_action( 'admin_notices', array( $this, 'maybe_error_notice' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'lgd guide-generate', array( __CLASS__, 'cli_generate' ) );
			WP_CLI::add_command( 'lgd guide-images', array( __CLASS__, 'cli_images' ) );
		}
	}

	// ── Featured image sourcing ──────────────────────────────────────────────────

	/**
	 * Give a guide a featured image. Priority:
	 *   1. Reuse the linked game's featured image (free, instant).
	 *   2. Sideload the linked game's first official screenshot as a new attachment.
	 *   3. AI-generated editorial thumbnail (only when $use_ai is true).
	 * Returns a status string ('thumb'|'reused-game'|'sideloaded'|'ai'|'none') or WP_Error.
	 */
	public static function set_guide_image( $guide_id, $use_ai = false ) {
		$guide_id = absint( $guide_id );
		if ( 'game_guide' !== get_post_type( $guide_id ) ) {
			return new WP_Error( 'lgd_guide_bad_image_target', __( 'Target is not a guide.', 'legend-game-directory' ) );
		}
		if ( get_post_thumbnail_id( $guide_id ) ) { return 'thumb'; }

		$game_id = (int) get_post_meta( $guide_id, '_lgd_guide_game_id', true );

		if ( $game_id ) {
			// 1. Reuse the game's featured image attachment directly.
			$game_thumb = get_post_thumbnail_id( $game_id );
			if ( $game_thumb ) {
				set_post_thumbnail( $guide_id, $game_thumb );
				return 'reused-game';
			}
			// 2. Sideload the game's first stored screenshot for the guide.
			$screens = get_post_meta( $game_id, '_lgd_official_screenshots', true );
			if ( is_array( $screens ) && ! empty( $screens[0] ) ) {
				require_once ABSPATH . 'wp-admin/includes/media.php';
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/image.php';
				$att = media_sideload_image( $screens[0], $guide_id, get_the_title( $guide_id ), 'id' );
				if ( ! is_wp_error( $att ) ) {
					set_post_thumbnail( $guide_id, $att );
					return 'sideloaded';
				}
			}
		}

		// 3. AI fallback (opt-in; respects image budget + settings).
		if ( $use_ai && LGD_AI_Adapter::available() ) {
			$genre = '';
			if ( $game_id ) {
				$g = wp_get_post_terms( $game_id, 'game_genre', array( 'fields' => 'names' ) );
				if ( ! is_wp_error( $g ) && ! empty( $g[0] ) ) { $genre = $g[0]; }
			}
			$prompt = 'Editorial thumbnail illustration for a video-game guide titled "' . get_the_title( $guide_id ) . '"'
				. ( $genre ? ', ' . $genre . ' genre' : '' ) . '. Vibrant, abstract, atmospheric, no text or logos.';
			$att = LGD_AI_Adapter::generate_artwork( $guide_id, $prompt, '16:9' );
			if ( ! is_wp_error( $att ) ) {
				set_post_thumbnail( $guide_id, $att );
				return 'ai';
			}
			return $att; // WP_Error — surface the reason.
		}

		return 'none';
	}

	public function maybe_error_notice() {
		if ( empty( $_GET['lgd_guide_error'] ) ) { return; }
		echo '<div class="notice notice-error is-dismissible"><p>'
			. esc_html__( 'Guide generation failed: ', 'legend-game-directory' )
			. esc_html( sanitize_text_field( wp_unslash( $_GET['lgd_guide_error'] ) ) )
			. '</p></div>';
	}

	// ── Core ────────────────────────────────────────────────────────────────────

	public static function build_context( $game_id ) {
		$get = function( $key ) use ( $game_id ) { return get_post_meta( $game_id, $key, true ); };
		$context = array(
			'title'             => get_the_title( $game_id ),
			'short_description' => $get( '_lgd_short_description' ) ?: wp_strip_all_tags( get_the_excerpt( $game_id ) ),
			'types'             => wp_get_post_terms( $game_id, 'game_type', array( 'fields' => 'names' ) ),
			'genres'            => wp_get_post_terms( $game_id, 'game_genre', array( 'fields' => 'names' ) ),
			'platforms'         => wp_get_post_terms( $game_id, 'game_platform', array( 'fields' => 'names' ) ),
			'pricing_type'      => $get( '_lgd_free_type' ),
			'in_app_purchases'  => $get( '_lgd_in_app_purchases' ),
			'multiplayer'       => $get( '_lgd_multiplayer' ),
			'developer'         => $get( '_lgd_developer' ),
			'best_for'          => $get( '_lgd_best_for' ),
			'pros'              => array_values( array_filter( (array) $get( '_lgd_pros' ) ) ),
			'cons'              => array_values( array_filter( (array) $get( '_lgd_cons' ) ) ),
		);
		return array_filter( $context, function( $v ) { return '' !== $v && array() !== $v && null !== $v; } );
	}

	public static function allowed_html() {
		return array(
			'h2'     => array(),
			'h3'     => array(),
			'p'      => array(),
			'ul'     => array(),
			'ol'     => array(),
			'li'     => array(),
			'strong' => array(),
			'em'     => array(),
			'br'     => array(),
		);
	}

	/**
	 * Generate one guide for a game. Returns new post ID or WP_Error.
	 */
	public static function generate_for_game( $game_id, $guide_type = 'Beginner Guide', $status = 'draft' ) {
		$game_id = absint( $game_id );
		if ( 'game' !== get_post_type( $game_id ) ) {
			return new WP_Error( 'lgd_guide_bad_game', __( 'Target is not a directory game.', 'legend-game-directory' ) );
		}
		if ( ! in_array( $guide_type, self::TYPES, true ) ) { $guide_type = 'Beginner Guide'; }

		$context = self::build_context( $game_id );
		$data    = LGD_AI_Adapter::generate_guide( $context, $guide_type );
		if ( is_wp_error( $data ) ) { return $data; }

		$title = sanitize_text_field( $data['title'] );
		$body  = wp_kses( (string) $data['body_html'], self::allowed_html() );
		if ( '' === trim( wp_strip_all_tags( $body ) ) ) {
			return new WP_Error( 'lgd_guide_empty', __( 'AI returned an empty guide body.', 'legend-game-directory' ) );
		}

		$reading = isset( $data['reading_time'] ) ? absint( $data['reading_time'] ) : 0;
		if ( ! $reading ) { $reading = max( 1, (int) round( str_word_count( wp_strip_all_tags( $body ) ) / 200 ) ); }

		$difficulty = ( isset( $data['difficulty'] ) && in_array( $data['difficulty'], array( 'Beginner', 'Intermediate', 'Advanced' ), true ) )
			? $data['difficulty'] : 'Beginner';

		$key_points = array_slice( array_values( array_filter( array_map(
			'sanitize_text_field',
			(array) ( isset( $data['key_points'] ) ? $data['key_points'] : array() )
		) ) ), 0, 5 );

		$meta_desc = sanitize_text_field( isset( $data['meta_description'] ) ? $data['meta_description'] : '' );
		$seo_title = sanitize_text_field( isset( $data['seo_title'] ) && $data['seo_title'] ? $data['seo_title'] : $title );

		$post_id = wp_insert_post( array(
			'post_type'    => 'game_guide',
			'post_status'  => in_array( $status, array( 'draft', 'publish', 'pending' ), true ) ? $status : 'draft',
			'post_title'   => $title,
			'post_content' => $body,
			'post_excerpt' => $meta_desc,
		), true );
		if ( is_wp_error( $post_id ) ) { return $post_id; }

		wp_set_object_terms( $post_id, $guide_type, 'guide_type', false );

		$platforms = wp_get_post_terms( $game_id, 'game_platform', array( 'fields' => 'names' ) );

		$meta = array(
			'_lgd_guide_game_id'          => $game_id,
			'_lgd_guide_game_name'        => get_the_title( $game_id ),
			'_lgd_guide_game_slug'        => get_post_field( 'post_name', $game_id ),
			'_lgd_guide_difficulty'       => $difficulty,
			'_lgd_guide_reading_time'     => $reading,
			'_lgd_guide_platform'         => is_wp_error( $platforms ) ? '' : implode( ', ', $platforms ),
			'_lgd_guide_key_points'       => $key_points,
			'_lgd_guide_seo_title'        => $seo_title,
			'_lgd_guide_meta_description' => $meta_desc,
			'_lgd_guide_ai_generated'     => 1,
			'_lgd_guide_ai_generated_at'  => current_time( 'mysql', true ),
			'_lgd_guide_last_updated'     => current_time( 'mysql', true ),
		);
		foreach ( $meta as $key => $value ) { update_post_meta( $post_id, $key, $value ); }

		// Give the new guide a featured image by reusing the game's artwork (free, instant).
		$image_status = self::set_guide_image( $post_id, false );

		LGD_Logger::log( 'guide_generated', 'AI guide draft created.', array(
			'guide_id' => $post_id, 'game_id' => $game_id, 'type' => $guide_type, 'status' => $status,
			'image'    => is_wp_error( $image_status ) ? 'error' : $image_status,
		), 'info', 'game', $game_id );

		return $post_id;
	}

	/**
	 * Does this game already have a guide of the given type?
	 */
	public static function existing_guide( $game_id, $guide_type ) {
		$q = new WP_Query( array(
			'post_type'      => 'game_guide',
			'post_status'    => array( 'publish', 'draft', 'pending', 'future' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( array( 'key' => '_lgd_guide_game_id', 'value' => absint( $game_id ), 'type' => 'NUMERIC' ) ),
			'tax_query'      => array( array( 'taxonomy' => 'guide_type', 'field' => 'name', 'terms' => $guide_type ) ),
		) );
		return $q->have_posts() ? (int) $q->posts[0] : 0;
	}

	// ── Admin button (single game) ───────────────────────────────────────────────

	public function add_meta_box() {
		add_meta_box(
			'lgd_generate_guide',
			__( 'AI Guide Generator', 'legend-game-directory' ),
			array( $this, 'render_meta_box' ),
			'game', 'side', 'low'
		);
	}

	public function render_meta_box( $post ) {
		if ( ! LGD_AI_Adapter::available() ) {
			echo '<p>' . esc_html__( 'Define LGD_OPENAI_API_KEY in wp-config.php to enable guide generation.', 'legend-game-directory' ) . '</p>';
			return;
		}
		$url = admin_url( 'admin-post.php' );
		?>
		<form method="post" action="<?php echo esc_url( $url ); ?>">
			<?php wp_nonce_field( 'lgd_generate_guide_' . $post->ID, 'lgd_generate_guide_nonce' ); ?>
			<input type="hidden" name="action" value="lgd_generate_guide">
			<input type="hidden" name="game_id" value="<?php echo esc_attr( $post->ID ); ?>">
			<p>
				<label style="display:block;font-weight:600;margin-bottom:4px"><?php esc_html_e( 'Guide type', 'legend-game-directory' ); ?></label>
				<select name="guide_type" style="width:100%">
					<?php foreach ( self::TYPES as $type ) : ?>
						<option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $type ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p class="description"><?php esc_html_e( 'Creates an original AI-written draft guide linked to this game. Review before publishing.', 'legend-game-directory' ); ?></p>
			<button type="submit" class="button button-primary" style="width:100%"><?php esc_html_e( 'Generate Draft Guide', 'legend-game-directory' ); ?></button>
		</form>
		<?php
	}

	public function handle_generate() {
		$game_id = isset( $_POST['game_id'] ) ? absint( $_POST['game_id'] ) : 0;
		if ( ! $game_id
			|| ! isset( $_POST['lgd_generate_guide_nonce'] )
			|| ! wp_verify_nonce( wp_unslash( $_POST['lgd_generate_guide_nonce'] ), 'lgd_generate_guide_' . $game_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'legend-game-directory' ) );
		}
		if ( ! current_user_can( 'edit_guides' ) || ! current_user_can( 'edit_post', $game_id ) ) {
			wp_die( esc_html__( 'You are not allowed to generate guides.', 'legend-game-directory' ) );
		}

		$guide_type = isset( $_POST['guide_type'] ) ? sanitize_text_field( wp_unslash( $_POST['guide_type'] ) ) : 'Beginner Guide';
		$result     = self::generate_for_game( $game_id, $guide_type, 'draft' );

		if ( is_wp_error( $result ) ) {
			$redirect = add_query_arg(
				array( 'lgd_guide_error' => rawurlencode( $result->get_error_message() ) ),
				get_edit_post_link( $game_id, 'url' )
			);
		} else {
			$redirect = get_edit_post_link( $result, 'url' );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	// ── WP-CLI batch ─────────────────────────────────────────────────────────────

	/**
	 * Generate AI guides for directory games.
	 *
	 * ## OPTIONS
	 *
	 * [--type=<type>]
	 * : Guide type. One of: "Beginner Guide", "Walkthrough", "Tips & Tricks", "Strategy", "FAQ". Default "Beginner Guide".
	 *
	 * [--status=<status>]
	 * : Post status for created guides: draft|pending|publish. Default draft.
	 *
	 * [--limit=<n>]
	 * : Max number of games to process. Default 10.
	 *
	 * [--game=<id>]
	 * : Only process this single game ID.
	 *
	 * [--skip-existing]
	 * : Skip games that already have a guide of this type.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lgd guide-generate --type="Beginner Guide" --limit=5 --skip-existing
	 *     wp lgd guide-generate --game=123 --type="Tips & Tricks" --status=publish
	 */
	public static function cli_generate( $args, $assoc ) {
		$type   = isset( $assoc['type'] ) ? $assoc['type'] : 'Beginner Guide';
		$status = isset( $assoc['status'] ) ? $assoc['status'] : 'draft';
		$limit  = isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : 10;
		$skip   = isset( $assoc['skip-existing'] );

		if ( ! in_array( $type, self::TYPES, true ) ) {
			WP_CLI::error( 'Invalid --type. Allowed: ' . implode( ' | ', self::TYPES ) );
		}

		if ( ! empty( $assoc['game'] ) ) {
			$ids = array( absint( $assoc['game'] ) );
		} else {
			$ids = get_posts( array(
				'post_type'      => 'game',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'fields'         => 'ids',
			) );
		}

		$done = 0; $skipped = 0; $failed = 0;
		foreach ( $ids as $game_id ) {
			$name = get_the_title( $game_id );
			if ( $skip && self::existing_guide( $game_id, $type ) ) {
				WP_CLI::log( "SKIP  [{$game_id}] {$name} — already has a {$type}." );
				$skipped++;
				continue;
			}
			$result = self::generate_for_game( $game_id, $type, $status );
			if ( is_wp_error( $result ) ) {
				WP_CLI::warning( "FAIL  [{$game_id}] {$name} — " . $result->get_error_message() );
				$failed++;
				// Stop early on budget exhaustion so we don't spin through the whole list.
				if ( 'lgd_ai_budget' === $result->get_error_code() ) {
					WP_CLI::warning( 'Budget reached — stopping.' );
					break;
				}
				continue;
			}
			WP_CLI::log( "OK    [{$game_id}] {$name} — guide #{$result} ({$status})." );
			$done++;
			usleep( 400000 ); // gentle pacing between API calls
		}

		WP_CLI::success( "Guides — Done:{$done} Skipped:{$skipped} Failed:{$failed}" );
	}

	/**
	 * Backfill featured images for guides that don't have one.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Max guides to process. Default 50.
	 *
	 * [--game=<id>]
	 * : Only process guides linked to this game ID.
	 *
	 * [--ai]
	 * : Allow AI image generation when no game artwork is available (uses image budget).
	 *
	 * ## EXAMPLES
	 *
	 *     wp lgd guide-images --limit=20
	 *     wp lgd guide-images --ai
	 */
	public static function cli_images( $args, $assoc ) {
		$limit  = isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : 50;
		$use_ai = isset( $assoc['ai'] );

		$query = array(
			'post_type'      => 'game_guide',
			'post_status'    => array( 'publish', 'draft', 'pending', 'future' ),
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'meta_query'     => array( array( 'key' => '_thumbnail_id', 'compare' => 'NOT EXISTS' ) ),
		);
		if ( ! empty( $assoc['game'] ) ) {
			$query['meta_query'] = array(
				'relation' => 'AND',
				array( 'key' => '_thumbnail_id', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_lgd_guide_game_id', 'value' => absint( $assoc['game'] ), 'type' => 'NUMERIC' ),
			);
		}
		$ids = get_posts( $query );

		if ( empty( $ids ) ) {
			WP_CLI::success( 'No guides need images.' );
			return;
		}

		$counts = array();
		foreach ( $ids as $guide_id ) {
			$name   = get_the_title( $guide_id );
			$status = self::set_guide_image( $guide_id, $use_ai );
			if ( is_wp_error( $status ) ) {
				WP_CLI::warning( "FAIL  [{$guide_id}] {$name} — " . $status->get_error_message() );
				if ( 'lgd_ai_budget' === $status->get_error_code() ) {
					WP_CLI::warning( 'Image budget reached — stopping.' );
					break;
				}
				continue;
			}
			$counts[ $status ] = ( isset( $counts[ $status ] ) ? $counts[ $status ] : 0 ) + 1;
			WP_CLI::log( "{$status}  [{$guide_id}] {$name}" );
			if ( 'ai' === $status ) { usleep( 400000 ); }
		}

		$summary = array();
		foreach ( $counts as $k => $v ) { $summary[] = "{$k}:{$v}"; }
		WP_CLI::success( 'Guide images — ' . ( $summary ? implode( ' ', $summary ) : 'none processed' ) );
	}
}
