<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * AI Layout Composer — a meta box on the guide editor where an editor pastes text
 * and adds images (Media Library or URLs), then has the AI arrange a clean,
 * sectioned guide layout to review and insert into the post content.
 */
final class LGD_Guide_Composer {

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_ajax_lgd_compose_guide', array( $this, 'ajax_compose' ) );
	}

	public function meta_box() {
		add_meta_box( 'lgd_guide_composer', __( 'AI Layout Composer', 'legend-game-directory' ), array( $this, 'render' ), 'game_guide', 'normal', 'high' );
	}

	public function assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) { return; }
		if ( 'game_guide' !== get_post_type() ) { return; }
		wp_enqueue_media();
		wp_enqueue_script(
			'lgd-composer',
			LGD_URL . 'assets/js/composer.js',
			array( 'jquery', 'wp-blocks', 'wp-data', 'wp-dom-ready' ),
			LGD_VERSION,
			true
		);
		wp_localize_script( 'lgd-composer', 'LGDComposer', array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'lgd_compose' ),
			'i18n'  => array(
				'running'  => __( 'Arranging…', 'legend-game-directory' ),
				'run'      => __( 'Arrange with AI', 'legend-game-directory' ),
				'failed'   => __( 'Could not arrange the layout.', 'legend-game-directory' ),
				'inserted' => __( 'Inserted into the guide. Review and save.', 'legend-game-directory' ),
				'empty'    => __( 'Add some text or images first.', 'legend-game-directory' ),
			),
		) );
	}

	public function render( $post ) {
		?>
		<p class="description"><?php esc_html_e( 'Paste your text/notes and add images, then let AI arrange a clean guide layout you can insert into the content above.', 'legend-game-directory' ); ?></p>
		<p><label><strong><?php esc_html_e( 'Text / notes', 'legend-game-directory' ); ?></strong></label>
			<textarea id="lgd-comp-text" rows="8" class="large-text" placeholder="<?php esc_attr_e( 'Paste your raw guide text or notes here…', 'legend-game-directory' ); ?>"></textarea></p>
		<p><label><strong><?php esc_html_e( 'Image URLs (one per line)', 'legend-game-directory' ); ?></strong></label>
			<textarea id="lgd-comp-images" rows="3" class="large-text" placeholder="https://…/image1.jpg"></textarea>
			<button type="button" class="button" id="lgd-comp-addimg" style="margin-top:6px"><?php esc_html_e( 'Add from Media Library', 'legend-game-directory' ); ?></button></p>
		<p><button type="button" class="button button-primary" id="lgd-comp-run"><?php esc_html_e( 'Arrange with AI', 'legend-game-directory' ); ?></button></p>
		<div class="lgd-comp-result" style="display:none">
			<h4><?php esc_html_e( 'Preview', 'legend-game-directory' ); ?></h4>
			<div id="lgd-comp-preview" style="border:1px solid #dcdcde;border-radius:6px;padding:14px;background:#fff;max-height:380px;overflow:auto"></div>
			<p style="margin-top:10px"><button type="button" class="button button-primary" id="lgd-comp-insert"><?php esc_html_e( 'Insert into guide', 'legend-game-directory' ); ?></button>
				<span class="description"><?php esc_html_e( 'Appends to the content above — review before saving.', 'legend-game-directory' ); ?></span></p>
			<details style="margin-top:8px"><summary><?php esc_html_e( 'View HTML', 'legend-game-directory' ); ?></summary>
				<textarea id="lgd-comp-output" rows="6" class="large-text code" readonly></textarea></details>
		</div>
		<?php
	}

	public function ajax_compose() {
		check_ajax_referer( 'lgd_compose', 'nonce' );
		if ( ! current_user_can( 'edit_guides' ) ) {
			wp_send_json_error( __( 'You are not allowed to do this.', 'legend-game-directory' ) );
		}

		$text   = isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : '';
		$images = array();
		if ( isset( $_POST['images'] ) && is_array( $_POST['images'] ) ) {
			foreach ( wp_unslash( $_POST['images'] ) as $u ) {
				$u = esc_url_raw( trim( $u ) );
				if ( $u ) { $images[] = $u; }
			}
		}

		$data = LGD_AI_Adapter::compose_layout( $text, $images );
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( $data->get_error_message() );
		}

		$html = wp_kses( (string) $data['html'], self::allowed_html() );
		wp_send_json_success( array( 'html' => $html ) );
	}

	public static function allowed_html() {
		return array(
			'h2'        => array(),
			'h3'        => array(),
			'p'         => array(),
			'ul'        => array(),
			'ol'        => array(),
			'li'        => array(),
			'strong'    => array(),
			'em'        => array(),
			'br'        => array(),
			'figure'    => array(),
			'figcaption'=> array(),
			'img'       => array( 'src' => array(), 'alt' => array() ),
		);
	}
}
