<?php if ( ! defined( 'ABSPATH' ) ) { exit; }
$existing_games = get_posts(array('post_type'=>'lcdp_game','author'=>get_current_user_id(),'posts_per_page'=>20,'post_status'=>array('publish','pending','draft')));
$at_limit = count($existing_games) >= 1 && !current_user_can('lcdp_submit_game');
?>
<div class="lcdp-form-wrap">
<?php if($at_limit): ?>
<div class="lcdp-callout lcdp-callout--warning">
	<p>Free listings are limited to 1 game per account. <a href="<?php echo esc_url(home_url('/developers/#memberships')); ?>">Upgrade to a membership</a> to list more games.</p>
</div>
<?php else: ?>
<h2>Submit Your Game</h2>
<p>All submissions are reviewed by our team before going live. We aim to review within 2–3 business days.</p>
<form id="lcdp-game-submit-form" class="lcdp-form" novalidate>
	<div class="lcdp-form__group lcdp-form__group--required">
		<label for="lcdp-game-title">Game Title <span aria-hidden="true">*</span></label>
		<input type="text" id="lcdp-game-title" name="game_title" required class="lcdp-input" maxlength="200">
	</div>
	<div class="lcdp-form__group">
		<label for="lcdp-studio">Studio Name</label>
		<input type="text" id="lcdp-studio" name="studio_name" class="lcdp-input" maxlength="200">
	</div>
	<div class="lcdp-form__group lcdp-form__group--required">
		<label for="lcdp-game-desc">Game Description <span aria-hidden="true">*</span></label>
		<textarea id="lcdp-game-desc" name="game_description" rows="5" required class="lcdp-input" maxlength="2000" placeholder="Describe your game — what players do, genre, core features."></textarea>
	</div>
	<div class="lcdp-form__row">
		<div class="lcdp-form__group">
			<label for="lcdp-genre">Primary Genre</label>
			<select id="lcdp-genre" name="genre" class="lcdp-input">
				<option value="">— Select —</option>
				<?php foreach(get_terms(array('taxonomy'=>'lcdp_genre','hide_empty'=>false)) as $term): ?>
				<option value="<?php echo esc_attr($term->slug); ?>"><?php echo esc_html($term->name); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div class="lcdp-form__group">
			<label for="lcdp-dev-stage">Development Stage</label>
			<select id="lcdp-dev-stage" name="dev_stage" class="lcdp-input">
				<option value="">— Select —</option>
				<?php foreach(get_terms(array('taxonomy'=>'lcdp_dev_stage','hide_empty'=>false)) as $term): ?>
				<option value="<?php echo esc_attr($term->slug); ?>"><?php echo esc_html($term->name); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
	</div>
	<div class="lcdp-form__group">
		<label>Platforms (select all that apply)</label>
		<div class="lcdp-checkbox-grid">
		<?php foreach(get_terms(array('taxonomy'=>'lcdp_platform','hide_empty'=>false)) as $term): ?>
		<label class="lcdp-checkbox-label">
			<input type="checkbox" name="platforms[]" value="<?php echo esc_attr($term->slug); ?>">
			<?php echo esc_html($term->name); ?>
		</label>
		<?php endforeach; ?>
		</div>
	</div>
	<div class="lcdp-form__group">
		<label for="lcdp-trailer">Trailer URL (YouTube / Vimeo)</label>
		<input type="url" id="lcdp-trailer" name="trailer_url" class="lcdp-input" placeholder="https://">
	</div>
	<div class="lcdp-form__group">
		<label for="lcdp-steam">Steam Page URL</label>
		<input type="url" id="lcdp-steam" name="steam_url" class="lcdp-input" placeholder="https://store.steampowered.com/app/...">
	</div>
	<div class="lcdp-form__group">
		<label for="lcdp-demo">Demo URL</label>
		<input type="url" id="lcdp-demo" name="demo_url" class="lcdp-input" placeholder="https://">
	</div>
	<div class="lcdp-form__group">
		<label for="lcdp-official">Official Website</label>
		<input type="url" id="lcdp-official" name="official_url" class="lcdp-input" placeholder="https://">
	</div>
	<div class="lcdp-form__group lcdp-form__group--checkbox">
		<label>
			<input type="checkbox" name="consent_listing" required>
			I confirm the information above is accurate and I have the rights to list this game. I understand my listing is subject to editorial review.
		</label>
	</div>
	<div id="lcdp-game-form-messages" class="lcdp-form__messages" aria-live="polite"></div>
	<button type="submit" class="lcdp-btn lcdp-btn--primary">Submit Game for Review</button>
</form>
<?php endif; ?>
</div>
