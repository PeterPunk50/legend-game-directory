<?php
/**
 * Child theme footer for Legend Create.
 *
 * Identical structure to the BizDirectory parent footer, but the ".site-info"
 * credit ("Powered By WordPress | Bizdirectory") is replaced with the site's
 * own copyright line and policy links.
 *
 * @package bizdirectory-child
 */

?>

	<footer id="colophon" class="site-footer">
		<div class="prefooter">
			<div class="container">
				<div class="row">
					<div class="col-md-12">
					<?php if ( is_active_sidebar( 'bizdirectory_footer_1' ) ) : ?>
						<div class="col-md-4">
							<?php dynamic_sidebar( 'bizdirectory_footer_1' ); ?>
						</div>
						<?php
					else : bizdirectory_blank_widget();
					endif; ?>
					<?php if ( is_active_sidebar( 'bizdirectory_footer_2' ) ) : ?>
						<div class="col-md-4">
							<?php dynamic_sidebar( 'bizdirectory_footer_2' ); ?>
						</div>
						<?php
					else : bizdirectory_blank_widget();
					endif; ?>
					<?php if ( is_active_sidebar( 'bizdirectory_footer_3' ) ) : ?>
						<div class="col-md-4">
							<?php dynamic_sidebar( 'bizdirectory_footer_3' ); ?>
						</div>
						<?php
					else : bizdirectory_blank_widget();
					endif; ?>
				</div>
			</div>
		</div>
		</div>
			<div class="site-info">
				<div class="container">
					<div class="row">
						<div class="col-md-12">
							<p class="lgd-footer-copy">
								&copy; <?php echo esc_html( date_i18n( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?>. <?php esc_html_e( 'All rights reserved.', 'bizdirectory-child' ); ?>
							</p>
							<p class="lgd-footer-links">
								<a href="<?php echo esc_url( home_url( '/about' ) ); ?>"><?php esc_html_e( 'About', 'bizdirectory-child' ); ?></a>
								<a href="<?php echo esc_url( home_url( '/editorial-policy' ) ); ?>"><?php esc_html_e( 'Editorial Policy', 'bizdirectory-child' ); ?></a>
								<a href="<?php echo esc_url( home_url( '/rating-methodology' ) ); ?>"><?php esc_html_e( 'Rating Methodology', 'bizdirectory-child' ); ?></a>
								<a href="<?php echo esc_url( home_url( '/ai-disclosure' ) ); ?>"><?php esc_html_e( 'AI Disclosure', 'bizdirectory-child' ); ?></a>
								<a href="<?php echo esc_url( home_url( '/affiliate-disclosure' ) ); ?>"><?php esc_html_e( 'Affiliate Disclosure', 'bizdirectory-child' ); ?></a>
								<a href="<?php echo esc_url( home_url( '/privacy-policy' ) ); ?>"><?php esc_html_e( 'Privacy', 'bizdirectory-child' ); ?></a>
								<a href="<?php echo esc_url( home_url( '/terms-of-service' ) ); ?>"><?php esc_html_e( 'Terms', 'bizdirectory-child' ); ?></a>
								<a href="<?php echo esc_url( home_url( '/contact' ) ); ?>"><?php esc_html_e( 'Contact', 'bizdirectory-child' ); ?></a>
							</p>
						</div>
					</div>
				</div>
			</div><!-- .site-info -->
	</footer><!-- #colophon -->
</div><!-- #page -->

<?php wp_footer(); ?>

</body>
</html>
