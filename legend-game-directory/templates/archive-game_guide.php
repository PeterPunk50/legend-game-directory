<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header();

$archive_url = get_post_type_archive_link( 'game_guide' );
$guide_types = get_terms( array( 'taxonomy' => 'guide_type', 'hide_empty' => true ) );

// Active filters
$f_type  = isset( $_GET['guide_type'] )     ? sanitize_title( wp_unslash( $_GET['guide_type'] ) ) : '';
$f_game  = isset( $_GET['guide_game'] )     ? (int) wp_unslash( $_GET['guide_game'] )              : 0;
$f_sort  = isset( $_GET['guide_sort'] )     ? sanitize_key( wp_unslash( $_GET['guide_sort'] ) )    : '';
$f_video = ! empty( $_GET['guide_has_video'] );
$has_filter = $f_type || $f_game || $f_sort || $f_video;

// Game IDs that have at least one published guide.
global $wpdb;
$guide_game_ids = $wpdb->get_col( $wpdb->prepare(
	"SELECT DISTINCT pm.meta_value
	 FROM {$wpdb->postmeta} pm
	 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
	 WHERE pm.meta_key = %s
	 AND CAST(pm.meta_value AS UNSIGNED) > 0
	 AND p.post_type = %s AND p.post_status = %s",
	'_lgd_guide_game_id', 'game_guide', 'publish'
) );
$guide_game_ids = array_values( array_filter( array_map( 'intval', (array) $guide_game_ids ) ) );

$sort_options = array(
	''      => __( 'Most Recent', 'legend-game-directory' ),
	'score' => __( 'Top Rated', 'legend-game-directory' ),
	'az'    => __( 'A – Z', 'legend-game-directory' ),
	'video' => __( 'Video First', 'legend-game-directory' ),
);

