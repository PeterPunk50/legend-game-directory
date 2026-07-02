<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Community Token system.
 *
 * Tokens are EARNED automatically at point milestones (1 token per 1,000 pts)
 * or AWARDED directly by an admin for beta contributions, quality reports, etc.
 *
 * Spending tokens requires a manual REQUEST that an admin approves.
 * Redemption is NEVER automated — no auto-Premium.
 *
 * Default redemption value: 1 token = 30 days Premium.
 *
 * Shortcode: [lcc_token_wallet]
 * Admin:     Users → Token Requests
 */
final class LCC_Tokens {

	const POINTS_PER_TOKEN = 1000;
	const DAYS_PER_TOKEN   = 30;
	const META_BALANCE     = 'lcc_tokens';
	const META_MILESTONE   = 'lcc_token_milestone'; // highest awarded milestone in points

	public function __construct() {
		add_action( 'lcc_points_awarded',       array( $this, 'maybe_award_on_milestone' ), 10, 3 );
		add_shortcode( 'lcc_token_wallet',      array( $this, 'wallet_shortcode' ) );
		add_action( 'admin_menu',               array( $this, 'register_admin_menu' ) );
		add_action( 'admin_post_lcc_token_request',     array( $this, 'handle_request' ) );
		add_action( 'admin_post_lcc_token_review',      array( $this, 'handle_review' ) );
		add_action( 'admin_post_lcc_token_award_admin', array( $this, 'handle_admin_award' ) );
	}

