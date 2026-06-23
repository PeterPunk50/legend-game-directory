<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LGD_Admin {
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'add_meta_boxes_game', array( $this, 'meta_box' ) );
		add_action( 'save_post_game', array( $this, 'save_game' ), 10, 2 );
		add_action( 'admin_post_lgd_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_lgd_approve_batch', array( $this, 'approve_batch' ) );
		add_action( 'admin_post_lgd_import', array( $this, 'import' ) );
		add_action( 'admin_post_lgd_seed_starters', array( $this, 'seed_starters' ) );
		add_action( 'admin_post_lgd_fetch_artwork', array( $this, 'fetch_artwork' ) );
	}

	public static function add_capabilities() {
		$role = get_role( 'administrator' ); if ( ! $role ) { return; }
		foreach ( array( 'edit_game', 'read_game', 'delete_game', 'edit_games', 'edit_others_games', 'publish_games', 'read_private_games', 'delete_games', 'delete_private_games', 'delete_published_games', 'delete_others_games', 'edit_private_games', 'edit_published_games', 'lgd_manage_sources', 'lgd_approve_games', 'lgd_override_scores', 'lgd_moderate_reviews' ) as $cap ) { $role->add_cap( $cap ); }
	}

	public function menu() {
		add_submenu_page( 'edit.php?post_type=game', __( 'Legend Dashboard', 'legend-game-directory' ), __( 'Dashboard', 'legend-game-directory' ), 'edit_games', 'lgd-dashboard', array( $this, 'dashboard' ) );
		add_submenu_page( 'edit.php?post_type=game', __( 'Approval Queue', 'legend-game-directory' ), __( 'Approval Queue', 'legend-game-directory' ), 'lgd_approve_games', 'lgd-approvals', array( $this, 'approvals' ) );
		add_submenu_page( 'edit.php?post_type=game', __( 'Source Import', 'legend-game-directory' ), __( 'Source Import', 'legend-game-directory' ), 'lgd_manage_sources', 'lgd-import', array( $this, 'import_page' ) );
		add_submenu_page( 'edit.php?post_type=game', __( 'Legend Settings', 'legend-game-directory' ), __( 'Settings', 'legend-game-directory' ), 'manage_options', 'lgd-settings', array( $this, 'settings_page' ) );
	}

	public function dashboard() {
		global $wpdb; $counts = wp_count_posts( 'game' );
		$cards = array(
			__( 'Free Games', 'legend-game-directory' ) => $this->term_count( 'game_type', 'free-games' ), __( 'Indie Games', 'legend-game-directory' ) => $this->term_count( 'game_type', 'indie-games' ),
			__( 'Mobile Games', 'legend-game-directory' ) => $this->term_count( 'game_type', 'mobile-games' ), __( 'Pending Approvals', 'legend-game-directory' ) => isset( $counts->pending ) ? $counts->pending : 0,
			__( 'Failed/Broken Sources', 'legend-game-directory' ) => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . LGD_Database::table( 'sources' ) . " WHERE status<>'active'" ),
			__( 'Low-confidence Records', 'legend-game-directory' ) => count( get_posts( array( 'post_type' => 'game', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_query' => array( array( 'key' => '_lgd_confidence', 'value' => 60, 'compare' => '<', 'type' => 'NUMERIC' ) ) ) ) ),
		);
		$daily = wp_parse_args( get_option( 'lgd_ai_usage_' . gmdate( 'Ymd' ), array() ), array( 'requests' => 0 ) ); $monthly = wp_parse_args( get_option( 'lgd_ai_usage_' . gmdate( 'Ym' ), array() ), array( 'cost' => 0 ) );
		?><div class="wrap lgd-admin"><h1><?php esc_html_e( 'Legend Game Directory', 'legend-game-directory' ); ?></h1><div class="lgd-admin-cards"><?php foreach ( $cards as $label => $value ) : ?><div class="lgd-admin-card"><strong><?php echo esc_html( number_format_i18n( $value ) ); ?></strong><span><?php echo esc_html( $label ); ?></span></div><?php endforeach; ?><div class="lgd-admin-card"><strong><?php echo esc_html( $daily['requests'] ); ?></strong><span><?php esc_html_e( 'AI Requests Today', 'legend-game-directory' ); ?></span></div><div class="lgd-admin-card"><strong><?php echo esc_html( number_format_i18n( $monthly['cost'], 2 ) ); ?></strong><span><?php esc_html_e( 'Estimated AI Cost This Month', 'legend-game-directory' ); ?></span></div></div>
		<h2><?php esc_html_e( 'Source Health', 'legend-game-directory' ); ?></h2><?php $health = get_option( 'lgd_provider_health', array() ); ?><pre><?php echo esc_html( wp_json_encode( $health, JSON_PRETTY_PRINT ) ); ?></pre></div><?php
	}

	private function term_count( $taxonomy, $slug ) { $term = get_term_by( 'slug', $slug, $taxonomy ); return $term ? (int) $term->count : 0; }

	public function approvals() {
		$settings = LGD_Security::settings(); $games = get_posts( array( 'post_type' => 'game', 'post_status' => 'pending', 'posts_per_page' => 100 ) );
		?><div class="wrap"><h1><?php esc_html_e( 'Approval Queue', 'legend-game-directory' ); ?></h1><p><?php esc_html_e( 'One approval publishes every pending record that has a verified source, meets the confidence threshold, and has no blocking warnings. New-record flags are acknowledged by this action; safety, sponsorship, conflicts, broken links, low confidence, and major score changes remain pending.', 'legend-game-directory' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'lgd_approve_batch' ); ?><input type="hidden" name="action" value="lgd_approve_batch"><button class="button button-primary button-hero"><?php esc_html_e( 'Approve Verified Batch', 'legend-game-directory' ); ?></button></form>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Game', 'legend-game-directory' ); ?></th><th><?php esc_html_e( 'Confidence', 'legend-game-directory' ); ?></th><th><?php esc_html_e( 'Sources', 'legend-game-directory' ); ?></th><th><?php esc_html_e( 'Review Flags', 'legend-game-directory' ); ?></th><th><?php esc_html_e( 'Eligible', 'legend-game-directory' ); ?></th></tr></thead><tbody><?php foreach ( $games as $game ) : $confidence = (float) get_post_meta( $game->ID, '_lgd_confidence', true ); $flags = (array) get_post_meta( $game->ID, '_lgd_mandatory_review_flags', true ); $blocking = array_diff( $flags, array( 'new_record' ) ); $eligible = $confidence >= (float) $settings['min_publish_confidence'] && 'verified_source' === get_post_meta( $game->ID, '_lgd_verification_status', true ) && empty( $blocking ); ?><tr><td><a href="<?php echo esc_url( get_edit_post_link( $game->ID ) ); ?>"><?php echo esc_html( $game->post_title ); ?></a></td><td><?php echo esc_html( $confidence ); ?>%</td><td><?php echo esc_html( get_post_meta( $game->ID, '_lgd_source_count', true ) ); ?></td><td><?php echo esc_html( implode( ', ', $flags ) ); ?></td><td><?php echo $eligible ? esc_html__( 'Yes', 'legend-game-directory' ) : esc_html__( 'No', 'legend-game-directory' ); ?></td></tr><?php endforeach; ?></tbody></table></div><?php
	}

	public function approve_batch() {
		check_admin_referer( 'lgd_approve_batch' ); if ( ! current_user_can( 'lgd_approve_games' ) ) { wp_die( esc_html__( 'Permission denied.', 'legend-game-directory' ) ); }
		$settings = LGD_Security::settings(); $approved = 0; $held = 0;
		foreach ( get_posts( array( 'post_type' => 'game', 'post_status' => 'pending', 'posts_per_page' => 500, 'fields' => 'ids' ) ) as $id ) {
			$flags = array_diff( (array) get_post_meta( $id, '_lgd_mandatory_review_flags', true ), array( 'new_record' ) );
			$eligible = (float) get_post_meta( $id, '_lgd_confidence', true ) >= (float) $settings['min_publish_confidence'] && 'verified_source' === get_post_meta( $id, '_lgd_verification_status', true ) && empty( $flags );
			if ( $eligible ) { wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) ); update_post_meta( $id, '_lgd_mandatory_review_flags', array() ); LGD_Logger::log( 'batch_approved', 'Game published in verified batch.', array(), 'info', 'game', $id ); $approved++; } else { $held++; }
		}
		wp_safe_redirect( add_query_arg( array( 'post_type' => 'game', 'page' => 'lgd-approvals', 'approved' => $approved, 'held' => $held ), admin_url( 'edit.php' ) ) ); exit;
	}

	public function import_page() {
		?><div class="wrap"><h1><?php esc_html_e( 'Source Import', 'legend-game-directory' ); ?></h1><p><?php esc_html_e( 'Imports always create or refresh reviewable records. Provider restrictions are enforced before requests are sent.', 'legend-game-directory' ); ?></p>
		<?php foreach ( array( 'steam' => __( 'Steam App ID', 'legend-game-directory' ), 'apple' => __( 'Apple game search', 'legend-game-directory' ), 'official_site' => __( 'Approved official game URL', 'legend-game-directory' ) ) as $provider => $label ) : ?><form class="lgd-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'lgd_import_' . $provider ); ?><input type="hidden" name="action" value="lgd_import"><input type="hidden" name="provider" value="<?php echo esc_attr( $provider ); ?>"><label><?php echo esc_html( $label ); ?><input name="value" required></label><button class="button"><?php esc_html_e( 'Create/refresh drafts', 'legend-game-directory' ); ?></button></form><?php endforeach; ?>
		<hr><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'lgd_seed_starters' ); ?><input type="hidden" name="action" value="lgd_seed_starters"><button class="button button-secondary"><?php esc_html_e( 'Create Starter Candidate Drafts', 'legend-game-directory' ); ?></button><p class="description"><?php esc_html_e( 'Creates unverified drafts with official source links and no scores. Provider jobs must verify them before batch approval.', 'legend-game-directory' ); ?></p></form></div><?php
	}

	public function import() {
		if ( ! current_user_can( 'lgd_manage_sources' ) ) { wp_die( esc_html__( 'Permission denied.', 'legend-game-directory' ) ); }
		$provider = sanitize_key( $_POST['provider'] ); check_admin_referer( 'lgd_import_' . $provider ); $value = sanitize_text_field( wp_unslash( $_POST['value'] ) );
		if ( 'apple' === $provider ) { $result = LGD_Importer::import_search( 'apple', $value, array( 'limit' => 10 ) ); }
		else { $result = LGD_Importer::import_external_id( $provider, $value ); }
		$status = is_wp_error( $result ) ? $result->get_error_message() : __( 'Import completed.', 'legend-game-directory' );
		wp_safe_redirect( add_query_arg( array( 'post_type' => 'game', 'page' => 'lgd-import', 'lgd_message' => rawurlencode( $status ) ), admin_url( 'edit.php' ) ) ); exit;
	}

	public function seed_starters() {
		if ( ! current_user_can( 'lgd_manage_sources' ) ) { wp_die( esc_html__( 'Permission denied.', 'legend-game-directory' ) ); } check_admin_referer( 'lgd_seed_starters' );
		$file = LGD_PATH . 'data/starter-candidates.json'; $data = file_exists( $file ) ? json_decode( file_get_contents( $file ), true ) : array(); $count = 0;
		foreach ( (array) $data as $candidate ) { $provider = LGD_Provider_Registry::get( 'manual' ); $normalized = $provider->normalize_game( $candidate ); if ( ! is_wp_error( $normalized ) ) { $id = LGD_Importer::upsert( 'manual', $normalized ); if ( ! is_wp_error( $id ) ) { update_post_meta( $id, '_lgd_verification_status', 'unverified_candidate' ); update_post_meta( $id, '_lgd_mandatory_review_flags', array( 'new_record', 'requires_provider_verification' ) ); $count++; } } }
		wp_safe_redirect( add_query_arg( array( 'post_type' => 'game', 'page' => 'lgd-import', 'seeded' => $count ), admin_url( 'edit.php' ) ) ); exit;
	}

	public function settings_page() {
		$s = LGD_Security::settings(); ?><div class="wrap"><h1><?php esc_html_e( 'Legend Settings', 'legend-game-directory' ); ?></h1><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'lgd_save_settings' ); ?><input type="hidden" name="action" value="lgd_save_settings">
		<table class="form-table"><tr><th><?php esc_html_e( 'Publication mode', 'legend-game-directory' ); ?></th><td><select name="publication_mode"><?php foreach ( array( 'review_everything' => 'Review Everything', 'trusted_sources' => 'Trusted Sources', 'advanced_automation' => 'Advanced Automation' ) as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['publication_mode'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr>
		<tr><th><?php esc_html_e( 'Publish confidence', 'legend-game-directory' ); ?></th><td><input type="number" min="0" max="100" name="min_publish_confidence" value="<?php echo esc_attr( $s['min_publish_confidence'] ); ?>"></td></tr>
		<?php foreach ( array( 'enable_ai' => 'Enable AI summaries', 'enable_ai_web_search' => 'Enable AI web research', 'enable_ai_images' => 'Enable AI artwork', 'steam_enabled' => 'Enable Steam', 'steam_terms_accepted' => 'Steam terms accepted for this application', 'apple_enabled' => 'Enable Apple Search', 'itch_enabled' => 'Enable itch.io account API', 'official_site_enabled' => 'Enable official-site JSON-LD', 'review_auto_approve' => 'Auto-approve clean local reviews' ) as $key => $label ) : ?><tr><th><?php echo esc_html( $label ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( ! empty( $s[ $key ] ) ); ?>> <?php esc_html_e( 'Enabled', 'legend-game-directory' ); ?></label></td></tr><?php endforeach; ?>
		<tr><th><?php esc_html_e( 'AI limits', 'legend-game-directory' ); ?></th><td><label><?php esc_html_e( 'Requests/day', 'legend-game-directory' ); ?> <input type="number" min="0" name="ai_daily_request_limit" value="<?php echo esc_attr( $s['ai_daily_request_limit'] ); ?>"></label> <label><?php esc_html_e( 'Estimated cost/month', 'legend-game-directory' ); ?> <input type="number" min="0" step="0.01" name="ai_monthly_cost_limit" value="<?php echo esc_attr( $s['ai_monthly_cost_limit'] ); ?>"></label><p class="description"><?php esc_html_e( 'The OpenAI key must be stored as OPENAI_API_KEY outside this form.', 'legend-game-directory' ); ?></p></td></tr>
		<tr><th><?php esc_html_e( 'Approved domains', 'legend-game-directory' ); ?></th><td><textarea name="approved_domains" rows="5" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $s['approved_domains'] ) ); ?></textarea></td></tr>
		<tr><th><?php esc_html_e( 'Blocked domains', 'legend-game-directory' ); ?></th><td><textarea name="blocked_domains" rows="4" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $s['blocked_domains'] ) ); ?></textarea></td></tr>
		<tr><th><?php esc_html_e( 'Steam App IDs', 'legend-game-directory' ); ?></th><td><textarea name="steam_app_ids" rows="4" class="large-text code"><?php echo esc_textarea( implode( "\n", isset( $s['steam_app_ids'] ) ? (array) $s['steam_app_ids'] : array() ) ); ?></textarea></td></tr>
		<tr><th><?php esc_html_e( 'Mobile search terms', 'legend-game-directory' ); ?></th><td><textarea name="mobile_search_terms" rows="4" class="large-text"><?php echo esc_textarea( implode( "\n", isset( $s['mobile_search_terms'] ) ? (array) $s['mobile_search_terms'] : array( 'indie game', 'free game' ) ) ); ?></textarea></td></tr></table>
		<h2><?php esc_html_e( 'Automated score weights', 'legend-game-directory' ); ?></h2><table class="form-table"><?php foreach ( LGD_Rating_Engine::default_weights() as $key => $default ) : ?><tr><th><?php echo esc_html( ucwords( str_replace( '_', ' ', $key ) ) ); ?></th><td><input type="number" min="0" step="0.1" name="weights[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( isset( $s['weights'][ $key ] ) ? $s['weights'][ $key ] : $default ); ?>"></td></tr><?php endforeach; ?></table><?php submit_button(); ?></form></div><?php
	}

	public function save_settings() {
		check_admin_referer( 'lgd_save_settings' ); if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Permission denied.', 'legend-game-directory' ) ); }
		$old = LGD_Security::settings(); $new = $old;
		$new['publication_mode'] = in_array( $_POST['publication_mode'], array( 'review_everything', 'trusted_sources', 'advanced_automation' ), true ) ? $_POST['publication_mode'] : 'review_everything';
		foreach ( array( 'min_publish_confidence', 'ai_daily_request_limit' ) as $key ) { $new[ $key ] = absint( $_POST[ $key ] ); }
		$new['ai_monthly_cost_limit'] = max( 0, (float) $_POST['ai_monthly_cost_limit'] );
		foreach ( array( 'enable_ai', 'enable_ai_web_search', 'enable_ai_images', 'steam_enabled', 'steam_terms_accepted', 'apple_enabled', 'itch_enabled', 'official_site_enabled', 'review_auto_approve' ) as $key ) { $new[ $key ] = ! empty( $_POST[ $key ] ); }
		foreach ( array( 'approved_domains', 'blocked_domains', 'steam_app_ids', 'mobile_search_terms' ) as $key ) { $new[ $key ] = LGD_Security::sanitize_string_list( wp_unslash( isset( $_POST[ $key ] ) ? $_POST[ $key ] : '' ) ); }
		$new['weights'] = array(); foreach ( LGD_Rating_Engine::default_weights() as $key => $default ) { $new['weights'][ $key ] = isset( $_POST['weights'][ $key ] ) ? max( 0, (float) $_POST['weights'][ $key ] ) : $default; }
		update_option( 'lgd_settings', $new, false ); LGD_Logger::log( 'settings_updated', 'Legend settings updated.', array( 'changed_keys' => array_keys( array_diff_assoc( $new, $old ) ) ) );
		wp_safe_redirect( add_query_arg( array( 'post_type' => 'game', 'page' => 'lgd-settings', 'updated' => 1 ), admin_url( 'edit.php' ) ) ); exit;
	}

	public function meta_box() { add_meta_box( 'lgd_game_details', __( 'Legend Game Details', 'legend-game-directory' ), array( $this, 'render_meta_box' ), 'game', 'normal', 'high' ); }
	public function render_meta_box( $post ) {
		wp_nonce_field( 'lgd_save_game', 'lgd_game_nonce' ); $fields = array( '_lgd_developer' => 'Developer', '_lgd_publisher' => 'Publisher', '_lgd_official_website' => 'Official website', '_lgd_steam_url' => 'Steam URL', '_lgd_google_play_url' => 'Google Play URL', '_lgd_apple_app_store_url' => 'Apple App Store URL', '_lgd_current_price' => 'Current price', '_lgd_currency' => 'Currency', '_lgd_editorial_score' => 'Editorial score (0–100)', '_lgd_external_critic_score' => 'External critic score (0–100)', '_lgd_external_user_score' => 'External user score (0–100)', '_lgd_confidence' => 'Confidence (0–100)' );
		echo '<table class="form-table">';
		foreach ( $fields as $key => $label ) {
			echo '<tr><th><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input class="regular-text" id="' . esc_attr( $key ) . '" name="lgd_meta[' . esc_attr( $key ) . ']" value="' . esc_attr( get_post_meta( $post->ID, $key, true ) ) . '">';
			if ( '_lgd_official_website' === $key ) {
				$fetch_url = wp_nonce_url( add_query_arg( array( 'action' => 'lgd_fetch_artwork', 'post_id' => $post->ID ), admin_url( 'admin-post.php' ) ), 'lgd_fetch_artwork_' . $post->ID );
				echo ' <a href="' . esc_url( $fetch_url ) . '" class="button button-secondary" style="vertical-align:middle">' . esc_html__( 'Fetch Artwork', 'legend-game-directory' ) . '</a>';
				$screens = get_post_meta( $post->ID, '_lgd_official_screenshots', true );
				if ( is_array( $screens ) && ! empty( $screens[0] ) ) {
					echo '<br><img src="' . esc_url( $screens[0] ) . '" style="margin-top:6px;max-width:240px;height:auto;border-radius:4px" loading="lazy">';
				}
			}
			echo '</td></tr>';
		}
		echo '</table>';
		// Admin notice if message was passed back.
		if ( ! empty( $_GET['lgd_aw'] ) ) {
			$msg = 'ok' === $_GET['lgd_aw'] ? __( 'Artwork fetched and stored.', 'legend-game-directory' ) : __( 'No artwork found on that page.', 'legend-game-directory' );
			echo '<p class="' . ( 'ok' === $_GET['lgd_aw'] ? 'updated' : 'error' ) . '" style="padding:4px 8px">' . esc_html( $msg ) . '</p>';
		}
	}

	public function fetch_artwork() {
		$post_id = absint( isset( $_GET['post_id'] ) ? $_GET['post_id'] : 0 );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) { wp_die( esc_html__( 'Permission denied.', 'legend-game-directory' ) ); }
		check_admin_referer( 'lgd_fetch_artwork_' . $post_id );
		$ok = LGD_Artwork_Fetcher::fetch_for_game( $post_id );
		if ( $ok ) { LGD_Artwork_Fetcher::sideload_for_game( $post_id ); }
		wp_safe_redirect( add_query_arg( array( 'action' => 'edit', 'post' => $post_id, 'lgd_aw' => $ok ? 'ok' : 'fail' ), admin_url( 'post.php' ) ) ); exit;
	}

	public function save_game( $post_id, $post ) {
		if ( ! isset( $_POST['lgd_game_nonce'] ) || ! wp_verify_nonce( $_POST['lgd_game_nonce'], 'lgd_save_game' ) || defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE || ! current_user_can( 'edit_post', $post_id ) ) { return; }
		foreach ( isset( $_POST['lgd_meta'] ) ? (array) $_POST['lgd_meta'] : array() as $key => $value ) {
			if ( ! array_key_exists( $key, LGD_Post_Types::meta_fields() ) ) { continue; }
			if ( false !== strpos( $key, '_score' ) && '' !== $value && ( ! is_numeric( $value ) || $value < 0 || $value > 100 ) ) { continue; }
			update_post_meta( $post_id, $key, LGD_Post_Types::sanitize_meta( wp_unslash( $value ), $key, 'post' ) );
		}
		LGD_Logger::log( 'manual_game_update', 'Game fields updated manually.', array(), 'info', 'game', $post_id );
	}
}
