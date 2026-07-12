<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class LCDP_Admin {

	public function __construct() {
		add_action( 'admin_menu',           array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts',array( $this, 'enqueue' ) );
		add_action( 'save_post_lcdp_game',  array( $this, 'save_game_meta' ), 10, 2 );
		add_filter( 'manage_lcdp_game_posts_columns',        array( $this, 'game_columns' ) );
		add_action( 'manage_lcdp_game_posts_custom_column',  array( $this, 'game_column_data' ), 10, 2 );
		add_action( 'wp_ajax_lcdp_admin_update_tester_status', array( $this, 'ajax_tester_status' ) );
		add_action( 'wp_ajax_lcdp_admin_award_points',         array( $this, 'ajax_award_points' ) );
		add_action( 'wp_ajax_lcdp_admin_generate_report',      array( $this, 'ajax_generate_report' ) );
	}

	public function register_menus() {
		add_menu_page(
			'Legend Create Platform',
			'LC Platform',
			'lcdp_view_admin_dashboard',
			'lcdp-platform',
			array( $this, 'page_dashboard' ),
			'dashicons-games',
			58
		);
		add_submenu_page( 'lcdp-platform', 'Dashboard',           'Dashboard',           'lcdp_view_admin_dashboard', 'lcdp-platform',              array($this,'page_dashboard') );
		add_submenu_page( 'lcdp-platform', 'Campaigns',           'Campaigns',           'lcdp_manage_campaigns',     'lcdp-campaigns',             array($this,'page_campaigns') );
		add_submenu_page( 'lcdp-platform', 'Tester Applications', 'Tester Applications', 'lcdp_review_tester_applications','lcdp-tester-applications',array($this,'page_applications') );
		add_submenu_page( 'lcdp-platform', 'Developer Leads',     'Developer Leads',     'lcdp_manage_leads',         'lcdp-leads',                 array($this,'page_leads') );
		add_submenu_page( 'lcdp-platform', 'Reports',             'Reports',             'lcdp_approve_reports',      'lcdp-reports',               array($this,'page_reports') );
		add_submenu_page( 'lcdp-platform', 'Outreach Queue',      'Outreach Queue',      'lcdp_approve_outreach',     'lcdp-outreach',              array($this,'page_outreach') );
		add_submenu_page( 'lcdp-platform', 'Token Ledger',        'Token Ledger',        'lcdp_view_financials',      'lcdp-tokens',                array($this,'page_tokens') );
		add_submenu_page( 'lcdp-platform', 'Settings',            'Settings',            'manage_options',            'lcdp-settings',              array($this,'page_settings') );
	}

	public function enqueue( $hook ) {
		if ( false === strpos( $hook, 'lcdp' ) && 'post.php' !== $hook && 'post-new.php' !== $hook ) { return; }
		wp_enqueue_style( 'lcdp-admin', LCDP_URL . 'assets/css/lcdp-admin.css', array(), LCDP_VERSION );
		wp_enqueue_script( 'lcdp-admin', LCDP_URL . 'assets/js/lcdp-admin.js', array('jquery'), LCDP_VERSION, true );
		wp_localize_script( 'lcdp-admin', 'lcdpAdmin', array(
			'nonce'   => wp_create_nonce('lcdp_admin_nonce'),
			'ajaxUrl' => admin_url('admin-ajax.php'),
		) );
	}

	// --- Admin pages ---

	public function page_dashboard() {
		global $wpdb;
		$new_apps   = $wpdb->get_var("SELECT COUNT(*) FROM " . LCDP_Database::table('tester_profiles') . " WHERE status='human_review'");
		$active_cmp = $wpdb->get_var("SELECT COUNT(*) FROM " . LCDP_Database::table('campaigns') . " WHERE status NOT IN ('completed','cancelled')");
		$pending_rpt= $wpdb->get_var("SELECT COUNT(*) FROM " . LCDP_Database::table('developer_reports') . " WHERE status='pending'");
		$opt_outs   = $wpdb->get_var("SELECT COUNT(*) FROM " . LCDP_Database::table('suppression_list') . " WHERE suppression_type='marketing'");
		$total_devs = $wpdb->get_var("SELECT COUNT(*) FROM " . LCDP_Database::table('developer_profiles'));
		$total_testers = $wpdb->get_var("SELECT COUNT(*) FROM " . LCDP_Database::table('tester_profiles'));
		?>
		<div class="wrap lcdp-admin">
		<h1>Legend Create Platform — Admin Dashboard</h1>
		<div class="lcdp-stat-row">
			<?php $this->stat_card('Active Campaigns', $active_cmp, 'admin.php?page=lcdp-campaigns'); ?>
			<?php $this->stat_card('Tester Reviews Pending', $new_apps, 'admin.php?page=lcdp-tester-applications', $new_apps > 0 ? 'warning' : ''); ?>
			<?php $this->stat_card('Reports Awaiting Approval', $pending_rpt, 'admin.php?page=lcdp-reports', $pending_rpt > 0 ? 'warning' : ''); ?>
			<?php $this->stat_card('Registered Developers', $total_devs, 'edit.php?post_type=lcdp_developer'); ?>
			<?php $this->stat_card('Registered Testers', $total_testers, 'admin.php?page=lcdp-tester-applications'); ?>
			<?php $this->stat_card('Marketing Opt-Outs', $opt_outs, ''); ?>
		</div>
		<h2>Quick Links</h2>
		<ul>
			<li><a href="<?php echo esc_url(admin_url('admin.php?page=lcdp-campaigns')); ?>">View all campaigns</a></li>
			<li><a href="<?php echo esc_url(admin_url('edit.php?post_type=lcdp_game')); ?>">Review game submissions</a></li>
			<li><a href="<?php echo esc_url(admin_url('admin.php?page=lcdp-tester-applications')); ?>">Review tester applications</a></li>
			<li><a href="<?php echo esc_url(admin_url('admin.php?page=lcdp-outreach')); ?>">Approve outreach emails</a></li>
		</ul>
		</div>
		<?php
	}

	public function page_campaigns() {
		global $wpdb;
		$campaigns = $wpdb->get_results(
			"SELECT c.*, u.display_name AS developer_name
			 FROM " . LCDP_Database::table('campaigns') . " c
			 LEFT JOIN {$wpdb->users} u ON u.ID = c.developer_user_id
			 ORDER BY c.created_at DESC LIMIT 100"
		);
		$statuses = LCDP_Campaign::statuses();
		?>
		<div class="wrap lcdp-admin">
		<h1>Campaigns</h1>
		<table class="widefat lcdp-table">
		<thead><tr><th>ID</th><th>Title</th><th>Developer</th><th>Package</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
		<tbody>
		<?php foreach ( $campaigns as $c ) : ?>
		<tr>
			<td><?php echo absint($c->id); ?></td>
			<td><?php echo esc_html($c->title); ?></td>
			<td><?php echo esc_html($c->developer_name); ?></td>
			<td><?php echo esc_html($c->service_package); ?></td>
			<td><span class="lcdp-badge lcdp-badge--<?php echo esc_attr($c->status); ?>"><?php echo esc_html($statuses[$c->status] ?? $c->status); ?></span></td>
			<td><?php echo esc_html(substr($c->created_at,0,10)); ?></td>
			<td>
				<select class="lcdp-status-select" data-campaign="<?php echo absint($c->id); ?>">
					<?php foreach ( $statuses as $k => $label ) : ?>
					<option value="<?php echo esc_attr($k); ?>" <?php selected($c->status,$k); ?>><?php echo esc_html($label); ?></option>
					<?php endforeach; ?>
				</select>
				<button class="button button-small lcdp-update-status" data-campaign="<?php echo absint($c->id); ?>">Update</button>
				<?php
				$margin = LCDP_Campaign::calculate_margin($c->id);
				if ( $margin && $margin['warning'] ) {
					echo '<span class="lcdp-margin-warning" title="Margin ' . esc_attr($margin['margin_pct']) . '% below minimum">⚠ Low margin</span>';
				}
				?>
			</td>
		</tr>
		<?php endforeach; ?>
		</tbody>
		</table>
		</div>
		<?php
	}

	public function page_applications() {
		global $wpdb;
		$apps = $wpdb->get_results(
			"SELECT t.*, u.display_name, u.user_email
			 FROM " . LCDP_Database::table('tester_profiles') . " t
			 LEFT JOIN {$wpdb->users} u ON u.ID = t.user_id
			 ORDER BY t.created_at DESC LIMIT 200"
		);
		$statuses = LCDP_Tester::statuses();
		?>
		<div class="wrap lcdp-admin">
		<h1>Tester Applications</h1>
		<p><strong>Note:</strong> AI flags are advisory only. A human reviewer must make all final status decisions. Applicants may appeal rejections.</p>
		<table class="widefat lcdp-table">
		<thead><tr><th>User</th><th>Country</th><th>Status</th><th>AI Flags</th><th>Completeness</th><th>Applied</th><th>Actions</th></tr></thead>
		<tbody>
		<?php foreach ( $apps as $a ) :
			$flags = json_decode($a->ai_flags ?? '[]', true);
			$comp  = LCDP_Tester::profile_completeness($a->user_id);
		?>
		<tr>
			<td><?php echo esc_html($a->display_name); ?><br><small><?php echo esc_html($a->user_email); ?></small></td>
			<td><?php echo esc_html($a->country); ?></td>
			<td><span class="lcdp-badge"><?php echo esc_html($statuses[$a->status] ?? $a->status); ?></span></td>
			<td>
				<?php if ( $flags ) : ?>
				<ul class="lcdp-ai-flags">
					<?php foreach($flags as $flag): ?>
					<li>⚠ <?php echo esc_html($flag); ?></li>
					<?php endforeach; ?>
				</ul>
				<?php else: echo '—'; endif; ?>
			</td>
			<td><?php echo absint($comp); ?>%</td>
			<td><?php echo esc_html(substr($a->created_at,0,10)); ?></td>
			<td>
				<select class="lcdp-tester-status" data-user="<?php echo absint($a->user_id); ?>">
					<?php foreach($statuses as $k=>$label): ?>
					<option value="<?php echo esc_attr($k); ?>" <?php selected($a->status,$k); ?>><?php echo esc_html($label); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="text" class="lcdp-tester-notes" placeholder="Notes (required for reject/suspend)" style="width:200px">
				<button class="button button-small lcdp-update-tester-status" data-user="<?php echo absint($a->user_id); ?>">Update</button>
				<a href="<?php echo esc_url(get_edit_user_link($a->user_id)); ?>" class="button button-small">View User</a>
			</td>
		</tr>
		<?php endforeach; ?>
		</tbody>
		</table>
		</div>
		<?php
	}

	public function page_leads() {
		?>
		<div class="wrap lcdp-admin">
		<h1>Developer Leads <span class="lcdp-phase-badge">Phase 3</span></h1>
		<p>Developer lead discovery is scheduled for Phase 3. The database table is ready. Approved-source research and AI scoring will be added in a future release.</p>
		<h2>Architecture ready:</h2>
		<ul>
			<li>Table: <code><?php echo esc_html(LCDP_Database::table('developer_leads')); ?></code></li>
			<li>Table: <code><?php echo esc_html(LCDP_Database::table('outreach_records')); ?></code></li>
			<li>AI scoring engine (Phase 3)</li>
			<li>Human-approval queue before any email sends</li>
			<li>Permanent suppression list enforced</li>
		</ul>
		</div>
		<?php
	}

	public function page_reports() {
		global $wpdb;
		$reports = $wpdb->get_results(
			"SELECT r.*, u.display_name AS developer_name
			 FROM " . LCDP_Database::table('developer_reports') . " r
			 LEFT JOIN {$wpdb->users} u ON u.ID = r.developer_user_id
			 ORDER BY r.created_at DESC LIMIT 50"
		);
		?>
		<div class="wrap lcdp-admin">
		<h1>Developer Reports</h1>
		<p><strong>Note:</strong> All reports must be reviewed by a human before they are sent to developers. AI drafts may not be sent automatically.</p>
		<table class="widefat lcdp-table">
		<thead><tr><th>ID</th><th>Campaign</th><th>Developer</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
		<tbody>
		<?php foreach($reports as $r): ?>
		<tr>
			<td><?php echo absint($r->id); ?></td>
			<td>Campaign #<?php echo absint($r->campaign_id); ?></td>
			<td><?php echo esc_html($r->developer_name); ?></td>
			<td><?php echo esc_html($r->status); ?></td>
			<td><?php echo esc_html(substr($r->created_at,0,10)); ?></td>
			<td>
				<button class="button button-small lcdp-generate-report" data-campaign="<?php echo absint($r->campaign_id); ?>">Generate AI Draft</button>
				<a href="#" class="button button-small">Review</a>
			</td>
		</tr>
		<?php endforeach; ?>
		</tbody>
		</table>
		<h2>Generate New Report</h2>
		<p>Select a completed campaign to generate an AI-assisted draft report for human review.</p>
		</div>
		<?php
	}

	public function page_outreach() {
		?>
		<div class="wrap lcdp-admin">
		<h1>Outreach Queue <span class="lcdp-phase-badge">Phase 3</span></h1>
		<p>No emails are sent without staff approval. The outreach system will be built in Phase 3.</p>
		<p><strong>Permanent rules in place now:</strong></p>
		<ul>
			<li>Suppression list is active — see table <code><?php echo esc_html(LCDP_Database::table('suppression_list')); ?></code></li>
			<li>All outreach records include sender identity, business purpose and unsubscribe link</li>
			<li>Maximum 2 follow-ups per lead</li>
			<li>AI may draft — humans must approve before send</li>
		</ul>
		</div>
		<?php
	}

	public function page_tokens() {
		global $wpdb;
		$top = $wpdb->get_results(
			"SELECT user_id, SUM(CASE WHEN points>0 THEN points ELSE 0 END) as total_earned, tokens_balance
			 FROM " . LCDP_Database::table('token_ledger') . "
			 GROUP BY user_id ORDER BY total_earned DESC LIMIT 50"
		);
		?>
		<div class="wrap lcdp-admin">
		<h1>Legend Token Ledger</h1>
		<h2>Top Earners</h2>
		<table class="widefat lcdp-table">
		<thead><tr><th>User</th><th>Total Points Earned</th><th>Current Token Balance</th><th>Membership Ready?</th><th>Award Points</th></tr></thead>
		<tbody>
		<?php foreach($top as $row):
			$user = get_userdata($row->user_id);
			$wallet = LCDP_Tokens::get_wallet($row->user_id);
		?>
		<tr>
			<td><?php echo $user ? esc_html($user->display_name) : "User #{$row->user_id}"; ?></td>
			<td><?php echo number_format($row->total_earned); ?></td>
			<td><?php echo absint($row->tokens_balance); ?> / <?php echo LCDP_Tokens::TOKENS_FOR_6_MONTH; ?></td>
			<td><?php echo $wallet['membership_ready'] ? '✓ Ready to redeem' : '—'; ?></td>
			<td>
				<input type="number" class="lcdp-award-points-input" placeholder="pts" style="width:60px" data-user="<?php echo absint($row->user_id); ?>">
				<input type="text" class="lcdp-award-reason" placeholder="Reason" style="width:150px">
				<button class="button button-small lcdp-award-points-btn" data-user="<?php echo absint($row->user_id); ?>">Award</button>
			</td>
		</tr>
		<?php endforeach; ?>
		</tbody>
		</table>
		</div>
		<?php
	}

	public function page_settings() {
		if ( isset($_POST['lcdp_save_settings']) ) {
			check_admin_referer('lcdp_settings_nonce');
			update_option('lcdp_min_margin_pct', absint($_POST['min_margin_pct'] ?? 30));
			update_option('lcdp_founding_pilot_slots', absint($_POST['founding_pilot_slots'] ?? 10));
			echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
		}
		?>
		<div class="wrap lcdp-admin">
		<h1>Legend Create Platform Settings</h1>
		<form method="post">
		<?php wp_nonce_field('lcdp_settings_nonce'); ?>
		<table class="form-table">
			<tr><th>Minimum campaign margin (%)</th>
				<td><input type="number" name="min_margin_pct" value="<?php echo absint(get_option('lcdp_min_margin_pct',30)); ?>" min="0" max="100">
				<p class="description">Admin will see a warning if a campaign margin falls below this.</p></td></tr>
			<tr><th>Founding Pilot available slots</th>
				<td><input type="number" name="founding_pilot_slots" value="<?php echo absint(get_option('lcdp_founding_pilot_slots',10)); ?>" min="0">
				<p class="description">Remaining pilot slots to display on the pricing page.</p></td></tr>
		</table>
		<p><input type="submit" name="lcdp_save_settings" class="button button-primary" value="Save Settings"></p>
		</form>
		<h2>Database Tables</h2>
		<ul>
		<?php
		$tables = array('developer_profiles','tester_profiles','campaigns','campaign_applications',
			'tester_submissions','bug_reports','developer_reports','consent_records',
			'token_ledger','ratings','suppression_list','developer_leads','outreach_records','audit_log');
		foreach($tables as $t):
			global $wpdb;
			$exists = $wpdb->get_var("SHOW TABLES LIKE '" . esc_sql(LCDP_Database::table($t)) . "'");
		?>
		<li><?php echo esc_html(LCDP_Database::table($t)); ?> — <?php echo $exists ? '<span style="color:green">✓ exists</span>' : '<span style="color:red">✗ missing</span>'; ?></li>
		<?php endforeach; ?>
		</ul>
		<form method="post" style="margin-top:10px">
		<?php wp_nonce_field('lcdp_settings_nonce'); ?>
		<input type="hidden" name="lcdp_reinstall_db" value="1">
		<p><input type="submit" class="button" value="Reinstall Database Tables" onclick="return confirm('This will run dbDelta to create any missing tables. Existing data is safe.')"></p>
		</form>
		</div>
		<?php
		if ( isset($_POST['lcdp_reinstall_db']) ) {
			check_admin_referer('lcdp_settings_nonce');
			LCDP_Database::install();
			echo '<div class="notice notice-success"><p>Database reinstall complete.</p></div>';
		}
	}

	// AJAX: admin updates tester status (human decision required — AI never calls this)
	public function ajax_tester_status() {
		LCDP_Security::ajax_check('lcdp_admin_nonce','lcdp_review_tester_applications');
		$user_id    = absint($_POST['user_id'] ?? 0);
		$new_status = sanitize_key($_POST['status'] ?? '');
		$notes      = sanitize_textarea_field($_POST['notes'] ?? '');
		if ( in_array($new_status, array('rejected','suspended'), true) && empty($notes) ) {
			wp_send_json_error(array('message' => 'Notes are required when rejecting or suspending.'));
		}
		$result = LCDP_Tester::update_status($user_id, $new_status, get_current_user_id(), $notes);
		$result ? wp_send_json_success(array('message'=>'Status updated.')) : wp_send_json_error(array('message'=>'Update failed.'));
	}

	// AJAX: manual point award by admin
	public function ajax_award_points() {
		LCDP_Security::ajax_check('lcdp_admin_nonce','lcdp_manage_platform');
		$user_id = absint($_POST['user_id'] ?? 0);
		$points  = absint($_POST['points'] ?? 0);
		$reason  = sanitize_text_field($_POST['reason'] ?? 'Manual admin award');
		if ( ! $user_id || ! $points ) { wp_send_json_error(array('message'=>'Invalid input.')); }
		// Use admin_award activity with custom points
		global $wpdb;
		$last = $wpdb->get_row($wpdb->prepare(
			'SELECT balance_after, tokens_balance FROM '.LCDP_Database::table('token_ledger').' WHERE user_id=%d ORDER BY id DESC LIMIT 1',
			$user_id
		));
		$current_pts = $last ? (int)$last->balance_after : 0;
		$current_tok = $last ? (int)$last->tokens_balance : 0;
		$new_pts     = $current_pts + $points;
		$conv        = 0;
		while ($new_pts >= LCDP_Tokens::POINTS_PER_TOKEN) { $new_pts -= LCDP_Tokens::POINTS_PER_TOKEN; $current_tok++; $conv++; }
		$wpdb->insert(LCDP_Database::table('token_ledger'), array(
			'user_id' => $user_id, 'activity_type' => 'admin_award', 'points' => $points,
			'tokens_converted' => $conv, 'balance_after' => $new_pts, 'tokens_balance' => $current_tok,
			'reference_type' => 'admin', 'reference_id' => get_current_user_id(),
			'description' => sanitize_text_field($reason), 'created_at' => current_time('mysql'),
		));
		LCDP_Security::audit('admin_award_points', "Admin awarded {$points} points to user {$user_id}: {$reason}", 'user', $user_id);
		wp_send_json_success(array('message' => "{$points} points awarded.", 'new_token_balance' => $current_tok));
	}

	// AJAX: generate AI report draft (does not auto-send)
	public function ajax_generate_report() {
		LCDP_Security::ajax_check('lcdp_admin_nonce','lcdp_approve_reports');
		$campaign_id = absint($_POST['campaign_id'] ?? 0);
		if ( ! $campaign_id ) { wp_send_json_error(array('message'=>'Invalid campaign.')); }
		$draft = self::generate_report_draft($campaign_id);
		if ( is_wp_error($draft) ) { wp_send_json_error(array('message' => $draft->get_error_message())); }
		wp_send_json_success(array('message' => 'Draft generated. Human review required before sending.', 'draft_id' => $draft));
	}

	private static function generate_report_draft($campaign_id) {
		global $wpdb;
		$campaign    = LCDP_Campaign::get($campaign_id);
		if (!$campaign) { return new WP_Error('not_found','Campaign not found.'); }
		$submissions = $wpdb->get_results($wpdb->prepare(
			'SELECT * FROM '.LCDP_Database::table('tester_submissions').' WHERE campaign_id=%d',
			$campaign_id
		));
		$bugs = $wpdb->get_results($wpdb->prepare(
			'SELECT * FROM '.LCDP_Database::table('bug_reports').' WHERE campaign_id=%d ORDER BY severity ASC',
			$campaign_id
		));

		// Calculate average ratings
		$rating_fields = array('first_impression','controls_rating','tutorial_rating','visual_rating',
			'performance_rating','difficulty_rating','combat_rating','sound_rating','originality_rating',
			'play_again_rating','recommend_rating');
		$averages = array();
		foreach ($rating_fields as $f) {
			$vals = array_column((array)$submissions, $f);
			$vals = array_filter($vals, fn($v) => $v > 0);
			$averages[$f] = $vals ? round(array_sum($vals)/count($vals),1) : 0;
		}

		$report_data = array(
			'campaign_id'   => $campaign_id,
			'campaign_title'=> $campaign->title,
			'tester_count'  => count($submissions),
			'bug_count'     => count($bugs),
			'averages'      => $averages,
			'completion_pct'=> $campaign->tester_count > 0 ? round((count($submissions)/$campaign->tester_count)*100) : 0,
		);

		// AI draft narrative (if AI available — falls back to template)
		$ai_draft = self::build_report_template($report_data, $submissions, $bugs);

		// Upsert report
		$existing = $wpdb->get_var($wpdb->prepare(
			'SELECT id FROM '.LCDP_Database::table('developer_reports').' WHERE campaign_id=%d',$campaign_id
		));
		$row = array(
			'campaign_id'        => $campaign_id,
			'developer_user_id'  => $campaign->developer_user_id,
			'report_data'        => wp_json_encode($report_data),
			'ai_draft'           => $ai_draft,
			'status'             => 'pending',
			'author_user_id'     => get_current_user_id(),
			'download_token'     => LCDP_Security::generate_token(32),
			'updated_at'         => current_time('mysql'),
		);
		if ($existing) {
			$wpdb->update(LCDP_Database::table('developer_reports'),$row,array('id'=>$existing));
			return $existing;
		} else {
			$row['created_at'] = current_time('mysql');
			$wpdb->insert(LCDP_Database::table('developer_reports'),$row);
			return $wpdb->insert_id;
		}
	}

	private static function build_report_template($data, $submissions, $bugs) {
		$count = $data['tester_count'];
		$title = esc_html($data['campaign_title']);
		ob_start();
		echo "# Developer Report: {$title}\n\n";
		echo "## Executive Summary\n";
		echo "Campaign tested by {$count} tester(s). Completion rate: {$data['completion_pct']}%. Total bugs reported: {$data['bug_count']}.\n\n";
		echo "## Player Ratings (1-10 scale)\n";
		foreach ($data['averages'] as $field => $avg) {
			$label = ucwords(str_replace('_rating','',$field));
			echo "- {$label}: {$avg}/10\n";
		}
		echo "\n## Reproducible Bugs\n";
		foreach ($bugs as $bug) {
			echo "- [{$bug->severity}] " . esc_html($bug->title) . " — " . esc_html($bug->frequency) . "\n";
		}
		echo "\n## Player Feedback Summary\n";
		foreach ($submissions as $sub) {
			if (!empty($sub->positive_notes)) { echo "**Positive:** " . esc_html(substr($sub->positive_notes,0,200)) . "\n"; }
			if (!empty($sub->negative_notes)) { echo "**Issues:** "   . esc_html(substr($sub->negative_notes,0,200)) . "\n"; }
		}
		echo "\n---\n*This is an AI-assisted draft. All findings require human review and verification before delivery.*\n";
		return ob_get_clean();
	}

	// Game CPT columns
	public function game_columns($cols) {
		$cols['dev_stage'] = 'Stage';
		$cols['developer'] = 'Developer';
		$cols['rating']    = 'Avg Rating';
		return $cols;
	}

	public function game_column_data($col, $post_id) {
		if ('dev_stage' === $col) {
			$terms = get_the_terms($post_id,'lcdp_dev_stage');
			echo $terms ? esc_html($terms[0]->name) : '—';
		} elseif ('developer' === $col) {
			echo esc_html(get_post_meta($post_id,'_lcdp_developer_name',true) ?: get_the_author_meta('display_name',get_post_field('post_author',$post_id)));
		} elseif ('rating' === $col) {
			$s = LCDP_Ratings::get_summary('lcdp_game',$post_id);
			echo $s['count'] ? esc_html("{$s['avg']}/5 ({$s['count']})") : '—';
		}
	}

	// Save game CPT custom meta from editor
	public function save_game_meta($post_id,$post) {
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return; }
		if (!current_user_can('edit_post',$post_id)) { return; }
		$meta_fields = array('trailer_url','steam_url','demo_url','official_url','dev_stage','developer_user_id');
		foreach ($meta_fields as $f) {
			if (isset($_POST['_lcdp_game_'.$f])) {
				$val = sanitize_text_field($_POST['_lcdp_game_'.$f]);
				update_post_meta($post_id,'_lcdp_game_'.$f,$val);
			}
		}
	}

	private function stat_card($label, $value, $link='', $class='') {
		$tag  = $link ? "a href='" . esc_url(admin_url($link)) . "'" : 'div';
		$etag = $link ? 'a' : 'div';
		echo "<{$tag} class='lcdp-stat-card {$class}'><span class='lcdp-stat-num'>{$value}</span><span class='lcdp-stat-lbl'>" . esc_html($label) . "</span></{$etag}>";
	}
}
