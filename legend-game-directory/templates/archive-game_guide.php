<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header();

$active_type = isset( $_GET['guide_type'] ) ? sanitize_title( wp_unslash( $_GET['guide_type'] ) ) : '';
$guide_types = get_terms( array( 'taxonomy' => 'guide_type', 'hide_empty' => true ) );
$archive_url = get_post_type_archive_link( 'game_guide' );
?>
<main class="lgd-shell">

	<section class="lgd-archive-hero">
		<div class="lgd-container">
			<h1><?php esc_html_e( 'Game Guides', 'legend-game-directory' ); ?></h1>
			<p class="lgd-lead"><?php esc_html_e( 'Walkthroughs, tips, and strategy guides for mobile, indie, and free-to-play games.', 'legend-game-directory' ); ?></p>

			<?php if ( ! is_wp_error( $guide_types ) && $guide_types ) : ?>
			<nav class="lgd-filter-tabs" aria-label="<?php esc_attr_e( 'Filter by guide type', 'legend-game-directory' ); ?>">
				<a class="lgd-filter-tab<?php echo '' === $active_type ? ' is-active' : ''; ?>" href="<?php echo esc_url( $archive_url ); ?>"><?php esc_html_e( 'All Guides', 'legend-game-directory' ); ?></a>
				<?php foreach ( $guide_types as $term ) : ?>
					<a class="lgd-filter-tab<?php echo $active_type === $term->slug ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'guide_type', $term->slug, $archive_url ) ); ?>"><?php echo esc_html( $term->name ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php endif; ?>
		</div>
	</section>

	<div class="lgd-container">
		<?php if ( have_posts() ) : ?>
		<div class="lgd-guide-grid">
			<?php while ( have_posts() ) : the_post();
				$guide_id  = get_the_ID();
				$game_id   = (int) get_post_meta( $guide_id, '_lgd_guide_game_id', true );
				$game_name = get_post_meta( $guide_id, '_lgd_guide_game_name', true );
				$diff      = get_post_meta( $guide_id, '_lgd_guide_difficulty', true );
				$read_time = (int) get_post_meta( $guide_id, '_lgd_guide_reading_time', true );
				$types     = wp_get_post_terms( $guide_id, 'guide_type', array( 'fields' => 'names' ) );
				$src_site  = get_post_meta( $guide_id, '_lgd_guide_source_site', true );
				$is_video  = (bool) get_post_meta( $guide_id, '_lgd_guide_video_url', true );
				$remote    = get_post_meta( $guide_id, '_lgd_guide_remote_image', true );
			?>
			<article class="lgd-guide-card">
				<?php if ( has_post_thumbnail() || $remote ) : ?>
					<a class="lgd-guide-card__image<?php echo $is_video ? ' lgd-guide-card__image--video' : ''; ?>" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
						<?php if ( has_post_thumbnail() ) { the_post_thumbnail( 'lgd-card', array( 'loading' => 'lazy' ) ); } else { echo '<img src="' . esc_url( $remote ) . '" alt="" loading="lazy" width="720" height="405">'; } ?>
						<?php if ( $is_video ) : ?><span class="lgd-guide-card__play" aria-hidden="true">&#9654;</span><?php endif; ?>
					</a>
				<?php endif; ?>
				<div class="lgd-guide-card__body">
					<div class="lgd-badges">
						<?php foreach ( $types as $type_name ) : ?>
							<span><?php echo esc_html( $type_name ); ?></span>
						<?php endforeach; ?>
						<?php if ( $diff ) : ?>
							<span class="lgd-badge-diff lgd-diff-<?php echo esc_attr( strtolower( $diff ) ); ?>"><?php echo esc_html( $diff ); ?></span>
						<?php endif; ?>
					</div>
					<h2 class="lgd-guide-card__title">
						<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
					</h2>
					<?php if ( $game_name ) : ?>
					<p class="lgd-guide-card__game">
						<?php if ( $game_id > 0 ) : ?>
							<a href="<?php echo esc_url( get_permalink( $game_id ) ); ?>"><?php echo esc_html( $game_name ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $game_name ); ?>
						<?php endif; ?>
					</p>
					<?php endif; ?>
					<p class="lgd-guide-card__excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 20 ) ); ?></p>
					<div class="lgd-guide-card__meta">
						<span><?php echo esc_html( get_the_date() ); ?></span>
						<?php if ( $read_time ) : ?>
							<span><?php echo esc_html( $read_time . ' min read' ); ?></span>
						<?php endif; ?>
					</div>
				</div>
			</article>
			<?php endwhile; ?>
		</div>

		<div class="lgd-pagination">
			<?php the_posts_pagination( array(
				'mid_size'  => 2,
				'prev_text' => __( '&larr; Older', 'legend-game-directory' ),
				'next_text' => __( 'Newer &rarr;', 'legend-game-directory' ),
			) ); ?>
		</div>

		<?php else : ?>
		<p class="lgd-missing" style="padding:60px 0"><?php esc_html_e( 'No guides have been published yet. Check back soon!', 'legend-game-directory' ); ?></p>
		<?php endif; ?>
	</div>
</main>
<?php get_footer(); ?>
