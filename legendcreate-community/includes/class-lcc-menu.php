<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Login-aware navigation. Hides the raw "Join" / "My Account" menu items and
 * appends a single styled auth button ("Login" when signed out, "My Account"
 * when signed in) to the primary menu. Items are matched by their linked page.
 */
final class LCC_Menu {

	public function __construct() {
		add_filter( 'wp_nav_menu_objects', array( $this, 'filter_items' ), 10, 2 );
		add_filter( 'wp_nav_menu_items', array( $this, 'add_auth_button' ), 10, 2 );
	}

	public function filter_items( $items, $args ) {
		$join_url = self::page_url( 'lcc_page_register' );
		$dash_url = self::page_url( 'lcc_page_dashboard' );

		foreach ( $items as $key => $item ) {
			$url = untrailingslashit( (string) $item->url );
			// Hide "Join" nav item (replaced by the styled auth button).
			if ( $join_url && $url === $join_url ) { unset( $items[ $key ] ); continue; }
			// Hide "My Account" nav item (replaced by the styled auth button).
			if ( $dash_url && $url === $dash_url ) { unset( $items[ $key ] ); continue; }
		}
		return $items;
	}

	public function add_auth_button( $items, $args ) {
		if ( empty( $args->theme_location ) || 'primary' !== $args->theme_location ) {
			return $items;
		}
		if ( is_user_logged_in() ) {
			$fb     = self::page_url( 'lcc_page_feedback' );
			$fb     = $fb ? $fb : home_url( '/feedback/' );
			$items .= '<li class="menu-item"><a href="' . esc_url( $fb ) . '">' . esc_html__( 'Feedback', 'legendcreate-community' ) . '</a></li>';
			$url    = self::page_url( 'lcc_page_dashboard' );
			$url    = $url ? $url : home_url( '/dashboard/' );
			$items .= '<li class="menu-item lcc-nav-cta"><a class="lcc-btn-nav" href="' . esc_url( $url ) . '">' . esc_html__( 'My Account', 'legendcreate-community' ) . '</a></li>';
		} else {
			$url    = self::page_url( 'lcc_page_register' );
			$url    = $url ? $url : home_url( '/join/' );
			$items .= '<li class="menu-item lcc-nav-cta"><a class="lcc-btn-nav" href="' . esc_url( $url ) . '">' . esc_html__( 'Login', 'legendcreate-community' ) . '</a></li>';
		}
		return $items;
	}

	private static function page_url( $option ) {
		$id = (int) get_option( $option, 0 );
		return $id ? untrailingslashit( get_permalink( $id ) ) : '';
	}
}
