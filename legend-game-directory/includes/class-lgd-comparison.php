<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LGD_Comparison {
	public function __construct() {
		add_shortcode( 'lgd_compare', array( $this, 'shortcode' ) );
		add_action( 'rest_api_init', array( $this, 'route' ) );
	}

	public function route() {
		register_rest_route( 'lgd/v1', '/compare', array( 'methods' => 'GET', 'callback' => array( $this, 'rest_compare' ), 'permission_callback' => '__return_true', 'args' => array( 'games' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ) ) ) );
	}

	public function rest_compare( WP_REST_Request $request ) {
		$ids = $this->ids( $request['games'] );
		return rest_ensure_response( $this->data( $ids ) );
	}

	private function ids( $value ) { return array_slice( array_values( array_filter( array_unique( array_map( 'absint', preg_split( '/[,\s]+/', (string) $value ) ) ) ) ), 0, 4 ); }

	public function data( $ids ) {
		$key = 'lgd_compare_' . md5( implode( ',', $ids ) ); $cached = get_transient( $key );
		if ( false !== $cached ) { return $cached; }
		$fields = array(
			'automated_score' => '_lgd_automated_score', 'editorial_score' => '_lgd_editorial_score', 'community_score' => '_lgd_community_score',
			'external_critic_score' => '_lgd_external_critic_score', 'store_sentiment' => '_lgd_steam_sentiment', 'price' => '_lgd_current_price',
			'free_type' => '_lgd_free_type', 'indie_status' => '_lgd_is_indie', 'advertising' => '_lgd_advertising',
			'in_app_purchases' => '_lgd_in_app_purchases', 'offline_support' => '_lgd_offline_support', 'controller_support' => '_lgd_controller_support',
			'multiplayer' => '_lgd_multiplayer', 'age_rating' => '_lgd_age_rating', 'last_verified' => '_lgd_last_verified',
		);
		$data = array();
		foreach ( $ids as $id ) {
			if ( 'game' !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) { continue; }
			$game = array( 'id' => $id, 'title' => get_the_title( $id ), 'url' => get_permalink( $id ), 'platforms' => wp_get_post_terms( $id, 'game_platform', array( 'fields' => 'names' ) ), 'genres' => wp_get_post_terms( $id, 'game_genre', array( 'fields' => 'names' ) ) );
			foreach ( $fields as $name => $meta ) { $game[ $name ] = get_post_meta( $id, $meta, true ); }
			$data[] = $game;
		}
		set_transient( $key, $data, 15 * MINUTE_IN_SECONDS );
		return $data;
	}

	public function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'games' => isset( $_GET['games'] ) ? sanitize_text_field( wp_unslash( $_GET['games'] ) ) : '' ), $atts );
		$games = $this->data( $this->ids( $atts['games'] ) );
		if ( count( $games ) < 2 ) { return '<p>' . esc_html__( 'Choose two to four published games to compare.', 'legend-game-directory' ) . '</p>'; }
		$rows = array(
			'automated_score' => __( 'Legend Automated Score', 'legend-game-directory' ), 'editorial_score' => __( 'Editorial Score', 'legend-game-directory' ),
			'community_score' => __( 'Community Score', 'legend-game-directory' ), 'external_critic_score' => __( 'External Critic Score', 'legend-game-directory' ),
			'store_sentiment' => __( 'Store Sentiment', 'legend-game-directory' ), 'price' => __( 'Price', 'legend-game-directory' ), 'free_type' => __( 'Free Type', 'legend-game-directory' ),
			'indie_status' => __( 'Indie Status', 'legend-game-directory' ), 'platforms' => __( 'Platforms', 'legend-game-directory' ), 'genres' => __( 'Genres', 'legend-game-directory' ),
			'advertising' => __( 'Advertising', 'legend-game-directory' ), 'in_app_purchases' => __( 'In-app Purchases', 'legend-game-directory' ), 'offline_support' => __( 'Offline Support', 'legend-game-directory' ),
			'controller_support' => __( 'Controller Support', 'legend-game-directory' ), 'multiplayer' => __( 'Multiplayer', 'legend-game-directory' ), 'age_rating' => __( 'Age Rating', 'legend-game-directory' ), 'last_verified' => __( 'Last Verified', 'legend-game-directory' ),
		);
		ob_start(); ?><div class="lgd-compare-wrap"><table class="lgd-compare"><thead><tr><th><?php esc_html_e( 'Feature', 'legend-game-directory' ); ?></th><?php foreach ( $games as $game ) : ?><th><a href="<?php echo esc_url( $game['url'] ); ?>"><?php echo esc_html( $game['title'] ); ?></a></th><?php endforeach; ?></tr></thead><tbody>
		<?php foreach ( $rows as $key => $label ) : ?><tr><th><?php echo esc_html( $label ); ?></th><?php foreach ( $games as $game ) : $value = isset( $game[ $key ] ) ? $game[ $key ] : ''; if ( is_array( $value ) ) { $value = implode( ', ', $value ); } if ( 'indie_status' === $key ) { $value = $value ? __( 'Yes', 'legend-game-directory' ) : __( 'Not verified', 'legend-game-directory' ); } ?><td><?php echo '' === (string) $value ? '<span class="lgd-missing">' . esc_html__( 'Unknown', 'legend-game-directory' ) . '</span>' : esc_html( $value ); ?></td><?php endforeach; ?></tr><?php endforeach; ?>
		</tbody></table></div><?php return ob_get_clean();
	}
}
