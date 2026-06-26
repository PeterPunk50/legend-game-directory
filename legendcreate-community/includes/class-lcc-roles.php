<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Roles and capability layer.
 *
 * Membership *levels* (Visitor / Legend Member / Legend Premium) are owned by
 * Paid Memberships Pro. This class adds the earned "Legend Tester" WordPress role
 * and the capability map, plus server-side helpers to check membership level.
 * Never trust a membership level from client-side input — always check here.
 */
final class LCC_Roles {

	const ROLE_TESTER = 'legend_tester';

	// Custom capabilities granted by this plugin.
	const CAPS_TESTER = array( 'lcc_apply_test', 'lcc_submit_test', 'lcc_access_tester_dashboard' );
	const CAPS_MANAGE = array( 'lcc_manage_tests', 'lcc_manage_squads', 'lcc_moderate_community', 'lcc_manage_community' );

	public function __construct() {
		// No runtime hooks needed yet; roles are installed on activation.
	}

	// ── Installation ─────────────────────────────────────────────────────────────

	public static function install_roles() {
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
		remove_role( self::ROLE_TESTER );
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array_merge( self::CAPS_TESTER, self::CAPS_MANAGE ) as $cap ) {
				$admin->remove_cap( $cap );
			}
		}
	}

	// ── Paid Memberships Pro integration ─────────────────────────────────────────

	public static function pmpro_active() {
		return function_exists( 'pmpro_hasMembershipLevel' );
	}

	/**
	 * Configured PMP level IDs. Defaults: free = 1, premium = 2. Stored as options so
	 * the IDs can be corrected after the levels are created in the PMP admin.
	 */
	public static function level_free() {
		return (int) get_option( 'lcc_level_free', 1 );
	}

	public static function level_premium() {
		return (int) get_option( 'lcc_level_premium', 2 );
	}

	// ── Server-side membership checks ────────────────────────────────────────────

	/**
	 * Is the user a member at all (free or premium)? Falls back to "logged in" when
	 * PMP is not active, so the community features still work during setup.
	 */
	public static function is_member( $user_id = null ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) { return false; }
		if ( ! self::pmpro_active() ) { return true; }
		return (bool) pmpro_hasMembershipLevel( array( self::level_free(), self::level_premium() ), $user_id );
	}

	/**
	 * Is the user a paying premium member? Always false when not logged in.
	 * When PMP is inactive this returns false (premium requires billing).
	 */
	public static function is_premium( $user_id = null ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) { return false; }
		if ( ! self::pmpro_active() ) { return false; }
		return (bool) pmpro_hasMembershipLevel( self::level_premium(), $user_id );
	}

	public static function is_tester( $user_id = null ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) { return false; }
		$user = get_userdata( $user_id );
		return $user && ( in_array( self::ROLE_TESTER, (array) $user->roles, true ) || user_can( $user_id, 'lcc_access_tester_dashboard' ) );
	}
}
