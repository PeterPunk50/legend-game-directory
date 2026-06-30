<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header();
$section = function( $title, $description, $shortcode ) {
	?><section class="lgd-home-section"><div class="lgd-container"><div class="lgd-home-heading"><div><h2><?php echo esc_html( $title ); ?></h2><p><?php echo esc_html( $description ); ?></p></div><a href="<?php echo esc_url( get_post_type_archive_link( 'game' ) ); ?>"><?php esc_html_e( 'Browse all', 'bizdirectory-legend' ); ?> →</a></div><?php echo do_shortcode( $shortcode ); ?></div></section><?php
};
?>
<main class="lgd-shell">
	<section class="lgd-archive-hero"><div class="lgd-container"><p class="lgd-badge"><?php esc_html_e( 'Transparent game discovery', 'bizdirectory-legend' ); ?></p><h1><?php esc_html_e( 'Find Your Next Free, Indie or Mobile Game', 'bizdirectory-legend' ); ?></h1><p class="lgd-lead"><?php esc_html_e( 'Compare ratings, discover hidden indie releases and track legitimate free games across PC, Android and iOS.', 'bizdirectory-legend' ); ?></p><?php echo do_shortcode( '[lgd_game_search]' ); ?></div></section>
	<?php
	$section( __( 'Top Free Games', 'bizdirectory-legend' ), __( 'Legitimate free games ranked with visible evidence and confidence.', 'bizdirectory-legend' ), '[lgd_game_grid type="free-games" orderby="score" limit="3"]' );
	$section( __( 'Best Indie Games', 'bizdirectory-legend' ), __( 'Independent releases with stored evidence for indie status.', 'bizdirectory-legend' ), '[lgd_game_grid type="indie-games" orderby="score" limit="3"]' );
	$section( __( 'Top Mobile Games', 'bizdirectory-legend' ), __( 'Verified Android and iOS releases without blurred score types.', 'bizdirectory-legend' ), '[lgd_game_grid type="mobile-games" orderby="score" limit="3"]' );
	$section( __( 'Free Mobile Games', 'bizdirectory-legend' ), __( 'Mobile games with a confirmed free or freemium entry price.', 'bizdirectory-legend' ), '[lgd_game_grid type="free-games" platform="ios" orderby="score" limit="3"]' );
	$section( __( 'Indie Games on Mobile', 'bizdirectory-legend' ), __( 'Independently produced games available on mobile platforms.', 'bizdirectory-legend' ), '[lgd_game_grid type="indie-games" platform="ios" limit="3"]' );
	$section( __( 'Temporarily Free Offers', 'bizdirectory-legend' ), __( 'Time-limited offers with expiry warnings and source links.', 'bizdirectory-legend' ), '[lgd_game_grid pricing="temporarily-free" orderby="verified" limit="3"]' );
	$section( __( 'Highest Community Ratings', 'bizdirectory-legend' ), __( 'Ratings submitted by verified local members only.', 'bizdirectory-legend' ), '[lgd_game_grid orderby="community" limit="3"]' );
	$section( __( 'Most Recently Verified', 'bizdirectory-legend' ), __( 'Records most recently checked against their stored sources.', 'bizdirectory-legend' ), '[lgd_game_grid orderby="verified" limit="3"]' );
	$section( __( 'New Releases', 'bizdirectory-legend' ), __( 'Recently released games within the Free, Indie, and Mobile scope.', 'bizdirectory-legend' ), '[lgd_game_grid orderby="release" limit="3"]' );
	?>
	<?php $lcc_join = (int) get_option( 'lcc_page_register', 0 ); $lcc_join_url = $lcc_join ? get_permalink( $lcc_join ) : home_url( '/join/' ); ?>
	<?php if ( is_user_logged_in() ) : ?>
	<section class="lgd-home-section"><div class="lgd-container lgd-home-cta"><div><h2><?php esc_html_e( 'Compare Games', 'bizdirectory-legend' ); ?></h2><p><?php esc_html_e( 'Select Compare on two to four game cards, then open the floating comparison button.', 'bizdirectory-legend' ); ?></p><a class="lgd-button" href="<?php echo esc_url( get_post_type_archive_link( 'game' ) ); ?>"><?php esc_html_e( 'Choose games', 'bizdirectory-legend' ); ?></a></div><div><h2><?php esc_html_e( 'Submit a Game', 'bizdirectory-legend' ); ?></h2><?php echo do_shortcode( '[lgd_submit_game]' ); ?></div></div></section>
	<?php else : ?>
	<section class="lgd-home-section"><div class="lgd-container lgd-panel" style="text-align:center"><h2><?php esc_html_e( 'Compare Games & Submit Your Own', 'bizdirectory-legend' ); ?></h2><p><?php esc_html_e( 'Join LegendCreate to compare games side by side and submit games for the directory.', 'bizdirectory-legend' ); ?></p><a class="lgd-button" href="<?php echo esc_url( $lcc_join_url ); ?>"><?php esc_html_e( 'Join free', 'bizdirectory-legend' ); ?></a></div></section>
	<?php endif; ?>
	<section class="lgd-home-section"><div class="lgd-container lgd-two-col"><div class="lgd-panel"><h2><?php esc_html_e( 'Browse by Genre', 'bizdirectory-legend' ); ?></h2><div class="lgd-tax-links"><?php foreach ( get_terms( array( 'taxonomy' => 'game_genre', 'hide_empty' => true) ) as $term ) : ?><a href="<?php echo esc_url( get_term_link( $term ) ); ?>"><?php echo esc_html( $term->name ); ?></a><?php endforeach; ?></div></div><div class="lgd-panel"><h2><?php esc_html_e( 'Browse by Platform', 'bizdirectory-legend' ); ?></h2><div class="lgd-tax-links"><?php foreach ( get_terms( array( 'taxonomy' => 'game_platform', 'hide_empty' => true) ) as $term ) : ?><a href="<?php echo esc_url( get_term_link( $term ) ); ?>"><?php echo esc_html( $term->name ); ?></a><?php endforeach; ?></div></div></div></section>
	<?php // Game Alerts moved to the member dashboard (opt-in in your profile / at signup). ?>
</main>
<?php get_footer(); ?>
