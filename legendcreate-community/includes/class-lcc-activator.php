<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Activation routine. Idempotent — safe to run on every activation/upgrade.
 */
final class LCC_Activator {

	const DB_VERSION = 3;

	public static function install() {
		LCC_Roles::install_roles();
		LCC_Memberships::schedule();
		self::create_tables();
		self::ensure_pages();
		update_option( 'lcc_db_version', self::DB_VERSION );
		update_option( 'lcc_installed_at', get_option( 'lcc_installed_at', current_time( 'mysql', true ) ) );
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$members = $wpdb->prefix . 'lcc_squad_members';

		dbDelta( "CREATE TABLE {$members} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			squad_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			role VARCHAR(20) NOT NULL DEFAULT 'member',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			points INT NOT NULL DEFAULT 0,
			joined_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY squad_user (squad_id, user_id),
			KEY squad (squad_id),
			KEY user (user_id)
		) {$charset};" );

		$points = $wpdb->prefix . 'lcc_points_log';
		dbDelta( "CREATE TABLE {$points} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			action VARCHAR(40) NOT NULL,
			points INT NOT NULL DEFAULT 0,
			ref_type VARCHAR(20) NOT NULL DEFAULT '',
			ref_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user (user_id),
			KEY user_action_ref (user_id, action, ref_id)
		) {$charset};" );
	}

	/**
	 * Create the member-facing pages (dashboard, onboarding) if missing and store
	 * their IDs. Idempotent — reuses existing pages by stored ID or slug.
	 */
	private static function ensure_pages() {
		$pages = array(
			'lcc_page_register'     => array( 'title' => 'Join LegendCreate', 'slug' => 'join', 'shortcode' => '[lcc_register]' ),
			'lcc_page_dashboard'    => array( 'title' => 'Member Dashboard', 'slug' => 'dashboard', 'shortcode' => '[lcc_dashboard]' ),
			'lcc_page_onboarding'   => array( 'title' => 'Get Started', 'slug' => 'get-started', 'shortcode' => '[lcc_onboarding]' ),
			'lcc_page_squad_create' => array( 'title' => 'Create a Squad', 'slug' => 'create-squad', 'shortcode' => '[lcc_squad_create]' ),
		);
		foreach ( $pages as $option => $page ) {
			$existing = (int) get_option( $option, 0 );
			if ( $existing && 'page' === get_post_type( $existing ) && 'trash' !== get_post_status( $existing ) ) {
				continue;
			}
			$by_slug = get_page_by_path( $page['slug'], OBJECT, 'page' );
			if ( $by_slug ) {
				update_option( $option, (int) $by_slug->ID );
				continue;
			}
			$id = wp_insert_post( array(
				'post_title'   => $page['title'],
				'post_name'    => $page['slug'],
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => $page['shortcode'],
			) );
			if ( $id && ! is_wp_error( $id ) ) { update_option( $option, (int) $id ); }
		}
	}
}
