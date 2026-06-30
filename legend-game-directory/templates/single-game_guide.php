<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header(); the_post();

$guide_id   = get_the_ID();
$game_id    = (int) get_post_meta( $guide_id, '_lgd_guide_game_id', true );
$game_name  = get_post_meta( $guide_id, '_lgd_guide_game_name', true );
$game_slug  = get_post_meta( $guide_id, '_lgd_guide_game_slug', true );
$diff       = get_post_meta( $guide_id, '_lgd_guide_difficulty', true );
$read_time  = (int) get_post_meta( $guide_id, '_lgd_guide_reading_time', true );
$platform   = get_post_meta( $guide_id, '_lgd_guide_platform', true );
$aff_url    = get_post_meta( $guide_id, '_lgd_guide_affiliate_url', true );
$aff_label  = get_post_meta( $guide_id, '_lgd_guide_affiliate_label', true ) ?: __( 'Get the Game', 'legend-game-directory' );
$key_points = array_filter( (array) get_post_meta( $guide_id, '_lgd_guide_key_points', true ) );
$guide_types = wp_get_post_terms( $guide_id, 'guide_type', array( 'fields' => 'names' ) );
$seo_title  = get_post_meta( $guide_id, '_lgd_guide_seo_title', true );
$meta_desc  = get_post_meta( $guide_id, '_lgd_guide_meta_description', true );
$is_external   = (bool) get_post_meta( $guide_id, '_lgd_guide_is_external', true );
$source_url    = get_post_meta( $guide_id, '_lgd_guide_source_url', true );
$source_site   = get_post_meta( $guide_id, '_lgd_guide_source_site', true );
$source_author = get_post_meta( $guide_id, '_lgd_guide_source_author', true );
$video_url     = get_post_meta( $guide_id, '_lgd_guide_video_url', true );

