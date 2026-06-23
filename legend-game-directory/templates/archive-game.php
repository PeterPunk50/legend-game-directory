<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header();
?>
<main class="lgd-shell">
	<section class="lgd-archive-hero"><div class="lgd-container"><p class="lgd-badge"><?php esc_html_e( 'Free · Indie · Mobile', 'legend-game-directory' ); ?></p><h1><?php post_type_archive_title(); ?></h1><p class="lgd-lead"><?php esc_html_e( 'Compare transparent ratings, discover hidden indie releases, and track legitimate free games across PC, Android, and iOS.', 'legend-game-directory' ); ?></p><?php echo do_shortcode( '[lgd_game_search]' ); ?></div></section>
	<div class="lgd-container">
		<?php if ( ! empty( $_GET['games'] ) ) : ?><section id="compare" class="lgd-section"><h2><?php esc_html_e( 'Compare Games', 'legend-game-directory' ); ?></h2><?php echo do_shortcode( '[lgd_compare games="' . esc_attr( sanitize_text_field( wp_unslash( $_GET['games'] ) ) ) . '"]' ); ?></section><?php endif; ?>
		<section class="lgd-section"><div class="lgd-grid">
		<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); echo LGD_Frontend::card( get_the_ID() ); endwhile; else : ?><p><?php esc_html_e( 'No verified games match these filters yet.', 'legend-game-directory' ); ?></p><?php endif; ?>
		</div><nav class="lgd-pagination" aria-label="<?php esc_attr_e( 'Game pages', 'legend-game-directory' ); ?>"><?php the_posts_pagination(); ?></nav></section>
		<section class="lgd-section lgd-two-col"><div class="lgd-panel"><h2><?php esc_html_e( 'Browse by Genre', 'legend-game-directory' ); ?></h2><div class="lgd-tax-links"><?php foreach ( get_terms( array( 'taxonomy' => 'game_genre', 'hide_empty' => true ) ) as $term ) : ?><a href="<?php echo esc_url( get_term_link( $term ) ); ?>"><?php echo esc_html( $term->name ); ?></a><?php endforeach; ?></div></div><div class="lgd-panel"><h2><?php esc_html_e( 'Browse by Platform', 'legend-game-directory' ); ?></h2><div class="lgd-tax-links"><?php foreach ( get_terms( array( 'taxonomy' => 'game_platform', 'hide_empty' => true ) ) as $term ) : ?><a href="<?php echo esc_url( get_term_link( $term ) ); ?>"><?php echo esc_html( $term->name ); ?></a><?php endforeach; ?></div></div></section>
	</div>
</main>
<?php get_footer(); ?>
