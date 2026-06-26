<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Membership state — timed Premium model (Fygaro is one-time-payment only).
 *
 * Premium is stored as a UTC expiry timestamp in user meta and enforced server-side.
 * A one-time WooCommerce order (paid via Fygaro) grants/extends Premium for a configured
 * number of days. A daily cron expires lapsed memberships and emails renewal reminders.
 * Never trust premium status from the client — always check is_premium() here.
 */
final class LCC_Memberships {

	const META_UNTIL    = '_lcc_premium_until';   // 'Y-m-d H:i:s' in UTC
	const META_SINCE    = '_lcc_premium_since';   // first time they went premium (UTC)
	const META_REMINDED = '_lcc_renewal_reminded'; // expiry timestamp we last reminded for
	const CRON_HOOK     = 'lcc_membership_maintenance';

	public function __construct() {
		// Grant Premium when a WooCommerce order is paid (guarded — only fires if Woo active).
		add_action( 'woocommerce_payment_complete', array( $this, 'maybe_grant_from_order' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'maybe_grant_from_order' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_maintenance' ) );
	}

	// ── Cron lifecycle ───────────────────────────────────────────────────────────

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule() {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) { wp_unschedule_event( $ts, self::CRON_HOOK ); }
	}

	// ── State ────────────────────────────────────────────────────────────────────

	/**
	 * Map of WooCommerce product ID => Premium days granted. Admin-configurable.
	 * e.g. array( 12 => 30, 13 => 365 ). Empty until products are created.
	 */
	public static function premium_products() {
		$map = get_option( 'lcc_premium_products', array() );
		return is_array( $map ) ? array_map( 'intval', $map ) : array();
	}

	public static function is_member( $user_id = null ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		return $user_id > 0; // any registered, logged-in user is a free member
	}

	public static function is_premium( $user_id = null ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( $user_id < 1 ) { return false; }
		$until = get_user_meta( $user_id, self::META_UNTIL, true );
		if ( ! $until ) { return false; }
		return strtotime( $until . ' UTC' ) >= time();
	}

	public static function premium_until( $user_id = null ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		return $user_id ? (string) get_user_meta( $user_id, self::META_UNTIL, true ) : '';
	}

	/**
	 * Grant or extend Premium by $days. If still active, extends from the current expiry;
	 * otherwise from now. Returns the new UTC expiry string or false.
	 */
	public static function grant( $user_id, $days, $source = 'manual' ) {
		$user_id = (int) $user_id;
		$days    = (int) $days;
		if ( $user_id < 1 || $days < 1 ) { return false; }

		$now     = time();
		$current = get_user_meta( $user_id, self::META_UNTIL, true );
		$base    = ( $current && strtotime( $current . ' UTC' ) > $now ) ? strtotime( $current . ' UTC' ) : $now;
		$until   = gmdate( 'Y-m-d H:i:s', $base + $days * DAY_IN_SECONDS );

		update_user_meta( $user_id, self::META_UNTIL, $until );
		if ( ! get_user_meta( $user_id, self::META_SINCE, true ) ) {
			update_user_meta( $user_id, self::META_SINCE, gmdate( 'Y-m-d H:i:s', $now ) );
		}
		delete_user_meta( $user_id, self::META_REMINDED );

		do_action( 'lcc_premium_granted', $user_id, $until, $source );
		return $until;
	}

	public static function revoke( $user_id, $reason = 'expired' ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) { return; }
		delete_user_meta( $user_id, self::META_UNTIL );
		do_action( 'lcc_premium_revoked', $user_id, $reason );
	}

	// ── WooCommerce order → grant ────────────────────────────────────────────────

	public function maybe_grant_from_order( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) { return; }
		$order = wc_get_order( $order_id );
		if ( ! $order ) { return; }
		$user_id = $order->get_user_id();
		if ( ! $user_id ) { return; }

		$map = self::premium_products();
		if ( empty( $map ) ) { return; }

		// Prevent double-granting if both payment_complete and status_completed fire.
		$processed = (array) $order->get_meta( '_lcc_premium_processed' );
		$changed   = false;
		foreach ( $order->get_items() as $item ) {
			$pid = (int) $item->get_product_id();
			if ( isset( $map[ $pid ] ) && ! in_array( $pid, $processed, true ) ) {
				self::grant( $user_id, $map[ $pid ], 'order:' . $order_id );
				$processed[] = $pid;
				$changed     = true;
			}
		}
		if ( $changed ) {
			$order->update_meta_data( '_lcc_premium_processed', $processed );
			$order->save();
		}
	}

	// ── Daily maintenance: expire + renewal reminders ────────────────────────────

	public function run_maintenance() {
		$now_ts        = time();
		$reminder_days = (int) get_option( 'lcc_renewal_reminder_days', 5 );
		$reminder_cut  = gmdate( 'Y-m-d H:i:s', $now_ts + $reminder_days * DAY_IN_SECONDS );
		$now_utc       = gmdate( 'Y-m-d H:i:s', $now_ts );

		// Only users who have a premium expiry set (bounded query).
		$users = get_users( array(
			'meta_key'     => self::META_UNTIL,
			'meta_compare' => 'EXISTS',
			'fields'       => array( 'ID' ),
			'number'       => 1000,
		) );

		foreach ( $users as $u ) {
			$uid   = (int) $u->ID;
			$until = get_user_meta( $uid, self::META_UNTIL, true );
			if ( ! $until ) { continue; }

			if ( strtotime( $until . ' UTC' ) < $now_ts ) {
				// Lapsed — revoke premium access (keep the account, free member remains).
				self::revoke( $uid, 'expired' );
				continue;
			}

			// Approaching expiry — send one reminder per cycle.
			if ( $until <= $reminder_cut && $until > $now_utc ) {
				$reminded = get_user_meta( $uid, self::META_REMINDED, true );
				if ( $reminded !== $until ) {
					$this->send_renewal_reminder( $uid, $until );
					update_user_meta( $uid, self::META_REMINDED, $until );
				}
			}
		}
	}

	private function send_renewal_reminder( $user_id, $until ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) { return; }

		$renew_url = get_option( 'lcc_renewal_url', home_url( '/premium/' ) );
		$expiry    = date_i18n( get_option( 'date_format' ), strtotime( $until . ' UTC' ) );
		$site      = get_bloginfo( 'name' );

		$subject = sprintf( __( 'Your %s Premium expires soon', 'legendcreate-community' ), $site );
		$body    = sprintf(
			/* translators: 1: display name, 2: expiry date, 3: renewal URL */
			__( "Hi %1\$s,\n\nYour Legend Premium membership expires on %2\$s. Renew here to keep your premium guides, priority testing, and ad-reduced experience:\n\n%3\$s\n\nThanks for being part of %4\$s.", 'legendcreate-community' ),
			$user->display_name,
			$expiry,
			$renew_url,
			$site
		);

		wp_mail( $user->user_email, $subject, $body );
		do_action( 'lcc_renewal_reminder_sent', $user_id, $until );
	}
}