	// ── DB table ─────────────────────────────────────────────────────────────────

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lcc_token_ledger';
	}

	// ── Balance ──────────────────────────────────────────────────────────────────

	public static function balance( $user_id ) {
		return max( 0, (int) get_user_meta( (int) $user_id, self::META_BALANCE, true ) );
	}

	private static function adjust( $user_id, $delta ) {
		$new = max( 0, self::balance( $user_id ) + (int) $delta );
		update_user_meta( (int) $user_id, self::META_BALANCE, $new );
	}

	// ── Earning (automated at point milestones) ──────────────────────────────────

	public function maybe_award_on_milestone( $user_id, $action, $points ) {
		if ( ! class_exists( 'LCC_Reputation' ) ) { return; }
		$total        = LCC_Reputation::total( (int) $user_id );
		$prev_ms      = (int) get_user_meta( (int) $user_id, self::META_MILESTONE, true );
		$new_ms       = (int) floor( $total / self::POINTS_PER_TOKEN ) * self::POINTS_PER_TOKEN;
		if ( $new_ms < self::POINTS_PER_TOKEN || $new_ms <= $prev_ms ) { return; }
		$tokens = (int) ( ( $new_ms - max( $prev_ms, 0 ) ) / self::POINTS_PER_TOKEN );
		if ( $tokens < 1 ) { return; }
		update_user_meta( (int) $user_id, self::META_MILESTONE, $new_ms );
		self::log_entry( $user_id, $tokens, 'earn',
			sprintf( 'Reached %s contribution points', number_format_i18n( $new_ms ) ) );
		self::adjust( $user_id, $tokens );
		do_action( 'lcc_token_earned', $user_id, $tokens );
		$this->notify_earned( $user_id, $tokens );
	}

	// ── Admin direct award ───────────────────────────────────────────────────────

	public static function award( $user_id, $amount, $note = '', $admin_id = 0 ) {
		$amount = max( 1, (int) $amount );
		self::log_entry( $user_id, $amount, 'award', $note, 'approved', $admin_id );
		self::adjust( $user_id, $amount );
		do_action( 'lcc_token_awarded', $user_id, $amount, $note );
	}

	public function handle_admin_award() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden.' ); }
		check_admin_referer( 'lcc_token_award_admin', 'lcc_token_award_nonce' );
		$uid    = (int) ( $_POST['award_user_id'] ?? 0 );
		$amount = max( 1, (int) ( $_POST['award_amount'] ?? 1 ) );
		$note   = isset( $_POST['award_note'] ) ? sanitize_text_field( wp_unslash( $_POST['award_note'] ) ) : '';
		$back   = admin_url( 'users.php?page=lcc-token-requests' );
		if ( $uid < 1 || ! get_userdata( $uid ) ) {
			wp_safe_redirect( add_query_arg( 'lcc_notice', 'bad_user', $back ) );
			exit;
		}
		self::award( $uid, $amount, $note, get_current_user_id() );
		// Notify member.
		$user = get_userdata( $uid );
		$site = get_bloginfo( 'name' );
		wp_mail(
			$user->user_email,
			sprintf( '[%s] You received %d Legend Token%s!', $site, $amount, $amount > 1 ? 's' : '' ),
			sprintf(
				"Hi %s,\n\nThe %s team has awarded you %d Legend Token%s!%s\n\nTokens can be redeemed for Premium membership days (1 token = 30 days). Submit a redemption request from your dashboard.\n\nDashboard: %s\n\nThe %s Team",
				$user->display_name, $site, $amount, $amount > 1 ? 's' : '',
				$note ? "\n\nReason: {$note}" : '',
				home_url( '/dashboard/' ), $site
			),
			array( 'Content-Type: text/plain; charset=UTF-8', 'From: ' . $site . ' <admin@legendcreate.com>' )
		);
		wp_safe_redirect( add_query_arg( 'lcc_notice', 'awarded', $back ) );
		exit;
	}

	// ── Redemption request (member submits) ──────────────────────────────────────

	public function handle_request() {
		if ( ! is_user_logged_in() ) { wp_safe_redirect( wp_login_url() ); exit; }
		check_admin_referer( 'lcc_token_request', 'lcc_token_req_nonce' );
		$user_id = get_current_user_id();
		$amount  = max( 1, (int) ( $_POST['lcc_tokens'] ?? 1 ) );
		$note    = isset( $_POST['lcc_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['lcc_note'] ) ) : '';
		$back    = home_url( '/dashboard/' );

		if ( $amount > self::balance( $user_id ) ) {
			wp_safe_redirect( add_query_arg( 'lcc_token_err', 'balance', $back ) );
			exit;
		}
		if ( self::has_pending( $user_id ) ) {
			wp_safe_redirect( add_query_arg( 'lcc_token_err', 'pending', $back ) );
			exit;
		}

		// Log the request (negative amount = intended spend, NOT yet deducted from balance).
		self::log_entry( $user_id, -$amount, 'request', $note, 'pending' );
		$this->notify_admin_request( $user_id, $amount, $note );

		wp_safe_redirect( add_query_arg( 'lcc_token_ok', '1', $back ) );
		exit;
	}

	// ── Admin review (approve / deny) ────────────────────────────────────────────

	public function handle_review() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden.' ); }
		check_admin_referer( 'lcc_token_review', 'lcc_token_review_nonce' );
		global $wpdb;
		$request_id = (int) ( $_POST['request_id'] ?? 0 );
		$action     = sanitize_key( $_POST['lcc_action'] ?? '' );
		$reason     = isset( $_POST['lcc_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['lcc_reason'] ) ) : '';
		$back       = admin_url( 'users.php?page=lcc-token-requests' );

		if ( ! in_array( $action, array( 'approve', 'deny' ), true ) || $request_id < 1 ) {
			wp_safe_redirect( $back );
			exit;
		}

		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE id=%d AND status=%s',
			$request_id, 'pending'
		) );
		if ( ! $row ) {
			wp_safe_redirect( add_query_arg( 'lcc_notice', 'not_found', $back ) );
			exit;
		}

		$user_id   = (int) $row->user_id;
		$tokens    = abs( (int) $row->amount );
		$new_status = 'approve' === $action ? 'approved' : 'denied';

		$wpdb->update(
			self::table(),
			array(
				'status'      => $new_status,
				'note'        => $reason ?: $row->note,
				'reviewed_by' => get_current_user_id(),
				'reviewed_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $request_id ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);

		if ( 'approve' === $action ) {
			self::adjust( $user_id, -$tokens ); // deduct from balance now
			$days = $tokens * self::DAYS_PER_TOKEN;
			if ( class_exists( 'LCC_Memberships' ) ) {
				LCC_Memberships::grant( $user_id, $days );
			}
			$this->notify_user_approved( $user_id, $tokens, $days );
		} else {
			$this->notify_user_denied( $user_id, $tokens, $reason );
		}

		wp_safe_redirect( add_query_arg( 'lcc_notice', $new_status, $back ) );
		exit;
	}

	// ── Admin menu & page ────────────────────────────────────────────────────────

	public function register_admin_menu() {
		add_submenu_page(
			'users.php',
			'Token Requests',
			'Token Requests',
			'manage_options',
			'lcc-token-requests',
			array( $this, 'admin_page' )
		);
	}

	public function admin_page() {
		global $wpdb;
		$tbl    = self::table();
		$notice = isset( $_GET['lcc_notice'] ) ? sanitize_key( $_GET['lcc_notice'] ) : '';

		$pending = $wpdb->get_results(
			"SELECT l.*, u.display_name, u.user_email
			 FROM {$tbl} l
			 JOIN {$wpdb->users} u ON u.ID = l.user_id
			 WHERE l.status = 'pending'
			 ORDER BY l.created_at ASC"
		);
		$recent = $wpdb->get_results(
			"SELECT l.*, u.display_name
			 FROM {$tbl} l
			 JOIN {$wpdb->users} u ON u.ID = l.user_id
			 WHERE l.status IN ('approved','denied')
			 ORDER BY l.reviewed_at DESC LIMIT 20"
		);

		echo '<div class="wrap"><h1>Legend Community — Token Requests</h1>';

		$notices = array(
			'approved'  => array( 'success', 'Request approved — Premium granted and member notified.' ),
			'denied'    => array( 'warning', 'Request denied — member notified.' ),
			'awarded'   => array( 'success', 'Tokens awarded and member notified.' ),
			'not_found' => array( 'error',   'Request not found or already reviewed.' ),
			'bad_user'  => array( 'error',   'User not found.' ),
		);
		if ( $notice && isset( $notices[ $notice ] ) ) {
			list( $type, $msg ) = $notices[ $notice ];
			echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}

		// ── Pending requests ──
		echo '<h2>Pending Requests (' . count( $pending ) . ')</h2>';
		if ( $pending ) {
			echo '<table class="widefat striped"><thead><tr>'
				. '<th>User</th><th>Tokens</th><th>= Days</th><th>Note</th><th>Requested</th><th>Balance</th><th>Actions</th>'
				. '</tr></thead><tbody>';
			foreach ( $pending as $row ) {
				$tokens = abs( (int) $row->amount );
				$bal    = self::balance( (int) $row->user_id );
				echo '<tr>'
					. '<td>' . esc_html( $row->display_name ) . '<br><small style="color:#999">' . esc_html( $row->user_email ) . '</small></td>'
					. '<td>' . esc_html( $tokens ) . '</td>'
					. '<td>' . esc_html( $tokens * self::DAYS_PER_TOKEN ) . ' days</td>'
					. '<td>' . esc_html( $row->note ) . '</td>'
					. '<td>' . esc_html( mysql2date( 'j M Y', $row->created_at ) ) . '</td>'
					. '<td>' . esc_html( $bal ) . ' tokens</td>'
					. '<td>';
				// Approve form.
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;margin-right:6px">';
				wp_nonce_field( 'lcc_token_review', 'lcc_token_review_nonce' );
				echo '<input type="hidden" name="action" value="lcc_token_review">'
					. '<input type="hidden" name="request_id" value="' . esc_attr( $row->id ) . '">'
					. '<input type="hidden" name="lcc_action" value="approve">'
					. '<button type="submit" class="button button-primary">Approve</button></form>';
				// Deny form.
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
				wp_nonce_field( 'lcc_token_review', 'lcc_token_review_nonce' );
				echo '<input type="hidden" name="action" value="lcc_token_review">'
					. '<input type="hidden" name="request_id" value="' . esc_attr( $row->id ) . '">'
					. '<input type="hidden" name="lcc_action" value="deny">'
					. '<input type="text" name="lcc_reason" placeholder="Reason (optional)" style="width:150px;margin-right:4px">'
					. '<button type="submit" class="button">Deny</button></form>';
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="description">No pending requests.</p>';
		}

		// ── Recently reviewed ──
		echo '<h2 style="margin-top:2em">Recently Reviewed</h2>';
		if ( $recent ) {
			echo '<table class="widefat striped"><thead><tr>'
				. '<th>User</th><th>Tokens</th><th>= Days</th><th>Status</th><th>Note</th><th>Reviewed</th>'
				. '</tr></thead><tbody>';
			foreach ( $recent as $row ) {
				$tokens = abs( (int) $row->amount );
				$colour = 'approved' === $row->status ? 'green' : 'red';
				echo '<tr>'
					. '<td>' . esc_html( $row->display_name ) . '</td>'
					. '<td>' . esc_html( $tokens ) . '</td>'
					. '<td>' . esc_html( $tokens * self::DAYS_PER_TOKEN ) . ' days</td>'
					. '<td><span style="color:' . $colour . '">' . esc_html( ucfirst( $row->status ) ) . '</span></td>'
					. '<td>' . esc_html( $row->note ) . '</td>'
					. '<td>' . esc_html( $row->reviewed_at ? mysql2date( 'j M Y', $row->reviewed_at ) : '—' ) . '</td>'
					. '</tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="description">No reviewed requests yet.</p>';
		}

		// ── Admin direct award ──
		echo '<h2 style="margin-top:2em">Award Tokens Directly</h2>';
		echo '<p class="description">Award tokens to a specific member for beta feedback, quality testing, etc. The member is notified by email.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'lcc_token_award_admin', 'lcc_token_award_nonce' );
		echo '<input type="hidden" name="action" value="lcc_token_award_admin">';
		echo '<table class="form-table"><tbody>'
			. '<tr><th><label for="award_user_id">User</label></th><td>';
		wp_dropdown_users( array( 'name' => 'award_user_id', 'id' => 'award_user_id', 'show_option_none' => '— Select member —', 'selected' => 0 ) );
		echo '</td></tr>'
			. '<tr><th><label for="award_amount">Tokens</label></th><td>'
			. '<input type="number" id="award_amount" name="award_amount" min="1" max="50" value="1" class="small-text">'
			. '</td></tr>'
			. '<tr><th><label for="award_note">Note</label></th><td>'
			. '<input type="text" id="award_note" name="award_note" placeholder="e.g. Beta quality report — week 1" class="regular-text">'
			. '</td></tr></tbody></table>';
		echo '<p class="submit"><button type="submit" class="button button-primary">Award Tokens</button></p>';
		echo '</form></div>';
	}

	// ── Front-end wallet shortcode ───────────────────────────────────────────────

	public function wallet_shortcode() {
		if ( ! is_user_logged_in() ) {
			return '<div class="lcc-shell"><div class="lcc-panel"><p>'
				. esc_html__( 'Please log in to view your token wallet.', 'legendcreate-community' )
				. '</p></div></div>';
		}

		$user_id = get_current_user_id();
		$balance = self::balance( $user_id );
		$pending = self::has_pending( $user_id );
		$err     = isset( $_GET['lcc_token_err'] ) ? sanitize_key( wp_unslash( $_GET['lcc_token_err'] ) ) : '';
		$ok      = ! empty( $_GET['lcc_token_ok'] );
		$history = self::recent_ledger( $user_id, 8 );

		ob_start(); ?>
		<div class="lcc-shell lcc-tokens">
			<div class="lcc-panel">
				<h3><?php esc_html_e( 'Legend Tokens', 'legendcreate-community' ); ?></h3>

				<p class="lcc-token-balance">
					<strong><?php echo esc_html( $balance ); ?></strong>
					<?php echo esc_html( _n( 'token', 'tokens', $balance, 'legendcreate-community' ) ); ?>
				</p>

				<p class="lcc-muted"><?php esc_html_e(
					'Earn 1 token for every 1,000 contribution points, or receive tokens from the team for beta contributions. '
					. '1 token = 30 days Premium. To use your tokens, submit a redemption request — our team reviews and grants Premium manually.',
					'legendcreate-community'
				); ?></p>

				<?php if ( $ok ) : ?>
					<div class="lcc-notice lcc-notice-ok"><?php esc_html_e( 'Request submitted! We\'ll review it and notify you by email.', 'legendcreate-community' ); ?></div>
				<?php elseif ( $err === 'balance' ) : ?>
					<div class="lcc-notice lcc-notice-err"><?php esc_html_e( 'You don\'t have enough tokens for that request.', 'legendcreate-community' ); ?></div>
				<?php elseif ( $err === 'pending' ) : ?>
					<div class="lcc-notice lcc-notice-err"><?php esc_html_e( 'You already have a pending request. Please wait for it to be reviewed before submitting another.', 'legendcreate-community' ); ?></div>
				<?php endif; ?>

				<?php if ( $pending ) : ?>
					<div class="lcc-notice"><?php esc_html_e( 'You have a redemption request under review. We\'ll email you once it\'s decided.', 'legendcreate-community' ); ?></div>

				<?php elseif ( $balance > 0 ) : ?>
					<div class="lcc-token-request">
						<h4><?php esc_html_e( 'Redeem Tokens for Premium', 'legendcreate-community' ); ?></h4>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'lcc_token_request', 'lcc_token_req_nonce' ); ?>
							<input type="hidden" name="action" value="lcc_token_request">
							<label><?php esc_html_e( 'Tokens to redeem', 'legendcreate-community' ); ?>
								<input type="number" name="lcc_tokens" min="1" max="<?php echo esc_attr( $balance ); ?>" value="1" class="lcc-token-qty">
							</label>
							<p class="lcc-muted"><?php esc_html_e( '1 token = 30 days Premium. Max: your available balance.', 'legendcreate-community' ); ?></p>
							<label><?php esc_html_e( 'Message to team (optional)', 'legendcreate-community' ); ?>
								<textarea name="lcc_note" rows="2" maxlength="300" class="lcc-token-note"
									placeholder="<?php esc_attr_e( 'e.g. Active since beta launch, submitted 3 bug reports', 'legendcreate-community' ); ?>"></textarea>
							</label>
							<button type="submit" class="lcc-btn"><?php esc_html_e( 'Submit Redemption Request', 'legendcreate-community' ); ?></button>
						</form>
					</div>

				<?php else :
					$pts    = class_exists( 'LCC_Reputation' ) ? LCC_Reputation::total( $user_id ) : 0;
					$needed = max( 0, self::POINTS_PER_TOKEN - ( $pts % self::POINTS_PER_TOKEN ) );
				?>
					<p class="lcc-muted"><?php printf(
						esc_html__( 'You have %1$s contribution points. Earn %2$s more to unlock your first token.', 'legendcreate-community' ),
						'<strong>' . esc_html( number_format_i18n( $pts ) ) . '</strong>',
						'<strong>' . esc_html( number_format_i18n( $needed ) ) . '</strong>'
					); ?></p>
				<?php endif; ?>

				<?php if ( $history ) : ?>
					<div class="lcc-token-history">
						<h4><?php esc_html_e( 'Token History', 'legendcreate-community' ); ?></h4>
						<ul class="lcc-token-log">
						<?php foreach ( $history as $row ) :
							$delta   = (int) $row->amount;
							$is_earn = $delta > 0;
							$label   = self::type_label( $row->type, $row->status );
						?>
							<li class="<?php echo $is_earn ? 'lcc-token-earn' : 'lcc-token-spend'; ?>">
								<span class="lcc-token-delta"><?php echo esc_html( ( $is_earn ? '+' : '' ) . $delta ); ?></span>
								<span class="lcc-token-type"><?php echo esc_html( $label ); ?></span>
								<?php if ( $row->note ) : ?>
									<span class="lcc-token-note-text lcc-muted"><?php echo esc_html( $row->note ); ?></span>
								<?php endif; ?>
								<span class="lcc-token-date lcc-muted"><?php echo esc_html( mysql2date( 'j M Y', $row->created_at ) ); ?></span>
							</li>
						<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	// ── Helpers ──────────────────────────────────────────────────────────────────

	public static function has_pending( $user_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM ' . self::table() . ' WHERE user_id=%d AND status=%s LIMIT 1',
			(int) $user_id, 'pending'
		) );
	}

	public static function recent_ledger( $user_id, $limit = 10 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE user_id=%d ORDER BY created_at DESC LIMIT %d',
			(int) $user_id, (int) $limit
		) );
	}

	private static function log_entry( $user_id, $amount, $type, $note = '', $status = null, $reviewed_by = 0 ) {
		global $wpdb;
		$row = array(
			'user_id'    => (int) $user_id,
			'amount'     => (int) $amount,
			'type'       => $type,
			'note'       => (string) $note,
			'created_at' => current_time( 'mysql', true ),
		);
		$fmt = array( '%d', '%d', '%s', '%s', '%s' );
		if ( null !== $status )  { $row['status']      = $status;            $fmt[] = '%s'; }
		if ( $reviewed_by > 0 ) { $row['reviewed_by'] = (int) $reviewed_by; $fmt[] = '%d'; }
		$wpdb->insert( self::table(), $row, $fmt );
	}

	private static function type_label( $type, $status ) {
		if ( 'earn'   === $type ) { return 'Earned (points milestone)'; }
		if ( 'award'  === $type ) { return 'Awarded by team'; }
		if ( 'request' === $type ) {
			if ( 'pending'  === $status ) { return 'Redemption pending review'; }
			if ( 'approved' === $status ) { return 'Redeemed'; }
			if ( 'denied'   === $status ) { return 'Request denied (tokens returned)'; }
		}
		return ucfirst( (string) $type );
	}

	// ── Email notifications ───────────────────────────────────────────────────────

	private static function from_header() {
		return 'From: ' . get_bloginfo( 'name' ) . ' <admin@legendcreate.com>';
	}

	private static function headers() {
		return array( 'Content-Type: text/plain; charset=UTF-8', self::from_header() );
	}

	private function notify_earned( $user_id, $tokens ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) { return; }
		$site = get_bloginfo( 'name' );
		$s    = $tokens > 1 ? 's' : '';
		wp_mail(
			$user->user_email,
			sprintf( '[%s] You earned %d Legend Token%s!', $site, $tokens, $s ),
			sprintf(
				"Hi %s,\n\nYou've hit a contribution milestone and earned %d Legend Token%s on %s!\n\nTokens = %d day%s of Premium membership. Head to your dashboard and submit a redemption request when you're ready — our team reviews and grants access manually.\n\nDashboard: %s\n\nThe %s Team",
				$user->display_name, $tokens, $s, $site,
				$tokens * self::DAYS_PER_TOKEN, $tokens * self::DAYS_PER_TOKEN > 1 ? 's' : '',
				home_url( '/dashboard/' ), $site
			),
			self::headers()
		);
	}

	private function notify_admin_request( $user_id, $tokens, $note ) {
		$user  = get_userdata( (int) $user_id );
		$name  = $user ? $user->display_name : 'User #' . $user_id;
		$site  = get_bloginfo( 'name' );
		wp_mail(
			get_bloginfo( 'admin_email' ),
			sprintf( '[%s Admin] Token redemption request from %s', $site, $name ),
			sprintf(
				"A member has submitted a token redemption request.\n\nUser: %s\nTokens: %d\nPremium days: %d\nNote: %s\n\nReview here: %s",
				$name, $tokens, $tokens * self::DAYS_PER_TOKEN,
				$note ?: 'None',
				admin_url( 'users.php?page=lcc-token-requests' )
			),
			self::headers()
		);
	}

	private function notify_user_approved( $user_id, $tokens, $days ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) { return; }
		$site  = get_bloginfo( 'name' );
		$until = get_user_meta( (int) $user_id, '_lcc_premium_until', true );
		$exp   = $until ? ' (expires ' . date_i18n( get_option( 'date_format' ), strtotime( $until ) ) . ')' : '';
		$s     = $tokens > 1 ? 's' : '';
		wp_mail(
			$user->user_email,
			sprintf( '[%s] Token redemption approved — %d days Premium active!', $site, $days ),
			sprintf(
				"Hi %s,\n\nYour request to redeem %d token%s has been approved!\n\n%d days of Premium membership have been added to your account%s.\n\nDashboard: %s\n\nThe %s Team",
				$user->display_name, $tokens, $s, $days, $exp,
				home_url( '/dashboard/' ), $site
			),
			self::headers()
		);
	}

	private function notify_user_denied( $user_id, $tokens, $reason ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) { return; }
		$site = get_bloginfo( 'name' );
		$s    = $tokens > 1 ? 's' : '';
		wp_mail(
			$user->user_email,
			sprintf( '[%s] Token redemption request — update', $site ),
			sprintf(
				"Hi %s,\n\nWe reviewed your request to redeem %d token%s. We're unable to approve it at this time.%s\n\nYour tokens have NOT been deducted — your balance is unchanged.\n\nIf you have questions, reply to this email.\n\nThe %s Team",
				$user->display_name, $tokens, $s,
				$reason ? "\n\nReason: {$reason}" : '',
				$site
			),
			self::headers()
		);
	}
}
