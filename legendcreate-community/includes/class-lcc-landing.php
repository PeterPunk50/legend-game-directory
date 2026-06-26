<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Game-specific acquisition landing pages (Fortnite, CoD, CS, Apex).
 *
 * Companion positioning — "bring your squad, keep playing the game you love."
 * Nominative use only: no logos, no implied partnership, explicit not-affiliated
 * disclaimer. Surfaces guides (by game name) + squads (by game) + a free-join CTA,
 * all degrading gracefully to "coming soon" until content exists.
 *
 * Shortcode: [lcc_game_landing game="Fortnite"]
 */
final class LCC_Landing {

	public function __construct() {
		add_shortcode( 'lcc_game_landing', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function assets() {
		if ( is_singular() && get_post() && has_shortcode( get_post()->post_content, 'lcc_game_landing' ) ) {
			wp_enqueue_style( 'lcc-community', LCC_URL . 'assets/css/community.css', array(), LCC_VERSION );
		}
	}

	public function render( $atts ) {
		$atts = shortcode_atts( array( 'game' => '' ), $atts );
		$game = sanitize_text_field( $atts['game'] );
		if ( '' === $game ) { return ''; }

		$join   = (int) get_option( 'lcc_page_register', 0 );
		$joinurl = $join ? get_permalink( $join ) : home_url( '/join/' );
		$create = function_exists( 'lcc_squad_create_page' ) ? lcc_squad_create_page() : 0;

		ob_start();
		echo '<div class="lcc-shell">';

		// Hero.
		echo '<section class="lcc-landing-hero">';
		echo '<h1>' . esc_html( sprintf( __( 'Playing %s? Bring your squad.', 'legendcreate-community' ), $game ) ) . '</h1>';
		echo '<p class="lcc-landing-lead">' . esc_html( sprintf( __( 'Keep playing %s with the friends you already game with. Use LegendCreate for guides, squad challenges, community votes, and earned status — no switching games, no leaving your crew.', 'legendcreate-community' ), $game ) ) . '</p>';
		echo '<div class="lcc-landing-cta">';
		echo '<a class="lcc-btn lcc-btn-lg" href="' . esc_url( $joinurl ) . '">' . esc_html__( 'Join free', 'legendcreate-community' ) . '</a> ';
		echo '<a class="lcc-btn lcc-btn-ghost" href="' . esc_url( get_post_type_archive_link( 'lc_squad' ) ) . '">' . esc_html__( 'Browse squads', 'legendcreate-community' ) . '</a>';
		echo '</div>';
		echo '<p class="lcc-disclaimer">' . esc_html( sprintf( __( 'LegendCreate is an independent community and is not affiliated with, endorsed by, or sponsored by %s or its publisher. All game names and trademarks belong to their respective owners.', 'legendcreate-community' ), $game ) ) . '</p>';
		echo '</section>';

		// Guides for this game.
		echo '<section class="lcc-landing-section"><div class="lcc-home-heading"><h2>' . esc_html( sprintf( __( '%s Guides', 'legendcreate-community' ), $game ) ) . '</h2></div>';
		echo $this->guides_html( $game );
		echo '</section>';

		// Polls for this game.
		if ( class_exists( 'LCC_Polls' ) ) {
			$polls = LCC_Polls::for_game( $game, 3 );
			if ( $polls ) {
				echo '<section class="lcc-landing-section"><div class="lcc-home-heading"><h2>' . esc_html( sprintf( __( '%s Community Polls', 'legendcreate-community' ), $game ) ) . '</h2></div>' . $polls . '</section>';
			}
		}

		// Squads playing this game.
		echo '<section class="lcc-landing-section"><div class="lcc-home-heading"><h2>' . esc_html( sprintf( __( '%s Squads', 'legendcreate-community' ), $game ) ) . '</h2>';
		if ( $create ) {
			$href = is_user_logged_in() ? get_permalink( $create ) : wp_login_url( get_permalink( $create ) );
			echo '<a class="lcc-link" href="' . esc_url( $href ) . '">' . esc_html__( 'Create a squad', 'legendcreate-community' ) . '</a>';
		}
		echo '</div>';
		echo $this->squads_html( $game );
		echo '</section>';

		// What you can do.
		echo '<section class="lcc-landing-section lcc-panel"><h2>' . esc_html__( 'What you can do here', 'legendcreate-community' ) . '</h2><ul class="lcc-landing-list">';
		foreach ( array(
			sprintf( __( 'Rate the current %s season and vote in community polls', 'legendcreate-community' ), $game ),
			__( 'Share loadouts, settings, and strategy with your squad', 'legendcreate-community' ),
			__( 'Take on weekly squad challenges and climb the contributor ranks', 'legendcreate-community' ),
			__( 'Test new free, indie, and mobile games between matches', 'legendcreate-community' ),
		) as $item ) {
			echo '<li>' . esc_html( $item ) . '</li>';
		}
		echo '</ul><a class="lcc-btn" href="' . esc_url( $joinurl ) . '">' . esc_html__( 'Create your free account', 'legendcreate-community' ) . '</a></section>';

		echo '</div>';
		return ob_get_clean();
	}

	private function guides_html( $game ) {
		if ( ! post_type_exists( 'game_guide' ) ) {
			return '<p class="lcc-muted">' . esc_html__( 'Guides are coming soon.', 'legendcreate-community' ) . '</p>';
		}
		$guides = get_posts( array(
			'post_type'      => 'game_guide',
			'post_status'    => 'publish',
			'posts_per_page' => 6,
			'meta_query'     => array( array( 'key' => '_lgd_guide_game_name', 'value' => $game, 'compare' => '=' ) ),
		) );
		if ( ! $guides ) {
			return '<p class="lcc-muted">' . esc_html( sprintf( __( 'No %s guides yet — be the first to contribute one.', 'legendcreate-community' ), $game ) ) . '</p>';
		}
		$out = '<div class="lcc-landing-guides">';
		foreach ( $guides as $g ) {
			$out .= '<a class="lcc-guide-card" href="' . esc_url( get_permalink( $g ) ) . '">'
				. '<h4>' . esc_html( get_the_title( $g ) ) . '</h4>'
				. '<p>' . esc_html( wp_trim_words( get_the_excerpt( $g ), 18 ) ) . '</p></a>';
		}
		return $out . '</div>';
	}

	private function squads_html( $game ) {
		if ( ! post_type_exists( 'lc_squad' ) ) {
			return '<p class="lcc-muted">' . esc_html__( 'Squads are coming soon.', 'legendcreate-community' ) . '</p>';
		}
		$squads = get_posts( array(
			'post_type'      => 'lc_squad',
			'post_status'    => 'publish',
			'posts_per_page' => 6,
			'meta_query'     => array(
				array( 'key' => '_lcc_squad_visibility', 'value' => 'public' ),
				array( 'key' => '_lcc_squad_games', 'value' => $game, 'compare' => 'LIKE' ),
			),
		) );
		if ( ! $squads ) {
			return '<p class="lcc-muted">' . esc_html( sprintf( __( 'No public %s squads yet — start one and invite your crew.', 'legendcreate-community' ), $game ) ) . '</p>';
		}
		$out = '<div class="lcc-squad-grid">';
		foreach ( $squads as $s ) { $out .= LCC_Squads::card( $s->ID ); }
		return $out . '</div>';
	}
}
