<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Member feedback — the capture end of the LegendCreate improvement loop.
 *
 * Members submit feedback via [lcc_feedback] (auto-created /feedback/ page and a
 * dashboard card). Items land in wp2l_lcc_feedback with status 'new'. Admins review
 * at Users > Community Feedback. FBE HQ pulls new items over REST (lcc/v1/feedback,
 * Application Password auth), analyzes them with the Feedback Analyst agent, and
 * routes recommendations through the HQ Approval Queue — nothing is acted on
 * without human authorization. HQ marks pulled items 'synced' via mark-synced.
 *
 * Statuses: new -> synced (pulled by HQ) -> actioned | dismissed (reopen -> new).
 */
final class LCC_Feedback {

	const DB_FLAG    = 'lcc_feedback_db';
	const CATEGORIES = array( 'bug', 'idea', 'content', 'praise', 'other' );
	const MAX_PER_DAY = 3;

	public function __construct() {
		add_shortcode( 'lcc_feedback', array( $this, 'form_shortcode' ) );
		add_action( 'admin_post_lcc_submit_feedback', array( $this, 'handle_submit' ) );
		add_action( 'admin_post_lcc_feedback_status', array( $this, 'handle_status' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_create_page' ) );
		add_action( 'rest_api_init', array( $this, 'rest_routes' ) );
	}

	// ── Storage ──────────────────────────────────────────────────────────────────

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lcc_feedback';
	}

	private static function ensure_table() {
		if ( get_option( self::DB_FLAG ) ) { return; }
		global $wpdb;
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		$wpdb->query( 'CREATE TABLE IF NOT EXISTS ' . $t . ' (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			category VARCHAR(20) NOT NULL DEFAULT \'other\',
			message TEXT NOT NULL,
			page_url VARCHAR(255) NOT NULL DEFAULT \'\',
			status VARCHAR(20) NOT NULL DEFAULT \'new\',
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY status (status),
			KEY user_id (user_id)
		) ' . $charset );
		update_option( self::DB_FLAG, 1 );
	}

	/** Auto-create the public /feedback/ page holding the shortcode (idempotent). */
	public function maybe_create_page() {
		$id = (int) get_option( 'lcc_page_feedback', 0 );
		if ( $id && 'page' === get_post_type( $id ) ) { return; }
		$page = get_page_by_path( 'feedback' );
		if ( $page ) {
			update_option( 'lcc_page_feedback', (int) $page->ID );
			return;
		}
		$new = wp_insert_post( array(
			'post_type'    => 'page',
			'post_title'   => __( 'Feedback', 'legendcreate-community' ),
			'post_name'    => 'feedback',
			'post_status'  => 'publish',
			'post_content' => '[lcc_feedback]',
		) );
		if ( $new && ! is_wp_error( $new ) ) { update_option( 'lcc_page_feedback', (int) $new ); }
	}

	// ── Member form ──────────────────────────────────────────────────────────────

	public function form_shortcode() {
		if ( ! is_user_logged_in() ) {
			$join = (int) get_option( 'lcc_page_register', 0 );
			$url  = $join ? get_permalink( $join ) : home_url( '/join/' );
			return '<div class="lcc-shell"><div class="lcc-panel lcc-gate"><p>'
				. esc_html__( 'Feedback is a member feature — sign in or create a free account to tell us what to build next.', 'legendcreate-community' )
				. '</p><a class="lcc-btn" href="' . esc_url( $url ) . '">' . esc_html__( 'Sign in / Join free', 'legendcreate-community' ) . '</a></div></div>';
		}

		$notice = '';
		if ( isset( $_GET['fb'] ) ) {
			if ( 'thanks' === $_GET['fb'] ) {
				$notice = '<div class="lcc-notice lcc-notice-ok">' . esc_html__( 'Thank you! Your feedback is in the review queue — it directly shapes what we improve next.', 'legendcreate-community' ) . '</div>';
			} elseif ( 'limit' === $_GET['fb'] ) {
				$notice = '<div class="lcc-notice lcc-notice-warn">' . esc_html__( 'You have reached today\'s feedback limit. Please try again tomorrow.', 'legendcreate-community' ) . '</div>';
			} elseif ( 'empty' === $_GET['fb'] ) {
				$notice = '<div class="lcc-notice lcc-notice-err">' . esc_html__( 'Please write a message before submitting.', 'legendcreate-community' ) . '</div>';
			}
		}

		$labels = array(
			'bug'     => __( 'Something is broken (bug)', 'legendcreate-community' ),
			'idea'    => __( 'Feature idea / improvement', 'legendcreate-community' ),
			'content' => __( 'Guide or content request', 'legendcreate-community' ),
			'praise'  => __( 'Something you love', 'legendcreate-community' ),
			'other'   => __( 'Other', 'legendcreate-community' ),
		);

		ob_start(); ?>
		<div class="lcc-shell">
			<?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<div class="lcc-panel">
				<h2><?php esc_html_e( 'Help shape LegendCreate', 'legendcreate-community' ); ?></h2>
				<p class="lcc-muted"><?php esc_html_e( 'Every submission is reviewed by the team. The best ideas go straight onto our build list.', 'legendcreate-community' ); ?></p>
				<form class="lcc-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'lcc_submit_feedback', 'lcc_feedback_nonce' ); ?>
					<input type="hidden" name="action" value="lcc_submit_feedback">
					<input type="text" name="lcc_fb_web" value="" style="position:absolute;left:-9999px" tabindex="-1" autocomplete="off" aria-hidden="true">

					<label><?php esc_html_e( 'What kind of feedback is this?', 'legendcreate-community' ); ?>
						<select name="lcc_fb_category">
							<?php foreach ( $labels as $val => $label ) : ?>
								<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select></label>

					<label><?php esc_html_e( 'Your feedback', 'legendcreate-community' ); ?>
						<textarea name="lcc_fb_message" rows="5" maxlength="2000" required placeholder="<?php esc_attr_e( 'The more specific, the better — what happened, or what would make LegendCreate better for you?', 'legendcreate-community' ); ?>"></textarea></label>

					<label><?php esc_html_e( 'Page it relates to (optional)', 'legendcreate-community' ); ?>
						<input type="url" name="lcc_fb_page" placeholder="https://legendcreate.com/..."></label>

					<button type="submit" class="lcc-btn"><?php esc_html_e( 'Send feedback', 'legendcreate-community' ); ?></button>
				</form>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	public function handle_submit() {
		if ( ! is_user_logged_in()
			|| ! isset( $_POST['lcc_feedback_nonce'] )
			|| ! wp_verify_nonce( wp_unslash( $_POST['lcc_feedback_nonce'] ), 'lcc_submit_feedback' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'legendcreate-community' ) );
		}
		$back = wp_get_referer();
		if ( ! $back ) {
			$fb   = (int) get_option( 'lcc_page_feedback', 0 );
			$back = $fb ? get_permalink( $fb ) : home_url( '/feedback/' );
		}
		$back = remove_query_arg( 'fb', $back );

		// Honeypot: silently accept bots without storing.
		if ( ! empty( $_POST['lcc_fb_web'] ) ) {
			wp_safe_redirect( add_query_arg( 'fb', 'thanks', $back ) );
			exit;
		}

		$uid     = get_current_user_id();
		$message = isset( $_POST['lcc_fb_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['lcc_fb_message'] ) ) : '';
		$message = mb_substr( trim( $message ), 0, 2000 );
		if ( '' === $message ) {
			wp_safe_redirect( add_query_arg( 'fb', 'empty', $back ) );
			exit;
		}

		self::ensure_table();
		global $wpdb;

		// Rate limit per member per day.
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . self::table() . ' WHERE user_id = %d AND created_at > %s', $uid, $since
		) );
		if ( $count >= self::MAX_PER_DAY ) {
			wp_safe_redirect( add_query_arg( 'fb', 'limit', $back ) );
			exit;
		}

		$category = isset( $_POST['lcc_fb_category'] ) ? sanitize_key( wp_unslash( $_POST['lcc_fb_category'] ) ) : 'other';
		if ( ! in_array( $category, self::CATEGORIES, true ) ) { $category = 'other'; }
		$page_url = isset( $_POST['lcc_fb_page'] ) ? esc_url_raw( wp_unslash( $_POST['lcc_fb_page'] ) ) : '';

		$wpdb->insert( self::table(), array(
			'user_id'    => $uid,
			'category'   => $category,
			'message'    => $message,
			'page_url'   => mb_substr( $page_url, 0, 255 ),
			'status'     => 'new',
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		), array( '%d', '%s', '%s', '%s', '%s', '%s' ) );

		do_action( 'lcc_feedback_submitted', (int) $wpdb->insert_id, $uid, $category );

		wp_safe_redirect( add_query_arg( 'fb', 'thanks', $back ) );
		exit;
	}

	// ── Admin review queue ───────────────────────────────────────────────────────

	public function admin_menu() {
		add_users_page(
			__( 'Community Feedback', 'legendcreate-community' ),
			__( 'Community Feedback', 'legendcreate-community' ),
			'manage_options',
			'lcc-feedback',
			array( $this, 'render_admin' )
		);
	}

	public function render_admin() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Not allowed.', 'legendcreate-community' ) ); }
		self::ensure_table();
		global $wpdb;

		$filter = isset( $_GET['fstatus'] ) ? sanitize_key( wp_unslash( $_GET['fstatus'] ) ) : '';
		$where  = $filter ? $wpdb->prepare( ' WHERE status = %s', $filter ) : '';
		$rows   = $wpdb->get_results( 'SELECT * FROM ' . self::table() . $where . ' ORDER BY id DESC LIMIT 100' );

		$statuses = array( '' => __( 'All', 'legendcreate-community' ), 'new' => 'New', 'synced' => 'Synced to HQ', 'actioned' => 'Actioned', 'dismissed' => 'Dismissed' );
		echo '<div class="wrap"><h1>' . esc_html__( 'Community Feedback', 'legendcreate-community' ) . '</h1>';
		echo '<p>' . esc_html__( 'New items are pulled into FBE HQ, analyzed by the Feedback Analyst agent, and only enter the improvement backlog after human approval.', 'legendcreate-community' ) . '</p>';
		echo '<p>';
		foreach ( $statuses as $key => $label ) {
			$url = add_query_arg( array( 'page' => 'lcc-feedback', 'fstatus' => $key ), admin_url( 'users.php' ) );
			echo '<a class="button' . ( $filter === $key ? ' button-primary' : '' ) . '" style="margin-right:6px" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</p>';

		if ( ! $rows ) {
			echo '<p><em>' . esc_html__( 'No feedback here yet.', 'legendcreate-community' ) . '</em></p></div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>'
			. '<th style="width:50px">ID</th><th style="width:140px">' . esc_html__( 'Member', 'legendcreate-community' ) . '</th>'
			. '<th style="width:90px">' . esc_html__( 'Category', 'legendcreate-community' ) . '</th>'
			. '<th>' . esc_html__( 'Message', 'legendcreate-community' ) . '</th>'
			. '<th style="width:110px">' . esc_html__( 'Status', 'legendcreate-community' ) . '</th>'
			. '<th style="width:130px">' . esc_html__( 'Date (UTC)', 'legendcreate-community' ) . '</th>'
			. '<th style="width:190px">' . esc_html__( 'Actions', 'legendcreate-community' ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $rows as $r ) {
			$user = get_userdata( (int) $r->user_id );
			$who  = $user ? $user->display_name : ( '#' . (int) $r->user_id );
			$act  = function ( $id, $to, $label ) {
				$url = wp_nonce_url( add_query_arg( array(
					'action' => 'lcc_feedback_status', 'fid' => (int) $id, 'to' => $to,
				), admin_url( 'admin-post.php' ) ), 'lcc_feedback_status_' . (int) $id );
				return '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
			};
			$actions = array();
			if ( 'actioned' !== $r->status )  { $actions[] = $act( $r->id, 'actioned', __( 'Mark actioned', 'legendcreate-community' ) ); }
			if ( 'dismissed' !== $r->status ) { $actions[] = $act( $r->id, 'dismissed', __( 'Dismiss', 'legendcreate-community' ) ); }
			if ( 'new' !== $r->status )       { $actions[] = $act( $r->id, 'new', __( 'Reopen', 'legendcreate-community' ) ); }

			echo '<tr>'
				. '<td>' . (int) $r->id . '</td>'
				. '<td>' . esc_html( $who ) . '</td>'
				. '<td>' . esc_html( $r->category ) . '</td>'
				. '<td>' . esc_html( mb_substr( $r->message, 0, 400 ) ) . ( $r->page_url ? '<br><a href="' . esc_url( $r->page_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'page', 'legendcreate-community' ) . '</a>' : '' ) . '</td>'
				. '<td>' . esc_html( $r->status ) . '</td>'
				. '<td>' . esc_html( $r->created_at ) . '</td>'
				. '<td>' . implode( ' &middot; ', $actions ) . '</td>'
				. '</tr>';
		}
		echo '</tbody></table></div>';
	}

	public function handle_status() {
		$fid = isset( $_GET['fid'] ) ? (int) $_GET['fid'] : 0;
		if ( ! current_user_can( 'manage_options' )
			|| ! $fid
			|| ! isset( $_GET['_wpnonce'] )
			|| ! wp_verify_nonce( wp_unslash( $_GET['_wpnonce'] ), 'lcc_feedback_status_' . $fid ) ) {
			wp_die( esc_html__( 'Security check failed.', 'legendcreate-community' ) );
		}
		$to = isset( $_GET['to'] ) ? sanitize_key( wp_unslash( $_GET['to'] ) ) : '';
		if ( in_array( $to, array( 'new', 'synced', 'actioned', 'dismissed' ), true ) ) {
			global $wpdb;
			self::ensure_table();
			$wpdb->update( self::table(), array( 'status' => $to ), array( 'id' => $fid ), array( '%s' ), array( '%d' ) );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'lcc-feedback' ), admin_url( 'users.php' ) ) );
		exit;
	}

