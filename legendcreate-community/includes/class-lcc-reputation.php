<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Reputation: contribution points + badges.
 *
 * Points are logged to {prefix}lcc_points_log (anti-fraud: once-per action+ref)
 * with a denormalized total in user meta for fast reads. Badges are derived from
 * profile state, points, and milestone events, stored in user meta.
 */
final class LCC_Reputation {

	const META_POINTS = 'lcc_points';
	const META_BADGES = 'lcc_badges';

	const ACTIONS = array(
		'verify_email'       => 10,
		'onboarding'         => 15,
		'profile_complete'   => 25,
		'squad_create'       => 20,
		'squad_join'         => 10,
		'premium'            => 50,
		'referral_activated' => 30,
		'rating_submitted'   => 5,
		'test_completed'     => 40,
	);

	// slug => [ label, description ]
	const BADGES = array(
		'founding_member'   => array( 'Founding Member', 'One of the first to join LegendCreate.' ),
		'squad_leader'      => array( 'Squad Leader', 'Created and leads a squad.' ),
		'community_builder' => array( 'Community Builder', 'Earned 100+ contribution points.' ),
		'premium_legend'    => array( 'Premium Legend', 'An active Legend Premium member.' ),
		'mobile_specialist' => array( 'Mobile Specialist', 'Focused on mobile games.' ),
		'indie_explorer'    => array( 'Indie Explorer', 'Champions indie games.' ),
		'free_game_hunter'  => array( 'Free Game Hunter', 'Loves a great free game.' ),
		'fortnite_guide'    => array( 'Fortnite Guide', 'Drops into Fortnite.' ),
		'cod_analyst'       => array( 'Call of Duty Analyst', 'Runs Call of Duty.' ),
		'cs_strategist'     => array( 'Counter-Strike Strategist', 'Calls the CS shots.' ),
		'apex_specialist'   => array( 'Apex Specialist', 'Drops into the Apex Games.' ),
		'verified_tester'   => array( 'Verified Tester', 'An approved game tester.' ),
	);

	const GAME_BADGE = array(
		'Fortnite'        => 'fortnite_guide',
		'Call of Duty'    => 'cod_analyst',
		'Counter-Strike'  => 'cs_strategist',
		'Apex Legends'    => 'apex_specialist',
	);

	const INTEREST_BADGE = array(
		'mobile' => 'mobile_specialist',
		'indie'  => 'indie_explorer',
		'free'   => 'free_game_hunter',
	);

	const FOUNDING_LIMIT = 500;

	public function __construct() {
		add_action( 'lcc_member_registered', array( $this, 'on_registered' ) );
		add_action( 'lcc_member_verified', array( $this, 'on_verified' ) );
		add_action( 'lcc_onboarding_completed', array( $this, 'on_onboarding' ) );
		add_action( 'lcc_profile_saved', array( $this, 'on_profile_saved' ) );
		add_action( 'lcc_squad_created', array( $this, 'on_squad_created' ), 10, 2 );
		add_action( 'lcc_squad_joined', array( $this, 'on_squad_joined' ), 10, 2 );
		add_action( 'lcc_premium_granted', array( $this, 'on_premium' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lcc_points_log';
	}

	// ── Points ───────────────────────────────────────────────────────────────────

	public static function total( $user_id ) {
		return (int) get_user_meta( (int) $user_id, self::META_POINTS, true );
	}

	private static function already( $user_id, $action, $ref_type, $ref_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM ' . self::table() . ' WHERE user_id=%d AND action=%s AND ref_type=%s AND ref_id=%d LIMIT 1',
			$user_id, $action, $ref_type, $ref_id
		) );
	}

	/**
	 * Award points for an action. $once (default) prevents repeat awards for the
	 * same action+ref — the core anti-fraud guard.
	 */
	public static function award( $user_id, $action, $ref_type = '', $ref_id = 0, $once = true ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( $user_id < 1 || ! isset( self::ACTIONS[ $action ] ) ) { return; }
		if ( $once && self::already( $user_id, $action, $ref_type, $ref_id ) ) { return; }

		$points = (int) self::ACTIONS[ $action ];
		$wpdb->insert( self::table(), array(
			'user_id'    => $user_id,
			'action'     => $action,
			'points'     => $points,
			'ref_type'   => $ref_type,
			'ref_id'     => (int) $ref_id,
			'created_at' => current_time( 'mysql', true ),
		), array( '%d', '%s', '%d', '%s', '%d', '%s' ) );

		update_user_meta( $user_id, self::META_POINTS, self::total( $user_id ) + $points );
		do_action( 'lcc_points_awarded', $user_id, $action, $points );
		self::evaluate_badges( $user_id );
	}

	// ── Badges ───────────────────────────────────────────────────────────────────

