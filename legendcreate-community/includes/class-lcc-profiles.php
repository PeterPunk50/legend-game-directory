<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Member profiles.
 *
 * Stores gaming profile data as user meta. Gaming IDs (Steam, Xbox, PSN, Discord, etc.)
 * are PRIVATE BY DEFAULT and only exposed publicly when the member explicitly opts in
 * per field. public_profile() returns only consented + always-public fields.
 */
final class LCC_Profiles {

	const PLATFORMS = array( 'PC', 'PlayStation', 'Xbox', 'Nintendo Switch', 'iOS', 'Android' );

	const INTERESTS = array(
		'guides'  => 'Guides',
		'testing' => 'Game Testing',
		'reviews' => 'Reviews',
		'squads'  => 'Squads',
		'free'    => 'Free Games',
		'indie'   => 'Indie Games',
		'mobile'  => 'Mobile Games',
	);

	// Gaming IDs: private by default, opt-in per field for public display.
	const GAMING_IDS = array(
		'steam'      => 'Steam Profile',
		'xbox'       => 'Xbox Gamertag',
		'psn'        => 'PlayStation ID',
		'nintendo'   => 'Nintendo Friend Code',
		'discord'    => 'Discord Username',
		'fortnite'   => 'Fortnite Display Name',
		'activision' => 'Activision ID',
	);

	const NOTIFY = array(
		'guide_updates' => 'New & updated guides for my games',
		'squad'         => 'Squad invitations & activity',
		'testing'       => 'Testing opportunities',
		'digest'        => 'Weekly community digest',
	);