// Reusable card closure — avoids duplicating markup across sections.
$card = static function( $gid, $h = 'h2' ) {
	$types    = wp_get_post_terms( $gid, 'guide_type', array( 'fields' => 'names' ) );
	$game_id  = (int) get_post_meta( $gid, '_lgd_guide_game_id', true );
	$game     = get_post_meta( $gid, '_lgd_guide_game_name', true );
	$diff     = get_post_meta( $gid, '_lgd_guide_difficulty', true );
	$rt       = (int) get_post_meta( $gid, '_lgd_guide_reading_time', true );
	$src_site = get_post_meta( $gid, '_lgd_guide_source_site', true );
	$is_video = (bool) get_post_meta( $gid, '_lgd_guide_video_url', true );
	$remote   = get_post_meta( $gid, '_lgd_guide_remote_image', true );
	$score    = (int) get_post_meta( $gid, '_lgd_guide_score', true );
	$url      = get_permalink( $gid );
	$cc       = (int) get_comments_number( $gid );
	?>
	<article class="lgd-guide-card">
		<?php if ( has_post_thumbnail( $gid ) || $remote ) : ?>
		<a class="lgd-guide-card__image<?php echo $is_video ? ' lgd-guide-card__image--video' : ''; ?>"
		   href="<?php echo esc_url( $url ); ?>" tabindex="-1" aria-hidden="true">
			<?php if ( has_post_thumbnail( $gid ) ) { echo get_the_post_thumbnail( $gid, 'lgd-card', array( 'loading' => 'lazy' ) ); } else { echo '<img src="' . esc_url( $remote ) . '" alt="" loading="lazy" width="720" height="405">'; } ?>
			<?php if ( $is_video ) : ?><span class="lgd-guide-card__play" aria-hidden="true">&#9654;</span><?php endif; ?>
			<?php if ( $score >= 70 ) : ?><span class="lgd-guide-card__score-badge"><?php echo esc_html( $score ); ?></span><?php endif; ?>
		</a>
		<?php endif; ?>
		<div class="lgd-guide-card__body">
			<div class="lgd-badges">
				<?php foreach ( (array) $types as $tn ) : if ( $tn ) : ?><span><?php echo esc_html( $tn ); ?></span><?php endif; endforeach; ?>
				<?php if ( $diff ) : ?><span class="lgd-badge-diff lgd-diff-<?php echo esc_attr( strtolower( $diff ) ); ?>"><?php echo esc_html( $diff ); ?></span><?php endif; ?>
			</div>
			<<?php echo esc_attr( $h ); ?> class="lgd-guide-card__title">
				<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( get_the_title( $gid ) ); ?></a>
			</<?php echo esc_attr( $h ); ?>>
			<?php if ( $game ) : ?>
			<p class="lgd-guide-card__game">
				<?php if ( $game_id > 0 ) : ?><a href="<?php echo esc_url( get_permalink( $game_id ) ); ?>"><?php echo esc_html( $game ); ?></a><?php else : echo esc_html( $game ); endif; ?>
			</p>
			<?php endif; ?>
			<p class="lgd-guide-card__excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt( $gid ), 20 ) ); ?></p>
			<div class="lgd-guide-card__meta">
				<span><?php echo esc_html( get_the_date( '', $gid ) ); ?></span>
				<?php if ( $is_video ) : ?><span><?php esc_html_e( 'Video', 'legend-game-directory' ); ?></span><?php endif; ?>
				<?php if ( $src_site ) : ?><span><?php echo esc_html( sprintf( __( 'via %s', 'legend-game-directory' ), $src_site ) ); ?></span><?php endif; ?>
				<?php if ( $rt ) : ?><span><?php echo esc_html( $rt . ' min read' ); ?></span><?php endif; ?>
				<?php if ( $cc ) : ?><a class="lgd-guide-card__comments" href="<?php echo esc_url( $url . '#lgd-comments' ); ?>">&#128172; <?php echo esc_html( number_format_i18n( $cc ) ); ?></a><?php endif; ?>
			</div>
		</div>
	</article>
	<?php
};
?>
<main class="lgd-shell">

	<!-- ── HERO + FILTER BAR ── -->
	<section class="lgd-archive-hero lgd-guides-hero">
		<div class="lgd-container">
			<h1><?php esc_html_e( 'Game Guides', 'legend-game-directory' ); ?></h1>
			<p class="lgd-lead"><?php esc_html_e( 'Walkthroughs, tips, strategies, and video guides for mobile, indie, and free-to-play games.', 'legend-game-directory' ); ?></p>

			<form class="lgd-guide-filterbar" method="get" action="<?php echo esc_url( $archive_url ); ?>">

				<?php if ( ! is_wp_error( $guide_types ) && $guide_types ) : ?>
				<div class="lgd-guide-filterbar__group">
					<label class="lgd-guide-filterbar__label" for="lgd-f-type"><?php esc_html_e( 'Type', 'legend-game-directory' ); ?></label>
					<select id="lgd-f-type" name="guide_type" class="lgd-guide-filterbar__select" onchange="this.form.submit()">
						<option value=""><?php esc_html_e( 'All types', 'legend-game-directory' ); ?></option>
						<?php foreach ( $guide_types as $term ) : ?>
							<option value="<?php echo esc_attr( $term->slug ); ?>"<?php selected( $f_type, $term->slug ); ?>><?php echo esc_html( $term->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<?php endif; ?>

				<?php if ( $guide_game_ids ) : ?>
				<div class="lgd-guide-filterbar__group">
					<label class="lgd-guide-filterbar__label" for="lgd-f-game"><?php esc_html_e( 'Game', 'legend-game-directory' ); ?></label>
					<select id="lgd-f-game" name="guide_game" class="lgd-guide-filterbar__select" onchange="this.form.submit()">
						<option value=""><?php esc_html_e( 'All games', 'legend-game-directory' ); ?></option>
						<?php foreach ( $guide_game_ids as $gid ) :
							$gtitle = get_the_title( $gid );
							if ( ! $gtitle ) { continue; }
						?>
							<option value="<?php echo esc_attr( $gid ); ?>"<?php selected( $f_game, $gid ); ?>><?php echo esc_html( $gtitle ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<?php endif; ?>

				<div class="lgd-guide-filterbar__group">
					<label class="lgd-guide-filterbar__label" for="lgd-f-sort"><?php esc_html_e( 'Sort', 'legend-game-directory' ); ?></label>
					<select id="lgd-f-sort" name="guide_sort" class="lgd-guide-filterbar__select" onchange="this.form.submit()">
						<?php foreach ( $sort_options as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>"<?php selected( $f_sort, $val ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<label class="lgd-guide-filterbar__toggle">
					<input type="checkbox" name="guide_has_video" value="1"<?php checked( $f_video ); ?> onchange="this.form.submit()">
					<?php esc_html_e( 'Video only', 'legend-game-directory' ); ?>
				</label>

				<?php if ( $has_filter ) : ?>
				<a class="lgd-guide-filterbar__clear" href="<?php echo esc_url( $archive_url ); ?>"><?php esc_html_e( '&#10005; Clear filters', 'legend-game-directory' ); ?></a>
				<?php endif; ?>

			</form>
		</div>
	</section>

	<div class="lgd-container">

	<?php if ( ! $has_filter ) : ?>

		<!-- ── FEATURED GUIDES ── -->
		<?php
		$featured = new WP_Query( array(
			'post_type'      => 'game_guide',
			'post_status'    => 'publish',
			'posts_per_page' => 3,
			'no_found_rows'  => true,
			'meta_key'       => '_lgd_guide_score',
			'orderby'        => 'meta_value_num',
			'order'          => 'DESC',
			'meta_query'     => array( array( 'key' => '_lgd_guide_score', 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC' ) ),
		) );
		if ( ! $featured->have_posts() ) {
			$featured = new WP_Query( array( 'post_type' => 'game_guide', 'post_status' => 'publish', 'posts_per_page' => 3, 'no_found_rows' => true ) );
		}
		if ( $featured->have_posts() ) : ?>
		<section class="lgd-guides-section">
			<div class="lgd-home-heading">
				<div>
					<h2><?php esc_html_e( 'Featured Guides', 'legend-game-directory' ); ?></h2>
					<p class="lgd-muted" style="font-size:.9rem;margin:0"><?php esc_html_e( 'Our highest-quality picks', 'legend-game-directory' ); ?></p>
				</div>
			</div>
			<div class="lgd-guide-featured-grid">
				<?php while ( $featured->have_posts() ) : $featured->the_post(); $card( get_the_ID(), 'h2' ); endwhile; wp_reset_postdata(); ?>
			</div>
		</section>
		<?php endif; ?>

		<!-- ── BROWSE BY GAME ── -->
		<?php if ( $guide_game_ids ) :
			$browse_games = new WP_Query( array(
				'post_type'      => 'game',
				'post_status'    => 'publish',
				'post__in'       => $guide_game_ids,
				'posts_per_page' => 16,
				'no_found_rows'  => true,
				'orderby'        => 'title',
				'order'          => 'ASC',
			) );
		if ( $browse_games->have_posts() ) : ?>
		<section class="lgd-guides-section">
			<div class="lgd-home-heading"><h2><?php esc_html_e( 'Browse by Game', 'legend-game-directory' ); ?></h2></div>
			<div class="lgd-guide-game-scroll">
				<?php while ( $browse_games->have_posts() ) : $browse_games->the_post();
					$bgid   = get_the_ID();
					$gcount = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->postmeta} pm
						 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
						 WHERE pm.meta_key = %s AND pm.meta_value = %s AND p.post_status = 'publish'",
						'_lgd_guide_game_id', $bgid
					) );
				?>
				<a class="lgd-guide-game-chip" href="<?php echo esc_url( add_query_arg( 'guide_game', $bgid, $archive_url ) ); ?>">
					<?php if ( has_post_thumbnail() ) : ?>
					<span class="lgd-guide-game-chip__img"><?php the_post_thumbnail( 'thumbnail', array( 'loading' => 'lazy' ) ); ?></span>
					<?php endif; ?>
					<span class="lgd-guide-game-chip__name"><?php the_title(); ?></span>
					<span class="lgd-guide-game-chip__count"><?php echo esc_html( sprintf( _n( '%d guide', '%d guides', $gcount, 'legend-game-directory' ), $gcount ) ); ?></span>
				</a>
				<?php endwhile; wp_reset_postdata(); ?>
			</div>
		</section>
		<?php endif; endif; ?>

		<!-- ── TYPE SECTION ROWS ── -->
		<?php
		$section_type_slugs = array( 'beginner-guide', 'tips-tricks', 'strategy', 'walkthrough' );
		if ( ! is_wp_error( $guide_types ) ) :
			foreach ( $guide_types as $sterm ) :
				if ( ! in_array( $sterm->slug, $section_type_slugs, true ) ) { continue; }
				$sq = new WP_Query( array(
					'post_type'      => 'game_guide',
					'post_status'    => 'publish',
					'posts_per_page' => 3,
					'no_found_rows'  => true,
					'tax_query'      => array( array( 'taxonomy' => 'guide_type', 'field' => 'slug', 'terms' => $sterm->slug ) ),
				) );
				if ( ! $sq->have_posts() ) { continue; }
		?>
		<section class="lgd-guides-section">
			<div class="lgd-home-heading">
				<h2><?php echo esc_html( $sterm->name ); ?></h2>
				<a href="<?php echo esc_url( add_query_arg( 'guide_type', $sterm->slug, $archive_url ) ); ?>"><?php esc_html_e( 'See all', 'legend-game-directory' ); ?> &rarr;</a>
			</div>
			<div class="lgd-guide-grid">
				<?php while ( $sq->have_posts() ) : $sq->the_post(); $card( get_the_ID(), 'h3' ); endwhile; wp_reset_postdata(); ?>
			</div>
		</section>
		<?php endforeach; endif; ?>

		<!-- ── VIDEO GUIDES ROW ── -->
		<?php
		$video_q = new WP_Query( array(
			'post_type'      => 'game_guide',
			'post_status'    => 'publish',
			'posts_per_page' => 3,
			'no_found_rows'  => true,
			'meta_query'     => array( array( 'key' => '_lgd_guide_video_url', 'value' => '', 'compare' => '!=' ) ),
		) );
		if ( $video_q->have_posts() ) : ?>
		<section class="lgd-guides-section">
			<div class="lgd-home-heading">
				<h2>&#9654; <?php esc_html_e( 'Video Guides', 'legend-game-directory' ); ?></h2>
				<a href="<?php echo esc_url( add_query_arg( 'guide_has_video', '1', $archive_url ) ); ?>"><?php esc_html_e( 'See all', 'legend-game-directory' ); ?> &rarr;</a>
			</div>
			<div class="lgd-guide-grid">
				<?php while ( $video_q->have_posts() ) : $video_q->the_post(); $card( get_the_ID(), 'h3' ); endwhile; wp_reset_postdata(); ?>
			</div>
		</section>
		<?php endif; ?>

	<?php endif; // end !$has_filter ?>

	<!-- ── ALL GUIDES / FILTERED RESULTS ── -->
	<?php $found = (int) $wp_query->found_posts; ?>
	<section class="lgd-guides-section">
		<div class="lgd-home-heading">
			<h2>
				<?php if ( $has_filter ) :
					echo esc_html( sprintf( _n( '%s guide found', '%s guides found', $found, 'legend-game-directory' ), number_format_i18n( $found ) ) );
				else :
					esc_html_e( 'All Guides', 'legend-game-directory' );
				endif; ?>
			</h2>
		</div>

		<?php if ( have_posts() ) : ?>
		<div class="lgd-guide-grid">
			<?php while ( have_posts() ) : the_post(); $card( get_the_ID() ); endwhile; ?>
		</div>
		<div class="lgd-pagination">
			<?php the_posts_pagination( array(
				'mid_size'  => 2,
				'prev_text' => __( '&larr; Older', 'legend-game-directory' ),
				'next_text' => __( 'Newer &rarr;', 'legend-game-directory' ),
			) ); ?>
		</div>
		<?php else : ?>
		<p class="lgd-missing" style="padding:40px 0">
			<?php esc_html_e( 'No guides match your filters.', 'legend-game-directory' ); ?>
			<?php if ( $has_filter ) : ?>
			<a href="<?php echo esc_url( $archive_url ); ?>"><?php esc_html_e( 'Clear filters', 'legend-game-directory' ); ?></a>
			<?php endif; ?>
		</p>
		<?php endif; ?>
	</section>

	</div>
</main>
<?php get_footer(); ?>