	public static function get_badges( $user_id ) {
		$b = get_user_meta( (int) $user_id, self::META_BADGES, true );
		return is_array( $b ) ? $b : array();
	}

	public static function has_badge( $user_id, $slug ) {
		return in_array( $slug, self::get_badges( $user_id ), true );
	}

	public static function award_badge( $user_id, $slug ) {
		if ( ! isset( self::BADGES[ $slug ] ) || self::has_badge( $user_id, $slug ) ) { return false; }
		$badges   = self::get_badges( $user_id );
		$badges[] = $slug;
		update_user_meta( (int) $user_id, self::META_BADGES, array_values( array_unique( $badges ) ) );
		update_user_meta( (int) $user_id, 'lcc_badge_' . $slug . '_at', current_time( 'mysql', true ) );
		do_action( 'lcc_badge_earned', $user_id, $slug );
		return true;
	}

	/** Re-derive auto badges from current state. Idempotent. */
	public static function evaluate_badges( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) { return; }

		if ( self::total( $user_id ) >= 100 ) { self::award_badge( $user_id, 'community_builder' ); }
		if ( class_exists( 'LCC_Memberships' ) && LCC_Memberships::is_premium( $user_id ) ) { self::award_badge( $user_id, 'premium_legend' ); }
		if ( class_exists( 'LCC_Squads' ) && LCC_Squads::owned_count( $user_id ) >= 1 ) { self::award_badge( $user_id, 'squad_leader' ); }
		if ( class_exists( 'LCC_Roles' ) && LCC_Roles::is_tester( $user_id ) ) { self::award_badge( $user_id, 'verified_tester' ); }

		if ( class_exists( 'LCC_Profiles' ) ) {
			$p = LCC_Profiles::get_profile( $user_id );
			foreach ( self::GAME_BADGE as $game => $slug ) {
				if ( in_array( $game, (array) $p['fav_games'], true ) ) { self::award_badge( $user_id, $slug ); }
			}
			foreach ( self::INTEREST_BADGE as $interest => $slug ) {
				if ( in_array( $interest, (array) $p['interests'], true ) ) { self::award_badge( $user_id, $slug ); }
			}
		}
	}

	// ── Event listeners ──────────────────────────────────────────────────────────

	public function on_registered( $user_id ) {
		$count = (int) get_option( 'lcc_founding_count', 0 );
		if ( $count < self::FOUNDING_LIMIT ) {
			if ( self::award_badge( $user_id, 'founding_member' ) ) {
				update_option( 'lcc_founding_count', $count + 1 );
			}
		}
	}

	public function on_verified( $user_id ) { self::award( $user_id, 'verify_email', 'user', $user_id ); }

	public function on_onboarding( $user_id ) {
		self::award( $user_id, 'onboarding', 'user', $user_id );
		$this->maybe_profile_complete( $user_id );
	}

	public function on_profile_saved( $user_id ) {
		$this->maybe_profile_complete( $user_id );
		self::evaluate_badges( $user_id );
	}

	private function maybe_profile_complete( $user_id ) {
		if ( class_exists( 'LCC_Profiles' ) && LCC_Profiles::completion_percentage( $user_id ) >= 100 ) {
			self::award( $user_id, 'profile_complete', 'user', $user_id );
		}
	}

	public function on_squad_created( $squad_id, $owner_id ) {
		self::award( $owner_id, 'squad_create', 'squad', $squad_id );
	}

	public function on_squad_joined( $squad_id, $user_id ) {
		self::award( $user_id, 'squad_join', 'squad', $squad_id );
	}

	public function on_premium( $user_id ) {
		self::award( $user_id, 'premium', 'premium', 0 );
		self::award_badge( $user_id, 'premium_legend' );
	}

	// ── Rendering ────────────────────────────────────────────────────────────────

	public static function render( $user_id ) {
		$total  = self::total( $user_id );
		$badges = self::get_badges( $user_id );
		ob_start();
		echo '<h3>' . esc_html__( 'Points & Badges', 'legendcreate-community' ) . '</h3>';
		echo '<p class="lcc-points-total"><strong>' . esc_html( number_format_i18n( $total ) ) . '</strong> ' . esc_html__( 'contribution points', 'legendcreate-community' ) . '</p>';
		if ( $badges ) {
			echo '<div class="lcc-badge-grid">';
			foreach ( $badges as $slug ) {
				if ( ! isset( self::BADGES[ $slug ] ) ) { continue; }
				echo '<span class="lcc-badge-chip" title="' . esc_attr( self::BADGES[ $slug ][1] ) . '">' . esc_html( self::BADGES[ $slug ][0] ) . '</span>';
			}
			echo '</div>';
		} else {
			echo '<p class="lcc-muted">' . esc_html__( 'Earn badges by completing your profile, joining squads, and contributing.', 'legendcreate-community' ) . '</p>';
		}
		return ob_get_clean();
	}
}
