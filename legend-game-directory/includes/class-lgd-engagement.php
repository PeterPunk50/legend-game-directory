<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Visitor engagement: moderated game submissions and double opt-in game alerts.
 * Submissions are always created as pending candidates that require provider verification;
 * no submitted fact is treated as confirmed and nothing here can auto-publish.
 */
final class LGD_Engagement {
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_shortcode( 'lgd_submit_game', array( $this, 'submit_shortcode' ) );
		add_shortcode( 'lgd_newsletter', array( $this, 'newsletter_shortcode' ) );
		add_shortcode( 'lgd_contact', array( $this, 'contact_shortcode' ) );
	}

	public function routes() {
		register_rest_route( 'lgd/v1', '/contact', array( 'methods' => 'POST', 'callback' => array( $this, 'contact' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( 'lgd/v1', '/submit-game', array( 'methods' => 'POST', 'callback' => array( $this, 'submit_game' ), 'permission_callback' => 'is_user_logged_in' ) );
		register_rest_route( 'lgd/v1', '/alerts/subscribe', array( 'methods' => 'POST', 'callback' => array( $this, 'subscribe' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( 'lgd/v1', '/alerts/confirm', array( 'methods' => 'GET', 'callback' => array( $this, 'confirm' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( 'lgd/v1', '/alerts/unsubscribe', array( 'methods' => 'GET', 'callback' => array( $this, 'unsubscribe' ), 'permission_callback' => '__return_true' ) );
	}

	private function identity() {
		return is_user_logged_in() ? 'user:' . get_current_user_id() : 'ip:' . sanitize_text_field( isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '' );
	}

	/* ----------------------------------------------------------------- Contact */

	public function contact( WP_REST_Request $request ) {
		if ( ! empty( $request['website'] ) ) { return new WP_Error( 'lgd_contact_spam', __( 'Message rejected.', 'legend-game-directory' ), array( 'status' => 400 ) ); }
		if ( ! LGD_Security::rate_limit( 'contact:' . $this->identity(), 10, DAY_IN_SECONDS ) ) { return new WP_Error( 'lgd_contact_rate', __( 'Too many messages. Please try again later.', 'legend-game-directory' ), array( 'status' => 429 ) ); }

		$name    = sanitize_text_field( $request['name'] );
		$email   = sanitize_email( $request['email'] );
		$subject = sanitize_text_field( $request['subject'] );
		$message = sanitize_textarea_field( $request['message'] );

		if ( '' === $name || ! is_email( $email ) || strlen( $message ) < 10 ) {
			return new WP_Error( 'lgd_contact_invalid', __( 'Please enter your name, a valid email, and a message (at least 10 characters).', 'legend-game-directory' ), array( 'status' => 400 ) );
		}

		// Contact recipient is configurable (lgd_contact_email), falling back to the
		// WP admin address. Set it to a mailbox you actually monitor.
		$to      = sanitize_email( get_option( 'lgd_contact_email', get_option( 'admin_email' ) ) );
		if ( ! is_email( $to ) ) { $to = sanitize_email( get_option( 'admin_email' ) ); }
		$site    = get_bloginfo( 'name' );
		$subj    = '[' . $site . ' Contact] ' . ( $subject ? $subject : __( 'New message', 'legend-game-directory' ) );
		$body    = sprintf( "Name: %s\nEmail: %s\nSubject: %s\n\n%s", $name, $email, $subject, $message );
		$headers = array( 'Reply-To: ' . $name . ' <' . $email . '>' );

		$sent = wp_mail( $to, $subj, $body, $headers );
		LGD_Logger::log( 'contact_message', 'Contact form message received.', array( 'from' => $email, 'subject' => $subject, 'delivered' => $sent ), 'info' );

		if ( ! $sent ) {
			return new WP_Error( 'lgd_contact_failed', __( 'Sorry, the message could not be sent right now. Please email us directly.', 'legend-game-directory' ), array( 'status' => 500 ) );
		}
		return rest_ensure_response( array( 'sent' => true, 'message' => __( 'Thanks — your message has been sent. We aim to reply within 48 hours.', 'legend-game-directory' ) ) );
	}

	public function contact_shortcode() {
		ob_start(); ?>
		<form class="lgd-contact-form lgd-ajax-form" data-endpoint="<?php echo esc_url( rest_url( 'lgd/v1/contact' ) ); ?>">
			<input type="text" name="website" class="lgd-honeypot" tabindex="-1" autocomplete="off">
			<label><?php esc_html_e( 'Your name', 'legend-game-directory' ); ?><input name="name" required maxlength="120"></label>
			<label><?php esc_html_e( 'Your email', 'legend-game-directory' ); ?><input name="email" type="email" required></label>
			<label><?php esc_html_e( 'Subject', 'legend-game-directory' ); ?><input name="subject" maxlength="160"></label>
			<label><?php esc_html_e( 'Message', 'legend-game-directory' ); ?><textarea name="message" required minlength="10" maxlength="4000" rows="6"></textarea></label>
			<button type="submit"><?php esc_html_e( 'Send message', 'legend-game-directory' ); ?></button>
			<p class="lgd-form-status" aria-live="polite"></p>
		</form><?php return ob_get_clean();
	}

	/* ----------------------------------------------------------------- Submissions */

	public function submit_game( WP_REST_Request $request ) {
		if ( ! empty( $request['website'] ) ) { return new WP_Error( 'lgd_submit_spam', __( 'Submission rejected.', 'legend-game-directory' ), array( 'status' => 400 ) ); }
		if ( ! LGD_Security::rate_limit( 'submit-game:' . $this->identity(), 5, DAY_IN_SECONDS ) ) { return new WP_Error( 'lgd_submit_rate', __( 'Submission limit reached. Please try again tomorrow.', 'legend-game-directory' ), array( 'status' => 429 ) ); }

		$title = sanitize_text_field( $request['title'] );
		$url    = esc_url_raw( $request['source_url'] );
		$category = sanitize_key( $request['category'] );
		if ( '' === $title || '' === $url ) { return new WP_Error( 'lgd_submit_invalid', __( 'A game title and an official store or developer link are required.', 'legend-game-directory' ), array( 'status' => 400 ) ); }
		if ( ! in_array( $category, array( 'free', 'indie', 'mobile' ), true ) ) { return new WP_Error( 'lgd_submit_category', __( 'Choose whether this is a free, indie, or mobile game.', 'legend-game-directory' ), array( 'status' => 400 ) ); }

		// Only accept links on approved store/developer domains; the URL is validated, not fetched here.
		$valid = LGD_Security::validate_public_url( $url );
		if ( is_wp_error( $valid ) ) { return new WP_Error( 'lgd_submit_domain', __( 'Submit a link on an approved store or official developer domain so the team can verify it.', 'legend-game-directory' ), array( 'status' => 400 ) ); }

		$candidate = array(
			'title' => $title, 'source_url' => $valid,
			'description' => sanitize_textarea_field( $request['notes'] ),
			'is_free' => 'free' === $category, 'free_type' => 'free' === $category ? 'Free to Play' : '',
			'is_indie' => 'indie' === $category, 'indie_confidence' => 'indie' === $category ? 50 : 0,
			'is_mobile' => 'mobile' === $category, 'confidence' => 15,
		);
		$normalized = LGD_Provider_Registry::get( 'manual' )->normalize_game( $candidate );
		if ( is_wp_error( $normalized ) ) { return new WP_Error( 'lgd_submit_failed', $normalized->get_error_message(), array( 'status' => 400 ) ); }
		$game_id = LGD_Importer::upsert( 'manual', $normalized );
		if ( is_wp_error( $game_id ) ) { return new WP_Error( 'lgd_submit_failed', $game_id->get_error_message(), array( 'status' => 400 ) ); }

		update_post_meta( $game_id, '_lgd_verification_status', 'unverified_candidate' );
		$flags = array_unique( array_merge( (array) get_post_meta( $game_id, '_lgd_mandatory_review_flags', true ), array( 'user_submission', 'requires_provider_verification' ) ) );
		update_post_meta( $game_id, '_lgd_mandatory_review_flags', $flags );
		LGD_Logger::log( 'game_submitted', 'Visitor submitted a game candidate for verification.', array( 'category' => $category, 'source_url' => $valid, 'submitter' => sanitize_email( $request['email'] ) ), 'info', 'game', $game_id );
		return rest_ensure_response( array( 'submitted' => true, 'message' => __( 'Thank you. Your submission is queued for source verification before it can appear.', 'legend-game-directory' ) ) );
	}

	public function submit_shortcode() {
		if ( ! is_user_logged_in() ) {
			return '<p class="lgd-missing">' . sprintf(
				wp_kses( __( 'Please <a href="%s">log in or join</a> to submit a game.', 'legend-game-directory' ), array( 'a' => array( 'href' => array() ) ) ),
				esc_url( wp_login_url( get_permalink() ) )
			) . '</p>';
		}
		ob_start(); ?>
		<form class="lgd-submit-form lgd-ajax-form" data-endpoint="<?php echo esc_url( rest_url( 'lgd/v1/submit-game' ) ); ?>">
			<input type="text" name="website" class="lgd-honeypot" tabindex="-1" autocomplete="off">
			<label><?php esc_html_e( 'Game title', 'legend-game-directory' ); ?><input name="title" required maxlength="200"></label>
			<label><?php esc_html_e( 'Official store or developer link', 'legend-game-directory' ); ?><input name="source_url" type="url" required placeholder="https://"></label>
			<label><?php esc_html_e( 'Category', 'legend-game-directory' ); ?><select name="category" required><option value=""><?php esc_html_e( 'Choose one', 'legend-game-directory' ); ?></option><option value="free"><?php esc_html_e( 'Free game', 'legend-game-directory' ); ?></option><option value="indie"><?php esc_html_e( 'Indie game', 'legend-game-directory' ); ?></option><option value="mobile"><?php esc_html_e( 'Mobile game', 'legend-game-directory' ); ?></option></select></label>
			<label><?php esc_html_e( 'Why should we list it? (optional)', 'legend-game-directory' ); ?><textarea name="notes" maxlength="2000"></textarea></label>
			<label><?php esc_html_e( 'Your email (optional)', 'legend-game-directory' ); ?><input name="email" type="email"></label>
			<button type="submit"><?php esc_html_e( 'Submit a game', 'legend-game-directory' ); ?></button>
			<p class="lgd-form-status" aria-live="polite"></p>
		</form><?php return ob_get_clean();
	}

	/* ----------------------------------------------------------------- Alerts / newsletter */

	public function subscribe( WP_REST_Request $request ) {
		global $wpdb;
		if ( ! empty( $request['website'] ) ) { return new WP_Error( 'lgd_alert_spam', __( 'Subscription rejected.', 'legend-game-directory' ), array( 'status' => 400 ) ); }
		if ( ! LGD_Security::rate_limit( 'alert-subscribe:' . $this->identity(), 5, DAY_IN_SECONDS ) ) { return new WP_Error( 'lgd_alert_rate', __( 'Too many requests. Please try again later.', 'legend-game-directory' ), array( 'status' => 429 ) ); }
		$email = sanitize_email( $request['email'] );
		if ( ! is_email( $email ) ) { return new WP_Error( 'lgd_alert_email', __( 'Enter a valid email address.', 'legend-game-directory' ), array( 'status' => 400 ) ); }

		$prefs = array_values( array_intersect( array( 'free', 'highly_rated', 'temporary_free' ), array_map( 'sanitize_key', (array) $request['preferences'] ) ) );
		if ( empty( $prefs ) ) { $prefs = array( 'free', 'highly_rated' ); }
		$token = wp_generate_password( 32, false, false );
		$table = LGD_Database::table( 'subscribers' );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$table} WHERE email=%s", $email ), ARRAY_A );
		$now = current_time( 'mysql', true );
		if ( $existing && 'confirmed' === $existing['status'] ) {
			$wpdb->update( $table, array( 'preferences' => implode( ',', $prefs ) ), array( 'id' => $existing['id'] ), array( '%s' ), array( '%d' ) );
			return rest_ensure_response( array( 'subscribed' => true, 'message' => __( 'You are already subscribed. Your alert preferences were updated.', 'legend-game-directory' ) ) );
		}
		$row = array( 'email' => $email, 'token_hash' => wp_hash_password( $token ), 'status' => 'pending', 'preferences' => implode( ',', $prefs ), 'created_at' => $now );
		if ( $existing ) { $wpdb->update( $table, $row, array( 'id' => $existing['id'] ), array( '%s', '%s', '%s', '%s', '%s' ), array( '%d' ) ); $id = (int) $existing['id']; }
		else { $wpdb->insert( $table, $row, array( '%s', '%s', '%s', '%s', '%s' ) ); $id = (int) $wpdb->insert_id; }

		$confirm = add_query_arg( array( 'id' => $id, 'token' => rawurlencode( $token ) ), rest_url( 'lgd/v1/alerts/confirm' ) );
		wp_mail( $email, __( 'Confirm your game alerts', 'legend-game-directory' ), sprintf( __( "Confirm your subscription to free and highly rated game alerts within 48 hours:\n%s", 'legend-game-directory' ), $confirm ) );
		LGD_Logger::log( 'alert_subscribe', 'Alert subscription requested.', array( 'preferences' => $prefs ), 'info' );
		return rest_ensure_response( array( 'subscribed' => true, 'message' => __( 'Almost done — check your inbox to confirm your game alerts.', 'legend-game-directory' ) ) );
	}

	public function confirm( WP_REST_Request $request ) {
		global $wpdb;
		$id = absint( $request['id'] ); $table = LGD_Database::table( 'subscribers' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $id ), ARRAY_A );
		if ( ! $row || 'unsubscribed' === $row['status'] || ! wp_check_password( (string) $request['token'], $row['token_hash'] ) ) {
			return self::render_page( __( 'Link invalid', 'legend-game-directory' ), __( 'This confirmation link is invalid or has expired.', 'legend-game-directory' ) );
		}
		if ( strtotime( $row['created_at'] . ' UTC' ) < time() - 2 * DAY_IN_SECONDS ) {
			return self::render_page( __( 'Link expired', 'legend-game-directory' ), __( 'This confirmation link has expired. Please subscribe again.', 'legend-game-directory' ) );
		}
		$wpdb->update( $table, array( 'status' => 'confirmed', 'confirmed_at' => current_time( 'mysql', true ) ), array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) );
		LGD_Logger::log( 'alert_confirmed', 'Alert subscription confirmed.', array(), 'info' );
		return self::render_page( __( 'Game alerts confirmed', 'legend-game-directory' ), __( "You're all set — you'll get alerts for new free and highly rated games.", 'legend-game-directory' ) );
	}

	public function unsubscribe( WP_REST_Request $request ) {
		global $wpdb;
		$id = absint( $request['id'] ); $table = LGD_Database::table( 'subscribers' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $id ), ARRAY_A );
		if ( ! $row || ! wp_check_password( (string) $request['token'], $row['token_hash'] ) ) {
			return self::render_page( __( 'Link invalid', 'legend-game-directory' ), __( 'This link is invalid.', 'legend-game-directory' ) );
		}
		$wpdb->update( $table, array( 'status' => 'unsubscribed' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
		return self::render_page( __( 'Unsubscribed', 'legend-game-directory' ), __( 'You have been unsubscribed from game alerts.', 'legend-game-directory' ) );
	}

	/**
	 * Render a small branded HTML page for email-link landings (confirm/unsubscribe)
	 * instead of returning raw JSON to the browser. Outputs and exits.
	 */
	private static function render_page( $title, $message ) {
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: text/html; charset=utf-8' );
			nocache_headers();
		}
		$home = home_url( '/' );
		$name = get_bloginfo( 'name' );
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . esc_html( $title ) . '</title>';
		echo '<style>body{margin:0;font-family:Inter,system-ui,-apple-system,Segoe UI,sans-serif;background:#07111f;color:#f6f8fc;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px}.box{max-width:480px;width:100%;text-align:center;background:#101c2d;border:1px solid rgba(255,255,255,.12);border-radius:16px;padding:40px 32px}.box h1{font-size:1.5rem;margin:0 0 12px}.box p{color:#a9b7ca;margin:0 0 24px;line-height:1.5}.box a{display:inline-block;background:linear-gradient(135deg,#31d7ff,#a875ff);color:#07111f;font-weight:800;text-decoration:none;padding:13px 22px;border-radius:10px}</style></head><body>';
		echo '<div class="box"><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $message ) . '</p><a href="' . esc_url( $home ) . '">' . esc_html( sprintf( __( 'Back to %s', 'legend-game-directory' ), $name ) ) . '</a></div></body></html>';
		exit;
	}

	public function newsletter_shortcode() {
		ob_start(); ?>
		<form class="lgd-newsletter-form lgd-ajax-form" data-endpoint="<?php echo esc_url( rest_url( 'lgd/v1/alerts/subscribe' ) ); ?>">
			<input type="text" name="website" class="lgd-honeypot" tabindex="-1" autocomplete="off">
			<label><?php esc_html_e( 'Email for game alerts', 'legend-game-directory' ); ?><input name="email" type="email" required placeholder="you@example.com"></label>
			<fieldset class="lgd-alert-prefs"><legend><?php esc_html_e( 'Alert me about', 'legend-game-directory' ); ?></legend>
				<label class="lgd-inline"><input type="checkbox" name="preferences[]" value="free" checked> <?php esc_html_e( 'Newly free games', 'legend-game-directory' ); ?></label>
				<label class="lgd-inline"><input type="checkbox" name="preferences[]" value="highly_rated" checked> <?php esc_html_e( 'Highly rated games', 'legend-game-directory' ); ?></label>
				<label class="lgd-inline"><input type="checkbox" name="preferences[]" value="temporary_free"> <?php esc_html_e( 'Temporary free offers', 'legend-game-directory' ); ?></label>
			</fieldset>
			<button type="submit"><?php esc_html_e( 'Sign up', 'legend-game-directory' ); ?></button>
			<p class="lgd-form-status" aria-live="polite"></p>
		</form><?php return ob_get_clean();
	}
}
