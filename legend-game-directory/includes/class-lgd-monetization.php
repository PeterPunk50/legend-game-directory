<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Deterministic Monetization Grade engine.
 * AI may summarize evidence after this engine assigns the grade — it never assigns it.
 * Grade is stored in _lgd_monetization_grade and recalculated on every save_post_game.
 */
final class LGD_Monetization {

	const GRADES = array( 'A', 'B', 'C', 'D', 'F', 'Pending' );

	/**
	 * Calculate grade from stored meta. Pure function — reads meta, returns letter.
	 * Precedence: F → D → C → B → A → Pending.
	 */
	public static function calculate( $game_id ) {
		$iap      = strtolower( trim( (string) get_post_meta( $game_id, '_lgd_in_app_purchases', true ) ) );
		$ads      = strtolower( trim( (string) get_post_meta( $game_id, '_lgd_advertising', true ) ) );
		$ft       = trim( (string) get_post_meta( $game_id, '_lgd_free_type', true ) );
		$price    = get_post_meta( $game_id, '_lgd_current_price', true );
		$combined = $iap . ' ' . $ads;

		// Need at least one monetization signal.
		if ( '' === $iap && '' === $ads && '' === $ft && '' === (string) $price ) {
			return 'Pending';
		}

		// F — Pay-to-win or highly exploitative.
		if ( preg_match( '/pay.?to.?win|p2w|paywall|misleading|manipulat|exploit/i', $combined ) ) {
			return 'F';
		}

		// D — Aggressive.
		if ( preg_match( '/\baggressive\b|forced\s+ad|excessive|heavy\s+iap|heavy\s+ad|energy\s+timer/i', $combined ) ) {
			return 'D';
		}

		// C — Moderate pressure.
		if ( preg_match( '/\bfrequent\b|\bmoderate\b|grind\s+reduc|repeated\s+prompt|strong\s+pressure/i', $combined ) ) {
			return 'C';
		}

		// Detect clean signals.
		$no_iap  = '' === $iap || preg_match( '/^none$|no\s+iap|no\s+purchase|cosmetic.only|cosmetic_only/i', $iap );
		$low_ads = '' === $ads || preg_match( '/^none$|no\s+ad|minimal\s+ad/i', $ads );
		$low_iap = preg_match( '/optional|convenience|one.?time|fair\s+price|small\s+price/i', $iap );
		$rwrd_ad = preg_match( '/rewarded|opt.?in/i', $ads );

		// A — Fair.
		if ( $no_iap && $low_ads ) { return 'A'; }

		// B — Mostly fair.
		if ( ( $no_iap || $low_iap ) && ( $low_ads || $rwrd_ad ) ) { return 'B'; }

		// Paid Indie with no ad/IAP signals → B (fair upfront purchase model).
		if ( 'Paid Indie' === $ft && '' === trim( $combined ) ) { return 'B'; }

		// Open Source / Permanently Free with no negative signals → A.
		if ( preg_match( '/Open Source|Permanently Free/i', $ft ) && '' === trim( $combined ) ) { return 'A'; }

		return 'Pending';
	}

	/**
	 * Calculate grade respecting any manual override, then persist it.
	 * Call after saving meta so the override field reflects the saved value.
	 */
	public static function save( $game_id ) {
		$override = get_post_meta( $game_id, '_lgd_monetization_grade_override', true );
		$grade    = ( $override && in_array( $override, self::GRADES, true ) ) ? $override : self::calculate( $game_id );
		update_post_meta( $game_id, '_lgd_monetization_grade', $grade );
		return $grade;
	}

	/** Recalculate grade for all published games (batch admin action). */
	public static function recalc_all() {
		$ids = get_posts( array( 'post_type' => 'game', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ) );
		$count = 0;
		foreach ( $ids as $id ) { self::save( $id ); $count++; }
		return $count;
	}

	public static function label( $grade ) {
		$map = array(
			'A' => 'Fair', 'B' => 'Mostly Fair', 'C' => 'Moderate Pressure',
			'D' => 'Aggressive', 'F' => 'Pay-to-Win / Exploitative', 'Pending' => 'Pending',
		);
		return isset( $map[ $grade ] ) ? $map[ $grade ] : 'Pending';
	}

	public static function description( $grade ) {
		$map = array(
			'A'       => 'No purchases required, or cosmetic-only purchases with no material competitive advantage.',
			'B'       => 'Optional convenience purchases or reasonable advertising. Meaningful progress is practical without spending.',
			'C'       => 'Frequent advertisements, grind-reduction purchases, or repeated purchase prompts create moderate pressure.',
			'D'       => 'Strong purchase pressure, excessive advertising, or energy timers designed to push spending.',
			'F'       => 'Spending materially affects competitive strength, or key progression is effectively paywalled.',
			'Pending' => 'Insufficient monetization evidence to assign a grade. Submit a correction if you have details.',
		);
		return isset( $map[ $grade ] ) ? $map[ $grade ] : '';
	}

	public static function css_class( $grade ) {
		$map = array(
			'A' => 'lgd-grade-a', 'B' => 'lgd-grade-b', 'C' => 'lgd-grade-c',
			'D' => 'lgd-grade-d', 'F' => 'lgd-grade-f', 'Pending' => 'lgd-grade-p',
		);
		return isset( $map[ $grade ] ) ? $map[ $grade ] : 'lgd-grade-p';
	}

	/** Freshness status based on overall _lgd_last_verified date. */
	public static function freshness( $game_id ) {
		$date = get_post_meta( $game_id, '_lgd_last_verified', true );
		if ( ! $date ) { return 'unverified'; }
		$ts = strtotime( (string) $date );
		if ( ! $ts ) { return 'unverified'; }
		$days = ( time() - $ts ) / DAY_IN_SECONDS;
		if ( $days <= 30 ) { return 'current'; }
		if ( $days <= 90 ) { return 'aging'; }
		return 'outdated';
	}

	public static function freshness_label( $status ) {
		$map = array( 'current' => 'Current', 'aging' => 'Aging', 'outdated' => 'Outdated', 'unverified' => 'Unverified' );
		return isset( $map[ $status ] ) ? $map[ $status ] : 'Unverified';
	}

	/** Confidence label from numeric percentage. */
	public static function confidence_label( $pct ) {
		$pct = (float) $pct;
		if ( $pct >= 75 ) { return 'High confidence'; }
		if ( $pct >= 50 ) { return 'Medium confidence'; }
		if ( $pct > 0 )   { return 'Low confidence'; }
		return 'Unverified';
	}
}
