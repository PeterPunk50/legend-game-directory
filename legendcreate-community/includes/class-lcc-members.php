<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Member-facing UI: dashboard, onboarding, and profile edit form.
 * Shortcodes: [lcc_dashboard], [lcc_onboarding].
 */
final class LCC_Members {

	const MARQUEE_GAMES = array( 'Fortnite', 'Call of Duty', 'Counter-Strike', 'Apex Legends' );

	public function __construct() {
		add_shortcode( 'lcc_dashboard', array( $this, 'dashboard' ) );
		add_shortcode( 'lcc_onboarding', array( $this, 'onboarding' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_lcc_complete_onboarding', array( $this, 'handle_onboarding' ) );
		add_action( 'admin_post_nopriv_lcc_complete_onboarding', array( $this, 'handle_onboarding' ) );
	}

	public function assets() {
		if ( ! is_singular() ) { return; }
		$post = get_post();
		if ( ! $post ) { return; }
		foreach ( array( 'lcc_dashboard', 'lcc_onboarding', 'lcc_register', 'lcc_signup', 'lcc_login' ) as $sc ) {
			if ( has_shortcode( $post->post_content, $sc ) ) {
				wp_enqueue_style( 'lcc-community', LCC_URL . 'assets/css/community.css', array(), LCC_VERSION );
				return;
			}
		}
	}

	private function login_gate() {
		$url = wp_login_url( get_permalink() );
		return '<div class="lcc-panel lcc-gate"><p>' . esc_html__( 'Please log in to access your member area.', 'legendcreate-community' )
			. '</p><a class="lcc-btn" href="' . esc_url( $url ) . '">' . esc_html__( 'Log in', 'legendcreate-community' ) . '</a></div>';
	}

	private static function saved_notice() {
		if ( empty( $_GET['lcc_saved'] ) ) { return ''; }
		return '<div class="lcc-notice lcc-notice-ok">' . esc_html__( 'Your changes were saved.', 'legendcreate-community' ) . '</div>';
	}

	// ── Dashboard ────────────────────────────────────────────────────────────────

	public function dashboard() {
		if ( ! is_user_logged_in() ) { return $this->login_gate(); }
		$uid     = get_current_user_id();
		$user    = wp_get_current_user();
		$p       = LCC_Profiles::get_profile( $uid );
		$pct     = LCC_Profiles::completion_percentage( $uid );
		$premium = LCC_Memberships::is_premium( $uid );
		$until   = LCC_Memberships::premium_until( $uid );
		$onb     = lcc_onboarding_page();

		ob_start();
		echo '<div class="lcc-shell">';
		echo self::saved_notice();

		if ( isset( $_GET['lcc_verified'] ) ) {
			echo '1' === $_GET['lcc_verified']
				? '<div class="lcc-notice lcc-notice-ok">' . esc_html__( 'Email confirmed — thank you!', 'legendcreate-community' ) . '</div>'
				: '<div class="lcc-notice lcc-notice-err">' . esc_html__( 'That verification link was invalid or expired.', 'legendcreate-community' ) . '</div>';
		}
		if ( ! LCC_Registration::is_verified( $uid ) ) {
			echo '<div class="lcc-notice lcc-notice-warn">' . esc_html__( 'Please check your email and confirm your account.', 'legendcreate-community' ) . '</div>';
		}

		// Header + membership badge.
		echo '<div class="lcc-dash-head">';
		echo '<div><h2>' . esc_html( sprintf( __( 'Welcome, %s', 'legendcreate-community' ), $user->display_name ) ) . '</h2>';
		if ( $premium ) {
			$exp = $until ? date_i18n( get_option( 'date_format' ), strtotime( $until . ' UTC' ) ) : '';
			echo '<span class="lcc-badge lcc-badge-premium">' . esc_html__( 'Legend Premium', 'legendcreate-community' ) . '</span>';
			if ( $exp ) { echo ' <span class="lcc-muted">' . esc_html( sprintf( __( 'until %s', 'legendcreate-community' ), $exp ) ) . '</span>'; }
		} else {
			echo '<span class="lcc-badge lcc-badge-free">' . esc_html__( 'Legend Member', 'legendcreate-community' ) . '</span> ';
			echo '<a class="lcc-link" href="' . esc_url( home_url( '/premium/' ) ) . '">' . esc_html__( 'Upgrade to Premium', 'legendcreate-community' ) . '</a>';
		}
		echo '</div></div>';

		// Onboarding CTA.
		if ( ! $p['onboarded'] && $onb ) {
			echo '<div class="lcc-panel lcc-cta"><strong>' . esc_html__( 'Finish setting up your profile', 'legendcreate-community' ) . '</strong>'
				. '<p>' . esc_html__( 'Pick your games, platforms and interests to get relevant guides and squad matches.', 'legendcreate-community' ) . '</p>'
				. '<a class="lcc-btn" href="' . esc_url( get_permalink( $onb ) ) . '">' . esc_html__( 'Start onboarding', 'legendcreate-community' ) . '</a></div>';
		}

		// Profile completion.
		echo '<div class="lcc-panel"><div class="lcc-prog-label">' . esc_html( sprintf( __( 'Profile %d%% complete', 'legendcreate-community' ), $pct ) ) . '</div>';
		echo '<div class="lcc-prog"><i style="width:' . esc_attr( $pct ) . '%"></i></div></div>';

		// Grid: summary + stubs.
		echo '<div class="lcc-grid">';
		echo '<div class="lcc-panel"><h3>' . esc_html__( 'My Games', 'legendcreate-community' ) . '</h3>';
		echo $p['fav_games'] ? '<p>' . esc_html( implode( ', ', $p['fav_games'] ) ) . '</p>' : '<p class="lcc-muted">' . esc_html__( 'None selected yet.', 'legendcreate-community' ) . '</p>';
		echo '<h3>' . esc_html__( 'Platforms', 'legendcreate-community' ) . '</h3>';
		echo $p['platforms'] ? '<p>' . esc_html( implode( ', ', $p['platforms'] ) ) . '</p>' : '<p class="lcc-muted">—</p>';
		echo '</div>';

		echo '<div class="lcc-panel">';
		if ( class_exists( 'LCC_Squads' ) ) {
			echo LCC_Squads::render_my_squads( $uid );
		}
		if ( class_exists( 'LCC_Reputation' ) ) {
			echo '<div style="margin-top:18px">' . LCC_Reputation::render( $uid ) . '</div>';
		}
		echo '</div>';
		echo '</div>';

		// Referrals.
		if ( class_exists( 'LCC_Referrals' ) ) {
			echo '<div class="lcc-panel">' . LCC_Referrals::render( $uid ) . '</div>';
		}

		// Edit profile form.
		echo '<div class="lcc-panel"><h3>' . esc_html__( 'Edit Profile', 'legendcreate-community' ) . '</h3>';
		echo $this->profile_form( $uid, $p );
		echo '</div>';

		echo '<p class="lcc-muted"><a href="' . esc_url( admin_url( 'profile.php' ) ) . '">' . esc_html__( 'Account & password settings', 'legendcreate-community' ) . '</a> &middot; <a href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">' . esc_html__( 'Log out', 'legendcreate-community' ) . '</a></p>';
		echo '</div>';
		return ob_get_clean();
	}

	private function profile_form( $uid, $p ) {
		ob_start(); ?>
		<form class="lcc-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'lcc_save_profile', 'lcc_profile_nonce' ); ?>
			<input type="hidden" name="action" value="lcc_save_profile">

			<label><?php esc_html_e( 'Bio', 'legendcreate-community' ); ?>
				<textarea name="lcc_bio" rows="3"><?php echo esc_textarea( $p['bio'] ); ?></textarea></label>

			<label><?php esc_html_e( 'Favourite games (comma separated)', 'legendcreate-community' ); ?>
				<input type="text" name="lcc_fav_games" value="<?php echo esc_attr( implode( ', ', $p['fav_games'] ) ); ?>"></label>

			<fieldset><legend><?php esc_html_e( 'Platforms', 'legendcreate-community' ); ?></legend>
				<?php foreach ( LCC_Profiles::PLATFORMS as $plat ) : ?>
					<label class="lcc-check"><input type="checkbox" name="lcc_platforms[]" value="<?php echo esc_attr( $plat ); ?>" <?php checked( in_array( $plat, $p['platforms'], true ) ); ?>> <?php echo esc_html( $plat ); ?></label>
				<?php endforeach; ?>
			</fieldset>

			<fieldset><legend><?php esc_html_e( 'Gaming IDs', 'legendcreate-community' ); ?> <span class="lcc-muted">(<?php esc_html_e( 'private unless you tick “public”', 'legendcreate-community' ); ?>)</span></legend>
				<?php foreach ( LCC_Profiles::GAMING_IDS as $key => $label ) : ?>
					<div class="lcc-gid-row">
						<label><?php echo esc_html( $label ); ?>
							<input type="text" name="lcc_gid[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $p['gaming_ids'][ $key ] ); ?>"></label>
						<label class="lcc-check lcc-pub"><input type="checkbox" name="lcc_gid_public[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $p['gid_public'], true ) ); ?>> <?php esc_html_e( 'public', 'legendcreate-community' ); ?></label>
					</div>
				<?php endforeach; ?>
			</fieldset>

			<fieldset><legend><?php esc_html_e( 'Email notifications', 'legendcreate-community' ); ?></legend>
				<?php foreach ( LCC_Profiles::NOTIFY as $key => $label ) : ?>
					<label class="lcc-check"><input type="checkbox" name="lcc_notify[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $p['notify'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
				<?php endforeach; ?>
			</fieldset>

			<label class="lcc-check"><input type="checkbox" name="lcc_public_profile" value="1" <?php checked( $p['public'] ); ?>> <?php esc_html_e( 'Make my profile public', 'legendcreate-community' ); ?></label>

			<button type="submit" class="lcc-btn"><?php esc_html_e( 'Save profile', 'legendcreate-community' ); ?></button>
		</form>
		<?php
		return ob_get_clean();
	}

	// ── Onboarding ───────────────────────────────────────────────────────────────

	public function onboarding() {
		if ( ! is_user_logged_in() ) { return $this->login_gate(); }
		$uid = get_current_user_id();
		$p   = LCC_Profiles::get_profile( $uid );
		$dash = lcc_dashboard_page();

		if ( $p['onboarded'] ) {
			$link = $dash ? get_permalink( $dash ) : home_url( '/' );
			return '<div class="lcc-panel"><p>' . esc_html__( 'You have completed onboarding.', 'legendcreate-community' )
				. '</p><a class="lcc-btn" href="' . esc_url( $link ) . '">' . esc_html__( 'Go to your dashboard', 'legendcreate-community' ) . '</a></div>';
		}

		$fav = $p['fav_games'];
		ob_start(); ?>
		<div class="lcc-shell">
		<form class="lcc-form lcc-onboarding" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'lcc_onboarding', 'lcc_onboarding_nonce' ); ?>
			<input type="hidden" name="action" value="lcc_complete_onboarding">

			<div class="lcc-step"><span class="lcc-step-n">1</span>
				<h3><?php esc_html_e( 'Choose your games', 'legendcreate-community' ); ?></h3>
				<?php foreach ( self::MARQUEE_GAMES as $game ) : ?>
					<label class="lcc-check"><input type="checkbox" name="lcc_fav_games_pick[]" value="<?php echo esc_attr( $game ); ?>" <?php checked( in_array( $game, $fav, true ) ); ?>> <?php echo esc_html( $game ); ?></label>
				<?php endforeach; ?>
				<label><?php esc_html_e( 'Other games (comma separated)', 'legendcreate-community' ); ?>
					<input type="text" name="lcc_fav_games_other" placeholder="e.g. Minecraft, Stardew Valley"></label>
			</div>

			<div class="lcc-step"><span class="lcc-step-n">2</span>
				<h3><?php esc_html_e( 'Choose your platforms', 'legendcreate-community' ); ?></h3>
				<?php foreach ( LCC_Profiles::PLATFORMS as $plat ) : ?>
					<label class="lcc-check"><input type="checkbox" name="lcc_platforms[]" value="<?php echo esc_attr( $plat ); ?>" <?php checked( in_array( $plat, $p['platforms'], true ) ); ?>> <?php echo esc_html( $plat ); ?></label>
				<?php endforeach; ?>
			</div>

			<div class="lcc-step"><span class="lcc-step-n">3</span>
				<h3><?php esc_html_e( 'What are you here for?', 'legendcreate-community' ); ?></h3>
				<?php foreach ( LCC_Profiles::INTERESTS as $key => $label ) : ?>
					<label class="lcc-check"><input type="checkbox" name="lcc_interests[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $p['interests'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
				<?php endforeach; ?>
			</div>

			<div class="lcc-step"><span class="lcc-step-n">4</span>
				<h3><?php esc_html_e( 'Email preferences', 'legendcreate-community' ); ?></h3>
				<?php foreach ( LCC_Profiles::NOTIFY as $key => $label ) : ?>
					<label class="lcc-check"><input type="checkbox" name="lcc_notify[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) $p['notify'], true ) || 'guide_updates' === $key ); ?>> <?php echo esc_html( $label ); ?></label>
				<?php endforeach; ?>
			</div>

			<button type="submit" class="lcc-btn lcc-btn-lg"><?php esc_html_e( 'Finish & go to dashboard', 'legendcreate-community' ); ?></button>
			<p class="lcc-muted"><?php esc_html_e( 'Squad setup and your first community mission unlock right after this.', 'legendcreate-community' ); ?></p>
		</form>
		</div>
		<?php
		return ob_get_clean();
	}

	public function handle_onboarding() {
		if ( ! is_user_logged_in()
			|| ! isset( $_POST['lcc_onboarding_nonce'] )
			|| ! wp_verify_nonce( wp_unslash( $_POST['lcc_onboarding_nonce'] ), 'lcc_onboarding' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'legendcreate-community' ) );
		}
		$uid = get_current_user_id();

		// Merge marquee picks + free-text other games.
		$games = isset( $_POST['lcc_fav_games_pick'] ) ? (array) wp_unslash( $_POST['lcc_fav_games_pick'] ) : array();
		if ( ! empty( $_POST['lcc_fav_games_other'] ) ) {
			$games = array_merge( $games, preg_split( '/[,\n]+/', wp_unslash( $_POST['lcc_fav_games_other'] ) ) );
		}

		LCC_Profiles::save_profile( $uid, array(
			'fav_games' => $games,
			'platforms' => isset( $_POST['lcc_platforms'] ) ? (array) wp_unslash( $_POST['lcc_platforms'] ) : array(),
			'interests' => isset( $_POST['lcc_interests'] ) ? (array) wp_unslash( $_POST['lcc_interests'] ) : array(),
			'notify'    => isset( $_POST['lcc_notify'] ) ? (array) wp_unslash( $_POST['lcc_notify'] ) : array(),
		) );
		update_user_meta( $uid, 'lcc_onboarded', 1 );
		do_action( 'lcc_onboarding_completed', $uid );

		$dash = lcc_dashboard_page();
		wp_safe_redirect( $dash ? get_permalink( $dash ) : home_url( '/' ) );
		exit;
	}
}

/** Helper accessors for the auto-created page IDs. */
function lcc_dashboard_page() { return (int) get_option( 'lcc_page_dashboard', 0 ); }
function lcc_onboarding_page() { return (int) get_option( 'lcc_page_onboarding', 0 ); }
