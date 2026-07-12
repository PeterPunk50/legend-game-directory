<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class LCDP_Frontend {

	public function __construct() {
		add_action( 'wp_enqueue_scripts',  array( $this, 'enqueue' ) );
		add_shortcode( 'lcdp_pricing',          array( $this, 'sc_pricing' ) );
		add_shortcode( 'lcdp_memberships',      array( $this, 'sc_memberships' ) );
		add_shortcode( 'lcdp_developer_hero',   array( $this, 'sc_developer_hero' ) );
		add_shortcode( 'lcdp_playtest_crew',    array( $this, 'sc_playtest_crew' ) );
		add_shortcode( 'lcdp_developer_dashboard', array( $this, 'sc_developer_dashboard' ) );
		add_shortcode( 'lcdp_tester_dashboard', array( $this, 'sc_tester_dashboard' ) );
		add_shortcode( 'lcdp_game_submit_form', array( $this, 'sc_game_submit' ) );
		add_shortcode( 'lcdp_tester_apply_form',array( $this, 'sc_tester_apply' ) );
		add_shortcode( 'lcdp_token_wallet',     array( $this, 'sc_token_wallet' ) );
		add_shortcode( 'lcdp_ratings',          array( $this, 'sc_ratings' ) );
		add_shortcode( 'lcdp_addons',           array( $this, 'sc_addons' ) );
	}

	public function enqueue() {
		wp_enqueue_style( 'lcdp-main', LCDP_URL . 'assets/css/lcdp-main.css', array(), LCDP_VERSION );
		wp_enqueue_script( 'lcdp-main', LCDP_URL . 'assets/js/lcdp-main.js', array('jquery'), LCDP_VERSION, true );
		wp_localize_script( 'lcdp-main', 'lcdp', array(
			'ajaxUrl'     => admin_url('admin-ajax.php'),
			'nonce'       => wp_create_nonce('lcdp_frontend_nonce'),
			'devNonce'    => wp_create_nonce('lcdp_developer_nonce'),
			'gameNonce'   => wp_create_nonce('lcdp_game_nonce'),
			'testerNonce' => wp_create_nonce('lcdp_tester_nonce'),
			'subNonce'    => wp_create_nonce('lcdp_submission_nonce'),
			'consentNonce'=> wp_create_nonce('lcdp_consent_nonce'),
			'privacyNonce'=> wp_create_nonce('lcdp_privacy_nonce'),
			'ratingNonce' => wp_create_nonce('lcdp_rating_nonce'),
			'isLoggedIn'  => is_user_logged_in(),
			'loginUrl'    => wp_login_url( get_permalink() ),
		) );
	}

	// --- Shortcodes ---

	public function sc_pricing( $atts ) {
		$atts = shortcode_atts( array( 'highlight' => 'targeted_playtest' ), $atts );
		$packages = LCDP_Developer::service_packages();
		$user_id  = get_current_user_id();
		ob_start();
		?>
		<div class="lcdp-pricing-grid">
		<?php foreach ( $packages as $key => $pkg ) :
			if ( 'custom_qa' === $key ) { continue; }
			$is_pilot  = !empty($pkg['pilot']);
			$price_info = $user_id ? LCDP_Developer::calculate_price($key,$user_id) : null;
			$price_show = $price_info ? $price_info['final'] : $pkg['price'];
			$savings    = $price_info && $price_info['savings'] > 0 ? $price_info['savings'] : 0;
			$checkout   = class_exists('WooCommerce') ? LCDP_WooCommerce::get_add_to_cart_url($key) : home_url('/contact/');
			$highlighted= ($key === $atts['highlight']) ? 'lcdp-card--highlight' : '';
			?>
			<div class="lcdp-card lcdp-pricing-card <?php echo esc_attr($highlighted); ?>">
			<?php if($is_pilot): ?>
				<div class="lcdp-card__badge">Limited Pilot</div>
			<?php endif; ?>
			<?php if($key === $atts['highlight']): ?>
				<div class="lcdp-card__badge lcdp-card__badge--popular">Most Popular</div>
			<?php endif; ?>
			<h3 class="lcdp-card__title"><?php echo esc_html($pkg['name']); ?></h3>
			<div class="lcdp-card__price">
				<?php if($pkg['price'] === 0): ?>
					<span class="lcdp-price">Free</span>
				<?php else: ?>
					<span class="lcdp-price">$<?php echo number_format($price_show,0); ?></span>
					<?php if($savings > 0): ?>
					<span class="lcdp-price-save">Save $<?php echo number_format($savings,0); ?> with membership</span>
					<?php endif; ?>
				<?php endif; ?>
			</div>
			<div class="lcdp-card__desc"><?php echo esc_html(LCDP_WooCommerce::get_product_description_safe($key)); ?></div>
			<a href="<?php echo esc_url($checkout); ?>" class="lcdp-btn lcdp-btn--primary lcdp-btn--full">
				<?php echo $pkg['price'] === 0 ? 'Get Free Listing' : 'Get Started'; ?>
			</a>
			</div>
		<?php endforeach; ?>
		<!-- Custom Campaign card -->
		<div class="lcdp-card lcdp-pricing-card">
			<h3 class="lcdp-card__title">Custom Research & QA Campaign</h3>
			<div class="lcdp-card__price"><span class="lcdp-price">From $750</span></div>
			<div class="lcdp-card__desc">More than 20 testers, multiple builds, specialist hardware, multiplayer balance, longitudinal testing or professional QA.</div>
			<a href="<?php echo esc_url(home_url('/contact/')); ?>" class="lcdp-btn lcdp-btn--secondary lcdp-btn--full">Request a Quote</a>
		</div>
		</div>
		<?php
		return ob_get_clean();
	}

	public function sc_memberships( $atts ) {
		$plans = LCDP_Developer::membership_plans();
		ob_start();
		?>
		<div class="lcdp-membership-grid">
		<?php foreach ( $plans as $key => $plan ) :
			if ( 'studio_partner' === $key ) { continue; }
			$checkout = class_exists('WooCommerce') ? LCDP_WooCommerce::get_add_to_cart_url('membership_'.$key) : home_url('/contact/');
		?>
		<div class="lcdp-card lcdp-membership-card">
			<h3 class="lcdp-card__title"><?php echo esc_html($plan['name']); ?></h3>
			<div class="lcdp-card__price">
				<span class="lcdp-price">$<?php echo absint($plan['price_monthly']); ?></span>
				<span class="lcdp-price-unit">/month</span>
			</div>
			<ul class="lcdp-feature-list">
			<?php foreach($plan['features'] as $feat): ?>
				<li>✓ <?php echo esc_html($feat); ?></li>
			<?php endforeach; ?>
			</ul>
			<a href="<?php echo esc_url($checkout); ?>" class="lcdp-btn lcdp-btn--primary lcdp-btn--full">Start Membership</a>
			<p class="lcdp-card__note">Monthly. Cancel anytime. Discounts apply to Legend Create platform fees only.</p>
		</div>
		<?php endforeach; ?>
		</div>
		<p class="lcdp-token-cta">
			<strong>Earn your membership free:</strong> Active contributors earn <a href="#tokens">Legend Tokens</a>.
			5 tokens = 6 months Developer Starter membership (worth $174).
		</p>
		<?php
		return ob_get_clean();
	}

	public function sc_developer_hero( $atts ) {
		ob_start();
		?>
		<section class="lcdp-hero">
			<div class="lcdp-hero__inner">
				<h1 class="lcdp-hero__title">Find the right players before launch.</h1>
				<p class="lcdp-hero__subtitle">Get targeted playtesting, structured feedback, Steam launch support, professional game guides and promotion built for indie developers.</p>
				<div class="lcdp-hero__actions">
					<a href="<?php echo esc_url(home_url('/submit-game/')); ?>" class="lcdp-btn lcdp-btn--primary lcdp-btn--lg">Submit Your Game</a>
					<a href="#developer-services" class="lcdp-btn lcdp-btn--outline lcdp-btn--lg">View Developer Services</a>
				</div>
				<p class="lcdp-hero__trust">✓ No guaranteed-outcome promises &nbsp;|&nbsp; ✓ Privacy-first &nbsp;|&nbsp; ✓ Human-reviewed reports</p>
			</div>
		</section>
		<?php
		return ob_get_clean();
	}

	public function sc_playtest_crew( $atts ) {
		ob_start();
		?>
		<section class="lcdp-hero lcdp-hero--tester">
			<div class="lcdp-hero__inner">
				<h1 class="lcdp-hero__title">Play new games. Give useful feedback. Get paid.</h1>
				<p class="lcdp-hero__subtitle">Join free, tell us which games and devices you use, and qualify for paid testing missions that match your experience.</p>
				<a href="<?php echo esc_url(home_url('/join-playtest-crew/')); ?>" class="lcdp-btn lcdp-btn--primary lcdp-btn--lg">Apply to Join</a>
			</div>
		</section>
		<div class="lcdp-crew-disclosures lcdp-callout">
			<h3>Before you apply:</h3>
			<ul>
				<li>Registration is free and does not guarantee assignments</li>
				<li>Payment is for completed and accepted testing work</li>
				<li>Payment is not based on giving positive feedback</li>
				<li>You must be 18 or over to join during our initial launch</li>
				<li>You must follow confidentiality requirements for assigned builds</li>
			</ul>
		</div>
		<section class="lcdp-section">
			<h2>How It Works</h2>
			<div class="lcdp-steps">
				<div class="lcdp-step"><span class="lcdp-step__num">1</span><h4>Apply</h4><p>Complete your profile and sample feedback task. A human reviewer checks your application.</p></div>
				<div class="lcdp-step"><span class="lcdp-step__num">2</span><h4>Get Matched</h4><p>Once approved, you receive campaign invitations that match your platforms and experience.</p></div>
				<div class="lcdp-step"><span class="lcdp-step__num">3</span><h4>Test & Report</h4><p>Play for the assigned duration, complete the test mission, and submit your structured feedback.</p></div>
				<div class="lcdp-step"><span class="lcdp-step__num">4</span><h4>Get Paid</h4><p>Approved submissions are rewarded. Earn Legend Tokens for quality contributions.</p></div>
			</div>
		</section>
		<section class="lcdp-section">
			<h2>Tester Categories</h2>
			<div class="lcdp-tag-grid">
			<?php foreach(LCDP_Tester::categories() as $k => $label): ?>
				<span class="lcdp-tag"><?php echo esc_html($label); ?></span>
			<?php endforeach; ?>
			</div>
		</section>
		<?php
		return ob_get_clean();
	}

	public function sc_developer_dashboard( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<div class="lcdp-callout"><p>Please <a href="' . esc_url(wp_login_url(get_permalink())) . '">log in</a> to access your developer dashboard.</p></div>';
		}
		$user_id  = get_current_user_id();
		if ( ! current_user_can('lcdp_submit_game') ) {
			return '<div class="lcdp-callout"><p>This dashboard is for developers. <a href="' . esc_url(home_url('/developers/')) . '">Register as a developer</a> to get started.</p></div>';
		}
		$profile   = LCDP_Developer::get_profile($user_id);
		$campaigns = LCDP_Campaign::get_for_developer($user_id);
		$wallet    = LCDP_Tokens::get_wallet($user_id);
		$games     = get_posts(array('post_type'=>'lcdp_game','author'=>$user_id,'posts_per_page'=>20,'post_status'=>array('publish','pending','draft')));
		ob_start();
		include LCDP_PATH . 'templates/dashboard-developer.php';
		return ob_get_clean();
	}

	public function sc_tester_dashboard( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<div class="lcdp-callout"><p>Please <a href="' . esc_url(wp_login_url(get_permalink())) . '">log in</a> to access your tester dashboard.</p></div>';
		}
		$user_id = get_current_user_id();
		$profile = LCDP_Tester::get_profile($user_id);
		$wallet  = LCDP_Tokens::get_wallet($user_id);
		ob_start();
		include LCDP_PATH . 'templates/dashboard-tester.php';
		return ob_get_clean();
	}

	public function sc_game_submit( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<div class="lcdp-callout"><p>Please <a href="' . esc_url(wp_login_url(get_permalink())) . '">log in</a> to submit your game.</p></div>';
		}
		ob_start();
		include LCDP_PATH . 'templates/form-game-submit.php';
		return ob_get_clean();
	}

	public function sc_tester_apply( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<div class="lcdp-callout"><p>Please <a href="' . esc_url(wp_login_url(get_permalink())) . '">log in</a> to apply as a tester.</p></div>';
		}
		ob_start();
		include LCDP_PATH . 'templates/form-tester-apply.php';
		return ob_get_clean();
	}

	public function sc_token_wallet( $atts ) {
		if ( ! is_user_logged_in() ) { return ''; }
		$user_id = get_current_user_id();
		$wallet  = LCDP_Tokens::get_wallet($user_id);
		$history = LCDP_Tokens::get_history($user_id, 10);
		ob_start();
		?>
		<div class="lcdp-wallet" id="tokens">
			<h3>Your Legend Tokens</h3>
			<div class="lcdp-wallet__summary">
				<div class="lcdp-wallet__stat">
					<span class="lcdp-wallet__num"><?php echo absint($wallet['tokens']); ?></span>
					<span class="lcdp-wallet__label">Tokens</span>
				</div>
				<div class="lcdp-wallet__stat">
					<span class="lcdp-wallet__num"><?php echo number_format($wallet['points_remaining']); ?></span>
					<span class="lcdp-wallet__label">Points to next token</span>
				</div>
				<div class="lcdp-wallet__stat">
					<span class="lcdp-wallet__num"><?php echo number_format($wallet['total_earned']); ?></span>
					<span class="lcdp-wallet__label">Total earned</span>
				</div>
			</div>
			<div class="lcdp-wallet__progress">
				<div class="lcdp-progress-bar">
					<div class="lcdp-progress-fill" style="width:<?php echo absint($wallet['progress_pct']); ?>%"></div>
				</div>
				<p><?php echo absint($wallet['tokens']); ?> / <?php echo LCDP_Tokens::TOKENS_FOR_6_MONTH; ?> tokens for 6-month membership</p>
			</div>
			<?php if ( $wallet['membership_ready'] ): ?>
			<div class="lcdp-wallet__reward">
				<p>🎉 You have earned enough tokens for a free 6-month Developer Starter membership!</p>
				<button class="lcdp-btn lcdp-btn--primary lcdp-redeem-membership">Claim Membership</button>
			</div>
			<?php endif; ?>
			<h4>How to earn tokens</h4>
			<ul class="lcdp-earn-list">
				<li>Submit a game listing (approved) — <strong>200 pts</strong></li>
				<li>Complete a playtest (accepted) — <strong>150 pts</strong></li>
				<li>Submit an accepted bug report — <strong>20–100 pts</strong></li>
				<li>Write an approved review — <strong>75 pts</strong></li>
				<li>Comment on games or guides — <strong>10 pts</strong></li>
				<li>Refer a developer who signs up — <strong>500 pts</strong></li>
				<li>1,000 points = 1 Legend Token &nbsp;|&nbsp; 5 tokens = 6 months membership</li>
			</ul>
			<?php if($history): ?>
			<h4>Recent activity</h4>
			<table class="lcdp-table lcdp-table--compact">
				<thead><tr><th>Activity</th><th>Points</th><th>Date</th></tr></thead>
				<tbody>
				<?php foreach($history as $h): ?>
				<tr>
					<td><?php echo esc_html($h->description); ?></td>
					<td><?php echo $h->points > 0 ? '+' . absint($h->points) : esc_html($h->points); ?></td>
					<td><?php echo esc_html(substr($h->created_at,0,10)); ?></td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	public function sc_ratings( $atts ) {
		$atts = shortcode_atts(array('entity_type'=>'lcdp_game','entity_id'=>0),$atts);
		$entity_id = absint($atts['entity_id']) ?: get_the_ID();
		if (!$entity_id) { return ''; }
		$summary = LCDP_Ratings::get_summary($atts['entity_type'],$entity_id);
		$reviews = LCDP_Ratings::get_reviews($atts['entity_type'],$entity_id,5);
		ob_start();
		?>
		<div class="lcdp-ratings">
			<div class="lcdp-ratings__summary">
				<?php echo wp_kses_post($summary['stars']); ?>
				<span class="lcdp-ratings__avg"><?php echo esc_html($summary['avg']); ?></span>
				<span class="lcdp-ratings__count">(<?php echo absint($summary['count']); ?> ratings)</span>
			</div>
			<?php if(is_user_logged_in()): ?>
			<div class="lcdp-rating-form" data-entity-type="<?php echo esc_attr($atts['entity_type']); ?>" data-entity-id="<?php echo absint($entity_id); ?>">
				<h4>Rate this</h4>
				<div class="lcdp-star-select">
					<?php for($i=1;$i<=5;$i++): ?>
					<button class="lcdp-star-btn" data-value="<?php echo $i; ?>">★</button>
					<?php endfor; ?>
				</div>
				<textarea class="lcdp-review-text" placeholder="Optional: share your thoughts" rows="3"></textarea>
				<button class="lcdp-btn lcdp-btn--secondary lcdp-submit-rating">Submit Rating</button>
			</div>
			<?php endif; ?>
			<?php foreach($reviews as $r): ?>
			<div class="lcdp-review">
				<div class="lcdp-review__meta">
					<?php echo wp_kses_post(LCDP_Ratings::stars_html($r->rating)); ?>
					<span><?php echo esc_html($r->rater_name); ?></span>
					<span class="lcdp-review__date"><?php echo esc_html(substr($r->created_at,0,10)); ?></span>
				</div>
				<?php if($r->review_text): ?>
				<p class="lcdp-review__text"><?php echo esc_html($r->review_text); ?></p>
				<?php endif; ?>
			</div>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	public function sc_addons( $atts ) {
		$addons = LCDP_Developer::addons();
		ob_start();
		?>
		<div class="lcdp-addons-grid">
		<?php foreach($addons as $key => $addon): ?>
		<div class="lcdp-addon-card">
			<h4><?php echo esc_html($addon['name']); ?></h4>
			<div class="lcdp-addon-price">
			<?php if(!empty($addon['custom_quote'])): ?>
				Custom quote
			<?php elseif(!empty($addon['price'])): ?>
				$<?php echo absint($addon['price']); ?>
			<?php elseif(!empty($addon['price_pct'])): ?>
				+<?php echo absint($addon['price_pct']); ?>% surcharge
			<?php elseif(!empty($addon['price_min']) && !empty($addon['price_max'])): ?>
				$<?php echo absint($addon['price_min']); ?>–$<?php echo absint($addon['price_max']); ?>
			<?php elseif(!empty($addon['price_min'])): ?>
				From $<?php echo absint($addon['price_min']); ?>
			<?php endif; ?>
			</div>
		</div>
		<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
