<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Login-aware navigation. Shows "Join" only to logged-out visitors and
 * "My Account" only to logged-in members, regardless of which menu they sit in.
 * Items are matched by their linked page (register / dashboard).
 */
final class LCC_Menu {

	public function __construct() {
		add_filter( 'wp_nav_menu_objects', array( $this, 'filter_items' ), 10, 2 );
	}

	public function filter_items( $items, $args ) {
		$logged   = is_user_logged_in();
		$join_url = self::page_url( 'lcc_page_register' );
		$dash_url = self::page_url( 'lcc_page_dashboard' );

		foreach ( $items as $key => $item ) {
			$url = untrailingslashit( (string) $item->url );
			// Hide "Join" once signed in.
			if ( $logged && $join_url && $url === $join_url ) { unset( $items[ $key ] ); continue; }
			// Hide "My Account" when signed out.
			if ( ! $logged && $dash_url && $url === $dash_url ) { unset( $items[ $key ] ); continue; }
		}
		return $items;
	}

	private static function page_url( $option ) {
		$id = (int) get_option( $option, 0 );
		return $id ? untrailingslashit( get_permalink( $id ) ) : '';
	}
}
