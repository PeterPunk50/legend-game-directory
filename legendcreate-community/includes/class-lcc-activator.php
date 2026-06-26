<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Activation routine. Idempotent — safe to run on every activation/upgrade.
 */
final class LCC_Activator {

	const DB_VERSION = 1;

	public static function install() {
		LCC_Roles::install_roles();
		LCC_Memberships::schedule();
		self::ensure_pages();
		update_option( 'lcc_db_version', self::DB_VERSION );
		update_option( 'lcc_installed_at', get_option( 'lcc_installed_at', current_time( 'mysql', true ) ) );
	}

	/**
	 * Create the member-facing pages (dashboard, onboarding) if missing and store
	 * their IDs. Idempotent — reuses existing pages by stored ID or slug.
	 */
	private static function ensure_pages() {
		$pages = array(
			'lcc_page_dashboard'  => array( 'title' => 'Member Dashboard', 'slug' => 'dashboard', 'shortcode' => '[lcc_dashboard]' ),
			'lcc_page_onboarding' => array( 'title' => 'Get Started', 'slug' => 'get-started', 'shortcode' => '[lcc_onboarding]' ),
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
