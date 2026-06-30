<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Branded member registration + email verification.
 *
 * Creates accounts via our own controlled handler (does NOT require the global
 * users_can_register flag, so WordPress's default register form stays closed).
 * Includes honeypot, rate limiting, password strength, email verification, and
 * auto-login routing new members into onboarding.
 *
 * Shortcodes: [lcc_register], [lcc_login].
 */
final class LCC_Registration {

	const ERRORS = array(
		'fields'     => 'Please fill in all fields.',
		'email'      => 'Please enter a valid email address.',
		'exists'     => 'An account with that email already exists. Try logging in.',
		'username'   => 'That username is taken. Please choose another.',
		'password'   => 'Password must be at least 8 characters.',
		'disposable' => 'Please use a non-disposable email address.',
		'rate'       => 'Too many attempts. Please try again later.',
		'spam'       => 'Registration could not be completed.',
		'terms'      => 'Please accept the community rules to continue.',
		'failed'     => 'Registration failed. Please try again.',
	);

	const DISPOSABLE = array( 'mailinator.com', 'guerrillamail.com', '10minutemail.com', 'tempmail.com', 'trashmail.com', 'yopmail.com', 'getnada.com', 'throwawaymail.com' );

	public function __construct() {
		add_shortcode( 'lcc_register', array( $this, 'register_shortcode' ) );
		add_shortcode( 'lcc_signup', array( $this, 'signup_shortcode' ) );
		add_shortcode( 'lcc_login', array( $this, 'login_shortcode' ) );
		add_action( 'admin_post_nopriv_lcc_register', array( $this, 'handle_register' ) );
		add_action( 'admin_post_lcc_register', array( $this, 'already_logged_in' ) );
		add_action( 'admin_post_nopriv_lcc_verify_email', array( $this, 'handle_verify' ) );
		add_action( 'admin_post_lcc_verify_email', array( $this, 'handle_verify' ) );
	}

	// ── Shortcodes ───────────────────────────────────────────────────────────────

	public function register_shortcode() {
		if ( is_user_logged_in() ) {
			$dash = lcc_dashboard_page();
			return '<div class="lcc-shell"><div class="lcc-panel"><p>' . esc_html__( 'You are already a member.', 'legendcreate-community' )
				. '</p><a class="lcc-btn" href="' . esc_url( $dash ? get_permalink( $dash ) : home_url( '/' ) ) . '">' . esc_html__( 'Go to your dashboard', 'legendcreate-community' ) . '</a></div></div>';
		}

		$err = isset( $_GET['lcc_reg_error'] ) ? sanitize_key( wp_unslash( $_GET['lcc_reg_error'] ) ) : '';
		$old_email = isset( $_GET['lcc_email'] ) ? sanitize_email( wp_unslash( $_GET['lcc_email'] ) ) : '';
		$ref = '';
		if ( ! empty( $_GET['ref'] ) ) { $ref = strtoupper( sanitize_text_field( wp_unslash( $_GET['ref'] ) ) ); }
		elseif ( ! empty( $_COOKIE['lcc_ref'] ) ) { $ref = strtoupper( sanitize_text_field( wp_unslash( $_COOKIE['lcc_ref'] ) ) ); }
		$buy_id = 0;
		if ( ! empty( $_GET['buy'] ) && class_exists( 'LCC_Premium' ) ) {
			$buy_id = LCC_Premium::product_id( 'annual' === sanitize_key( wp_unslash( $_GET['buy'] ) ) ? 'annual' : 'monthly' );
		}
		$plan      = isset( $_GET['plan'] ) ? sanitize_key( wp_unslash( $_GET['plan'] ) ) : '';
		$show_form = false; // The signup form now lives on its own /signup/ page.
		$rules_page = get_page_by_path( 'community-rules', OBJECT, 'page' );

		ob_start(); ?>
		<div class="lcc-shell">
			<div class="lcc-join-head">
				<h2><?php esc_html_e( 'Join LegendCreate', 'legendcreate-community' ); ?></h2>
				<p class="lcc-muted"><?php esc_html_e( 'Bring your squad, keep playing the games you love, and earn community status.', 'legendcreate-community' ); ?></p>
			</div>
			<?php if ( class_exists( 'LCC_Premium' ) ) { echo LCC_Premium::pricing_summary(); } ?>
			<?php if ( $show_form ) : ?>
			<div class="lcc-panel lcc-join-form" id="lcc-join-form">
					<h3><?php esc_html_e( 'Create your free account', 'legendcreate-community' ); ?></h3>
					<?php if ( $err && isset( self::ERRORS[ $err ] ) ) : ?>
						<div class="lcc-notice lcc-notice-err"><?php echo esc_html( self::ERRORS[ $err ] ); ?></div>
					<?php endif; ?>
					<form class="lcc-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'lcc_register', 'lcc_register_nonce' ); ?>
					<input type="hidden" name="action" value="lcc_register">
					<div class="lcc-hp" aria-hidden="true"><label>Leave this empty<input type="text" name="lcc_hp" tabindex="-1" autocomplete="off"></label></div>
					<?php if ( $ref ) : ?><input type="hidden" name="lcc_ref" value="<?php echo esc_attr( $ref ); ?>"><?php endif; ?>
					<?php if ( $buy_id ) : ?><input type="hidden" name="lcc_buy" value="<?php echo esc_attr( $buy_id ); ?>">
						<div class="lcc-notice lcc-notice-ok"><?php esc_html_e( 'Premium selected — create your account and you’ll continue straight to secure checkout.', 'legendcreate-community' ); ?></div><?php endif; ?>

					<label><?php esc_html_e( 'Display name', 'legendcreate-community' ); ?>
						<input type="text" name="lcc_display" required maxlength="50"></label>
					<label><?php esc_html_e( 'Email', 'legendcreate-community' ); ?>
						<input type="email" name="lcc_email" required value="<?php echo esc_attr( $old_email ); ?>"></label>
					<label><?php esc_html_e( 'Password (8+ characters)', 'legendcreate-community' ); ?>
						<input type="password" name="lcc_password" required minlength="8" autocomplete="new-password"></label>

					<label class="lcc-check"><input type="checkbox" name="lcc_terms" value="1" required>
						<?php
						if ( $rules_page ) {
							printf(
								/* translators: %s community rules link */
								wp_kses( __( 'I agree to the <a href="%s" target="_blank">community rules</a>.', 'legendcreate-community' ), array( 'a' => array( 'href' => array(), 'target' => array() ) ) ),
								esc_url( get_permalink( $rules_page ) )
							);
						} else {
							esc_html_e( 'I agree to the community rules.', 'legendcreate-community' );
						}
						?>
					</label>

					<label class="lcc-check"><input type="checkbox" name="lcc_game_alerts" value="1" checked>
						<?php esc_html_e( 'Email me game alerts — new free & highly rated games. You can change this anytime in your profile.', 'legendcreate-community' ); ?>
					</label>

					<button type="submit" class="lcc-btn lcc-btn-lg"><?php echo $buy_id ? esc_html__( 'Create account & continue to payment', 'legendcreate-community' ) : esc_html__( 'Create my free account', 'legendcreate-community' ); ?></button>
				</form>
					<p class="lcc-muted"><?php esc_html_e( 'Already a member?', 'legendcreate-community' ); ?> <a class="lcc-link" href="<?php echo esc_url( wp_login_url( home_url( '/dashboard/' ) ) ); ?>"><?php esc_html_e( 'Log in', 'legendcreate-community' ); ?></a></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/** The signup form on its own page; reads ?buy / ?plan chosen on the Join page. */
	public function signup_shortcode() {
		if ( is_user_logged_in() ) {
			$dash = lcc_dashboard_page();
			return '<div class="lcc-shell"><div class="lcc-panel"><p>' . esc_html__( 'You are already a member.', 'legendcreate-community' )
				. '</p><a class="lcc-btn" href="' . esc_url( $dash ? get_permalink( $dash ) : home_url( '/' ) ) . '">' . esc_html__( 'Go to your dashboard', 'legendcreate-community' ) . '</a></div></div>';
		}

		$err       = isset( $_GET['lcc_reg_error'] ) ? sanitize_key( wp_unslash( $_GET['lcc_reg_error'] ) ) : '';
		$old_email = isset( $_GET['lcc_email'] ) ? sanitize_email( wp_unslash( $_GET['lcc_email'] ) ) : '';
		$ref       = '';
		if ( ! empty( $_GET['ref'] ) ) { $ref = strtoupper( sanitize_text_field( wp_unslash( $_GET['ref'] ) ) ); }
		elseif ( ! empty( $_COOKIE['lcc_ref'] ) ) { $ref = strtoupper( sanitize_text_field( wp_unslash( $_COOKIE['lcc_ref'] ) ) ); }
		$buy_id = 0;
		if ( ! empty( $_GET['buy'] ) && class_exists( 'LCC_Premium' ) ) {
			$buy_id = LCC_Premium::product_id( 'annual' === sanitize_key( wp_unslash( $_GET['buy'] ) ) ? 'annual' : 'monthly' );
		}
		$rules_page = get_page_by_path( 'community-rules', OBJECT, 'page' );
		$plans      = (int) get_option( 'lcc_page_register', 0 );
		$plans_url  = $plans ? get_permalink( $plans ) : home_url( '/join/' );
		$premium_note = __( 'Premium selected — create your account and you will continue straight to secure checkout.', 'legendcreate-community' );

		ob_start(); ?>
		<div class="lcc-shell">
			<div class="lcc-panel lcc-join-form" id="lcc-join-form">
				<h3><?php echo $buy_id ? esc_html__( 'Create your account', 'legendcreate-community' ) : esc_html__( 'Create your free account', 'legendcreate-community' ); ?></h3>
				<?php if ( $err && isset( self::ERRORS[ $err ] ) ) : ?>
					<div class="lcc-notice lcc-notice-err"><?php echo esc_html( self::ERRORS[ $err ] ); ?></div>
				<?php endif; ?>
				<?php if ( $buy_id ) : ?>
					<div class="lcc-notice lcc-notice-ok"><?php echo esc_html( $premium_note ); ?></div>
				<?php endif; ?>
				<form class="lcc-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'lcc_register', 'lcc_register_nonce' ); ?>
					<input type="hidden" name="action" value="lcc_register">
					<div class="lcc-hp" aria-hidden="true"><label>Leave this empty<input type="text" name="lcc_hp" tabindex="-1" autocomplete="off"></label></div>
					<?php if ( $ref ) : ?><input type="hidden" name="lcc_ref" value="<?php echo esc_attr( $ref ); ?>"><?php endif; ?>
					<?php if ( $buy_id ) : ?><input type="hidden" name="lcc_buy" value="<?php echo esc_attr( $buy_id ); ?>"><?php endif; ?>

					<label><?php esc_html_e( 'Display name', 'legendcreate-community' ); ?>
						<input type="text" name="lcc_display" required maxlength="50"></label>
					<label><?php esc_html_e( 'Email', 'legendcreate-community' ); ?>
						<input type="email" name="lcc_email" required value="<?php echo esc_attr( $old_email ); ?>"></label>
					<label><?php esc_html_e( 'Password (8+ characters)', 'legendcreate-community' ); ?>
						<input type="password" name="lcc_password" required minlength="8" autocomplete="new-password"></label>

					<label class="lcc-check"><input type="checkbox" name="lcc_terms" value="1" required>
						<?php
						if ( $rules_page ) {
							printf(
								wp_kses( __( 'I agree to the <a href="%s" target="_blank">community rules</a>.', 'legendcreate-community' ), array( 'a' => array( 'href' => array(), 'target' => array() ) ) ),
								esc_url( get_permalink( $rules_page ) )
							);
						} else {
							esc_html_e( 'I agree to the community rules.', 'legendcreate-community' );
						}
						?>
					</label>

					<label class="lcc-check"><input type="checkbox" name="lcc_game_alerts" value="1" checked>
						<?php esc_html_e( 'Email me game alerts — new free & highly rated games. You can change this anytime in your profile.', 'legendcreate-community' ); ?>
					</label>

					<button type="submit" class="lcc-btn lcc-btn-lg"><?php echo $buy_id ? esc_html__( 'Create account & continue to payment', 'legendcreate-community' ) : esc_html__( 'Create my free account', 'legendcreate-community' ); ?></button>
				</form>
				<p class="lcc-muted"><?php esc_html_e( 'Already a member?', 'legendcreate-community' ); ?> <a class="lcc-link" href="<?php echo esc_url( wp_login_url( home_url( '/dashboard/' ) ) ); ?>"><?php esc_html_e( 'Log in', 'legendcreate-community' ); ?></a>
					&nbsp;·&nbsp; <a class="lcc-link" href="<?php echo esc_url( $plans_url ); ?>">&larr; <?php esc_html_e( 'Back to plans', 'legendcreate-community' ); ?></a></p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	public function login_shortcode() {
		if ( is_user_logged_in() ) {
			$dash = lcc_dashboard_page();
			return '<div class="lcc-shell"><div class="lcc-panel"><a class="lcc-btn" href="' . esc_url( $dash ? get_permalink( $dash ) : home_url( '/' ) ) . '">' . esc_html__( 'Go to your dashboard', 'legendcreate-community' ) . '</a></div></div>';
		}
		$dash = lcc_dashboard_page();
		return '<div class="lcc-shell"><div class="lcc-panel lcc-auth">'
			. wp_login_form( array( 'echo' => false, 'redirect' => $dash ? get_permalink( $dash ) : home_url( '/' ) ) )
			. '</div></div>';
	}

	// ── Handlers ─────────────────────────────────────────────────────────────────

	public function already_logged_in() {
		wp_safe_redirect( home_url( '/dashboard/' ) );
		exit;
	}

	public function handle_register() {
		$back = wp_get_referer() ? wp_get_referer() : home_url( '/join/' );

		if ( ! isset( $_POST['lcc_register_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['lcc_register_nonce'] ), 'lcc_register' ) ) {
			$this->fail( $back, 'spam' );
		}
		// Honeypot: real users leave it empty.
		if ( ! empty( $_POST['lcc_hp'] ) ) { $this->fail( $back, 'spam' ); }
		// Rate limit by IP: 5 attempts / hour.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		if ( ! self::rate_ok( 'reg:' . $ip, 5, HOUR_IN_SECONDS ) ) { $this->fail( $back, 'rate' ); }

		$display  = isset( $_POST['lcc_display'] ) ? sanitize_text_field( wp_unslash( $_POST['lcc_display'] ) ) : '';
		$email    = isset( $_POST['lcc_email'] ) ? sanitize_email( wp_unslash( $_POST['lcc_email'] ) ) : '';
		$password = isset( $_POST['lcc_password'] ) ? (string) wp_unslash( $_POST['lcc_password'] ) : '';
		$terms    = ! empty( $_POST['lcc_terms'] );

		if ( '' === $display || '' === $email || '' === $password ) { $this->fail( $back, 'fields', $email ); }
		if ( ! $terms ) { $this->fail( $back, 'terms', $email ); }
		if ( ! is_email( $email ) ) { $this->fail( $back, 'email', $email ); }
		if ( self::is_disposable( $email ) ) { $this->fail( $back, 'disposable', $email ); }
		if ( strlen( $password ) < 8 ) { $this->fail( $back, 'password', $email ); }
		if ( email_exists( $email ) ) { $this->fail( $back, 'exists', $email ); }

		// Derive a unique username from the email local part.
		$base = sanitize_user( current( explode( '@', $email ) ), true );
		if ( '' === $base ) { $base = 'legend'; }
		$username = $base;
		$i = 1;
		while ( username_exists( $username ) ) { $username = $base . $i; $i++; }

		$user_id = wp_create_user( $username, $password, $email );
		if ( is_wp_error( $user_id ) ) { $this->fail( $back, 'failed', $email ); }

		wp_update_user( array( 'ID' => $user_id, 'display_name' => $display, 'nickname' => $display, 'role' => LCC_Roles::ROLE_MEMBER ) );

		// Email verification token (store a hash; email the plain token).
		$token = wp_generate_password( 24, false );
		update_user_meta( $user_id, 'lcc_verify_hash', wp_hash( $token ) );
		update_user_meta( $user_id, 'lcc_verified', 0 );
		$this->send_verification( $user_id, $token );

		// Record the game-alerts opt-in from signup. We only push it to the alert
		// list once the email is verified (see verify()), to keep double opt-in.
		if ( ! empty( $_POST['lcc_game_alerts'] ) ) {
			$notify = (array) get_user_meta( $user_id, 'lcc_notify', true );
			$notify = array_values( array_unique( array_merge( $notify, array( 'game_alerts' ) ) ) );
			update_user_meta( $user_id, 'lcc_notify', $notify );
		}

		$ref = isset( $_POST['lcc_ref'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['lcc_ref'] ) ) ) : '';
		do_action( 'lcc_member_registered', $user_id, $ref );

		// Auto-login.
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );

		// If they chose a Premium plan on the Join page, go straight to checkout.
		$buy = isset( $_POST['lcc_buy'] ) ? absint( $_POST['lcc_buy'] ) : 0;
		if ( $buy && 'product' === get_post_type( $buy ) && function_exists( 'wc_get_checkout_url' ) ) {
			wp_safe_redirect( add_query_arg( 'add-to-cart', $buy, wc_get_checkout_url() ) );
			exit;
		}

		// Otherwise route into onboarding.
		$onb = lcc_onboarding_page();
		wp_safe_redirect( add_query_arg( 'welcome', '1', $onb ? get_permalink( $onb ) : home_url( '/dashboard/' ) ) );
		exit;
	}

	public function handle_verify() {
		$uid   = isset( $_GET['uid'] ) ? absint( $_GET['uid'] ) : 0;
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$dash  = home_url( '/dashboard/' );

		if ( $uid && $token ) {
			$stored = get_user_meta( $uid, 'lcc_verify_hash', true );
			if ( $stored && hash_equals( $stored, wp_hash( $token ) ) ) {
				update_user_meta( $uid, 'lcc_verified', 1 );
				delete_user_meta( $uid, 'lcc_verify_hash' );
				// Now that the email is verified, honour the game-alerts opt-in.
				if ( method_exists( 'LCC_Profiles', 'sync_game_alerts' ) ) {
					$notify = (array) get_user_meta( $uid, 'lcc_notify', true );
					LCC_Profiles::sync_game_alerts( $uid, in_array( 'game_alerts', $notify, true ) );
				}
				do_action( 'lcc_member_verified', $uid );
				wp_safe_redirect( add_query_arg( 'lcc_verified', '1', $dash ) );
				exit;
			}
		}
		wp_safe_redirect( add_query_arg( 'lcc_verified', '0', $dash ) );
		exit;
	}

	// ── Helpers ──────────────────────────────────────────────────────────────────

	private function send_verification( $user_id, $token ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) { return; }
		$link = add_query_arg(
			array( 'action' => 'lcc_verify_email', 'uid' => $user_id, 'token' => rawurlencode( $token ) ),
			admin_url( 'admin-post.php' )
		);
		$site    = get_bloginfo( 'name' );
		$subject = sprintf( __( 'Confirm your %s account', 'legendcreate-community' ), $site );
		$body    = sprintf(
			/* translators: 1: name, 2: verify link, 3: site */
			__( "Hi %1\$s,\n\nWelcome to %3\$s! Please confirm your email address by clicking the link below:\n\n%2\$s\n\nIf you didn't create this account, you can ignore this message.", 'legendcreate-community' ),
			$user->display_name,
			$link,
			$site
		);
		wp_mail( $user->user_email, $subject, $body );
	}

	private function fail( $back, $code, $email = '' ) {
		$args = array( 'lcc_reg_error' => $code );
		if ( $email ) { $args['lcc_email'] = rawurlencode( $email ); }
		wp_safe_redirect( add_query_arg( $args, $back ) );
		exit;
	}

	private static function rate_ok( $key, $max, $window ) {
		$k = 'lcc_rl_' . md5( $key );
		$n = (int) get_transient( $k );
		if ( $n >= $max ) { return false; }
		set_transient( $k, $n + 1, $window );
		return true;
	}

	public static function is_disposable( $email ) {
		$domain = strtolower( substr( strrchr( $email, '@' ), 1 ) );
		return in_array( $domain, self::DISPOSABLE, true );
	}

	public static function is_verified( $user_id ) {
		return (bool) get_user_meta( (int) $user_id, 'lcc_verified', true );
	}
}
