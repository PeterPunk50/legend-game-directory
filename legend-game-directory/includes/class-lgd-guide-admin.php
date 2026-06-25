<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LGD_Guide_Admin {

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_game_guide', array( $this, 'save_meta' ), 10, 2 );
		add_filter( 'manage_game_guide_posts_columns', array( $this, 'list_columns' ) );
		add_action( 'manage_game_guide_posts_custom_column', array( $this, 'list_column_values' ), 10, 2 );
	}

	public function add_meta_boxes() {
		add_meta_box(
			'lgd_guide_details',
			__( 'Guide Details', 'legend-game-directory' ),
			array( $this, 'render_details_box' ),
			'game_guide', 'side', 'high'
		);
		add_meta_box(
			'lgd_guide_seo',
			__( 'SEO, Key Points & Affiliate', 'legend-game-directory' ),
			array( $this, 'render_seo_box' ),
			'game_guide', 'normal', 'low'
		);
	}

	public function render_details_box( $post ) {
		wp_nonce_field( 'lgd_guide_meta', 'lgd_guide_nonce' );
		$game_id   = (int) get_post_meta( $post->ID, '_lgd_guide_game_id', true );
		$game_name = get_post_meta( $post->ID, '_lgd_guide_game_name', true );
		$diff      = get_post_meta( $post->ID, '_lgd_guide_difficulty', true );
		$time      = (int) get_post_meta( $post->ID, '_lgd_guide_reading_time', true );
		$platform  = get_post_meta( $post->ID, '_lgd_guide_platform', true );

		$games = get_posts( array(
			'post_type'   => 'game',
			'post_status' => 'publish',
			'numberposts' => -1,
			'orderby'     => 'title',
			'order'       => 'ASC',
			'fields'      => array( 'ID', 'post_title' ),
		) );
		?>
		<p>
			<label style="font-weight:600;display:block;margin-bottom:4px"><?php esc_html_e( 'Directory Game (optional)', 'legend-game-directory' ); ?></label>
			<select name="lgd_guide_game_id" style="width:100%">
				<option value="0"><?php esc_html_e( '— Not in directory —', 'legend-game-directory' ); ?></option>
				<?php foreach ( $games as $g ) : ?>
					<option value="<?php echo esc_attr( $g->ID ); ?>" <?php selected( $game_id, $g->ID ); ?>><?php echo esc_html( $g->post_title ); ?></option>
				<?php endforeach; ?>
			</select>
			<span style="color:#999;font-size:.8em"><?php esc_html_e( 'Links guide to its directory page & auto-fills Game Name.', 'legend-game-directory' ); ?></span>
		</p>
		<p>
			<label style="font-weight:600;display:block;margin-bottom:4px"><?php esc_html_e( 'Game Name (if not in directory)', 'legend-game-directory' ); ?></label>
			<input type="text" name="lgd_guide_game_name" value="<?php echo esc_attr( $game_name ); ?>" style="width:100%" placeholder="e.g. Minecraft">
		</p>
		<p>
			<label style="font-weight:600;display:block;margin-bottom:4px"><?php esc_html_e( 'Difficulty', 'legend-game-directory' ); ?></label>
			<select name="lgd_guide_difficulty" style="width:100%">
				<?php foreach ( array( '' => '— Select —', 'Beginner' => 'Beginner', 'Intermediate' => 'Intermediate', 'Advanced' => 'Advanced' ) as $val => $label ) : ?>
					<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $diff, $val ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label style="font-weight:600;display:block;margin-bottom:4px"><?php esc_html_e( 'Reading Time (minutes)', 'legend-game-directory' ); ?></label>
			<input type="number" name="lgd_guide_reading_time" value="<?php echo esc_attr( $time ?: '' ); ?>" min="1" max="120" style="width:100%">
		</p>
		<p>
			<label style="font-weight:600;display:block;margin-bottom:4px"><?php esc_html_e( 'Primary Platform', 'legend-game-directory' ); ?></label>
			<input type="text" name="lgd_guide_platform" value="<?php echo esc_attr( $platform ); ?>" style="width:100%" placeholder="e.g. iOS, PC, Android">
		</p>
		<?php
	}

	public function render_seo_box( $post ) {
		$seo_title  = get_post_meta( $post->ID, '_lgd_guide_seo_title', true );
		$meta_desc  = get_post_meta( $post->ID, '_lgd_guide_meta_description', true );
		$aff_url    = get_post_meta( $post->ID, '_lgd_guide_affiliate_url', true );
		$aff_label  = get_post_meta( $post->ID, '_lgd_guide_affiliate_label', true );
		$key_points = (array) get_post_meta( $post->ID, '_lgd_guide_key_points', true );
		?>
		<table class="form-table" style="margin:0">
			<tr>
				<th scope="row"><label><?php esc_html_e( 'SEO Title', 'legend-game-directory' ); ?></label></th>
				<td><input type="text" name="lgd_guide_seo_title" value="<?php echo esc_attr( $seo_title ); ?>" class="large-text" placeholder="<?php esc_attr_e( 'Defaults to post title if blank', 'legend-game-directory' ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label><?php esc_html_e( 'Meta Description', 'legend-game-directory' ); ?></label></th>
				<td><textarea name="lgd_guide_meta_description" class="large-text" rows="2"><?php echo esc_textarea( $meta_desc ); ?></textarea></td>
			</tr>
			<tr>
				<th scope="row"><label><?php esc_html_e( 'Key Points', 'legend-game-directory' ); ?></label></th>
				<td>
					<textarea name="lgd_guide_key_points" class="large-text" rows="5" placeholder="Free to play&#10;Works offline&#10;No ads"><?php echo esc_textarea( implode( "\n", array_filter( $key_points ) ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One per line. Shown as a quick-summary box at the top of the guide.', 'legend-game-directory' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label><?php esc_html_e( 'Affiliate URL', 'legend-game-directory' ); ?></label></th>
				<td><input type="url" name="lgd_guide_affiliate_url" value="<?php echo esc_attr( $aff_url ); ?>" class="large-text" placeholder="https://"></td>
			</tr>
			<tr>
				<th scope="row"><label><?php esc_html_e( 'Button Label', 'legend-game-directory' ); ?></label></th>
				<td><input type="text" name="lgd_guide_affiliate_label" value="<?php echo esc_attr( $aff_label ?: 'Get the Game' ); ?>" class="regular-text"></td>
			</tr>
		</table>
		<?php
	}

	public function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST['lgd_guide_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['lgd_guide_nonce'] ), 'lgd_guide_meta' ) ) { return; }
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

		if ( isset( $_POST['lgd_guide_game_id'] ) )       { update_post_meta( $post_id, '_lgd_guide_game_id', absint( $_POST['lgd_guide_game_id'] ) ); }
		if ( isset( $_POST['lgd_guide_reading_time'] ) )  { update_post_meta( $post_id, '_lgd_guide_reading_time', absint( $_POST['lgd_guide_reading_time'] ) ); }

		$str = array(
			'lgd_guide_game_name'        => '_lgd_guide_game_name',
			'lgd_guide_difficulty'       => '_lgd_guide_difficulty',
			'lgd_guide_platform'         => '_lgd_guide_platform',
			'lgd_guide_seo_title'        => '_lgd_guide_seo_title',
			'lgd_guide_meta_description' => '_lgd_guide_meta_description',
			'lgd_guide_affiliate_label'  => '_lgd_guide_affiliate_label',
		);
		foreach ( $str as $post_key => $meta_key ) {
			if ( isset( $_POST[ $post_key ] ) ) {
				update_post_meta( $post_id, $meta_key, sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) ) );
			}
		}

		if ( isset( $_POST['lgd_guide_affiliate_url'] ) ) {
			update_post_meta( $post_id, '_lgd_guide_affiliate_url', esc_url_raw( wp_unslash( $_POST['lgd_guide_affiliate_url'] ) ) );
		}

		if ( isset( $_POST['lgd_guide_key_points'] ) ) {
			$points = array_values( array_filter( array_map(
				'sanitize_text_field',
				explode( "\n", wp_unslash( $_POST['lgd_guide_key_points'] ) )
			) ) );
			update_post_meta( $post_id, '_lgd_guide_key_points', $points );
		}

		// Auto-fill game name + slug from directory post if linked.
		$game_id = (int) get_post_meta( $post_id, '_lgd_guide_game_id', true );
		if ( $game_id > 0 ) {
			update_post_meta( $post_id, '_lgd_guide_game_name', get_the_title( $game_id ) );
			update_post_meta( $post_id, '_lgd_guide_game_slug', get_post_field( 'post_name', $game_id ) );
		}

		update_post_meta( $post_id, '_lgd_guide_last_updated', current_time( 'mysql', true ) );
	}

	public function list_columns( $cols ) {
		$new = array();
		foreach ( $cols as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'title' === $k ) {
				$new['lgd_guide_game']       = __( 'Game', 'legend-game-directory' );
				$new['lgd_guide_difficulty'] = __( 'Difficulty', 'legend-game-directory' );
				$new['lgd_guide_read_time']  = __( 'Read Time', 'legend-game-directory' );
			}
		}
		return $new;
	}

	public function list_column_values( $col, $post_id ) {
		if ( 'lgd_guide_game' === $col ) {
			$name    = get_post_meta( $post_id, '_lgd_guide_game_name', true );
			$game_id = (int) get_post_meta( $post_id, '_lgd_guide_game_id', true );
			if ( $game_id > 0 ) {
				echo '<a href="' . esc_url( get_edit_post_link( $game_id ) ) . '">' . esc_html( $name ) . '</a>';
			} else {
				echo esc_html( $name ?: '—' );
			}
		}
		if ( 'lgd_guide_difficulty' === $col ) {
			echo esc_html( get_post_meta( $post_id, '_lgd_guide_difficulty', true ) ?: '—' );
		}
		if ( 'lgd_guide_read_time' === $col ) {
			$t = (int) get_post_meta( $post_id, '_lgd_guide_reading_time', true );
			echo $t ? esc_html( $t . ' min' ) : '—';
		}
	}
}