// Related guides (same game or same type — limit 3).
$related_args = array(
	'post_type'      => 'game_guide',
	'post_status'    => 'publish',
	'posts_per_page' => 3,
	'post__not_in'   => array( $guide_id ),
);
if ( $game_id > 0 ) {
	$related_args['meta_query'] = array( array( 'key' => '_lgd_guide_game_id', 'value' => $game_id, 'type' => 'NUMERIC' ) );
} elseif ( ! is_wp_error( $guide_types ) && $guide_types ) {
	$related_args['tax_query'] = array( array( 'taxonomy' => 'guide_type', 'field' => 'name', 'terms' => $guide_types ) );
}
$related = new WP_Query( $related_args );
?>
<main class="lgd-shell">

	<section class="lgd-guide-hero">
		<div class="lgd-container">
			<div class="lgd-badges">
				<?php foreach ( $guide_types as $type_name ) : ?>
					<span><?php echo esc_html( $type_name ); ?></span>
				<?php endforeach; ?>
				<?php if ( $diff ) : ?>
					<span class="lgd-badge-diff lgd-diff-<?php echo esc_attr( strtolower( $diff ) ); ?>"><?php echo esc_html( $diff ); ?></span>
				<?php endif; ?>
			</div>

			<h1><?php the_title(); ?></h1>

			<?php if ( $game_name ) : ?>
			<p class="lgd-guide-game-name">
				<?php esc_html_e( 'Guide for:', 'legend-game-directory' ); ?>
				<?php if ( $game_id > 0 ) : ?>
					<a href="<?php echo esc_url( get_permalink( $game_id ) ); ?>"><?php echo esc_html( $game_name ); ?></a>
				<?php else : ?>
					<strong><?php echo esc_html( $game_name ); ?></strong>
				<?php endif; ?>
			</p>
			<?php endif; ?>

			<div class="lgd-guide-meta">
				<span><?php echo esc_html( get_the_date() ); ?></span>
				<?php if ( $read_time ) : ?><span><?php echo esc_html( $read_time . ' min read' ); ?></span><?php endif; ?>
				<?php if ( $platform ) : ?><span><?php echo esc_html( $platform ); ?></span><?php endif; ?>
			</div>
		</div>
	</section>

	<div class="lgd-container lgd-guide-layout">

		<article class="lgd-guide-content">

			<?php if ( $key_points ) : ?>
			<div class="lgd-guide-key-points">
				<strong><?php esc_html_e( 'Quick Summary', 'legend-game-directory' ); ?></strong>
				<ul>
					<?php foreach ( $key_points as $point ) : ?>
						<li><?php echo esc_html( $point ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endif; ?>

			<!-- AD SLOT: guide-top -->
			<div class="lgd-ad-slot lgd-ad-slot--guide-top" aria-hidden="true">
				<!-- Insert ad code here (e.g. AdSense / Ezoic) -->
			</div>

			<div class="lgd-guide-body">
				<?php the_content(); ?>
			</div>

			<?php if ( $video_url ) : ?>
			<div class="lgd-guide-video">
				<a class="lgd-button" rel="noopener" target="_blank" href="<?php echo esc_url( $video_url ); ?>">&#9654; <?php esc_html_e( 'Watch video guide', 'legend-game-directory' ); ?></a>
			</div>
			<?php endif; ?>

			<?php if ( $is_external && $source_url ) : ?>
			<div class="lgd-guide-source">
				<a class="lgd-button lgd-button--cta" rel="nofollow noopener" target="_blank" href="<?php echo esc_url( $source_url ); ?>"><?php esc_html_e( 'Read Full Guide', 'legend-game-directory' ); ?> &rarr;</a>
				<p class="lgd-guide-cta__disclosure">
					<?php echo esc_html( sprintf( __( 'Summary by Legend Create. The full guide is on %s — credit to the original source.', 'legend-game-directory' ), $source_site ? $source_site : wp_parse_url( $source_url, PHP_URL_HOST ) ) ); ?>
					<?php if ( $source_author ) { echo ' ' . esc_html( sprintf( __( 'By %s.', 'legend-game-directory' ), $source_author ) ); } ?>
				</p>
			</div>
			<?php endif; ?>

			<!-- AD SLOT: guide-mid -->
			<div class="lgd-ad-slot lgd-ad-slot--guide-mid" aria-hidden="true">
				<!-- Insert ad code here -->
			</div>

			<?php if ( $aff_url ) : ?>
			<div class="lgd-guide-cta">
				<a class="lgd-button lgd-button--cta" rel="nofollow noopener sponsored" target="_blank" href="<?php echo esc_url( $aff_url ); ?>">
					<?php echo esc_html( $aff_label ); ?> &rarr;
				</a>
				<p class="lgd-guide-cta__disclosure"><?php esc_html_e( 'Affiliate link — we may earn a commission at no extra cost to you.', 'legend-game-directory' ); ?></p>
			</div>
			<?php endif; ?>

		</article>

		<aside class="lgd-guide-sidebar">

			<?php if ( comments_open() || get_comments_number() ) : $cc = (int) get_comments_number(); ?>
			<div class="lgd-guide-sidebar__panel lgd-guide-discuss">
				<h3><?php esc_html_e( 'Discussion', 'legend-game-directory' ); ?></h3>
				<p class="lgd-muted" style="font-size:.85rem"><?php echo esc_html( $cc ? sprintf( _n( '%s comment so far.', '%s comments so far.', $cc, 'legend-game-directory' ), number_format_i18n( $cc ) ) : __( 'No comments yet — start the conversation.', 'legend-game-directory' ) ); ?></p>
				<a class="lgd-button" href="#lgd-comments" style="font-size:.85rem;padding:10px 14px"><?php echo esc_html( $cc ? __( 'Join the discussion', 'legend-game-directory' ) : __( 'Leave a comment', 'legend-game-directory' ) ); ?></a>
			</div>
			<?php endif; ?>

			<?php if ( $game_id > 0 ) : ?>
			<div class="lgd-guide-sidebar__panel lgd-guide-game-panel">
				<h3><?php esc_html_e( 'About the Game', 'legend-game-directory' ); ?></h3>
				<?php if ( has_post_thumbnail( $game_id ) ) : ?>
					<a href="<?php echo esc_url( get_permalink( $game_id ) ); ?>">
						<?php echo get_the_post_thumbnail( $game_id, 'lgd-thumb', array( 'class' => 'lgd-guide-game-thumb' ) ); ?>
					</a>
				<?php endif; ?>
				<p><a href="<?php echo esc_url( get_permalink( $game_id ) ); ?>"><?php echo esc_html( $game_name ); ?></a></p>
				<p class="lgd-muted" style="font-size:.85rem"><?php echo esc_html( wp_trim_words( get_the_excerpt( $game_id ), 20 ) ); ?></p>
				<a class="lgd-button" href="<?php echo esc_url( get_permalink( $game_id ) ); ?>" style="font-size:.85rem;padding:10px 14px"><?php esc_html_e( 'View Game Profile', 'legend-game-directory' ); ?></a>
			</div>
			<?php endif; ?>

			<!-- AD SLOT: guide-sidebar -->
			<div class="lgd-ad-slot lgd-ad-slot--guide-sidebar" aria-hidden="true">
				<!-- Insert sidebar ad code here -->
			</div>

			<?php if ( $related->have_posts() ) : ?>
			<div class="lgd-guide-sidebar__panel">
				<h3><?php esc_html_e( 'More Guides', 'legend-game-directory' ); ?></h3>
				<ul class="lgd-related-guides-list">
					<?php while ( $related->have_posts() ) : $related->the_post(); ?>
					<li>
						<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
						<?php $rt = (int) get_post_meta( get_the_ID(), '_lgd_guide_reading_time', true ); if ( $rt ) : ?>
							<span class="lgd-muted" style="font-size:.8rem"> &middot; <?php echo esc_html( $rt . ' min' ); ?></span>
						<?php endif; ?>
					</li>
					<?php endwhile; wp_reset_postdata(); ?>
				</ul>
			</div>
			<?php endif; ?>

		</aside>

	</div>

	<?php if ( comments_open() || get_comments_number() ) : ?>
	<section id="lgd-comments" class="lgd-container lgd-comments">
		<?php comments_template(); ?>
	</section>
	<?php endif; ?>

</main>
<?php get_footer(); ?>
