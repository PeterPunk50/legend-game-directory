<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Rewrites long/raw store descriptions into concise, original editorial overviews.
 * Backs up the original copy before overwriting, so every change is reversible.
 */
final class LGD_Content_Cleanup {

	// Markers that strongly indicate raw, un-summarized store copy.
	const BOILERPLATE = '/(Privacy Policy:|Terms of Service|Notice of Access|Parent.?s Guide|IGRS Rating|five star reviews|Support:\s|in-app purchases in your device|●|•)/i';

	public function __construct() {
		add_action( 'admin_post_lgd_tidy_description', array( $this, 'handle_single' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'lgd tidy-descriptions', array( __CLASS__, 'cli_tidy' ) );
			WP_CLI::add_command( 'lgd restore-description', array( __CLASS__, 'cli_restore' ) );
		}
	}

	// ── Detection ────────────────────────────────────────────────────────────────

	/**
	 * Is this game still showing raw store copy that needs a rewrite?
	 */
	public static function is_raw( $game_id ) {
		// Never rewritten by either summarizer/overview pipeline.
		if ( '' === (string) get_post_meta( $game_id, '_lgd_ai_generated_at', true )
			&& '' === (string) get_post_meta( $game_id, '_lgd_overview_rewritten_at', true ) ) {
			return true;
		}
		$content = (string) get_post_field( 'post_content', $game_id );
		if ( preg_match( self::BOILERPLATE, $content ) ) { return true; }
		// A very long single block with almost no paragraph breaks = unstructured store dump.
		$paras = substr_count( strtolower( $content ), '<p' );
		if ( str_word_count( wp_strip_all_tags( $content ) ) > 220 && $paras <= 1 ) { return true; }
		return false;
	}

	public static function build_context( $game_id ) {
		$raw = wp_strip_all_tags( (string) get_post_meta( $game_id, '_lgd_original_store_description', true ) );
		if ( '' === $raw ) { $raw = wp_strip_all_tags( (string) get_post_field( 'post_content', $game_id ) ); }
		$raw = trim( preg_replace( '/\s+/', ' ', $raw ) );
		if ( strlen( $raw ) > 8000 ) { $raw = substr( $raw, 0, 8000 ); }

		return array_filter( array(
			'title'            => get_the_title( $game_id ),
			'raw_description'  => $raw,
			'types'            => wp_get_post_terms( $game_id, 'game_type', array( 'fields' => 'names' ) ),
			'genres'           => wp_get_post_terms( $game_id, 'game_genre', array( 'fields' => 'names' ) ),
			'platforms'        => wp_get_post_terms( $game_id, 'game_platform', array( 'fields' => 'names' ) ),
			'developer'        => get_post_meta( $game_id, '_lgd_developer', true ),
			'pricing_type'     => get_post_meta( $game_id, '_lgd_free_type', true ),
			'multiplayer'      => get_post_meta( $game_id, '_lgd_multiplayer', true ),
		), function( $v ) { return '' !== $v && array() !== $v && null !== $v; } );
	}

	public static function allowed_html() {
		return array(
			'p'      => array(),
			'ul'     => array(),
			'li'     => array(),
			'strong' => array(),
			'em'     => array(),
		);
	}

	/**
	 * Rewrite one game's overview. Returns true or WP_Error.
	 */
	public static function rewrite_game( $game_id ) {
		$game_id = absint( $game_id );
		if ( 'game' !== get_post_type( $game_id ) ) {
			return new WP_Error( 'lgd_cleanup_bad_game', __( 'Target is not a directory game.', 'legend-game-directory' ) );
		}

		// Back up the original store copy once, before the first overwrite.
		if ( '' === (string) get_post_meta( $game_id, '_lgd_original_store_description', true ) ) {
			$original = (string) get_post_field( 'post_content', $game_id );
			if ( '' !== trim( $original ) ) {
				update_post_meta( $game_id, '_lgd_original_store_description', $original );
			}
		}

		$context = self::build_context( $game_id );
		if ( empty( $context['raw_description'] ) ) {
			return new WP_Error( 'lgd_cleanup_no_source', __( 'No description text to rewrite.', 'legend-game-directory' ) );
		}

		$data = LGD_AI_Adapter::rewrite_overview( $context );
		if ( is_wp_error( $data ) ) { return $data; }

		$overview = wp_kses( (string) $data['overview_html'], self::allowed_html() );
		if ( '' === trim( wp_strip_all_tags( $overview ) ) ) {
			return new WP_Error( 'lgd_cleanup_empty', __( 'AI returned an empty overview.', 'legend-game-directory' ) );
		}
		$short = sanitize_textarea_field( $data['short_description'] );
		if ( function_exists( 'mb_substr' ) && mb_strlen( $short ) > 280 ) { $short = mb_substr( $short, 0, 280 ); }

		wp_update_post( array(
			'ID'           => $game_id,
			'post_content' => $overview,
			'post_excerpt' => $short,
		) );
		update_post_meta( $game_id, '_lgd_short_description', $short );
		update_post_meta( $game_id, '_lgd_overview_rewritten_at', current_time( 'mysql', true ) );

		LGD_Logger::log( 'overview_rewritten', 'Game overview rewritten from store copy.', array(
			'game_id' => $game_id,
		), 'info', 'game', $game_id );

		return true;
	}

	// ── Admin button (single game) ───────────────────────────────────────────────

	public function add_meta_box() {
		add_meta_box(
			'lgd_tidy_description',
			__( 'Overview Rewrite', 'legend-game-directory' ),
			array( $this, 'render_meta_box' ),
			'game', 'side', 'low'
		);
	}

