<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header();
$create = lcc_squad_create_page();
?>
<main class="lcc-shell" style="padding:30px 0">
	<div class="lcc-container" style="width:min(1080px,calc(100% - 32px));margin:auto">
		<div class="lcc-dash-head">
			<div>
				<h1><?php esc_html_e( 'Squads', 'legendcreate-community' ); ?></h1>
				<p class="lcc-muted"><?php esc_html_e( 'Bring your crew or join an open squad and play the games you already love together.', 'legendcreate-community' ); ?></p>
			</div>
			<?php if ( $create && is_user_logged_in() ) : ?>
				<a class="lcc-btn" href="<?php echo esc_url( get_permalink( $create ) ); ?>"><?php esc_html_e( 'Create a squad', 'legendcreate-community' ); ?></a>
			<?php elseif ( $create ) : ?>
				<a class="lcc-btn" href="<?php echo esc_url( wp_login_url( get_permalink( $create ) ) ); ?>"><?php esc_html_e( 'Log in to create', 'legendcreate-community' ); ?></a>
			<?php endif; ?>
		</div>

		<div class="lcc-squad-grid">
			<?php if ( have_posts() ) : ?>
				<?php while ( have_posts() ) : the_post();
					if ( 'private' === LCC_Squads::visibility( get_the_ID() ) ) { continue; }
					echo LCC_Squads::card( get_the_ID() );
				endwhile; ?>
			<?php else : ?>
				<p class="lcc-muted"><?php esc_html_e( 'No public squads yet. Be the first to create one!', 'legendcreate-community' ); ?></p>
			<?php endif; ?>
		</div>

		<div class="lcc-pagination"><?php the_posts_pagination( array( 'mid_size' => 2 ) ); ?></div>
	</div>
</main>
<?php get_footer(); ?>
