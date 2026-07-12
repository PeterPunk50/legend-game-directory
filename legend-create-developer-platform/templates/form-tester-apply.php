<?php if ( ! defined( 'ABSPATH' ) ) { exit; }
$profile  = LCDP_Tester::get_profile(get_current_user_id());
$statuses = LCDP_Tester::statuses();
if($profile && in_array($profile->status,array('approved','specialist_approved'),true)):
?>
<div class="lcdp-callout lcdp-callout--success">
	<p>✓ Your Playtest Crew application has been approved. Watch your inbox for campaign invitations.</p>
	<a href="<?php echo esc_url(home_url('/tester-dashboard/')); ?>" class="lcdp-btn lcdp-btn--primary">Go to Dashboard</a>
</div>
<?php else: ?>
<div class="lcdp-form-wrap">
<h2>Join the Playtest Crew</h2>
<div class="lcdp-callout">
	<ul>
		<li>Registration is free</li>
		<li>Registration does not guarantee campaign assignments</li>
		<li>You must be 18 or over</li>
		<li>Payment is for completed and accepted testing work only</li>
		<li>Payment is never for positive feedback, Steam reviews or wishlists</li>
	</ul>
</div>
<form id="lcdp-tester-apply-form" class="lcdp-form lcdp-form--tester" novalidate>

	<h3>Step 1 — About You</h3>
	<div class="lcdp-form__group lcdp-form__group--required">
		<label>
			<input type="checkbox" name="age_confirmed" required>
			I confirm I am 18 years of age or older
		</label>
	</div>
	<div class="lcdp-form__row">
		<div class="lcdp-form__group">
			<label for="lcdp-country">Country <span aria-hidden="true">*</span></label>
			<select id="lcdp-country" name="country" class="lcdp-input" required>
				<option value="">— Select —</option>
				<?php
				$countries = array('US'=>'United States','GB'=>'United Kingdom','CA'=>'Canada','AU'=>'Australia','DE'=>'Germany','FR'=>'France','BR'=>'Brazil','MX'=>'Mexico','PL'=>'Poland','NL'=>'Netherlands','SE'=>'Sweden','NO'=>'Norway','DK'=>'Denmark','FI'=>'Finland','ZA'=>'South Africa','NG'=>'Nigeria','BB'=>'Barbados','TT'=>'Trinidad and Tobago','JM'=>'Jamaica','GY'=>'Guyana','OTHER'=>'Other');
				foreach($countries as $code=>$name): ?>
				<option value="<?php echo esc_attr($code); ?>" <?php if($profile) selected($profile->country,$code); ?>><?php echo esc_html($name); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div class="lcdp-form__group">
			<label for="lcdp-timezone">Timezone</label>
			<select id="lcdp-timezone" name="timezone" class="lcdp-input">
				<option value="">— Select —</option>
				<?php foreach(DateTimeZone::listIdentifiers(DateTimeZone::ALL) as $tz): ?>
				<option value="<?php echo esc_attr($tz); ?>" <?php if($profile) selected($profile->timezone,$tz); ?>><?php echo esc_html($tz); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
	</div>
	<div class="lcdp-form__group">
		<label for="lcdp-languages">Languages spoken</label>
		<input type="text" id="lcdp-languages" name="languages" class="lcdp-input" placeholder="e.g. English, Spanish"
			value="<?php echo esc_attr($profile->languages ?? ''); ?>">
	</div>

	<h3>Step 2 — Hardware & Setup</h3>
	<div class="lcdp-form__group">
		<label>Platforms (select all you have)</label>
		<div class="lcdp-checkbox-grid">
		<?php foreach(array('PC','Mac','Linux','PlayStation','Xbox','Nintendo Switch','iOS','Android','Steam Deck') as $p):
			$t_plats = json_decode($profile->platforms ?? '[]', true);
		?>
		<label class="lcdp-checkbox-label">
			<input type="checkbox" name="platforms[]" value="<?php echo esc_attr($p); ?>" <?php checked(in_array($p,$t_plats)); ?>>
			<?php echo esc_html($p); ?>
		</label>
		<?php endforeach; ?>
		</div>
	</div>
	<div class="lcdp-form__group">
		<label>Input methods</label>
		<label class="lcdp-checkbox-label"><input type="checkbox" name="has_controller" value="1" <?php if($profile) checked($profile->has_controller,1); ?>> Controller</label>
		<label class="lcdp-checkbox-label"><input type="checkbox" name="has_keyboard_mouse" value="1" <?php if($profile) checked($profile->has_keyboard_mouse,1); ?>> Keyboard &amp; Mouse</label>
	</div>
	<div class="lcdp-form__group">
		<label for="lcdp-pc-cpu">PC Specs (if applicable)</label>
		<div class="lcdp-form__row">
			<input type="text" name="pc_cpu" class="lcdp-input" placeholder="CPU e.g. AMD Ryzen 5 5600X">
			<input type="text" name="pc_gpu" class="lcdp-input" placeholder="GPU e.g. RTX 3060">
			<input type="text" name="pc_ram" class="lcdp-input" placeholder="RAM e.g. 16GB">
		</div>
	</div>
	<div class="lcdp-form__group">
		<label for="lcdp-internet">Internet Connection</label>
		<select id="lcdp-internet" name="internet_category" class="lcdp-input">
			<option value="">— Select —</option>
			<option value="broadband" <?php if($profile) selected($profile->internet_category,'broadband'); ?>>Broadband (50+ Mbps)</option>
			<option value="standard" <?php if($profile) selected($profile->internet_category,'standard'); ?>>Standard (10–50 Mbps)</option>
			<option value="basic" <?php if($profile) selected($profile->internet_category,'basic'); ?>>Basic (&lt; 10 Mbps)</option>
			<option value="mobile" <?php if($profile) selected($profile->internet_category,'mobile'); ?>>Mobile data</option>
		</select>
	</div>

	<h3>Step 3 — Gaming Experience</h3>
	<div class="lcdp-form__group">
		<label>Game categories you play regularly (select all that apply)</label>
		<div class="lcdp-checkbox-grid">
		<?php foreach(LCDP_Tester::categories() as $k=>$label):
			$t_cats = json_decode($profile->categories ?? '[]', true);
		?>
		<label class="lcdp-checkbox-label">
			<input type="checkbox" name="categories[]" value="<?php echo esc_attr($k); ?>" <?php checked(in_array($k,$t_cats)); ?>>
			<?php echo esc_html($label); ?>
		</label>
		<?php endforeach; ?>
		</div>
	</div>
	<div class="lcdp-form__row">
		<div class="lcdp-form__group">
			<label>Play style</label>
			<select name="competitive_casual" class="lcdp-input">
				<option value="">— Select —</option>
				<option value="competitive" <?php if($profile) selected($profile->competitive_casual,'competitive'); ?>>Competitive</option>
				<option value="casual" <?php if($profile) selected($profile->competitive_casual,'casual'); ?>>Casual</option>
				<option value="both" <?php if($profile) selected($profile->competitive_casual,'both'); ?>>Both</option>
			</select>
		</div>
		<div class="lcdp-form__group">
			<label>Ranked experience</label>
			<select name="ranked_experience" class="lcdp-input">
				<option value="">— Select —</option>
				<option value="none" <?php if($profile) selected($profile->ranked_experience,'none'); ?>>Never ranked</option>
				<option value="bronze_silver" <?php if($profile) selected($profile->ranked_experience,'bronze_silver'); ?>>Bronze / Silver</option>
				<option value="gold_plat" <?php if($profile) selected($profile->ranked_experience,'gold_plat'); ?>>Gold / Platinum</option>
				<option value="diamond_plus" <?php if($profile) selected($profile->ranked_experience,'diamond_plus'); ?>>Diamond+</option>
			</select>
		</div>
	</div>

	<h3>Step 4 — Availability & Preferences</h3>
	<div class="lcdp-form__group">
		<label>Available hours (select all that apply)</label>
		<div class="lcdp-checkbox-grid">
		<?php foreach(array('weekday_morning','weekday_afternoon','weekday_evening','weekend_any') as $av):
			$t_av = json_decode($profile->availability ?? '[]', true);
		?>
		<label class="lcdp-checkbox-label">
			<input type="checkbox" name="availability[]" value="<?php echo esc_attr($av); ?>" <?php checked(in_array($av,$t_av)); ?>>
			<?php echo esc_html(ucwords(str_replace('_',' ',$av))); ?>
		</label>
		<?php endforeach; ?>
		</div>
	</div>
	<div class="lcdp-form__group">
		<label>Optional capabilities</label>
		<label class="lcdp-checkbox-label"><input type="checkbox" name="voice_chat" value="1" <?php if($profile) checked($profile->voice_chat,1); ?>> Available for voice chat</label>
		<label class="lcdp-checkbox-label"><input type="checkbox" name="recording_willing" value="1" <?php if($profile) checked($profile->recording_willing,1); ?>> Willing to record gameplay</label>
		<label class="lcdp-checkbox-label"><input type="checkbox" name="nda_willing" value="1" <?php if($profile) checked($profile->nda_willing,1); ?>> Willing to sign NDA for unreleased builds</label>
		<label class="lcdp-checkbox-label"><input type="checkbox" name="accessibility_interest" value="1" <?php if($profile) checked($profile->accessibility_interest,1); ?>> Interested in accessibility testing</label>
	</div>
	<div class="lcdp-form__group">
		<label for="lcdp-payment-method">Preferred payment method</label>
		<select id="lcdp-payment-method" name="payment_method" class="lcdp-input">
			<option value="">— Select —</option>
			<option value="paypal" <?php if($profile) selected($profile->payment_method,'paypal'); ?>>PayPal</option>
			<option value="wise" <?php if($profile) selected($profile->payment_method,'wise'); ?>>Wise</option>
			<option value="bank" <?php if($profile) selected($profile->payment_method,'bank'); ?>>Bank Transfer</option>
			<option value="gift_card" <?php if($profile) selected($profile->payment_method,'gift_card'); ?>>Gift Card (Steam / Amazon)</option>
		</select>
	</div>

	<h3>Step 5 — Sample Task</h3>
	<p>Imagine you just played a game called <em>ExampleArena</em> for 30 minutes. Write a short bug report or piece of feedback that would actually be useful to the developer. Include what you saw, what you expected, and any steps to reproduce if it was a bug.</p>
	<div class="lcdp-form__group lcdp-form__group--required">
		<label for="lcdp-sample">Your sample feedback / bug report <span aria-hidden="true">*</span></label>
		<textarea id="lcdp-sample" name="sample_feedback" rows="6" required class="lcdp-input" placeholder="Be specific. Show us how you think as a tester." maxlength="3000"></textarea>
	</div>

	<h3>Step 6 — Consent</h3>
	<div class="lcdp-form__group lcdp-form__group--required">
		<label><input type="checkbox" name="consent_account" required> I agree to create an account on Legend Create and have read the <a href="<?php echo esc_url(home_url('/privacy-policy/')); ?>" target="_blank">Privacy Policy</a>.</label>
	</div>
	<div class="lcdp-form__group">
		<label><input type="checkbox" name="consent_invites" value="1"> I agree to receive relevant playtest invitations by email.</label>
	</div>
	<div class="lcdp-form__group">
		<label><input type="checkbox" name="consent_marketing" value="1"> I agree to receive marketing updates and newsletters from Legend Create.</label>
	</div>
	<div class="lcdp-form__group lcdp-form__group--required">
		<label><input type="checkbox" name="consent_anon_feedback" required> I agree that anonymised testing feedback may be shared with participating developers.</label>
	</div>

	<div id="lcdp-tester-form-messages" class="lcdp-form__messages" aria-live="polite"></div>
	<button type="submit" class="lcdp-btn lcdp-btn--primary">Submit Application</button>
</form>
</div>
<?php endif; ?>
