<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Community polls.
 *
 * Polls are lc_poll posts (admin-managed) with options + optional linked game.
 * Members vote once each (enforced by a UNIQUE key); visitors see results and a
 * join CTA. Voting awards contribution points.
 *
 * Shortcodes: [lcc_poll id="X"], [lcc_polls].
 */
final class LCC_Polls {

	const CPT = 'lc_poll';

	public function __construct() {
		add_action( 'init', array( __CLASS__, 'register_cpt' ) );
		add_action( 'add_meta_boxes', array( $this, 'meta_box' ) );
		add_action( 'save_post_' . self::CPT, array( $this, 'save_meta' ), 10, 2 );
		add_shortcode( 'lcc_poll', array( $this, 'poll_shortcode' ) );
		add_shortcode( 'lcc_polls', array( $this, 'polls_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_lcc_poll_vote', array( $this, 'handle_vote' ) );
	}

	public static function register_cpt() {
		register_post_type( self::CPT, array(
			'labels'             => array( 'name' => __( 'Polls', 'legendcreate-community' ), 'singular_name' => __( 'Poll', 'legendcreate-community' ) ),
			'public'             => false,
			'show_ui'            => true,
			'publicly_queryable' => false,
			'menu_icon'          => 'dashicons-chart-bar',
			'supports'           => array( 'title' ),
		) );
	}

	public function assets() {
		if ( is_singular() && get_post() && ( has_shortcode( get_post()->post_content, 'lcc_poll' ) || has_shortcode( get_post()->post_content, 'lcc_polls' ) || has_shortcode( get_post()->post_content, 'lcc_game_landing' ) ) ) {
			wp_enqueue_style( 'lcc-community', LCC_URL . 'assets/css/community.css', array(), LCC_VERSION );
		}
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lcc_poll_votes';
	}

	// ── Meta ─────────────────────────────────────────────────────────────────────

	public static function options( $poll_id ) {
		$o = get_post_meta( $poll_id, '_lcc_poll_options', true );
		return is_array( $o ) ? array_values( array_filter( $o ) ) : array();
	}

	public static function is_closed( $poll_id ) {
		return (bool) get_post_meta( $poll_id, '_lcc_poll_closed', true );
	}

	public static function game( $poll_id ) {
		return (string) get_post_meta( $poll_id, '_lcc_poll_game', true );
	}

	public function meta_box() {
		add_meta_box( 'lcc_poll_settings', __( 'Poll Settings', 'legendcreate-community' ), array( $this, 'render_box' ), self::CPT, 'normal', 'high' );
	}

	public function render_box( $post ) {
		wp_nonce_field( 'lcc_poll_meta_' . $post->ID, 'lcc_poll_meta_nonce' );
		$opts   = implode( "\n", self::options( $post->ID ) );
		$game   = self::game( $post->ID );
		$closed = self::is_closed( $post->ID );
		echo '<p><label><strong>' . esc_html__( 'Options (one per line)', 'legendcreate-community' ) . '</strong></label>';
		echo '<textarea name="lcc_poll_options" rows="5" class="large-text" placeholder="Option A&#10;Option B&#10;Option C">' . esc_textarea( $opts ) . '</textarea></p>';
		echo '<p><label><strong>' . esc_html__( 'Linked game (optional — surfaces on that landing page)', 'legendcreate-community' ) . '</strong></label>';
		echo '<input type="text" name="lcc_poll_game" value="' . esc_attr( $game ) . '" class="regular-text" placeholder="Fortnite"></p>';
		echo '<p><label><input type="checkbox" name="lcc_poll_closed" value="1" ' . checked( $closed, true, false ) . '> ' . esc_html__( 'Closed (no more voting; show results only)', 'legendcreate-community' ) . '</label></p>';
	}

	public function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST['lcc_poll_meta_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['lcc_poll_meta_nonce'] ), 'lcc_poll_meta_' . $post_id ) ) { return; }
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

		if ( isset( $_POST['lcc_poll_options'] ) ) {
			$opts = array_values( array_filter( array_map( 'sanitize_text_field', preg_split( '/\n+/', wp_unslash( $_POST['lcc_poll_options'] ) ) ) ) );
			update_post_meta( $post_id, '_lcc_poll_options', array_slice( $opts, 0, 12 ) );
		}
		update_post_meta( $post_id, '_lcc_poll_game', isset( $_POST['lcc_poll_game'] ) ? sanitize_text_field( wp_unslash( $_POST['lcc_poll_game'] ) ) : '' );
		update_post_meta( $post_id, '_lcc_poll_closed', empty( $_POST['lcc_poll_closed'] ) ? 0 : 1 );
	}

	// ── Votes ────────────────────────────────────────────────────────────────────

	public static function has_voted( $poll_id, $user_id ) {
		global $wpdb;
		return null !== $wpdb->get_var( $wpdb->prepare(
			'SELECT option_idx FROM ' . self::table() . ' WHERE poll_id=%d AND user_id=%d', $poll_id, $user_id
		) );
	}

	public static function user_vote( $poll_id, $user_id ) {
		global $wpdb;
		$v = $wpdb->get_var( $wpdb->prepare( 'SELECT option_idx FROM ' . self::table() . ' WHERE poll_id=%d AND user_id=%d', $poll_id, $user_id ) );
		return null === $v ? -1 : (int) $v;
	}

	public static function tallies( $poll_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT option_idx, COUNT(*) AS n FROM ' . self::table() . ' WHERE poll_id=%d GROUP BY option_idx', $poll_id
		), OBJECT_K );
		$counts = array();
		$total  = 0;
		foreach ( self::options( $poll_id ) as $i => $label ) {
			$n            = isset( $rows[ $i ] ) ? (int) $rows[ $i ]->n : 0;
			$counts[ $i ] = $n;
			$total       += $n;
		}
		return array( 'counts' => $counts, 'total' => $total );
	}

	public function handle_vote() {
		if ( ! is_user_logged_in() || ! isset( $_POST['lcc_poll_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['lcc_poll_nonce'] ), 'lcc_poll_vote' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'legendcreate-community' ) );
		}
		global $wpdb;
		$poll_id = isset( $_POST['poll_id'] ) ? absint( $_POST['poll_id'] ) : 0;
		$idx     = isset( $_POST['option_idx'] ) ? absint( $_POST['option_idx'] ) : -1;
		$uid     = get_current_user_id();
		$back    = wp_get_referer() ? wp_get_referer() : home_url( '/' );

		if ( $poll_id && self::CPT === get_post_type( $poll_id ) && ! self::is_closed( $poll_id )
			&& $idx >= 0 && $idx < count( self::options( $poll_id ) ) && ! self::has_voted( $poll_id, $uid ) ) {
			$ok = $wpdb->insert( self::table(), array(
				'poll_id'    => $poll_id,
				'option_idx' => $idx,
				'user_id'    => $uid,
				'created_at' => current_time( 'mysql', true ),
			), array( '%d', '%d', '%d', '%s' ) );
			if ( $ok && class_exists( 'LCC_Reputation' ) ) {
				LCC_Reputation::award( $uid, 'poll_vote', 'poll', $poll_id );
			}
			do_action( 'lcc_poll_voted', $poll_id, $uid, $idx );
		}
		wp_safe_redirect( $back . '#poll-' . $poll_id );
		exit;
	}

	// ── Render ───────────────────────────────────────────────────────────────────

	public static function render_poll( $poll_id ) {
		$poll_id = (int) $poll_id;
		if ( self::CPT !== get_post_type( $poll_id ) ) { return ''; }
		$options = self::options( $poll_id );
		if ( ! $options ) { return ''; }

		$uid        = get_current_user_id();
		$voted      = $uid && self::has_voted( $poll_id, $uid );
		$closed     = self::is_closed( $poll_id );
		$show_result = $closed || $voted || ! is_user_logged_in();
		$tallies    = self::tallies( $poll_id );

		ob_start();
		echo '<div class="lcc-poll" id="poll-' . esc_attr( $poll_id ) . '">';
		echo '<h3 class="lcc-poll-q">' . esc_html( get_the_title( $poll_id ) ) . '</h3>';

		if ( $show_result ) {
			$my = $uid ? self::user_vote( $poll_id, $uid ) : -1;
			foreach ( $options as $i => $label ) {
				$n   = $tallies['counts'][ $i ];
				$pct = $tallies['total'] > 0 ? round( $n / $tallies['total'] * 100 ) : 0;
				echo '<div class="lcc-poll-row' . ( $i === $my ? ' is-mine' : '' ) . '">';
				echo '<div class="lcc-poll-bar"><i style="width:' . esc_attr( $pct ) . '%"></i><span>' . esc_html( $label ) . '</span><b>' . esc_html( $pct ) . '%</b></div></div>';
			}
			echo '<p class="lcc-muted">' . esc_html( sprintf( _n( '%d vote', '%d votes', $tallies['total'], 'legendcreate-community' ), $tallies['total'] ) );
			if ( ! is_user_logged_in() ) {
				$join = (int) get_option( 'lcc_page_register', 0 );
				echo ' · <a class="lcc-link" href="' . esc_url( $join ? get_permalink( $join ) : home_url( '/join/' ) ) . '">' . esc_html__( 'Join to vote', 'legendcreate-community' ) . '</a>';
			}
			echo '</p>';
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lcc-poll-form">';
			wp_nonce_field( 'lcc_poll_vote', 'lcc_poll_nonce' );
			echo '<input type="hidden" name="action" value="lcc_poll_vote"><input type="hidden" name="poll_id" value="' . esc_attr( $poll_id ) . '">';
			foreach ( $options as $i => $label ) {
				echo '<button class="lcc-poll-choice" type="submit" name="option_idx" value="' . esc_attr( $i ) . '">' . esc_html( $label ) . '</button>';
			}
			echo '</form>';
		}
		echo '</div>';
		return ob_get_clean();
	}

	public function poll_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts );
		return self::render_poll( (int) $atts['id'] );
	}

	public function polls_shortcode( $atts ) {
		$atts  = shortcode_atts( array( 'game' => '', 'limit' => 5 ), $atts );
		$polls = self::query_polls( $atts['game'], (int) $atts['limit'] );
		if ( ! $polls ) { return '<p class="lcc-muted">' . esc_html__( 'No polls right now — check back soon.', 'legendcreate-community' ) . '</p>'; }
		$out = '<div class="lcc-shell">';
		foreach ( $polls as $p ) { $out .= '<div class="lcc-panel">' . self::render_poll( $p->ID ) . '</div>'; }
		return $out . '</div>';
	}

	public static function query_polls( $game = '', $limit = 5 ) {
		$args = array( 'post_type' => self::CPT, 'post_status' => 'publish', 'posts_per_page' => $limit, 'orderby' => 'date', 'order' => 'DESC' );
		if ( $game ) { $args['meta_query'] = array( array( 'key' => '_lcc_poll_game', 'value' => $game, 'compare' => '=' ) ); }
		return get_posts( $args );
	}

	/** Landing-page helper: render up to N polls for a game (no wrapper). */
	public static function for_game( $game, $limit = 3 ) {
		$polls = self::query_polls( $game, $limit );
		if ( ! $polls ) { return ''; }
		$out = '';
		foreach ( $polls as $p ) { $out .= '<div class="lcc-panel">' . self::render_poll( $p->ID ) . '</div>'; }
		return $out;
	}
}