	public function render_meta_box( $post ) {
		if ( ! LGD_AI_Adapter::available() ) {
			echo '<p>' . esc_html__( 'Define LGD_OPENAI_API_KEY to enable overview rewrites.', 'legend-game-directory' ) . '</p>';
			return;
		}
		$raw = self::is_raw( $post->ID );
		echo '<p>' . ( $raw
			? '<strong style="color:#b32d2e">' . esc_html__( 'This overview still looks like raw store copy.', 'legend-game-directory' ) . '</strong>'
			: esc_html__( 'This overview has already been rewritten.', 'legend-game-directory' ) ) . '</p>';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'lgd_tidy_description_' . $post->ID, 'lgd_tidy_nonce' ); ?>
			<input type="hidden" name="action" value="lgd_tidy_description">
			<input type="hidden" name="game_id" value="<?php echo esc_attr( $post->ID ); ?>">
			<button type="submit" class="button" style="width:100%"><?php esc_html_e( 'Rewrite Overview with AI', 'legend-game-directory' ); ?></button>
			<p class="description"><?php esc_html_e( 'Original store copy is backed up and can be restored.', 'legend-game-directory' ); ?></p>
		</form>
		<?php
	}

	public function handle_single() {
		$game_id = isset( $_POST['game_id'] ) ? absint( $_POST['game_id'] ) : 0;
		if ( ! $game_id
			|| ! isset( $_POST['lgd_tidy_nonce'] )
			|| ! wp_verify_nonce( wp_unslash( $_POST['lgd_tidy_nonce'] ), 'lgd_tidy_description_' . $game_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'legend-game-directory' ) );
		}
		if ( ! current_user_can( 'edit_post', $game_id ) ) {
			wp_die( esc_html__( 'You are not allowed to edit this game.', 'legend-game-directory' ) );
		}

		$result   = self::rewrite_game( $game_id );
		$redirect = get_edit_post_link( $game_id, 'url' );
		if ( is_wp_error( $result ) ) {
			$redirect = add_query_arg( 'lgd_guide_error', rawurlencode( $result->get_error_message() ), $redirect );
		} else {
			$redirect = add_query_arg( 'lgd_overview_done', '1', $redirect );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	// ── WP-CLI batch ─────────────────────────────────────────────────────────────

	/**
	 * Rewrite raw store descriptions into concise original overviews.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Max games to process. Default 10.
	 *
	 * [--game=<id>]
	 * : Only process this single game ID.
	 *
	 * [--all]
	 * : Process every published game, not just ones detected as raw store copy.
	 *
	 * [--dry-run]
	 * : List which games would be processed without calling the AI or changing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lgd tidy-descriptions --dry-run --all
	 *     wp lgd tidy-descriptions --limit=5
	 *     wp lgd tidy-descriptions --game=112
	 */
	public static function cli_tidy( $args, $assoc ) {
		$limit  = isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : 10;
		$all    = isset( $assoc['all'] );
		$dry    = isset( $assoc['dry-run'] );

		if ( ! empty( $assoc['game'] ) ) {
			$ids = array( absint( $assoc['game'] ) );
		} else {
			$ids = get_posts( array(
				'post_type'      => 'game',
				'post_status'    => 'publish',
				'posts_per_page' => $all ? -1 : ( $limit * 4 ), // over-fetch; we filter to raw below
				'orderby'        => 'date',
				'order'          => 'ASC',
				'fields'         => 'ids',
			) );
		}

		$done = 0; $skipped = 0; $failed = 0; $processed = 0;
		foreach ( $ids as $game_id ) {
			// Limit caps the default targeted run; --all and --game are uncapped.
			if ( ! $all && empty( $assoc['game'] ) && $processed >= $limit ) { break; }
			$name = get_the_title( $game_id );

			if ( ! $all && empty( $assoc['game'] ) && ! self::is_raw( $game_id ) ) {
				$skipped++;
				continue;
			}

			if ( $dry ) {
				WP_CLI::log( "WOULD [{$game_id}] {$name}" );
				$processed++;
				continue;
			}

			$result = self::rewrite_game( $game_id );
			$processed++;
			if ( is_wp_error( $result ) ) {
				WP_CLI::warning( "FAIL  [{$game_id}] {$name} — " . $result->get_error_message() );
				$failed++;
				if ( 'lgd_ai_budget' === $result->get_error_code() ) {
					WP_CLI::warning( 'Budget reached — stopping.' );
					break;
				}
				continue;
			}
			WP_CLI::log( "OK    [{$game_id}] {$name}" );
			$done++;
			usleep( 400000 );
		}

		if ( $dry ) {
			WP_CLI::success( "Dry run — {$processed} game(s) would be rewritten." );
		} else {
			WP_CLI::success( "Overviews — Done:{$done} Skipped:{$skipped} Failed:{$failed}" );
		}
	}

	/**
	 * Restore the backed-up original store description for a game.
	 *
	 * ## OPTIONS
	 *
	 * --game=<id>
	 * : The game ID to restore.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lgd restore-description --game=112
	 */
	public static function cli_restore( $args, $assoc ) {
		$game_id  = isset( $assoc['game'] ) ? absint( $assoc['game'] ) : 0;
		$original = (string) get_post_meta( $game_id, '_lgd_original_store_description', true );
		if ( ! $game_id || '' === $original ) {
			WP_CLI::error( 'No backup found for that game.' );
		}
		wp_update_post( array( 'ID' => $game_id, 'post_content' => wp_kses_post( $original ) ) );
		delete_post_meta( $game_id, '_lgd_overview_rewritten_at' );
		WP_CLI::success( "Restored original description for game {$game_id}." );
	}
}
