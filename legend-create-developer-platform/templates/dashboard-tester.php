<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<div class="lcdp-dashboard lcdp-dashboard--tester">
	<div class="lcdp-dashboard__header">
		<h2>Tester Dashboard</h2>
		<?php $user = get_userdata($user_id); ?>
		<p class="lcdp-dashboard__subtitle"><?php echo esc_html($user->display_name); ?></p>
	</div>

	<?php if(!$profile): ?>
	<div class="lcdp-callout">
		<p>You have not yet applied to the Playtest Crew.</p>
		<a href="<?php echo esc_url(home_url('/join-playtest-crew/')); ?>" class="lcdp-btn lcdp-btn--primary">Apply to Join</a>
	</div>
	<?php else: ?>

	<div class="lcdp-dashboard__stats">
		<?php
		$statuses = LCDP_Tester::statuses();
		$comp     = LCDP_Tester::profile_completeness($user_id);
		?>
		<div class="lcdp-stat-card">
			<span class="lcdp-stat-num"><?php echo esc_html($statuses[$profile->status] ?? $profile->status); ?></span>
			<span class="lcdp-stat-lbl">Application Status</span>
		</div>
		<div class="lcdp-stat-card">
			<span class="lcdp-stat-num"><?php echo absint($comp); ?>%</span>
			<span class="lcdp-stat-lbl">Profile Complete</span>
		</div>
		<div class="lcdp-stat-card">
			<span class="lcdp-stat-num"><?php echo number_format($profile->reliability_score,0); ?>/100</span>
			<span class="lcdp-stat-lbl">Reliability Score</span>
		</div>
		<div class="lcdp-stat-card">
			<span class="lcdp-stat-num"><?php echo absint($wallet['tokens']); ?>/<?php echo LCDP_Tokens::TOKENS_FOR_6_MONTH; ?></span>
			<span class="lcdp-stat-lbl">Legend Tokens</span>
		</div>
	</div>

	<?php if('approved' === $profile->status || 'specialist_approved' === $profile->status): ?>
	<div class="lcdp-callout lcdp-callout--success">
		<p>✓ Your application is approved. You will receive campaign invitations matching your profile.</p>
	</div>
	<?php elseif('rejected' === $profile->status || 'suspended' === $profile->status): ?>
	<div class="lcdp-callout lcdp-callout--warning">
		<p>Your application status is: <strong><?php echo esc_html($statuses[$profile->status]); ?></strong>.
		If you believe this is incorrect, please <a href="<?php echo esc_url(home_url('/contact/')); ?>">contact us</a>.</p>
	</div>
	<?php else: ?>
	<div class="lcdp-callout">
		<p>Your application is <strong><?php echo esc_html($statuses[$profile->status] ?? $profile->status); ?></strong>. We will be in touch soon.</p>
	</div>
	<?php endif; ?>

	<div class="lcdp-dashboard__tabs">
		<a href="#tester-profile" class="lcdp-tab lcdp-tab--active">My Profile</a>
		<a href="#assignments" class="lcdp-tab">Assignments</a>
		<a href="#tokens" class="lcdp-tab">Tokens</a>
	</div>

	<section class="lcdp-tab-panel" id="tester-profile">
		<h3>Profile &amp; Categories</h3>
		<?php
		$cats      = json_decode($profile->categories ?? '[]', true);
		$platforms = json_decode($profile->platforms ?? '[]', true);
		$all_cats  = LCDP_Tester::categories();
		?>
		<table class="lcdp-table lcdp-table--compact">
			<tr><th>Country</th><td><?php echo esc_html($profile->country); ?></td></tr>
			<tr><th>Timezone</th><td><?php echo esc_html($profile->timezone); ?></td></tr>
			<tr><th>Platforms</th><td><?php echo esc_html(implode(', ', $platforms)); ?></td></tr>
			<tr><th>Controller</th><td><?php echo $profile->has_controller ? 'Yes' : 'No'; ?></td></tr>
			<tr><th>KB & Mouse</th><td><?php echo $profile->has_keyboard_mouse ? 'Yes' : 'No'; ?></td></tr>
			<tr><th>Recording</th><td><?php echo $profile->recording_willing ? 'Willing' : 'Not available'; ?></td></tr>
			<tr><th>NDA</th><td><?php echo $profile->nda_willing ? 'Willing' : 'Not available'; ?></td></tr>
		</table>
		<?php if($cats): ?>
		<h4>Approved Categories</h4>
		<div class="lcdp-tag-grid">
		<?php foreach($cats as $cat):
			if(isset($all_cats[$cat])): ?>
			<span class="lcdp-tag"><?php echo esc_html($all_cats[$cat]); ?></span>
		<?php endif; endforeach; ?>
		</div>
		<?php endif; ?>
		<a href="<?php echo esc_url(home_url('/join-playtest-crew/')); ?>" class="lcdp-btn lcdp-btn--outline lcdp-btn--sm">Update Profile</a>
	</section>

	<section class="lcdp-tab-panel" id="assignments">
		<h3>Active Assignments</h3>
		<?php
		global $wpdb;
		$assignments = $wpdb->get_results($wpdb->prepare(
			"SELECT a.*, c.title as campaign_title, c.end_date, c.build_version, c.mission_brief
			 FROM ".LCDP_Database::table('campaign_applications')." a
			 LEFT JOIN ".LCDP_Database::table('campaigns')." c ON c.id = a.campaign_id
			 WHERE a.tester_user_id=%d AND a.status='assigned'
			 ORDER BY c.end_date ASC",
			$user_id
		));
		$submissions = $wpdb->get_results($wpdb->prepare(
			"SELECT s.*, c.title as campaign_title
			 FROM ".LCDP_Database::table('tester_submissions')." s
			 LEFT JOIN ".LCDP_Database::table('campaigns')." c ON c.id = s.campaign_id
			 WHERE s.tester_user_id=%d ORDER BY s.submitted_at DESC LIMIT 20",
			$user_id
		));
		?>
		<?php if($assignments): ?>
		<?php foreach($assignments as $a): ?>
		<div class="lcdp-assignment-card">
			<h4><?php echo esc_html($a->campaign_title); ?></h4>
			<?php if($a->end_date): ?>
			<p class="lcdp-assignment__deadline">Deadline: <?php echo esc_html($a->end_date); ?></p>
			<?php endif; ?>
			<p>Build: v<?php echo esc_html($a->build_version); ?></p>
			<div class="lcdp-assignment__actions">
				<a href="<?php echo esc_url(home_url('/submit-feedback/?campaign='.$a->campaign_id)); ?>" class="lcdp-btn lcdp-btn--primary lcdp-btn--sm">Submit Feedback</a>
				<a href="<?php echo esc_url(home_url('/submit-bug/?campaign='.$a->campaign_id)); ?>" class="lcdp-btn lcdp-btn--secondary lcdp-btn--sm">Report Bug</a>
			</div>
			<div class="lcdp-confidentiality-reminder">⚠ Confidentiality reminder: Do not share this build outside the campaign.</div>
		</div>
		<?php endforeach; ?>
		<?php else: ?>
		<p>No active assignments. Keep your profile complete to match more campaigns.</p>
		<?php endif; ?>

		<?php if($submissions): ?>
		<h3>Submission History</h3>
		<table class="lcdp-table lcdp-table--compact">
			<thead><tr><th>Campaign</th><th>Status</th><th>Reward</th><th>Submitted</th></tr></thead>
			<tbody>
			<?php foreach($submissions as $s): ?>
			<tr>
				<td><?php echo esc_html($s->campaign_title); ?></td>
				<td><span class="lcdp-badge"><?php echo esc_html(ucwords(str_replace('_',' ',$s->status))); ?></span></td>
				<td><?php echo $s->reward_amount > 0 ? '$'.number_format($s->reward_amount,2) : '—'; ?></td>
				<td><?php echo esc_html(substr($s->submitted_at,0,10)); ?></td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	</section>

	<section class="lcdp-tab-panel" id="tokens">
		<?php echo do_shortcode('[lcdp_token_wallet]'); ?>
	</section>

	<?php endif; ?>
</div>
