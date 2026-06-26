<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Roles and capability layer.
 *
 * Membership state (free vs. timed Premium) is owned by LCC_Memberships, backed by
 * WooCommerce orders paid through Fygaro. This class adds the earned "Legend Tester"
 * WordPress role and the capability map, and exposes thin membership helpers that
 * delegate to LCC_Memberships. Never trust a membership level from client input.
 */
final class LCC_Roles {

	const ROLE_MEMBER = 'legend_member';
	const ROLE_TESTER = 'legend_tester';

	// Custom capabilities granted by this plugin.
	const CAPS_TESTER = array( 'lcc_apply_test', 'lcc_submit_test', 'lcc_access_tester_dashboard' );
	const CAPS_MANAGE = array( 'lcc_manage_tests', 'lcc_manage_squads', 'lcc_moderate_community', 'lcc_manage_community' );

	public function __construct() {
		// No runtime hooks needed yet; roles are installed on activation.
	}

	// ── Installation ─────────────────────────────────────────────────────────────

	public static function install_roles() {
		// Legend Member: the free membership role (subscriber-equivalent).
		add_role( self::ROLE_MEMBER, __( 'Legend Member', 'legendcreate-community' ), array( 'read' => true ) );

		// Legend Tester: a subscriber-equivalent with tester capabilities.
		$tester_caps = array( 'read' => true );
		foreach ( self::CAPS_TESTER as $cap ) { $tester_caps[ $cap ] = true; }
		add_role( self::ROLE_TESTER, __( 'Legend Tester', 'legendcreate-community' ), $tester_caps );

		// Grant management + tester caps to administrators.
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array_merge( self::CAPS_TESTER, self::CAPS_MANAGE ) as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	public static function remove_roles() {
		remove_role( self::ROLE_MEMBER );
		remove_role( self::ROLE_TESTER );
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array_merge( self::CAPS_TESTER, self::CAPS_MANAGE ) as $cap ) {
				$admin->remove_cap( $cap );
			}
		}
	}

	// ── WooCommerce dependency ───────────────────────────────────────────────────

	public static function woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	// ── Server-side membership checks (delegate to LCC_Memberships) ───────────────

	/** Any registered, logged-in user is a free member. */
	public static function is_member( $user_id = null ) {
		return LCC_Memberships::is_member( $user_id );
	}

	/** Paying premium member with an unexpired timed membership. */
	public static function is_premium( $user_id = null ) {
		return LCC_Memberships::is_premium( $user_id );
	}

	public static function is_tester( $user_id = null ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) { return false; }
		$user = get_userdata( $user_id );
		return $user && ( in_array( self::ROLE_TESTER, (array) $user->roles, true ) || user_can( $user_id, 'lcc_access_tester_dashboard' ) );
	}
}
