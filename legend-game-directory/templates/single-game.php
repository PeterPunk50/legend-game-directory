<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header(); the_post(); $id = get_the_ID();
$types  = wp_get_post_terms( $id, 'game_type', array( 'fields' => 'names' ) );
$stores = array( '_lgd_steam_url' => 'Steam', '_lgd_google_play_url' => 'Google Play', '_lgd_apple_app_store_url' => 'App Store', '_lgd_itch_url' => 'itch.io', '_lgd_official_website' => 'Official Website' );
$scores = array( '_lgd_automated_score' => 'Legend Automated Score', '_lgd_editorial_score' => 'Legend Editorial Score', '_lgd_community_score' => 'Community Score', '_lgd_external_critic_score' => 'External Critic Score', '_lgd_external_user_score' => 'External User Score', '_lgd_steam_sentiment' => 'Store Sentiment' );
$facts  = array( '_lgd_developer' => 'Developer', '_lgd_publisher' => 'Publisher', '_lgd_release_date' => 'Release Date', '_lgd_last_update_date' => 'Last Update', '_lgd_current_price' => 'Current Price', '_lgd_free_type' => 'Pricing Type', '_lgd_in_app_purchases' => 'In-app Purchases', '_lgd_advertising' => 'Advertising', '_lgd_multiplayer' => 'Multiplayer', '_lgd_online_requirement' => 'Internet Requirement', '_lgd_offline_support' => 'Offline Support', '_lgd_controller_support' => 'Controller Support', '_lgd_age_rating' => 'Age Rating', '_lgd_last_verified' => 'Last Verified' );
$grade  = get_post_meta( $id, '_lgd_monetization_grade', true ) ?: 'Pending';
?>
<main class="lgd-shell">
	<section class="lgd-game-hero"><div class="lgd-container lgd-game-hero-grid"><div>
		<div class="lgd-badges"><?php foreach ( $types as $type ) : ?><span><?php echo esc_html( $type ); ?></span><?php endforeach; ?></div>
		<h1><?php the_title(); ?></h1>
		<p class="lgd-lead"><?php echo esc_html( get_post_meta( $id, '_lgd_short_description', true ) ?: get_the_excerpt() ); ?></p>
		<div class="lgd-store-links"><?php foreach ( $stores as $meta => $label ) : $url = get_post_meta( $id, $meta, true ); if ( $url ) : ?><a class="lgd-button" rel="nofollow noopener" target="_blank" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a><?php endif; endforeach; ?></div>
	</div><div class="lgd-game-image"><?php if ( has_post_thumbnail() ) { the_post_thumbnail( 'large' ); } ?></div></div></section>

	<div class="lgd-container">

		<section class="lgd-score-board" aria-label="<?php esc_attr_e( 'Separate rating types', 'legend-game-directory' ); ?>">
		<?php foreach ( $scores as $meta => $label ) :
			$value = get_post_meta( $id, $meta, true );
			$empty = '' === (string) $value;
		?>
			<div class="lgd-score-box">
				<?php if ( '_lgd_automated_score' === $meta && $empty ) : ?>
					<strong class="lgd-rating-pending"><?php esc_html_e( 'Rating Pending', 'legend-game-directory' ); ?></strong>
				<?php else : ?>
					<strong><?php echo $empty ? esc_html__( '—', 'legend-game-directory' ) : esc_html( $value ); ?></strong>
				<?php endif; ?>
				<span><?php echo esc_html( $label ); ?></span>
			</div>
		<?php endforeach; ?>
		</section>

		<?php
		$confidence  = get_post_meta( $id, '_lgd_confidence', true );
		$source_count = get_post_meta( $id, '_lgd_source_count', true );
		$missing     = (array) get_post_meta( $id, '_lgd_missing_data', true );
		$conf_label  = LGD_Monetization::confidence_label( $confidence );
		?>
		<p><?php echo esc_html( sprintf(
			__( 'Score confidence: %s%% (%s) · %s stored sources', 'legend-game-directory' ),
			'' === (string) $confidence ? 0 : $confidence,
			$conf_label,
			$source_count ?: 0
		) ); ?></p>
		<?php if ( $missing ) : ?><p class="lgd-missing"><?php echo esc_html( sprintf( __( 'Missing data: %s. Missing evidence does not receive positive points.', 'legend-game-directory' ), implode( ', ', array_map( function( $item ) { return str_replace( '_', ' ', $item ); }, $missing ) ) ) ); ?></p><?php endif; ?>

		<section class="lgd-section lgd-panel lgd-monetization-panel" aria-label="<?php esc_attr_e( 'Monetization Grade', 'legend-game-directory' ); ?>">
			<h2><?php esc_html_e( 'Monetization Grade', 'legend-game-directory' ); ?></h2>
			<div class="lgd-grade-display">
				<span class="lgd-grade-badge <?php echo esc_attr( LGD_Monetization::css_class( $grade ) ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Monetization grade: %s', 'legend-game-directory' ), $grade ) ); ?>"><?php echo esc_html( $grade ); ?></span>
				<div class="lgd-grade-info">
					<strong><?php echo esc_html( LGD_Monetization::label( $grade ) ); ?></strong>
					<p><?php echo esc_html( LGD_Monetization::description( $grade ) ); ?></p>
					<?php $last_ver = get_post_meta( $id, '_lgd_verified_monetization_check', true ) ?: get_post_meta( $id, '_lgd_last_verified', true ); if ( $last_ver ) : ?>
					<p class="lgd-muted" style="font-size:.85rem"><?php echo esc_html( sprintf( __( 'Last checked: %s', 'legend-game-directory' ), $last_ver ) ); ?></p>
					<?php endif; ?>
				</div>
			</div>
		</section>

		<section class="lgd-section lgd-two-col">
			<article class="lgd-panel">
				<h2><?php esc_html_e( 'Overview', 'legend-game-directory' ); ?></h2>
				<div class="lgd-overview-body"><?php the_content(); ?></div>
				<?php $best = get_post_meta( $id, '_lgd_best_for', true ); if ( $best ) : ?><h3><?php esc_html_e( 'Best for', 'legend-game-directory' ); ?></h3><p><?php echo esc_html( $best ); ?></p><?php endif; ?>
			</article>
			<aside class="lgd-facts">
				<?php foreach ( $facts as $meta => $label ) :
					$value = get_post_meta( $id, $meta, true );
					$display = '' === (string) $value ? esc_html__( 'Unknown', 'legend-game-directory' ) : esc_html( is_array( $value ) ? implode( ', ', $value ) : $value );
				?>
				<div class="lgd-fact">
					<span><?php echo esc_html( $label ); ?></span>
					<strong>
						<?php echo $display; // already escaped ?>
						<?php if ( '_lgd_last_verified' === $meta && '' !== (string) $value ) :
							$fs = LGD_Monetization::freshness( $id );
						?><br><span class="lgd-freshness lgd-fresh-<?php echo esc_attr( $fs ); ?>"><?php echo esc_html( LGD_Monetization::freshness_label( $fs ) ); ?></span>
						<?php endif; ?>
					</strong>
				</div>
				<?php endforeach; ?>
			</aside>
		</section>

		<?php $breakdown = get_post_meta( $id, '_lgd_score_breakdown', true ); if ( is_array( $breakdown ) ) : ?>
		<section class="lgd-section lgd-panel">
			<h2><?php esc_html_e( 'Automated Score Breakdown', 'legend-game-directory' ); ?></h2>
			<?php foreach ( $breakdown as $key => $part ) : $value = isset( $part['score'] ) ? $part['score'] : null; ?>
			<div class="lgd-breakdown-row">
				<span><?php echo esc_html( ucwords( str_replace( '_', ' ', $key ) ) ); ?></span>
				<div class="lgd-bar"><i style="width:<?php echo esc_attr( null === $value ? 0 : min( 100, max( 0, $value ) ) ); ?>%"></i></div>
				<strong><?php echo null === $value ? esc_html__( 'Missing', 'legend-game-directory' ) : esc_html( $value ); ?></strong>
			</div>
			<?php endforeach; ?>
		</section>
		<?php endif; ?>

		<section class="lgd-section lgd-two-col">
			<div class="lgd-panel"><h2><?php esc_html_e( 'Pros', 'legend-game-directory' ); ?></h2><ul><?php foreach ( (array) get_post_meta( $id, '_lgd_pros', true ) as $item ) : if ( $item ) : ?><li><?php echo esc_html( $item ); ?></li><?php endif; endforeach; ?></ul></div>
			<div class="lgd-panel"><h2><?php esc_html_e( 'Cons', 'legend-game-directory' ); ?></h2><ul><?php foreach ( (array) get_post_meta( $id, '_lgd_cons', true ) as $item ) : if ( $item ) : ?><li><?php echo esc_html( $item ); ?></li><?php endif; endforeach; ?></ul></div>
		</section>

		<?php $screens = (array) get_post_meta( $id, '_lgd_official_screenshots', true ); if ( array_filter( $screens ) ) : ?>
		<section class="lgd-section">
			<h2><?php esc_html_e( 'Official Screenshots', 'legend-game-directory' ); ?></h2>
			<div class="lgd-grid"><?php foreach ( $screens as $screen ) : if ( ! $screen ) { continue; } ?><figure class="lgd-card"><img loading="lazy" src="<?php echo esc_url( $screen ); ?>" alt="<?php echo esc_attr( sprintf( __( 'Official screenshot for %s', 'legend-game-directory' ), get_the_title() ) ); ?>"></figure><?php endforeach; ?></div>
		</section>
		<?php endif; ?>

		<section class="lgd-section lgd-two-col">
			<div class="lgd-panel"><h2><?php esc_html_e( 'Community Review', 'legend-game-directory' ); ?></h2><?php echo do_shortcode( '[lgd_review_form game_id="' . $id . '"]' ); ?></div>
			<div class="lgd-panel"><h2><?php esc_html_e( 'Sources and Freshness', 'legend-game-directory' ); ?></h2><?php echo do_shortcode( '[lgd_sources game_id="' . $id . '"]' ); ?></div>
		</section>

		<section class="lgd-section"><h2><?php esc_html_e( 'Similar Games', 'legend-game-directory' ); ?></h2><?php $genres = wp_get_post_terms( $id, 'game_genre', array( 'fields' => 'slugs' ) ); echo do_shortcode( '[lgd_game_grid limit="3" genre="' . esc_attr( isset( $genres[0] ) ? $genres[0] : '' ) . '"]' ); ?></section>

		<?php
		$game_guides = new WP_Query( array(
			'post_type'      => 'game_guide',
			'post_status'    => 'publish',
			'posts_per_page' => 3,
			'meta_query'     => array( array( 'key' => '_lgd_guide_game_id', 'value' => $id, 'type' => 'NUMERIC' ) ),
		) );
		if ( $game_guides->have_posts() ) : ?>
		<section class="lgd-section">
			<div class="lgd-home-heading">
				<h2><?php esc_html_e( 'Guides for This Game', 'legend-game-directory' ); ?></h2>
				<a href="<?php echo esc_url( get_post_type_archive_link( 'game_guide' ) ); ?>"><?php esc_html_e( 'All guides', 'legend-game-directory' ); ?> &rarr;</a>
			</div>
			<div class="lgd-game-guides-grid">
				<?php while ( $game_guides->have_posts() ) : $game_guides->the_post();
					$guide_types = wp_get_post_terms( get_the_ID(), 'guide_type', array( 'fields' => 'names' ) );
					$read_time   = (int) get_post_meta( get_the_ID(), '_lgd_guide_reading_time', true );
					$g_diff      = get_post_meta( get_the_ID(), '_lgd_guide_difficulty', true );
				?>
				<a class="lgd-game-guides-card" href="<?php the_permalink(); ?>">
					<?php if ( ! empty( $guide_types[0] ) ) : ?><span class="lgd-game-guides-card__type"><?php echo esc_html( $guide_types[0] ); ?></span><?php endif; ?>
					<p class="lgd-game-guides-card__title"><?php the_title(); ?></p>
					<span class="lgd-game-guides-card__meta">
						<?php if ( $g_diff ) : ?><?php echo esc_html( $g_diff ); ?><?php endif; ?>
						<?php if ( $read_time ) : ?> &middot; <?php echo esc_html( $read_time . ' min' ); ?><?php endif; ?>
					</span>
				</a>
				<?php endwhile; wp_reset_postdata(); ?>
			</div>
		</section>
		<?php endif; ?>

		<section class="lgd-section lgd-panel"><h2><?php esc_html_e( 'Report Incorrect Information', 'legend-game-directory' ); ?></h2><?php echo do_shortcode( '[lgd_report_game game_id="' . $id . '"]' ); ?></section>

		<?php if ( comments_open() || get_comments_number() ) : ?>
		<section id="lgd-comments" class="lgd-section lgd-comments"><?php comments_template(); ?></section>
		<?php endif; ?>

	</div>
</main>
<?php get_footer(); ?>
