<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Referrals — invite your existing crew.
 *
 * Each member has a unique referral code → /join/?ref=CODE. A referral is recorded
 * pending at signup and only counts as "activated" once the referred member performs
 * a meaningful action (completes onboarding) — never on raw signup. Self-referrals and
 * one-referrer-per-user are enforced; disposable/duplicate emails are blocked upstream
 * at registration. Activation awards the referrer points (once per referred user).
 */
final class LCC_Referrals {

	const COOKIE = 'lcc_ref';

	public function __construct() {
		add_action( 'init', array( $this, 'capture' ) );
		add_action( 'lcc_member_registered', array( $this, 'record' ), 10, 2 );
		add_action( 'lcc_member_verified', array( $this, 'maybe_activate' ) );
		add_action( 'lcc_onboarding_completed', array( $this, 'maybe_activate' ) );
		add_action( 'lcc_profile_saved', array( $this, 'maybe_activate' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lcc_referrals';
	}

	// ── Codes & links ────────────────────────────────────────────────────────────

	public static function ref_code( $user_id ) {
		$user_id = (int) $user_id;
		$code    = get_user_meta( $user_id, 'lcc_ref_code', true );
		if ( $code ) { return $code; }
		do {
			$code = strtoupper( wp_generate_password( 8, false ) );
		} while ( self::code_owner( $code ) );
		update_user_meta( $user_id, 'lcc_ref_code', $code );
		return $code;
	}

	public static function code_owner( $code ) {
		global $wpdb;
		$id = $wpdb->get_var( $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='lcc_ref_code' AND meta_value=%s LIMIT 1", $code
		) );
		return $id ? (int) $id : 0;
	}

	public static function ref_link( $user_id ) {
		$join = (int) get_option( 'lcc_page_register', 0 );
		$base = $join ? get_permalink( $join ) : home_url( '/join/' );
		return add_query_arg( 'ref', self::ref_code( $user_id ), $base );
	}

	// ── Capture ──────────────────────────────────────────────────────────────────

	public function capture() {
		if ( is_user_logged_in() || empty( $_GET['ref'] ) || headers_sent() ) { return; }
		$code = strtoupper( sanitize_text_field( wp_unslash( $_GET['ref'] ) ) );
		if ( $code ) {
			setcookie( self::COOKIE, $code, time() + 30 * DAY_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN );
		}
	}

	public static function ref_from_request() {
		if ( ! empty( $_POST['lcc_ref'] ) ) { return strtoupper( sanitize_text_field( wp_unslash( $_POST['lcc_ref'] ) ) ); }
		if ( ! empty( $_COOKIE[ self::COOKIE ] ) ) { return strtoupper( sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) ); }
		return '';
	}

	// ── Record + activate ────────────────────────────────────────────────────────

	public function record( $user_id, $ref = '' ) {
		global $wpdb;
		$user_id = (int) $user_id;
		$code    = $ref ? strtoupper( $ref ) : self::ref_from_request();
		if ( '' === $code ) { return; }

		$referrer = self::code_owner( $code );
		if ( ! $referrer || $referrer === $user_id ) { return; } // no self-referral

		// One referrer per referred user (UNIQUE on referred_id makes this idempotent).
		$wpdb->insert( self::table(), array(
			'referrer_id' => $referrer,
			'referred_id' => $user_id,
			'code'        => $code,
			'status'      => 'pending',
			'created_at'  => current_time( 'mysql', true ),
		), array( '%d', '%d', '%s', '%s', '%s' ) );

		// Clear the cookie now that it's been attributed.
		if ( ! headers_sent() && ! empty( $_COOKIE[ self::COOKIE ] ) ) {
			setcookie( self::COOKIE, '', time() - 3600, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN );
		}
	}

	private static function criteria_met( $user_id ) {
		$onboarded = class_exists( 'LCC_Profiles' ) && LCC_Profiles::is_onboarded( $user_id );
		if ( ! $onboarded ) { return false; }
		// Optional extra gate once email delivery (SMTP) is set up.
		if ( get_option( 'lcc_referral_require_verify', 0 ) && class_exists( 'LCC_Registration' ) ) {
			return LCC_Registration::is_verified( $user_id );
		}
		return true;
	}

	public function maybe_activate( $user_id ) {
		global $wpdb;
		$user_id = (int) $user_id;
		$row     = $wpdb->get_row( $wpdb->prepare(
			'SELECT id, referrer_id, status FROM ' . self::table() . ' WHERE referred_id=%d', $user_id
		) );
		if ( ! $row || 'pending' !== $row->status ) { return; }
		if ( ! self::criteria_met( $user_id ) ) { return; }

		$wpdb->update( self::table(),
			array( 'status' => 'activated', 'activated_at' => current_time( 'mysql', true ) ),
			array( 'id' => (int) $row->id ),
			array( '%s', '%s' ), array( '%d' )
		);
		if ( class_exists( 'LCC_Reputation' ) ) {
			LCC_Reputation::award( (int) $row->referrer_id, 'referral_activated', 'referral', $user_id );
		}
		do_action( 'lcc_referral_activated', (int) $row->referrer_id, $user_id );
	}

	// ── Stats + render ───────────────────────────────────────────────────────────

	public static function stats( $user_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT status, COUNT(*) AS n FROM ' . self::table() . ' WHERE referrer_id=%d GROUP BY status', $user_id
		), OBJECT_K );
		$activated = isset( $rows['activated'] ) ? (int) $rows['activated']->n : 0;
		$pending   = isset( $rows['pending'] ) ? (int) $rows['pending']->n : 0;
		return array( 'activated' => $activated, 'pending' => $pending, 'total' => $activated + $pending );
	}

	public static function render( $user_id ) {
		$link  = self::ref_link( $user_id );
		$stats = self::stats( $user_id );
		ob_start();
		echo '<h3>' . esc_html__( 'Invite your squad', 'legendcreate-community' ) . '</h3>';
		echo '<p class="lcc-muted">' . esc_html__( 'Share your link. You earn points when an invited friend joins and completes onboarding.', 'legendcreate-community' ) . '</p>';
		echo '<input class="lcc-invite" type="text" readonly onclick="this.select()" value="' . esc_url( $link ) . '">';
		echo '<div class="lcc-ref-stats">';
		echo '<span><strong>' . esc_html( $stats['total'] ) . '</strong> ' . esc_html__( 'invited', 'legendcreate-community' ) . '</span>';
		echo '<span><strong>' . esc_html( $stats['activated'] ) . '</strong> ' . esc_html__( 'activated', 'legendcreate-community' ) . '</span>';
		echo '<span><strong>' . esc_html( $stats['pending'] ) . '</strong> ' . esc_html__( 'pending', 'legendcreate-community' ) . '</span>';
		echo '</div>';
		return ob_get_clean();
	}
}
