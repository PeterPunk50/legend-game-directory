<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LGD_Reviews {
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_shortcode( 'lgd_review_form', array( $this, 'form_shortcode' ) );
	}

	public function routes() {
		register_rest_route( 'lgd/v1', '/reviews', array( 'methods' => 'POST', 'callback' => array( $this, 'submit' ), 'permission_callback' => array( $this, 'can_review' ) ) );
		register_rest_route( 'lgd/v1', '/reviews/(?P<id>\d+)/report', array( 'methods' => 'POST', 'callback' => array( $this, 'report' ), 'permission_callback' => function() { return is_user_logged_in(); } ) );
		register_rest_route( 'lgd/v1', '/email-verification/request', array( 'methods' => 'POST', 'callback' => array( $this, 'request_verification' ), 'permission_callback' => function() { return is_user_logged_in(); } ) );
		register_rest_route( 'lgd/v1', '/email-verification/confirm', array( 'methods' => 'GET', 'callback' => array( $this, 'confirm_verification' ), 'permission_callback' => '__return_true' ) );
	}

	public function can_review() {
		if ( ! is_user_logged_in() ) { return new WP_Error( 'lgd_login_required', __( 'Sign in to review a game.', 'legend-game-directory' ), array( 'status' => 401 ) ); }
		$settings = LGD_Security::settings();
		if ( ! empty( $settings['review_require_verified'] ) && ! current_user_can( 'manage_options' ) && ! get_user_meta( get_current_user_id(), '_lgd_email_verified', true ) ) { return new WP_Error( 'lgd_email_unverified', __( 'Verify your email before reviewing.', 'legend-game-directory' ), array( 'status' => 403 ) ); }
		return true;
	}

	public function submit( WP_REST_Request $request ) {
		global $wpdb;
		$user_id = get_current_user_id(); $game_id = absint( $request['game_id'] ); $rating = (float) $request['rating'];
		$text = sanitize_textarea_field( $request['review_text'] );
		if ( ! $game_id || 'game' !== get_post_type( $game_id ) || $rating < 0 || $rating > 10 ) { return new WP_Error( 'lgd_review_invalid', __( 'Choose a valid game and a rating from 0 to 10.', 'legend-game-directory' ), array( 'status' => 400 ) ); }
		if ( ! empty( $request['website'] ) ) { return new WP_Error( 'lgd_review_spam', __( 'Review rejected.', 'legend-game-directory' ), array( 'status' => 400 ) ); }
		if ( ! LGD_Security::rate_limit( 'review:' . $user_id, 5, HOUR_IN_SECONDS ) ) { return new WP_Error( 'lgd_review_rate', __( 'Please wait before submitting another review.', 'legend-game-directory' ), array( 'status' => 429 ) ); }
		$flags = $this->moderation_flags( $text, $game_id, $user_id );
		$settings = LGD_Security::settings();
		$status = empty( $flags ) && ! empty( $settings['review_auto_approve'] ) ? 'approved' : 'pending';
		$table = LGD_Database::table( 'reviews' );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE game_id=%d AND user_id=%d", $game_id, $user_id ), ARRAY_A );
		$now = current_time( 'mysql', true );
		if ( $existing ) {
			$wpdb->insert( LGD_Database::table( 'review_history' ), array( 'review_id' => $existing['id'], 'user_id' => $user_id, 'old_rating' => $existing['rating'], 'old_text' => $existing['review_text'], 'changed_at' => $now ), array( '%d', '%d', '%f', '%s', '%s' ) );
			$wpdb->update( $table, array( 'rating' => $rating, 'review_text' => $text, 'status' => $status, 'moderation_flags' => wp_json_encode( $flags ), 'updated_at' => $now ), array( 'id' => $existing['id'] ), array( '%f', '%s', '%s', '%s', '%s' ), array( '%d' ) );
			$review_id = (int) $existing['id'];
		} else {
			$wpdb->insert( $table, array( 'game_id' => $game_id, 'user_id' => $user_id, 'rating' => $rating, 'review_text' => $text, 'status' => $status, 'moderation_flags' => wp_json_encode( $flags ), 'created_at' => $now, 'updated_at' => $now ), array( '%d', '%d', '%f', '%s', '%s', '%s', '%s', '%s' ) );
			$review_id = (int) $wpdb->insert_id;
		}
		self::recalculate( $game_id );
		LGD_Logger::log( 'review_saved', 'Local community review saved.', array( 'review_id' => $review_id, 'status' => $status, 'flags' => $flags ), 'info', 'game', $game_id );
		return rest_ensure_response( array( 'id' => $review_id, 'status' => $status, 'message' => 'approved' === $status ? __( 'Review published.', 'legend-game-directory' ) : __( 'Review submitted for moderation.', 'legend-game-directory' ) ) );
	}

	private function moderation_flags( $text, $game_id, $user_id ) {
		global $wpdb; $flags = array();
		if ( substr_count( strtolower( $text ), 'http://' ) + substr_count( strtolower( $text ), 'https://' ) > 2 ) { $flags[] = 'excessive_links'; }
		if ( preg_match( '/(.)\1{9,}/u', $text ) ) { $flags[] = 'repeated_characters'; }
		if ( $text ) {
			$duplicate = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LGD_Database::table( 'reviews' ) . ' WHERE review_text=%s AND user_id<>%d LIMIT 1', $text, $user_id ) );
			if ( $duplicate ) { $flags[] = 'duplicate_text'; }
		}
		$comment = array( 'comment_type' => 'lgd_game_review', 'comment_post_ID' => $game_id, 'comment_content' => $text, 'comment_author' => wp_get_current_user()->display_name, 'comment_author_email' => wp_get_current_user()->user_email );
		if ( apply_filters( 'preprocess_comment', $comment ) !== $comment ) { $flags[] = 'spam_filter'; }
		return array_values( array_unique( apply_filters( 'lgd_ai_moderation_flags', $flags, $text, $game_id, $user_id ) ) );
	}

	public function report( WP_REST_Request $request ) {
		global $wpdb; $review_id = absint( $request['id'] );
		if ( ! LGD_Security::rate_limit( 'review-report:' . get_current_user_id(), 10, DAY_IN_SECONDS ) ) { return new WP_Error( 'lgd_report_rate', __( 'Report limit reached.', 'legend-game-directory' ), array( 'status' => 429 ) ); }
		$review = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LGD_Database::table( 'reviews' ) . ' WHERE id=%d', $review_id ), ARRAY_A );
		if ( ! $review ) { return new WP_Error( 'lgd_review_missing', __( 'Review not found.', 'legend-game-directory' ), array( 'status' => 404 ) ); }
		$wpdb->update( LGD_Database::table( 'reviews' ), array( 'status' => 'reported' ), array( 'id' => $review_id ), array( '%s' ), array( '%d' ) );
		LGD_Logger::log( 'review_reported', sanitize_textarea_field( $request['reason'] ), array( 'review_id' => $review_id ), 'warning', 'game', $review['game_id'] );
		return rest_ensure_response( array( 'reported' => true ) );
	}

	public function request_verification() {
		$user = wp_get_current_user(); $token = wp_generate_password( 32, false, false );
		update_user_meta( $user->ID, '_lgd_email_verification_hash', wp_hash_password( $token ) );
		update_user_meta( $user->ID, '_lgd_email_verification_expires', time() + DAY_IN_SECONDS );
		$url = add_query_arg( array( 'user_id' => $user->ID, 'token' => rawurlencode( $token ) ), rest_url( 'lgd/v1/email-verification/confirm' ) );
		wp_mail( $user->user_email, __( 'Verify your game-review email', 'legend-game-directory' ), sprintf( __( "Open this link within 24 hours:\n%s", 'legend-game-directory' ), $url ) );
		return rest_ensure_response( array( 'sent' => true ) );
	}

	public function confirm_verification( WP_REST_Request $request ) {
		$user_id = absint( $request['user_id'] ); $hash = get_user_meta( $user_id, '_lgd_email_verification_hash', true );
		if ( ! $hash || time() > (int) get_user_meta( $user_id, '_lgd_email_verification_expires', true ) || ! wp_check_password( (string) $request['token'], $hash ) ) { return new WP_Error( 'lgd_verification_invalid', __( 'Verification link is invalid or expired.', 'legend-game-directory' ), array( 'status' => 400 ) ); }
		update_user_meta( $user_id, '_lgd_email_verified', current_time( 'mysql', true ) ); delete_user_meta( $user_id, '_lgd_email_verification_hash' ); delete_user_meta( $user_id, '_lgd_email_verification_expires' );
		return rest_ensure_response( array( 'verified' => true ) );
	}

	public static function recalculate( $game_id ) {
		global $wpdb; $row = $wpdb->get_row( $wpdb->prepare( 'SELECT AVG(rating) average_rating, COUNT(*) review_count FROM ' . LGD_Database::table( 'reviews' ) . " WHERE game_id=%d AND status='approved'", $game_id ), ARRAY_A );
		$score = $row && $row['review_count'] ? round( (float) $row['average_rating'] * 10, 1 ) : null;
		update_post_meta( $game_id, '_lgd_community_score', $score ); update_post_meta( $game_id, '_lgd_community_review_count', $row ? absint( $row['review_count'] ) : 0 );
	}

	public function form_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'game_id' => get_the_ID() ), $atts ); $game_id = absint( $atts['game_id'] );
		if ( ! is_user_logged_in() ) { return '<p>' . esc_html__( 'Sign in to leave a community review.', 'legend-game-directory' ) . '</p>'; }
		ob_start(); ?>
		<form class="lgd-review-form" data-endpoint="<?php echo esc_url( rest_url( 'lgd/v1/reviews' ) ); ?>">
			<input type="hidden" name="game_id" value="<?php echo esc_attr( $game_id ); ?>"><input type="text" name="website" class="lgd-honeypot" tabindex="-1" autocomplete="off">
			<label><?php esc_html_e( 'Rating (0–10)', 'legend-game-directory' ); ?><input name="rating" type="number" min="0" max="10" step="0.5" required></label>
			<label><?php esc_html_e( 'Review (optional)', 'legend-game-directory' ); ?><textarea name="review_text" maxlength="4000"></textarea></label>
			<button type="submit"><?php esc_html_e( 'Submit review', 'legend-game-directory' ); ?></button><p class="lgd-form-status" aria-live="polite"></p>
		</form><?php return ob_get_clean();
	}
}
