<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * AdSense integration for the game guide template.
 *
 * - Ads are shown to guests and Free-tier members only.
 * - Premium members (LCC_Memberships::is_premium()) see no ads.
 * - Publisher ID and per-slot Ad Unit IDs are stored as WP options,
 *   editable at Games → Ad Settings.
 * - The adsbygoogle.js script is injected once in <head> on guide pages
 *   only when a publisher ID is configured and the visitor is not Premium.
 */
final class LGD_Ads {

	const OPTION_PUBLISHER = 'lgd_adsense_publisher_id';

	const SLOTS = array(
		'guide-top'     => 'lgd_adsense_guide_top',
		'guide-mid'     => 'lgd_adsense_guide_mid',
		'guide-sidebar' => 'lgd_adsense_guide_sidebar',
	);

	public function __construct() {
		add_action( 'admin_menu',              array( $this, 'register_menu' ) );
		add_action( 'admin_post_lgd_ads_save', array( $this, 'handle_save' ) );
		add_action( 'wp_head',                 array( $this, 'maybe_inject_script' ), 1 );
	}

	// ── Helpers ──────────────────────────────────────────────────────────────────

	public static function publisher_id() {
		return trim( (string) get_option( self::OPTION_PUBLISHER, '' ) );
	}

	private static function is_premium() {
		if ( ! is_user_logged_in() ) { return false; }
		if ( ! class_exists( 'LCC_Memberships' ) ) { return false; }
		return LCC_Memberships::is_premium( get_current_user_id() );
	}

	private static function ads_active() {
		return ( ! is_admin() ) && self::publisher_id() && ! self::is_premium();
	}

	// ── Script injection (<head>) ─────────────────────────────────────────────────

	public function maybe_inject_script() {
		if ( ! is_singular( 'game_guide' ) ) { return; }
		if ( ! self::ads_active() ) { return; }
		printf(
			'<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=%s" crossorigin="anonymous"></script>' . "\n",
			esc_attr( self::publisher_id() )
		);
	}

	// ── Slot renderer ─────────────────────────────────────────────────────────────

	public static function render( $slot ) {
		if ( ! self::ads_active() ) { return; }
		$option = self::SLOTS[ $slot ] ?? null;
		if ( ! $option ) { return; }
		$unit = trim( (string) get_option( $option, '' ) );
		if ( ! $unit ) { return; }
		?>
		<ins class="adsbygoogle"
		     style="display:block"
		     data-ad-client="<?php echo esc_attr( self::publisher_id() ); ?>"
		     data-ad-slot="<?php echo esc_attr( $unit ); ?>"
		     data-ad-format="auto"
		     data-full-width-responsive="true"></ins>
		<script>(adsbygoogle = window.adsbygoogle || []).push({});</script>
		<?php
	}

	// ── Admin settings page ───────────────────────────────────────────────────────

	public function register_menu() {
		add_submenu_page(
			'edit.php?post_type=game',
			__( 'Ad Settings', 'legend-game-directory' ),
			__( 'Ad Settings', 'legend-game-directory' ),
			'manage_options',
			'lgd-ads',
			array( $this, 'admin_page' )
		);
	}

	public function admin_page() {
		$pub     = self::publisher_id();
		$updated = isset( $_GET['updated'] );
		$slots_labels = array(
			'guide-top'     => __( 'Guide Page — Top (above content body)', 'legend-game-directory' ),
			'guide-mid'     => __( 'Guide Page — Mid (below content body)', 'legend-game-directory' ),
			'guide-sidebar' => __( 'Guide Page — Sidebar', 'legend-game-directory' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Ad Settings — AdSense', 'legend-game-directory' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Ads are shown to Free-tier visitors only. Premium members always see a clean, ad-free experience.', 'legend-game-directory' ); ?></p>

			<?php if ( $updated ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Ad settings saved.', 'legend-game-directory' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'lgd_ads_save', 'lgd_ads_nonce' ); ?>
				<input type="hidden" name="action" value="lgd_ads_save">

				<table class="form-table"><tbody>
					<tr>
						<th><label for="lgd_adsense_publisher_id"><?php esc_html_e( 'Publisher ID', 'legend-game-directory' ); ?></label></th>
						<td>
							<input type="text" id="lgd_adsense_publisher_id" name="lgd_adsense_publisher_id"
							       value="<?php echo esc_attr( $pub ); ?>" class="regular-text"
							       placeholder="ca-pub-XXXXXXXXXXXXXXXX">
							<p class="description"><?php esc_html_e( 'Your Google AdSense Publisher ID. Find it in your AdSense account overview.', 'legend-game-directory' ); ?></p>
						</td>
					</tr>

					<?php foreach ( self::SLOTS as $slot => $option ) : ?>
					<tr>
						<th><label for="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $slots_labels[ $slot ] ?? $slot ); ?></label></th>
						<td>
							<input type="text" id="<?php echo esc_attr( $option ); ?>"
							       name="<?php echo esc_attr( $option ); ?>"
							       value="<?php echo esc_attr( get_option( $option, '' ) ); ?>"
							       class="regular-text"
							       placeholder="<?php esc_attr_e( 'Ad Unit ID (digits only, e.g. 1234567890)', 'legend-game-directory' ); ?>">
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody></table>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Ad Settings', 'legend-game-directory' ); ?></button>
				</p>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Setup guide', 'legend-game-directory' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'Sign in to Google AdSense and copy your Publisher ID (ca-pub-...) from the account overview.', 'legend-game-directory' ); ?></li>
				<li><?php esc_html_e( 'In AdSense → Ads → By ad unit, create a Display ad for each slot (Responsive is fine). Copy the data-ad-slot number.', 'legend-game-directory' ); ?></li>
				<li><?php esc_html_e( 'Paste the Publisher ID and the Ad Unit ID for each slot above, then save.', 'legend-game-directory' ); ?></li>
				<li><?php esc_html_e( 'Leave any slot blank to disable it. Slots with no Ad Unit ID render nothing.', 'legend-game-directory' ); ?></li>
				<li><?php esc_html_e( 'AdSense takes 24–48 hours to approve a new site. Ads will show once your account is approved.', 'legend-game-directory' ); ?></li>
			</ol>
		</div>
		<?php
	}

	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden.' ); }
		check_admin_referer( 'lgd_ads_save', 'lgd_ads_nonce' );

		$pub = isset( $_POST['lgd_adsense_publisher_id'] )
			? sanitize_text_field( wp_unslash( $_POST['lgd_adsense_publisher_id'] ) )
			: '';
		update_option( self::OPTION_PUBLISHER, $pub, false );

		foreach ( self::SLOTS as $slot => $option ) {
			$val = isset( $_POST[ $option ] ) ? sanitize_text_field( wp_unslash( $_POST[ $option ] ) ) : '';
			update_option( $option, $val, false );
		}

		wp_safe_redirect( add_query_arg(
			array( 'post_type' => 'game', 'page' => 'lgd-ads', 'updated' => 1 ),
			admin_url( 'edit.php' )
		) );
		exit;
	}
}
