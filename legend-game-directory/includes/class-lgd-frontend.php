<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LGD_Frontend {
	public function __construct() {
		add_action( 'after_setup_theme', array( $this, 'images' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'template_include', array( $this, 'templates' ) );
		add_action( 'pre_get_posts', array( $this, 'filters' ) );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_shortcode( 'lgd_game_grid', array( $this, 'grid_shortcode' ) );
		add_shortcode( 'lgd_game_search', array( $this, 'search_shortcode' ) );
		add_shortcode( 'lgd_sources', array( $this, 'sources_shortcode' ) );
		add_shortcode( 'lgd_report_game', array( $this, 'report_shortcode' ) );
	}

	public function images() {
		add_image_size( 'lgd-card', 720, 405, true ); add_image_size( 'lgd-square', 640, 640, true ); add_image_size( 'lgd-thumb', 320, 180, true );
	}

	public function assets() {
		if ( is_front_page() || is_post_type_archive( 'game' ) || is_singular( 'game' ) || is_tax( array( 'game_type', 'game_platform', 'game_genre', 'game_pricing' ) ) ) {
			wp_enqueue_style( 'lgd-public', LGD_URL . 'assets/css/public.css', array(), LGD_VERSION );
			wp_enqueue_script( 'lgd-public', LGD_URL . 'assets/js/public.js', array(), LGD_VERSION, true );
			wp_localize_script( 'lgd-public', 'LGD', array( 'nonce' => wp_create_nonce( 'wp_rest' ), 'compareUrl' => add_query_arg( 'games', '', get_post_type_archive_link( 'game' ) ) . '#compare' ) );
		}
		if ( is_post_type_archive( 'game_guide' ) || is_singular( 'game_guide' ) || is_singular( 'game' ) ) {
			wp_enqueue_style( 'lgd-public', LGD_URL . 'assets/css/public.css', array(), LGD_VERSION );
			wp_enqueue_style( 'lgd-guides', LGD_URL . 'assets/css/guides.css', array( 'lgd-public' ), LGD_VERSION );
		}
		// Pages carrying our AJAX forms (contact / submit / newsletter) need the public assets too.
		if ( is_singular() && ! is_front_page() ) {
			$p = get_post();
			if ( $p && ( has_shortcode( $p->post_content, 'lgd_contact' ) || has_shortcode( $p->post_content, 'lgd_submit_game' ) || has_shortcode( $p->post_content, 'lgd_newsletter' ) ) ) {
				wp_enqueue_style( 'lgd-public', LGD_URL . 'assets/css/public.css', array(), LGD_VERSION );
				wp_enqueue_script( 'lgd-public', LGD_URL . 'assets/js/public.js', array(), LGD_VERSION, true );
				wp_localize_script( 'lgd-public', 'LGD', array( 'nonce' => wp_create_nonce( 'wp_rest' ), 'compareUrl' => add_query_arg( 'games', '', get_post_type_archive_link( 'game' ) ) . '#compare' ) );
			}
		}
		if ( is_admin() ) { wp_enqueue_style( 'lgd-admin', LGD_URL . 'assets/css/admin.css', array(), LGD_VERSION ); }
	}

	public function templates( $template ) {
		if ( is_singular( 'game' ) && false === strpos( wp_normalize_path( $template ), 'single-game.php' ) ) { return LGD_PATH . 'templates/single-game.php'; }
		if ( is_post_type_archive( 'game' ) && false === strpos( wp_normalize_path( $template ), 'archive-game.php' ) ) { return LGD_PATH . 'templates/archive-game.php'; }
		if ( is_singular( 'game_guide' ) && false === strpos( wp_normalize_path( $template ), 'single-game_guide.php' ) ) { return LGD_PATH . 'templates/single-game_guide.php'; }
		if ( is_post_type_archive( 'game_guide' ) && false === strpos( wp_normalize_path( $template ), 'archive-game_guide.php' ) ) { return LGD_PATH . 'templates/archive-game_guide.php'; }
		return $template;
	}

	public function filters( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) { return; }
		if ( $query->is_post_type_archive( 'game' ) || $query->is_tax( array( 'game_type', 'game_platform', 'game_genre', 'game_pricing' ) ) ) {
			$query->set( 'posts_per_page', 24 );
			$tax_query = array();
			foreach ( array( 'type' => 'game_type', 'platform' => 'game_platform', 'genre' => 'game_genre', 'pricing' => 'game_pricing' ) as $param => $taxonomy ) {
				if ( ! empty( $_GET[ $param ] ) ) { $tax_query[] = array( 'taxonomy' => $taxonomy, 'field' => 'slug', 'terms' => sanitize_title( wp_unslash( $_GET[ $param ] ) ) ); }
			}
			if ( $tax_query ) { $query->set( 'tax_query', $tax_query ); }
			if ( isset( $_GET['rating'] ) && is_numeric( $_GET['rating'] ) ) { $query->set( 'meta_query', array( array( 'key' => '_lgd_automated_score', 'value' => (float) $_GET['rating'], 'compare' => '>=', 'type' => 'NUMERIC' ) ) ); }
			if ( ! empty( $_GET['keyword'] ) ) { $query->set( 's', sanitize_text_field( wp_unslash( $_GET['keyword'] ) ) ); }
		}
		if ( $query->is_post_type_archive( 'game_guide' ) ) {
			$query->set( 'posts_per_page', 12 );
			if ( ! empty( $_GET['guide_type'] ) ) {
				$query->set( 'tax_query', array( array( 'taxonomy' => 'guide_type', 'field' => 'slug', 'terms' => sanitize_title( wp_unslash( $_GET['guide_type'] ) ) ) ) );
			}
		}
	}

	public function routes() {
		register_rest_route( 'lgd/v1', '/games/(?P<id>\d+)/report', array( 'methods' => 'POST', 'callback' => array( $this, 'report' ), 'permission_callback' => '__return_true' ) );
	}

	public function report( WP_REST_Request $request ) {
		$game_id = absint( $request['id'] ); if ( 'game' !== get_post_type( $game_id ) ) { return new WP_Error( 'lgd_game_missing', __( 'Game not found.', 'legend-game-directory' ), array( 'status' => 404 ) ); }
		if ( ! empty( $request['website'] ) ) { return new WP_Error( 'lgd_report_spam', __( 'Report rejected.', 'legend-game-directory' ), array( 'status' => 400 ) ); }
		$identity = is_user_logged_in() ? 'user:' . get_current_user_id() : 'ip:' . sanitize_text_field( isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '' );
		if ( ! LGD_Security::rate_limit( 'game-report:' . $identity, 5, DAY_IN_SECONDS ) ) { return new WP_Error( 'lgd_report_rate', __( 'Report limit reached.', 'legend-game-directory' ), array( 'status' => 429 ) ); }
		$reason = sanitize_textarea_field( $request['reason'] ); if ( strlen( $reason ) < 10 ) { return new WP_Error( 'lgd_report_short', __( 'Please provide enough detail to verify the correction.', 'legend-game-directory' ), array( 'status' => 400 ) ); }
		$flags = array_unique( array_merge( (array) get_post_meta( $game_id, '_lgd_mandatory_review_flags', true ), array( 'user_report' ) ) ); update_post_meta( $game_id, '_lgd_mandatory_review_flags', $flags );
		LGD_Logger::log( 'game_reported', $reason, array( 'email' => sanitize_email( $request['email'] ) ), 'warning', 'game', $game_id );
		return rest_ensure_response( array( 'reported' => true, 'message' => __( 'Thank you. The record was flagged for verification.', 'legend-game-directory' ) ) );
	}

	public function search_shortcode() {
		$taxes = array( 'type' => 'game_type', 'platform' => 'game_platform', 'genre' => 'game_genre', 'pricing' => 'game_pricing' ); ob_start(); ?>
		<form class="lgd-search" action="<?php echo esc_url( get_post_type_archive_link( 'game' ) ); ?>" method="get"><label><span><?php esc_html_e( 'Keyword', 'legend-game-directory' ); ?></span><input name="keyword" value="<?php echo isset( $_GET['keyword'] ) ? esc_attr( wp_unslash( $_GET['keyword'] ) ) : ''; ?>"></label>
		<?php foreach ( $taxes as $name => $taxonomy ) : ?><label><span><?php echo esc_html( ucfirst( $name ) ); ?></span><select name="<?php echo esc_attr( $name ); ?>"><option value=""><?php esc_html_e( 'Any', 'legend-game-directory' ); ?></option><?php foreach ( get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => true ) ) as $term ) : ?><option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( isset( $_GET[ $name ] ) ? sanitize_title( wp_unslash( $_GET[ $name ] ) ) : '', $term->slug ); ?>><?php echo esc_html( $term->name ); ?></option><?php endforeach; ?></select></label><?php endforeach; ?>
		<label><span><?php esc_html_e( 'Minimum rating', 'legend-game-directory' ); ?></span><select name="rating"><option value=""><?php esc_html_e( 'Any', 'legend-game-directory' ); ?></option><?php foreach ( array( 90, 80, 70, 60 ) as $rating ) : ?><option value="<?php echo esc_attr( $rating ); ?>"><?php echo esc_html( $rating . '+' ); ?></option><?php endforeach; ?></select></label><button type="submit"><?php esc_html_e( 'Find games', 'legend-game-directory' ); ?></button></form><?php return ob_get_clean();
	}

	public function grid_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'type' => '', 'platform' => '', 'genre' => '', 'pricing' => '', 'limit' => 6, 'orderby' => 'date' ), $atts );
		$args = array( 'post_type' => 'game', 'post_status' => 'publish', 'posts_per_page' => min( 24, absint( $atts['limit'] ) ) );
		$args = array_merge( $args, self::order_args( $atts['orderby'] ) ); $tax = array();
		foreach ( array( 'type' => 'game_type', 'platform' => 'game_platform', 'genre' => 'game_genre', 'pricing' => 'game_pricing' ) as $key => $taxonomy ) { if ( $atts[ $key ] ) { $tax[] = array( 'taxonomy' => $taxonomy, 'field' => 'slug', 'terms' => sanitize_title( $atts[ $key ] ) ); } }
		if ( $tax ) { $args['tax_query'] = $tax; } $games = new WP_Query( $args ); ob_start(); echo '<div class="lgd-grid">'; if ( $games->have_posts() ) { while ( $games->have_posts() ) { $games->the_post(); echo self::card( get_the_ID() ); } } else { echo '<p class="lgd-missing">' . esc_html__( 'No verified games are published in this section yet.', 'legend-game-directory' ) . '</p>'; } echo '</div>'; wp_reset_postdata(); return ob_get_clean();
	}

	private static function order_args( $orderby ) {
		switch ( sanitize_key( $orderby ) ) {
			case 'score': return array( 'meta_key' => '_lgd_automated_score', 'orderby' => 'meta_value_num', 'order' => 'DESC' );
			case 'community': return array( 'meta_key' => '_lgd_community_score', 'orderby' => 'meta_value_num', 'order' => 'DESC' );
			case 'verified': return array( 'meta_key' => '_lgd_last_verified', 'orderby' => 'meta_value', 'order' => 'DESC' );
			case 'release': return array( 'meta_key' => '_lgd_release_date', 'orderby' => 'meta_value', 'order' => 'DESC' );
			case 'title': return array( 'orderby' => 'title', 'order' => 'ASC' );
			default: return array( 'orderby' => 'date', 'order' => 'DESC' );
		}
	}

	public static function card( $id ) {
		$score = get_post_meta( $id, '_lgd_automated_score', true ); $types = wp_get_post_terms( $id, 'game_type', array( 'fields' => 'names' ) );
		$thumb = get_the_post_thumbnail( $id, 'lgd-card', array( 'loading' => 'lazy' ) );
		if ( ! $thumb ) {
			$screens = get_post_meta( $id, '_lgd_official_screenshots', true );
			if ( is_array( $screens ) && ! empty( $screens[0] ) ) {
				$thumb = '<img src="' . esc_url( $screens[0] ) . '" alt="' . esc_attr( get_the_title( $id ) ) . '" loading="lazy" width="720" height="405">';
			}
		}
		if ( ! $thumb ) {
			// No real artwork available — render a branded title tile so the card never looks broken.
			$title = get_the_title( $id );
			$hue   = (int) round( hexdec( substr( md5( $title ), 0, 2 ) ) * 360 / 255 );
			$thumb = '<span class="lgd-card-ph" style="--lgd-h:' . esc_attr( $hue ) . '">' . esc_html( $title ) . '</span>';
		}
		ob_start(); ?>
		<article class="lgd-card"><a class="lgd-card-image" href="<?php echo esc_url( get_permalink( $id ) ); ?>"><?php echo $thumb; // already escaped ?></a><div class="lgd-card-body"><div class="lgd-badges"><?php foreach ( $types as $type ) : ?><span><?php echo esc_html( str_replace( ' Games', '', $type ) ); ?></span><?php endforeach; ?></div><h3><a href="<?php echo esc_url( get_permalink( $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a></h3><p><?php echo esc_html( wp_trim_words( get_the_excerpt( $id ), 20 ) ); ?></p><div class="lgd-card-footer"><span class="lgd-score <?php echo '' === (string) $score ? 'is-missing' : ''; ?>"><?php echo '' === (string) $score ? esc_html__( 'Not scored', 'legend-game-directory' ) : esc_html( round( $score ) ); ?></span><?php if ( is_user_logged_in() ) : ?><label><input type="checkbox" class="lgd-compare-choice" value="<?php echo esc_attr( $id ); ?>"> <?php esc_html_e( 'Compare', 'legend-game-directory' ); ?></label><?php endif; ?></div></div></article><?php return ob_get_clean();
	}

	public function sources_shortcode( $atts ) {
		global $wpdb; $atts = shortcode_atts( array( 'game_id' => get_the_ID() ), $atts ); $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT provider,source_url,retrieved_at,confidence,status FROM ' . LGD_Database::table( 'sources' ) . ' WHERE game_id=%d ORDER BY retrieved_at DESC', absint( $atts['game_id'] ) ), ARRAY_A );
		if ( ! $rows ) { return '<p class="lgd-missing">' . esc_html__( 'No verified sources have been stored yet.', 'legend-game-directory' ) . '</p>'; }
		ob_start(); ?><ul class="lgd-sources"><?php foreach ( $rows as $row ) : ?><li><a rel="nofollow noopener" target="_blank" href="<?php echo esc_url( $row['source_url'] ); ?>"><?php echo esc_html( ucwords( str_replace( '_', ' ', $row['provider'] ) ) ); ?></a><span><?php echo esc_html( sprintf( __( 'Retrieved %1$s · confidence %2$s%% · %3$s', 'legend-game-directory' ), $row['retrieved_at'], $row['confidence'], $row['status'] ) ); ?></span></li><?php endforeach; ?></ul><?php return ob_get_clean();
	}

	public function report_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'game_id' => get_the_ID() ), $atts ); ob_start(); ?><form class="lgd-report-form" data-endpoint="<?php echo esc_url( rest_url( 'lgd/v1/games/' . absint( $atts['game_id'] ) . '/report' ) ); ?>"><input class="lgd-honeypot" name="website" tabindex="-1" autocomplete="off"><label><?php esc_html_e( 'What is incorrect?', 'legend-game-directory' ); ?><textarea name="reason" minlength="10" maxlength="2000" required></textarea></label><label><?php esc_html_e( 'Email (optional)', 'legend-game-directory' ); ?><input name="email" type="email"></label><button><?php esc_html_e( 'Report incorrect information', 'legend-game-directory' ); ?></button><p class="lgd-form-status" aria-live="polite"></p></form><?php return ob_get_clean();
	}
}