	// ── REST feed for FBE HQ (Application Password auth, manage_options) ────────

	public function rest_routes() {
		register_rest_route( 'lcc/v1', '/feedback', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_list' ),
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			'args'                => array(
				'since_id' => array( 'default' => 0 ),
				'status'   => array( 'default' => 'new' ),
				'limit'    => array( 'default' => 50 ),
			),
		) );
		register_rest_route( 'lcc/v1', '/feedback/mark-synced', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_mark_synced' ),
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
		) );
	}

	public function rest_list( $request ) {
		self::ensure_table();
		global $wpdb;
		$since  = max( 0, (int) $request['since_id'] );
		$limit  = min( 100, max( 1, (int) $request['limit'] ) );
		$status = sanitize_key( (string) $request['status'] );

		$sql    = 'SELECT * FROM ' . self::table() . ' WHERE id > %d';
		$params = array( $since );
		if ( $status && 'any' !== $status ) {
			$sql     .= ' AND status = %s';
			$params[] = $status;
		}
		$sql .= ' ORDER BY id ASC LIMIT ' . (int) $limit;
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		$items = array();
		foreach ( (array) $rows as $r ) {
			$user    = get_userdata( (int) $r->user_id );
			$items[] = array(
				'id'         => (int) $r->id,
				'member'     => $user ? $user->display_name : ( 'user#' . (int) $r->user_id ),
				'premium'    => class_exists( 'LCC_Memberships' ) ? LCC_Memberships::is_premium( (int) $r->user_id ) : false,
				'category'   => $r->category,
				'message'    => $r->message,
				'page_url'   => $r->page_url,
				'status'     => $r->status,
				'created_at' => $r->created_at,
			);
		}
		return rest_ensure_response( array( 'items' => $items, 'count' => count( $items ) ) );
	}

	public function rest_mark_synced( $request ) {
		self::ensure_table();
		global $wpdb;
		$ids = array_filter( array_map( 'intval', (array) $request->get_param( 'ids' ) ) );
		if ( ! $ids ) { return rest_ensure_response( array( 'updated' => 0 ) ); }
		$in      = implode( ',', $ids );
		$updated = $wpdb->query( "UPDATE " . self::table() . " SET status = 'synced' WHERE status = 'new' AND id IN ($in)" );
		return rest_ensure_response( array( 'updated' => (int) $updated ) );
	}
}
