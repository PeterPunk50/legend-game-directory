<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<div class="lcdp-dashboard lcdp-dashboard--developer">
	<div class="lcdp-dashboard__header">
		<h2>Developer Dashboard</h2>
		<?php if($profile): ?>
		<p class="lcdp-dashboard__subtitle">
			<?php echo esc_html($profile->studio_name ?: get_userdata($user_id)->display_name); ?>
			<?php if($profile->membership_plan && 'none' !== $profile->membership_plan): ?>
			— <span class="lcdp-badge lcdp-badge--membership"><?php
				$plans = LCDP_Developer::membership_plans();
				echo esc_html($plans[$profile->membership_plan]['name'] ?? $profile->membership_plan);
			?></span>
			<?php else: ?>
			— <a href="<?php echo esc_url(home_url('/developers/#memberships')); ?>">Upgrade Membership</a>
			<?php endif; ?>
		</p>
		<?php endif; ?>
	</div>

	<div class="lcdp-dashboard__tabs">
		<a href="#games" class="lcdp-tab lcdp-tab--active">Games</a>
		<a href="#campaigns" class="lcdp-tab">Campaigns</a>
		<a href="#tokens" class="lcdp-tab">Tokens</a>
		<a href="#settings" class="lcdp-tab">Profile</a>
	</div>

	<!-- Games tab -->
	<section class="lcdp-tab-panel" id="games">
		<div class="lcdp-panel-header">
			<h3>Your Games</h3>
			<a href="<?php echo esc_url(home_url('/submit-game/')); ?>" class="lcdp-btn lcdp-btn--primary lcdp-btn--sm">+ Submit Game</a>
		</div>
		<?php if($games): ?>
		<table class="lcdp-table">
			<thead><tr><th>Title</th><th>Status</th><th>Stage</th><th>Actions</th></tr></thead>
			<tbody>
			<?php foreach($games as $game):
				$terms = get_the_terms($game->ID,'lcdp_dev_stage');
				$stage = $terms ? $terms[0]->name : '—';
				$rating= LCDP_Ratings::get_summary('lcdp_game',$game->ID);
			?>
			<tr>
				<td><?php echo esc_html($game->post_title); ?>
					<?php if($rating['count']): ?>
					<?php echo wp_kses_post($rating['stars']); ?> <small>(<?php echo absint($rating['count']); ?>)</small>
					<?php endif; ?>
				</td>
				<td><span class="lcdp-badge lcdp-badge--<?php echo esc_attr($game->post_status); ?>"><?php echo esc_html(ucfirst($game->post_status)); ?></span></td>
				<td><?php echo esc_html($stage); ?></td>
				<td>
					<?php if('publish' === $game->post_status): ?>
					<a href="<?php echo esc_url(get_permalink($game->ID)); ?>" class="lcdp-btn lcdp-btn--sm lcdp-btn--outline">View</a>
					<?php endif; ?>
					<a href="<?php echo esc_url(home_url('/request-playtest/?game='.$game->ID)); ?>" class="lcdp-btn lcdp-btn--sm lcdp-btn--secondary">Request Playtest</a>
				</td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php else: ?>
		<div class="lcdp-empty-state">
			<p>No games yet. Submit your first game to get started.</p>
			<a href="<?php echo esc_url(home_url('/submit-game/')); ?>" class="lcdp-btn lcdp-btn--primary">Submit Your Game</a>
		</div>
		<?php endif; ?>
	</section>

	<!-- Campaigns tab -->
	<section class="lcdp-tab-panel" id="campaigns">
		<h3>Campaigns</h3>
		<?php if($campaigns): ?>
		<table class="lcdp-table">
			<thead><tr><th>Title</th><th>Package</th><th>Status</th><th>Created</th></tr></thead>
			<tbody>
			<?php foreach($campaigns as $c):
				$statuses = LCDP_Campaign::statuses();
			?>
			<tr>
				<td><?php echo esc_html($c->title); ?></td>
				<td><?php echo esc_html(str_replace('_',' ',ucwords($c->service_package,'_'))); ?></td>
				<td><span class="lcdp-badge lcdp-badge--<?php echo esc_attr($c->status); ?>"><?php echo esc_html($statuses[$c->status] ?? $c->status); ?></span></td>
				<td><?php echo esc_html(substr($c->created_at,0,10)); ?></td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php else: ?>
		<div class="lcdp-empty-state">
			<p>No campaigns yet.</p>
			<a href="<?php echo esc_url(home_url('/developers/')); ?>" class="lcdp-btn lcdp-btn--primary">View Services</a>
		</div>
		<?php endif; ?>
	</section>

	<!-- Tokens tab -->
	<section class="lcdp-tab-panel" id="tokens">
		<?php echo do_shortcode('[lcdp_token_wallet]'); ?>
	</section>

	<!-- Profile/settings tab -->
	<section class="lcdp-tab-panel" id="settings">
		<h3>Developer Profile</h3>
		<form class="lcdp-form lcdp-developer-profile-form">
			<div class="lcdp-form__group">
				<label for="lcdp-studio-name">Studio Name</label>
				<input type="text" id="lcdp-studio-name" name="studio_name" value="<?php echo esc_attr($profile->studio_name ?? ''); ?>" class="lcdp-input">
			</div>
			<div class="lcdp-form__group">
				<label for="lcdp-studio-website">Studio Website</label>
				<input type="url" id="lcdp-studio-website" name="studio_website" value="<?php echo esc_attr($profile->studio_website ?? ''); ?>" class="lcdp-input">
			</div>
			<div class="lcdp-form__group">
				<label for="lcdp-bio">About Your Studio</label>
				<textarea id="lcdp-bio" name="bio" rows="4" class="lcdp-input"><?php echo esc_textarea($profile->bio ?? ''); ?></textarea>
			</div>
			<button type="submit" class="lcdp-btn lcdp-btn--primary">Save Profile</button>
		</form>
	</section>
</div>
