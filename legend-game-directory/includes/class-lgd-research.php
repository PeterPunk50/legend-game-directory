<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Research importer + evidence store for the Game Guides pipeline.
 *
 * Editors paste an approved, public source URL. The page is fetched (robots-aware,
 * rate-limited, logged, size-capped) and the AI extracts ORIGINAL, paraphrased
 * research notes — never the full article. Each source is stored as an lgd_evidence
 * item (admin-only CPT) with facts, community observations, season/patch, and
 * permission/verification status, retaining the source URL for editorial checks.
 */
final class LGD_Research {

	const CPT = 'lgd_evidence';

	const SOURCE_TYPES = array(
		'official_site' => 'Official Game Site',
		'dev_blog'      => 'Developer Blog',
		'patch_notes'   => 'Official Patch Notes',
		'steam_news'    => 'Steam News',
		'api'           => 'Game-Data API',
		'youtube'       => 'YouTube',
		'twitch'        => 'Twitch',
		'media_kit'     => 'Publisher Media Kit',
		'database'      => 'Licensed Game Database',
		'forum'         => 'Community Forum',
		'user_tip'      => 'User-Submitted Tip',
	);

	const COMMUNITY_LABELS = array(
		''                     => '— None —',
		'commonly_recommended' => 'Commonly Recommended',
		'frequently_reported'  => 'Frequently Reported',
		'disputed'             => 'Disputed',
		'unverified'           => 'Unverified',
		'tested'               => 'Tested by Legend Create',
		'confirmed_official'   => 'Confirmed by Official Source',
	);

	const VERIFICATION = array(
		'unverified' => 'Unverified',
		'in_review'  => 'In Review',
		'verified'   => 'Verified',
		'rejected'   => 'Rejected',
	);

	const PERMISSION = array(
		'unknown'     => 'Unknown',
		'facts_only'  => 'Facts/Reference only',
		'permitted'   => 'Permitted',
		'not_allowed' => 'Not allowed',
	);

	const FETCH_LIMIT = 2097152; // 2 MB
	const TIMEOUT     = 20;

	public function __construct() {
		add_action( 'init', array( __CLASS__, 'register_cpt' ) );
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_lgd_import_research', array( $this, 'handle_import' ) );
		add_action( 'add_meta_boxes', array( $this, 'meta_box' ) );
		add_action( 'save_post_' . self::CPT, array( $this, 'save_meta' ), 10, 2 );
		add_filter( 'manage_' . self::CPT . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( $this, 'column_values' ), 10, 2 );
	}

	public static function register_cpt() {
		register_post_type( self::CPT, array(
			'labels'            => array(
				'name'          => __( 'Research', 'legend-game-directory' ),
				'singular_name' => __( 'Research Source', 'legend-game-directory' ),
				'menu_name'     => __( 'Research', 'legend-game-directory' ),
			),
			'public'            => false,
			'show_ui'           => true,
			'show_in_menu'      => 'edit.php?post_type=game_guide',
			'show_in_rest'      => false,
			'supports'          => array( 'title', 'editor', 'author' ),
			'capability_type'   => array( 'guide', 'guides' ),
			'map_meta_cap'      => true,
		) );
	}

	// ── Importer admin page ──────────────────────────────────────────────────────

	public function menu() {
		add_submenu_page(
			'edit.php?post_type=game_guide',
			__( 'Research Importer', 'legend-game-directory' ),
			__( 'Research Importer', 'legend-game-directory' ),
			'edit_guides',
			'lgd-research-importer',
			array( $this, 'render_importer' )
		);
	}

