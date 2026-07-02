<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Transactional email notifications for community events.
 *
 * Hooks into existing do_action() calls fired by other LCC classes —
 * no modifications to those classes needed.
 *
 * Events covered:
 *  - Badge earned (milestone badges only — not game/interest badges)
 *  - Referral activated (notify referrer + show points earned)
 *  - Squad joined (notify squad leader)
 *  - Premium granted (welcome + expiry date)
 */
final class LCC_Notifications {

	// Only milestone badges warrant an email — game/interest badges are too noisy.
	const BADGE_EMAIL = array(
		'founding_member',
		'squad_leader',
		'community_builder',
		'premium_legend',
		'verified_tester',
	);

	public function __construct() {
		add_action( 'lcc_badge_earned',       array( $this, 'on_badge_earned' ),      10, 2 );
		add_action( 'lcc_referral_activated', array( $this, 'on_referral_activated' ), 10, 2 );
		add_action( 'lcc_squad_joined',       array( $this, 'on_squad_joined' ),       10, 2 );
		add_action( 'lcc_premium_granted',    array( $this, 'on_premium_granted' ) );
	}

	// ── Event handlers ───────────────────────────────────────────────────────────

	public function on_badge_earned( $user_id, $slug ) {
		if ( ! in_array( $slug, self::BADGE_EMAIL, true ) ) { return; }
		if ( ! class_exists( 'LCC_Reputation' ) || ! isset( LCC_Reputation::BADGES[ $slug ] ) ) { return; }
		$badge = LCC_Reputation::BADGES[ $slug ];
		$user  = get_userdata( (int) $user_id );
		if ( ! $user ) { return; }

		$site    = get_bloginfo( 'name' );
		$subject = sprintf( '[%s] You earned a badge: %s', $site, $badge[0] );
		$body    = sprintf(
			/* translators: 1:name 2:badge label 3:site 4:badge desc 5:site 6:dashboard url */
			"Hi %1\$s,\n\nCongratulations — you've just earned the \"%2\$s\" badge on %3\$s!\n\n%4\$s\n\nKeep contributing to unlock more badges and points.\n\nThe %5\$s Team\n%6\$s",
			$user->display_name,
			$badge[0],
			$site,
			$badge[1],
			$site,
			home_url( '/dashboard/' )
		);
		self::send( $user->user_email, $subject, $body );
	}

	public function on_referral_activated( $referrer_id, $referred_id ) {
		$referrer = get_userdata( (int) $referrer_id );
		$referred = get_userdata( (int) $referred_id );
		if ( ! $referrer || ! $referred ) { return; }

		$points  = class_exists( 'LCC_Reputation' ) && isset( LCC_Reputation::ACTIONS['referral_activated'] )
			? (int) LCC_Reputation::ACTIONS['referral_activated'] : 30;
		$total   = class_exists( 'LCC_Reputation' ) ? LCC_Reputation::total( (int) $referrer_id ) : 0;
		$site    = get_bloginfo( 'name' );
		$subject = sprintf( '[%s] Your referral activated — +%d points!', $site, $points );
		$body    = sprintf(
			/* translators: 1:referrer name 2:referred name 3:site 4:points 5:total 6:dashboard url 7:site */
			"Hi %1\$s,\n\n%2\$s joined %3\$s using your referral link and has completed their profile.\n\nYou earned +%4\$d contribution points. Running total: %5\$d pts.\n\nDashboard: %6\$s\n\nThe %7\$s Team",
			$referrer->display_name,
			$referred->display_name,
			$site,
			$points,
			$total,
			home_url( '/dashboard/' ),
			$site
		);
		self::send( $referrer->user_email, $subject, $body );
	}

	public function on_squad_joined( $squad_id, $user_id ) {
		$squad_id = (int) $squad_id;
		$user_id  = (int) $user_id;
		$squad    = get_post( $squad_id );
		if ( ! $squad ) { return; }

		$leader_id = (int) $squad->post_author;
		if ( $leader_id === $user_id ) { return; } // leader joining their own squad — skip

		$leader = get_userdata( $leader_id );
		$joiner = get_userdata( $user_id );
		if ( ! $leader || ! $joiner ) { return; }

		$site    = get_bloginfo( 'name' );
		$subject = sprintf( '[%s] %s joined your squad "%s"', $site, $joiner->display_name, $squad->post_title );
		$body    = sprintf(
			/* translators: 1:leader name 2:joiner name 3:squad name 4:site 5:squad url 6:site */
			"Hi %1\$s,\n\n%2\$s just joined your squad \"%3\$s\" on %4\$s.\n\nView squad: %5\$s\n\nThe %6\$s Team",
			$leader->display_name,
			$joiner->display_name,
			$squad->post_title,
			$site,
			get_permalink( $squad_id ),
			$site
		);
		self::send( $leader->user_email, $subject, $body );
	}

	public function on_premium_granted( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) { return; }

		$until   = get_user_meta( (int) $user_id, '_lcc_premium_until', true );
		$expires = $until
			? date_i18n( get_option( 'date_format' ), strtotime( $until ) )
			: __( 'N/A', 'legendcreate-community' );
		$site    = get_bloginfo( 'name' );
		$subject = sprintf( '[%s] Welcome to Premium!', $site );
		$body    = sprintf(
			/* translators: 1:name 2:site 3:expires 4:dashboard url 5:site */
			"Hi %1\$s,\n\nYour %2\$s Premium membership is now active.\n\nExpires: %3\$s\n\nYou have no ads, premium content access, and priority squad features. We'll send a renewal reminder before your membership expires.\n\nDashboard: %4\$s\n\nThe %5\$s Team",
			$user->display_name,
			$site,
			$expires,
			home_url( '/dashboard/' ),
			$site
		);
		self::send( $user->user_email, $subject, $body );
	}

	// ── Core send helper ─────────────────────────────────────────────────────────

	private static function send( $to, $subject, $body ) {
		if ( ! is_email( $to ) ) { return; }
		$headers = array(
			'Content-Type: text/plain; charset=UTF-8',
			'From: ' . get_bloginfo( 'name' ) . ' <admin@legendcreate.com>',
		);
		wp_mail( $to, $subject, $body, $headers );
	}
}