	public function __construct() {
		// WP admin user-edit screen fields.
		add_action( 'show_user_profile', array( $this, 'admin_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'admin_fields' ) );
		add_action( 'personal_options_update', array( $this, 'admin_save' ) );
		add_action( 'edit_user_profile_update', array( $this, 'admin_save' ) );
		// Front-end profile save.
		add_action( 'admin_post_lcc_save_profile', array( $this, 'handle_save' ) );
	}

	// ── Read ─────────────────────────────────────────────────────────────────────

	public static function get_profile( $user_id ) {
		$user_id = (int) $user_id;
		$gids    = array();
		foreach ( array_keys( self::GAMING_IDS ) as $key ) {
			$gids[ $key ] = (string) get_user_meta( $user_id, 'lcc_gid_' . $key, true );
		}
		return array(
			'bio'           => (string) get_user_meta( $user_id, 'lcc_bio', true ),
			'fav_games'     => (array) get_user_meta( $user_id, 'lcc_fav_games', true ),
			'platforms'     => (array) get_user_meta( $user_id, 'lcc_platforms', true ),
			'interests'     => (array) get_user_meta( $user_id, 'lcc_interests', true ),
			'gaming_ids'    => $gids,
			'gid_public'    => (array) get_user_meta( $user_id, 'lcc_gid_public', true ),
			'notify'        => (array) get_user_meta( $user_id, 'lcc_notify', true ),
			'public'        => (bool) get_user_meta( $user_id, 'lcc_public_profile', true ),
			'onboarded'     => (bool) get_user_meta( $user_id, 'lcc_onboarded', true ),
		);
	}

	/** Only fields safe for public display (gaming IDs filtered by per-field consent). */
	public static function public_profile( $user_id ) {
		$user_id = (int) $user_id;
		$p       = self::get_profile( $user_id );
		$user    = get_userdata( $user_id );
		if ( ! $user || ! $p['public'] ) { return null; }

		$public_gids = array();
		foreach ( $p['gid_public'] as $key ) {
			if ( ! empty( $p['gaming_ids'][ $key ] ) ) {
				$public_gids[ $key ] = $p['gaming_ids'][ $key ];
			}
		}
		return array(
			'display_name' => $user->display_name,
			'avatar'       => get_avatar_url( $user_id, array( 'size' => 128 ) ),
			'bio'          => $p['bio'],
			'fav_games'    => $p['fav_games'],
			'platforms'    => $p['platforms'],
			'interests'    => array_intersect_key( self::INTERESTS, array_flip( $p['interests'] ) ),
			'gaming_ids'   => $public_gids,
			'joined'       => $user->user_registered,
			'is_premium'   => LCC_Memberships::is_premium( $user_id ),
		);
	}

	public static function is_onboarded( $user_id ) {
		return (bool) get_user_meta( (int) $user_id, 'lcc_onboarded', true );
	}

	public static function gaming_id_is_public( $user_id, $key ) {
		$pub = (array) get_user_meta( (int) $user_id, 'lcc_gid_public', true );
		return in_array( $key, $pub, true );
	}

	/** Rough completeness 0-100 to drive the dashboard progress bar. */
	public static function completion_percentage( $user_id ) {
		$p     = self::get_profile( $user_id );
		$score = 0;
		if ( get_avatar_url( $user_id ) ) { $score += 15; }
		if ( '' !== trim( $p['bio'] ) )    { $score += 15; }
		if ( ! empty( $p['fav_games'] ) )  { $score += 25; }
		if ( ! empty( $p['platforms'] ) )  { $score += 20; }
		if ( ! empty( $p['interests'] ) )  { $score += 15; }
		if ( array_filter( $p['gaming_ids'] ) ) { $score += 10; }
		return min( 100, $score );
	}

	// ── Write ────────────────────────────────────────────────────────────────────

	/** Sanitize + persist a profile data array for a user. Only writes provided keys. */
	public static function save_profile( $user_id, $data ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) { return; }

		if ( isset( $data['bio'] ) ) {
			update_user_meta( $user_id, 'lcc_bio', sanitize_textarea_field( $data['bio'] ) );
		}
		if ( isset( $data['fav_games'] ) ) {
			$games = is_array( $data['fav_games'] ) ? $data['fav_games'] : preg_split( '/[,\n]+/', (string) $data['fav_games'] );
			$games = array_slice( array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'trim', $games ) ) ) ), 0, 20 );
			update_user_meta( $user_id, 'lcc_fav_games', $games );
		}
		if ( isset( $data['platforms'] ) ) {
			$plat = array_values( array_intersect( self::PLATFORMS, (array) $data['platforms'] ) );
			update_user_meta( $user_id, 'lcc_platforms', $plat );
		}
		if ( isset( $data['interests'] ) ) {
			$int = array_values( array_intersect( array_keys( self::INTERESTS ), (array) $data['interests'] ) );
			update_user_meta( $user_id, 'lcc_interests', $int );
		}
		if ( isset( $data['gaming_ids'] ) && is_array( $data['gaming_ids'] ) ) {
			foreach ( self::GAMING_IDS as $key => $label ) {
				if ( isset( $data['gaming_ids'][ $key ] ) ) {
					update_user_meta( $user_id, 'lcc_gid_' . $key, sanitize_text_field( $data['gaming_ids'][ $key ] ) );
				}
			}
		}
		if ( isset( $data['gid_public'] ) ) {
			$pub = array_values( array_intersect( array_keys( self::GAMING_IDS ), (array) $data['gid_public'] ) );
			update_user_meta( $user_id, 'lcc_gid_public', $pub );
		}
		if ( isset( $data['notify'] ) ) {
			$notify = array_values( array_intersect( array_keys( self::NOTIFY ), (array) $data['notify'] ) );
			update_user_meta( $user_id, 'lcc_notify', $notify );
		}
		if ( isset( $data['public'] ) ) {
			update_user_meta( $user_id, 'lcc_public_profile', $data['public'] ? 1 : 0 );
		}

		do_action( 'lcc_profile_saved', $user_id );
	}

	// ── Front-end save handler ───────────────────────────────────────────────────

	public function handle_save() {
		if ( ! is_user_logged_in()
			|| ! isset( $_POST['lcc_profile_nonce'] )
			|| ! wp_verify_nonce( wp_unslash( $_POST['lcc_profile_nonce'] ), 'lcc_save_profile' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'legendcreate-community' ) );
		}
		$user_id = get_current_user_id();
		self::save_profile( $user_id, self::collect_post() );

		$redirect = wp_get_referer() ? wp_get_referer() : home_url( '/' );
		wp_safe_redirect( add_query_arg( 'lcc_saved', '1', $redirect ) );
		exit;
	}

	/** Pull a normalized profile array from $_POST (used by both profile + onboarding forms). */
	public static function collect_post() {
		$gids = array();
		if ( isset( $_POST['lcc_gid'] ) && is_array( $_POST['lcc_gid'] ) ) {
			foreach ( wp_unslash( $_POST['lcc_gid'] ) as $k => $v ) { $gids[ sanitize_key( $k ) ] = $v; }
		}
		return array(
			'bio'        => isset( $_POST['lcc_bio'] ) ? wp_unslash( $_POST['lcc_bio'] ) : null,
			'fav_games'  => isset( $_POST['lcc_fav_games'] ) ? wp_unslash( $_POST['lcc_fav_games'] ) : null,
			'platforms'  => isset( $_POST['lcc_platforms'] ) ? (array) wp_unslash( $_POST['lcc_platforms'] ) : null,
			'interests'  => isset( $_POST['lcc_interests'] ) ? (array) wp_unslash( $_POST['lcc_interests'] ) : null,
			'gaming_ids' => $gids ? $gids : null,
			'gid_public' => isset( $_POST['lcc_gid_public'] ) ? (array) wp_unslash( $_POST['lcc_gid_public'] ) : null,
			'notify'     => isset( $_POST['lcc_notify'] ) ? (array) wp_unslash( $_POST['lcc_notify'] ) : null,
			'public'     => ! empty( $_POST['lcc_public_profile'] ),
		);
	}

	// ── WP admin user-edit screen ────────────────────────────────────────────────

	public function admin_fields( $user ) {
		$p = self::get_profile( $user->ID );
		echo '<h2>' . esc_html__( 'LegendCreate Community Profile', 'legendcreate-community' ) . '</h2>';
		echo '<table class="form-table"><tr><th>' . esc_html__( 'Gaming IDs (private unless marked public)', 'legendcreate-community' ) . '</th><td>';
		wp_nonce_field( 'lcc_admin_profile_' . $user->ID, 'lcc_admin_profile_nonce' );
		foreach ( self::GAMING_IDS as $key => $label ) {
			$val = esc_attr( $p['gaming_ids'][ $key ] );
			$pub = self::gaming_id_is_public( $user->ID, $key ) ? 'checked' : '';
			echo '<p><label style="display:inline-block;min-width:180px">' . esc_html( $label ) . '</label> ';
			echo '<input type="text" name="lcc_gid[' . esc_attr( $key ) . ']" value="' . $val . '" class="regular-text"> ';
			echo '<label><input type="checkbox" name="lcc_gid_public[]" value="' . esc_attr( $key ) . '" ' . esc_attr( $pub ) . '> ' . esc_html__( 'public', 'legendcreate-community' ) . '</label></p>';
		}
		echo '</td></tr></table>';
	}

	public function admin_save( $user_id ) {
		if ( ! isset( $_POST['lcc_admin_profile_nonce'] )
			|| ! wp_verify_nonce( wp_unslash( $_POST['lcc_admin_profile_nonce'] ), 'lcc_admin_profile_' . $user_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_user', $user_id ) ) { return; }
		$gids = array();
		if ( isset( $_POST['lcc_gid'] ) && is_array( $_POST['lcc_gid'] ) ) {
			foreach ( wp_unslash( $_POST['lcc_gid'] ) as $k => $v ) { $gids[ sanitize_key( $k ) ] = $v; }
		}
		self::save_profile( $user_id, array(
			'gaming_ids' => $gids,
			'gid_public' => isset( $_POST['lcc_gid_public'] ) ? (array) wp_unslash( $_POST['lcc_gid_public'] ) : array(),
		) );
	}
}