	public function render_importer() {
		$games  = get_posts( array( 'post_type' => 'game', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC', 'fields' => array( 'ID', 'post_title' ) ) );
		$notice = isset( $_GET['lgd_research_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['lgd_research_msg'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Research Importer', 'legend-game-directory' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Paste an approved, public source URL. The page is fetched and the AI extracts original, paraphrased research notes (never the full article), retaining the source link for verification. Use only sources you are permitted to reference — do not bypass paywalls, logins or robots rules.', 'legend-game-directory' ); ?></p>
			<?php if ( $notice ) : ?><div class="notice notice-info is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'lgd_import_research', 'lgd_research_nonce' ); ?>
				<input type="hidden" name="action" value="lgd_import_research">
				<table class="form-table">
					<tr><th><label><?php esc_html_e( 'Source URL', 'legend-game-directory' ); ?></label></th>
						<td><input type="url" name="source_url" class="large-text" required placeholder="https://"></td></tr>
					<tr><th><label><?php esc_html_e( 'Source type', 'legend-game-directory' ); ?></label></th>
						<td><select name="source_type"><?php foreach ( self::SOURCE_TYPES as $k => $v ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $v ); ?></option><?php endforeach; ?></select></td></tr>
					<tr><th><label><?php esc_html_e( 'Game', 'legend-game-directory' ); ?></label></th>
						<td><select name="game_id"><option value="0"><?php esc_html_e( '— Not in directory —', 'legend-game-directory' ); ?></option><?php foreach ( $games as $g ) : ?><option value="<?php echo esc_attr( $g->ID ); ?>"><?php echo esc_html( $g->post_title ); ?></option><?php endforeach; ?></select>
						<input type="text" name="game_name" class="regular-text" placeholder="<?php esc_attr_e( 'or type a game name (e.g. Fortnite)', 'legend-game-directory' ); ?>"></td></tr>
					<tr><th><label><?php esc_html_e( 'Season / Patch', 'legend-game-directory' ); ?></label></th>
						<td><input type="text" name="season" class="regular-text" placeholder="<?php esc_attr_e( 'Season', 'legend-game-directory' ); ?>"> <input type="text" name="patch" class="regular-text" placeholder="<?php esc_attr_e( 'Patch / version', 'legend-game-directory' ); ?>"></td></tr>
				</table>
				<?php submit_button( __( 'Fetch & Extract Evidence', 'legend-game-directory' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Recent research', 'legend-game-directory' ); ?></h2>
			<?php
			$recent = get_posts( array( 'post_type' => self::CPT, 'post_status' => 'any', 'numberposts' => 10 ) );
			if ( $recent ) {
				echo '<ul>';
				foreach ( $recent as $r ) {
					echo '<li><a href="' . esc_url( get_edit_post_link( $r->ID ) ) . '">' . esc_html( get_the_title( $r->ID ) ) . '</a> — '
						. esc_html( self::VERIFICATION[ get_post_meta( $r->ID, '_lgd_ev_verification', true ) ] ?? 'Unverified' ) . '</li>';
				}
				echo '</ul>';
			} else {
				echo '<p>' . esc_html__( 'No research collected yet.', 'legend-game-directory' ) . '</p>';
			}
			?>
		</div>
		<?php
	}

	// ── Import handler ───────────────────────────────────────────────────────────

	public function handle_import() {
		if ( ! current_user_can( 'edit_guides' )
			|| ! isset( $_POST['lgd_research_nonce'] )
			|| ! wp_verify_nonce( wp_unslash( $_POST['lgd_research_nonce'] ), 'lgd_import_research' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'legend-game-directory' ) );
		}

		$back = add_query_arg( array( 'post_type' => 'game_guide', 'page' => 'lgd-research-importer' ), admin_url( 'edit.php' ) );

		// Rate limit: 30 imports / hour / user.
		if ( ! LGD_Security::rate_limit( 'research-import:user:' . get_current_user_id(), 30, HOUR_IN_SECONDS ) ) {
			$this->bail( $back, __( 'Import rate limit reached. Please wait before importing more sources.', 'legend-game-directory' ) );
		}

		$url = isset( $_POST['source_url'] ) ? esc_url_raw( wp_unslash( $_POST['source_url'] ), array( 'https', 'http' ) ) : '';
		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			$this->bail( $back, __( 'Please provide a valid public source URL.', 'legend-game-directory' ) );
		}
		if ( ! self::robots_allow( $url ) ) {
			$this->bail( $back, __( 'That source disallows automated access in its robots.txt. Add the notes manually instead.', 'legend-game-directory' ) );
		}

		$source_type = isset( $_POST['source_type'] ) && isset( self::SOURCE_TYPES[ $_POST['source_type'] ] ) ? sanitize_key( wp_unslash( $_POST['source_type'] ) ) : 'official_site';
		$game_id     = isset( $_POST['game_id'] ) ? absint( $_POST['game_id'] ) : 0;
		$game_name   = isset( $_POST['game_name'] ) ? sanitize_text_field( wp_unslash( $_POST['game_name'] ) ) : '';
		if ( $game_id && 'game' === get_post_type( $game_id ) ) { $game_name = get_the_title( $game_id ); }
		$season = isset( $_POST['season'] ) ? sanitize_text_field( wp_unslash( $_POST['season'] ) ) : '';
		$patch  = isset( $_POST['patch'] ) ? sanitize_text_field( wp_unslash( $_POST['patch'] ) ) : '';

		// Fetch the page safely.
		$response = wp_remote_get( $url, array(
			'timeout'             => self::TIMEOUT,
			'redirection'         => 3,
			'reject_unsafe_urls'  => true,
			'limit_response_size' => self::FETCH_LIMIT,
			'headers'             => array(
				'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
				'Accept'     => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
			),
		) );
		if ( is_wp_error( $response ) ) {
			LGD_Logger::log( 'research_fetch_error', 'Research fetch failed.', array( 'url' => $url, 'error' => $response->get_error_message() ), 'warning' );
			$this->bail( $back, sprintf( __( 'Could not fetch that source: %s', 'legend-game-directory' ), $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$this->bail( $back, sprintf( __( 'Source returned HTTP %d.', 'legend-game-directory' ), $code ) );
		}

		$html  = wp_remote_retrieve_body( $response );
		$title = self::extract_title( $html ) ?: wp_parse_url( $url, PHP_URL_HOST );
		$text  = self::readable_text( $html );
		if ( strlen( $text ) < 200 ) {
			$this->bail( $back, __( 'The source had too little readable text to extract evidence from.', 'legend-game-directory' ) );
		}

		// AI extraction (original paraphrase, no full-article copy).
		$data = LGD_AI_Adapter::extract_evidence( array(
			'url'        => $url,
			'title'      => $title,
			'game'       => $game_name,
			'season'     => $season,
			'patch'      => $patch,
			'source_type'=> self::SOURCE_TYPES[ $source_type ],
			'text'       => mb_substr( $text, 0, 16000 ),
		) );
		if ( is_wp_error( $data ) ) {
			$this->bail( $back, $data->get_error_message() );
		}

		$is_community = in_array( $source_type, array( 'forum', 'user_tip', 'youtube', 'twitch' ), true );
		$evidence_id  = wp_insert_post( array(
			'post_type'    => self::CPT,
			'post_status'  => 'draft',
			'post_title'   => $title,
			'post_content' => '', // never store the full article
		), true );
		if ( is_wp_error( $evidence_id ) ) { $this->bail( $back, $evidence_id->get_error_message() ); }

		$meta = array(
			'_lgd_ev_url'              => $url,
			'_lgd_ev_publisher'        => sanitize_text_field( $data['publisher'] ?? wp_parse_url( $url, PHP_URL_HOST ) ),
			'_lgd_ev_source_type'      => $source_type,
			'_lgd_ev_game_id'          => $game_id,
			'_lgd_ev_game_name'        => $game_name,
			'_lgd_ev_season'           => $season ?: sanitize_text_field( $data['season'] ?? '' ),
			'_lgd_ev_patch'            => $patch ?: sanitize_text_field( $data['patch'] ?? '' ),
			'_lgd_ev_date_published'   => sanitize_text_field( $data['date_published'] ?? '' ),
			'_lgd_ev_date_collected'   => current_time( 'mysql', true ),
			'_lgd_ev_facts'            => self::clean_list( $data['facts'] ?? array() ),
			'_lgd_ev_community'        => self::clean_list( $data['community_observations'] ?? array() ),
			'_lgd_ev_conflicts'        => self::clean_list( $data['conflicts'] ?? array() ),
			'_lgd_ev_needs_verify'     => self::clean_list( $data['claims_needing_verification'] ?? array() ),
			'_lgd_ev_community_label'  => $is_community ? 'unverified' : 'confirmed_official',
			'_lgd_ev_usage_permission' => 'facts_only',
			'_lgd_ev_image_permission' => 'unknown',
			'_lgd_ev_verification'     => 'unverified',
			'_lgd_ev_editor_notes'     => '',
		);
		foreach ( $meta as $k => $v ) { update_post_meta( $evidence_id, $k, $v ); }

		LGD_Logger::log( 'research_imported', 'Research evidence extracted from a source.', array(
			'evidence_id' => $evidence_id, 'url' => $url, 'source_type' => $source_type, 'facts' => count( $meta['_lgd_ev_facts'] ),
		), 'info' );

		wp_safe_redirect( get_edit_post_link( $evidence_id, 'url' ) );
		exit;
	}

	private function bail( $back, $message ) {
		wp_safe_redirect( add_query_arg( 'lgd_research_msg', rawurlencode( $message ), $back ) );
		exit;
	}

	// ── Safe fetch helpers ───────────────────────────────────────────────────────

	/** Lightweight robots.txt check: refuse if the path is Disallowed for * or our bot. */
	public static function robots_allow( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) { return false; }
		$robots_url = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . '/robots.txt';
		$res = wp_remote_get( $robots_url, array( 'timeout' => 8, 'limit_response_size' => 131072, 'headers' => array( 'User-Agent' => 'LegendCreateResearchBot/1.0' ) ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) { return true; } // no robots = allowed
		$body = wp_remote_retrieve_body( $res );
		$path = ( $parts['path'] ?? '/' );
		$apply = false;
		foreach ( preg_split( '/\r\n|\r|\n/', $body ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) { continue; }
			if ( preg_match( '/^user-agent:\s*(.+)$/i', $line, $m ) ) {
				$ua    = strtolower( trim( $m[1] ) );
				$apply = ( '*' === $ua || false !== strpos( $ua, 'legendcreate' ) );
			} elseif ( $apply && preg_match( '/^disallow:\s*(.*)$/i', $line, $m ) ) {
				$rule = trim( $m[1] );
				if ( '' !== $rule && 0 === strpos( $path, $rule ) ) { return false; }
			}
		}
		return true;
	}

	private static function extract_title( $html ) {
		if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $m ) ) {
			return trim( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) );
		}
		return '';
	}

	/** Strip scripts/styles/nav and return readable text for the AI to summarize. */
	private static function readable_text( $html ) {
		$html = preg_replace( '#<(script|style|noscript|nav|header|footer|form)[^>]*>.*?</\1>#is', ' ', $html );
		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		return trim( preg_replace( '/\s+/', ' ', $text ) );
	}

	private static function clean_list( $items ) {
		return array_slice( array_values( array_filter( array_map( 'sanitize_text_field', (array) $items ) ) ), 0, 40 );
	}

	// ── Evidence meta box ────────────────────────────────────────────────────────

	public function meta_box() {
		add_meta_box( 'lgd_evidence_details', __( 'Research Evidence', 'legend-game-directory' ), array( $this, 'render_box' ), self::CPT, 'normal', 'high' );
	}

	public function render_box( $post ) {
		wp_nonce_field( 'lgd_evidence_' . $post->ID, 'lgd_evidence_nonce' );
		$g = function ( $k ) use ( $post ) { return get_post_meta( $post->ID, $k, true ); };
		$facts     = implode( "\n", (array) $g( '_lgd_ev_facts' ) );
		$community = implode( "\n", (array) $g( '_lgd_ev_community' ) );
		$conflicts = implode( "\n", (array) $g( '_lgd_ev_conflicts' ) );
		$verify    = implode( "\n", (array) $g( '_lgd_ev_needs_verify' ) );
		?>
		<p><strong><?php esc_html_e( 'Source URL', 'legend-game-directory' ); ?>:</strong>
			<a href="<?php echo esc_url( $g( '_lgd_ev_url' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $g( '_lgd_ev_url' ) ); ?></a></p>
		<table class="form-table">
			<tr><th><?php esc_html_e( 'Verification', 'legend-game-directory' ); ?></th><td><select name="lgd_ev_verification"><?php foreach ( self::VERIFICATION as $k => $v ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $g( '_lgd_ev_verification' ), $k ); ?>><?php echo esc_html( $v ); ?></option><?php endforeach; ?></select></td></tr>
			<tr><th><?php esc_html_e( 'Community label', 'legend-game-directory' ); ?></th><td><select name="lgd_ev_community_label"><?php foreach ( self::COMMUNITY_LABELS as $k => $v ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $g( '_lgd_ev_community_label' ), $k ); ?>><?php echo esc_html( $v ); ?></option><?php endforeach; ?></select></td></tr>
			<tr><th><?php esc_html_e( 'Usage permission', 'legend-game-directory' ); ?></th><td><select name="lgd_ev_usage_permission"><?php foreach ( self::PERMISSION as $k => $v ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $g( '_lgd_ev_usage_permission' ), $k ); ?>><?php echo esc_html( $v ); ?></option><?php endforeach; ?></select></td></tr>
			<tr><th><?php esc_html_e( 'Image permission', 'legend-game-directory' ); ?></th><td><select name="lgd_ev_image_permission"><?php foreach ( self::PERMISSION as $k => $v ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $g( '_lgd_ev_image_permission' ), $k ); ?>><?php echo esc_html( $v ); ?></option><?php endforeach; ?></select></td></tr>
			<tr><th><?php esc_html_e( 'Season / Patch', 'legend-game-directory' ); ?></th><td><input type="text" name="lgd_ev_season" value="<?php echo esc_attr( $g( '_lgd_ev_season' ) ); ?>"> <input type="text" name="lgd_ev_patch" value="<?php echo esc_attr( $g( '_lgd_ev_patch' ) ); ?>"></td></tr>
		</table>
		<p><label><strong><?php esc_html_e( 'Extracted facts (one per line)', 'legend-game-directory' ); ?></strong></label>
			<textarea name="lgd_ev_facts" rows="6" class="large-text"><?php echo esc_textarea( $facts ); ?></textarea></p>
		<p><label><strong><?php esc_html_e( 'Community observations (one per line)', 'legend-game-directory' ); ?></strong></label>
			<textarea name="lgd_ev_community" rows="4" class="large-text"><?php echo esc_textarea( $community ); ?></textarea></p>
		<p><label><strong><?php esc_html_e( 'Conflicting claims', 'legend-game-directory' ); ?></strong></label>
			<textarea name="lgd_ev_conflicts" rows="3" class="large-text"><?php echo esc_textarea( $conflicts ); ?></textarea></p>
		<p><label><strong><?php esc_html_e( 'Claims needing verification', 'legend-game-directory' ); ?></strong></label>
			<textarea name="lgd_ev_needs_verify" rows="3" class="large-text"><?php echo esc_textarea( $verify ); ?></textarea></p>
		<p><label><strong><?php esc_html_e( 'Editor notes', 'legend-game-directory' ); ?></strong></label>
			<textarea name="lgd_ev_editor_notes" rows="2" class="large-text"><?php echo esc_textarea( $g( '_lgd_ev_editor_notes' ) ); ?></textarea></p>
		<?php
	}

	public function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST['lgd_evidence_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['lgd_evidence_nonce'] ), 'lgd_evidence_' . $post_id ) ) { return; }
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

		foreach ( array( 'lgd_ev_verification', 'lgd_ev_community_label', 'lgd_ev_usage_permission', 'lgd_ev_image_permission', 'lgd_ev_season', 'lgd_ev_patch', 'lgd_ev_editor_notes' ) as $f ) {
			if ( isset( $_POST[ $f ] ) ) { update_post_meta( $post_id, '_' . $f, sanitize_text_field( wp_unslash( $_POST[ $f ] ) ) ); }
		}
		foreach ( array( 'lgd_ev_facts', 'lgd_ev_community', 'lgd_ev_conflicts', 'lgd_ev_needs_verify' ) as $f ) {
			if ( isset( $_POST[ $f ] ) ) {
				update_post_meta( $post_id, '_' . $f, self::clean_list( preg_split( '/\r\n|\r|\n/', wp_unslash( $_POST[ $f ] ) ) ) );
			}
		}
	}

	public function columns( $cols ) {
		$cols['lgd_ev_game']   = __( 'Game', 'legend-game-directory' );
		$cols['lgd_ev_status'] = __( 'Verification', 'legend-game-directory' );
		return $cols;
	}

	public function column_values( $col, $post_id ) {
		if ( 'lgd_ev_game' === $col ) { echo esc_html( get_post_meta( $post_id, '_lgd_ev_game_name', true ) ?: '—' ); }
		if ( 'lgd_ev_status' === $col ) { echo esc_html( self::VERIFICATION[ get_post_meta( $post_id, '_lgd_ev_verification', true ) ] ?? 'Unverified' ); }
	}

	/** Collect verified evidence for a game, for the guide generator to consume. */
	public static function for_game( $game_id, $game_name = '' ) {
		$meta_query = array( 'relation' => 'OR' );
		if ( $game_id ) { $meta_query[] = array( 'key' => '_lgd_ev_game_id', 'value' => (int) $game_id ); }
		if ( $game_name ) { $meta_query[] = array( 'key' => '_lgd_ev_game_name', 'value' => $game_name ); }

		$items = get_posts( array(
			'post_type'   => self::CPT,
			'post_status' => 'any',
			'numberposts' => 30,
			'meta_query'  => array(
				array( 'key' => '_lgd_ev_verification', 'value' => array( 'verified', 'in_review' ), 'compare' => 'IN' ),
				$meta_query,
			),
		) );

		$out = array();
		foreach ( $items as $it ) {
			$out[] = array(
				'source_url' => get_post_meta( $it->ID, '_lgd_ev_url', true ),
				'publisher'  => get_post_meta( $it->ID, '_lgd_ev_publisher', true ),
				'season'     => get_post_meta( $it->ID, '_lgd_ev_season', true ),
				'patch'      => get_post_meta( $it->ID, '_lgd_ev_patch', true ),
				'facts'      => (array) get_post_meta( $it->ID, '_lgd_ev_facts', true ),
				'community'  => (array) get_post_meta( $it->ID, '_lgd_ev_community', true ),
				'label'      => get_post_meta( $it->ID, '_lgd_ev_community_label', true ),
			);
		}
		return $out;
	}
}
