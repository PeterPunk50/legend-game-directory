<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Guide Indexer — aggregator mode. Index an external guide as an attributed,
 * ranked summary card connected to a game, with a "Read Full Guide" outbound link.
 *
 * Stores metadata + a SHORT original AI summary only — never the full article.
 * Fetches og: metadata where the source allows (robots-aware, rate-limited); for
 * sources behind bot protection, the editor can supply title/image/notes manually.
 */
final class LGD_Guide_Indexer {

	const FETCH_LIMIT = 1048576; // 1 MB
	const TIMEOUT     = 18;

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_lgd_index_guide', array( $this, 'handle' ) );
	}

	public function menu() {
		add_submenu_page(
			'edit.php?post_type=game_guide',
			__( 'Index a Guide', 'legend-game-directory' ),
			__( 'Index a Guide', 'legend-game-directory' ),
			'edit_guides',
			'lgd-guide-indexer',
			array( $this, 'render' )
		);
	}

	public function render() {
		$games  = get_posts( array( 'post_type' => 'game', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC', 'fields' => array( 'ID', 'post_title' ) ) );
		$types  = get_terms( array( 'taxonomy' => 'guide_type', 'hide_empty' => false ) );
		$msg    = isset( $_GET['lgd_idx_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['lgd_idx_msg'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Index a Guide', 'legend-game-directory' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Add an external guide as an attributed summary card linked to a game. We store only metadata + a short original summary and a “Read Full Guide” link — never the full article. We try to fetch the page’s image/title automatically; for sites that block that, fill in the manual fields.', 'legend-game-directory' ); ?></p>
			<?php if ( $msg ) : ?><div class="notice notice-info is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div><?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'lgd_index_guide', 'lgd_idx_nonce' ); ?>
				<input type="hidden" name="action" value="lgd_index_guide">
				<table class="form-table">
					<tr><th><label><?php esc_html_e( 'Source URL (Read Full Guide)', 'legend-game-directory' ); ?> *</label></th>
						<td><input type="url" name="source_url" class="large-text" required placeholder="https://"></td></tr>
					<tr><th><label><?php esc_html_e( 'Game', 'legend-game-directory' ); ?></label></th>
						<td><select name="game_id"><option value="0"><?php esc_html_e( '— Not in directory —', 'legend-game-directory' ); ?></option><?php foreach ( $games as $g ) : ?><option value="<?php echo esc_attr( $g->ID ); ?>"><?php echo esc_html( $g->post_title ); ?></option><?php endforeach; ?></select>
						<input type="text" name="game_name" class="regular-text" placeholder="<?php esc_attr_e( 'or game name (e.g. Apex Legends)', 'legend-game-directory' ); ?>"></td></tr>
					<tr><th><label><?php esc_html_e( 'Guide type', 'legend-game-directory' ); ?></label></th>
						<td><select name="guide_type"><?php if ( ! is_wp_error( $types ) ) { foreach ( $types as $t ) { echo '<option value="' . esc_attr( $t->name ) . '">' . esc_html( $t->name ) . '</option>'; } } ?></select></td></tr>
					<tr><th><label><?php esc_html_e( 'Video URL (optional)', 'legend-game-directory' ); ?></label></th>
						<td><input type="url" name="video_url" class="large-text" placeholder="<?php esc_attr_e( 'YouTube/Twitch link — uses the video thumbnail if no image', 'legend-game-directory' ); ?>"></td></tr>
					<tr><th colspan="2"><strong><?php esc_html_e( 'Manual fields (used if the source blocks auto-fetch)', 'legend-game-directory' ); ?></strong></th></tr>
					<tr><th><label><?php esc_html_e( 'Title', 'legend-game-directory' ); ?></label></th>
						<td><input type="text" name="m_title" class="large-text"></td></tr>
					<tr><th><label><?php esc_html_e( 'Image URL', 'legend-game-directory' ); ?></label></th>
						<td><input type="url" name="m_image" class="large-text"></td></tr>
					<tr><th><label><?php esc_html_e( 'Source site name', 'legend-game-directory' ); ?></label></th>
						<td><input type="text" name="m_site" class="regular-text"></td></tr>
					<tr><th><label><?php esc_html_e( 'Notes / description', 'legend-game-directory' ); ?></label></th>
						<td><textarea name="m_desc" rows="4" class="large-text" placeholder="<?php esc_attr_e( 'A few sentences about the guide (feeds the AI summary). Do not paste the full article.', 'legend-game-directory' ); ?>"></textarea></td></tr>
					<tr><th><?php esc_html_e( 'Mode', 'legend-game-directory' ); ?></th>
						<td><label><input type="radio" name="mode" value="publish" checked> <?php esc_html_e( 'Publish (Summary Import)', 'legend-game-directory' ); ?></label>
						&nbsp; <label><input type="radio" name="mode" value="draft"> <?php esc_html_e( 'Save to review queue', 'legend-game-directory' ); ?></label></td></tr>
				</table>
				<?php submit_button( __( 'Index Guide', 'legend-game-directory' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function handle() {
		if ( ! current_user_can( 'edit_guides' ) || ! isset( $_POST['lgd_idx_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['lgd_idx_nonce'] ), 'lgd_index_guide' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'legend-game-directory' ) );
		}
		$back = add_query_arg( array( 'post_type' => 'game_guide', 'page' => 'lgd-guide-indexer' ), admin_url( 'edit.php' ) );

		if ( ! LGD_Security::rate_limit( 'guide-index:user:' . get_current_user_id(), 60, HOUR_IN_SECONDS ) ) {
			$this->bail( $back, __( 'Rate limit reached. Please wait before indexing more guides.', 'legend-game-directory' ) );
		}

		$url = isset( $_POST['source_url'] ) ? esc_url_raw( wp_unslash( $_POST['source_url'] ), array( 'https', 'http' ) ) : '';
		if ( ! $url || ! wp_http_validate_url( $url ) ) { $this->bail( $back, __( 'Please provide a valid source URL.', 'legend-game-directory' ) ); }

		// Duplicate check by source URL.
		$dupe = get_posts( array( 'post_type' => 'game_guide', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_query' => array( array( 'key' => '_lgd_guide_source_url', 'value' => $url ) ) ) );
		if ( $dupe ) { $this->bail( $back, __( 'That guide URL is already indexed.', 'legend-game-directory' ) ); }

		$game_id   = isset( $_POST['game_id'] ) ? absint( $_POST['game_id'] ) : 0;
		$game_name = isset( $_POST['game_name'] ) ? sanitize_text_field( wp_unslash( $_POST['game_name'] ) ) : '';
		if ( $game_id && 'game' === get_post_type( $game_id ) ) { $game_name = get_the_title( $game_id ); }
		$guide_type = isset( $_POST['guide_type'] ) ? sanitize_text_field( wp_unslash( $_POST['guide_type'] ) ) : '';
		$video_url  = isset( $_POST['video_url'] ) ? esc_url_raw( wp_unslash( $_POST['video_url'] ) ) : '';
		$status     = ( isset( $_POST['mode'] ) && 'draft' === $_POST['mode'] ) ? 'draft' : 'publish';

		$m_title = isset( $_POST['m_title'] ) ? sanitize_text_field( wp_unslash( $_POST['m_title'] ) ) : '';
		$m_image = isset( $_POST['m_image'] ) ? esc_url_raw( wp_unslash( $_POST['m_image'] ) ) : '';
		$m_site  = isset( $_POST['m_site'] ) ? sanitize_text_field( wp_unslash( $_POST['m_site'] ) ) : '';
		$m_desc  = isset( $_POST['m_desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['m_desc'] ) ) : '';

		// Try to fetch og: metadata (best effort; manual fields win).
		$og = self::fetch_meta( $url );

		$title = $m_title ?: ( $og['title'] ?? '' ) ?: wp_parse_url( $url, PHP_URL_HOST );
		$desc  = $m_desc ?: ( $og['description'] ?? '' );
		$site  = $m_site ?: ( $og['site_name'] ?? wp_parse_url( $url, PHP_URL_HOST ) );
		$image = $m_image ?: ( $og['image'] ?? '' );
		if ( ! $video_url && ! empty( $og['video'] ) ) { $video_url = $og['video']; }

		$image_from_video = false;
		if ( ! $image && $video_url ) {
			$yt = self::youtube_thumb( $video_url );
			if ( $yt ) { $image = $yt; $image_from_video = true; }
		}

		// Short original summary from metadata.
		$summary = $desc;
		$ai = LGD_AI_Adapter::index_summary( array( 'title' => $title, 'description' => $desc, 'game' => $game_name, 'guide_type' => $guide_type ) );
		if ( ! is_wp_error( $ai ) && ! empty( $ai['summary'] ) ) { $summary = $ai['summary']; }
		if ( '' === trim( (string) $summary ) ) { $summary = sprintf( __( 'An external %s guide. See the original source for the full guide.', 'legend-game-directory' ), $game_name ?: 'game' ); }

		$guide_id = wp_insert_post( array(
			'post_type'    => 'game_guide',
			'post_status'  => $status,
			'post_title'   => $title,
			'post_content' => '<p>' . esc_html( $summary ) . '</p>',
			'post_excerpt' => wp_trim_words( $summary, 40 ),
		), true );
		if ( is_wp_error( $guide_id ) ) { $this->bail( $back, $guide_id->get_error_message() ); }

		if ( $guide_type ) { wp_set_object_terms( $guide_id, $guide_type, 'guide_type', false ); }

		$meta = array(
			'_lgd_guide_is_external'      => 1,
			'_lgd_guide_source_url'       => $url,
			'_lgd_guide_source_site'      => $site,
			'_lgd_guide_source_author'    => sanitize_text_field( $og['author'] ?? '' ),
			'_lgd_guide_source_date'      => sanitize_text_field( $og['date'] ?? '' ),
			'_lgd_guide_video_url'        => $video_url,
			'_lgd_guide_image_from_video' => $image_from_video ? 1 : 0,
			'_lgd_guide_game_id'          => $game_id,
			'_lgd_guide_game_name'        => $game_name,
			'_lgd_guide_game_slug'        => $game_id ? get_post_field( 'post_name', $game_id ) : '',
			'_lgd_guide_difficulty'       => self::difficulty_from_type( $guide_type ),
			'_lgd_guide_ai_generated'     => 1,
			'_lgd_guide_imported_at'      => current_time( 'mysql', true ),
			'_lgd_guide_last_updated'     => current_time( 'mysql', true ),
		);
		foreach ( $meta as $k => $v ) { update_post_meta( $guide_id, $k, $v ); }

		// Card image: sideload remote → else reuse the game's featured image.
		self::set_card_image( $guide_id, $image, $game_id, $title );

		// Score and store.
		update_post_meta( $guide_id, '_lgd_guide_score', self::score( $guide_id ) );

		LGD_Logger::log( 'guide_indexed', 'External guide indexed.', array( 'guide_id' => $guide_id, 'url' => $url, 'site' => $site, 'status' => $status ), 'info' );
		wp_safe_redirect( get_edit_post_link( $guide_id, 'url' ) );
		exit;
	}

	private function bail( $back, $message ) {
		wp_safe_redirect( add_query_arg( 'lgd_idx_msg', rawurlencode( $message ), $back ) );
		exit;
	}

	// ── Metadata fetch ───────────────────────────────────────────────────────────

	public static function fetch_meta( $url ) {
		$out = array();
		if ( class_exists( 'LGD_Research' ) && ! LGD_Research::robots_allow( $url ) ) { return $out; }
		$res = wp_remote_get( $url, array(
			'timeout'             => self::TIMEOUT,
			'redirection'         => 3,
			'reject_unsafe_urls'  => true,
			'limit_response_size' => self::FETCH_LIMIT,
			'headers'             => array( 'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36', 'Accept' => 'text/html,*/*;q=0.8' ),
		) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) { return $out; }
		$html = wp_remote_retrieve_body( $res );

		$out['title']       = self::meta( $html, 'og:title', 'property' ) ?: self::tag_title( $html );
		$out['description'] = self::meta( $html, 'og:description', 'property' ) ?: self::meta( $html, 'description', 'name' );
		$out['image']       = esc_url_raw( self::meta( $html, 'og:image', 'property' ) );
		$out['site_name']   = self::meta( $html, 'og:site_name', 'property' );
		$out['date']        = self::meta( $html, 'article:published_time', 'property' );
		$out['author']      = self::meta( $html, 'article:author', 'property' ) ?: self::meta( $html, 'author', 'name' );
		$out['video']       = esc_url_raw( self::meta( $html, 'og:video', 'property' ) ?: self::meta( $html, 'og:video:url', 'property' ) );
		return array_map( function( $v ) { return is_string( $v ) ? trim( html_entity_decode( $v, ENT_QUOTES, 'UTF-8' ) ) : $v; }, $out );
	}

	private static function meta( $html, $key, $attr ) {
		$k = preg_quote( $key, '#' );
		if ( preg_match( '#<meta\s[^>]*' . $attr . '=["\']' . $k . '["\'][^>]*content=["\']([^"\']*)["\']#i', $html, $m ) ) { return $m[1]; }
		if ( preg_match( '#<meta\s[^>]*content=["\']([^"\']*)["\'][^>]*' . $attr . '=["\']' . $k . '["\']#i', $html, $m ) ) { return $m[1]; }
		return '';
	}

	private static function tag_title( $html ) {
		return preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $m ) ? wp_strip_all_tags( $m[1] ) : '';
	}

	public static function youtube_thumb( $url ) {
		if ( preg_match( '#(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{11})#', $url, $m ) ) {
			return 'https://img.youtube.com/vi/' . $m[1] . '/hqdefault.jpg';
		}
		return '';
	}

	private static function set_card_image( $guide_id, $image_url, $game_id, $title ) {
		if ( $image_url ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$att = media_sideload_image( $image_url, $guide_id, $title, 'id' );
			if ( ! is_wp_error( $att ) ) { set_post_thumbnail( $guide_id, $att ); return; }
			// Sideload blocked (CDN) — keep the remote URL for the card to render directly.
			update_post_meta( $guide_id, '_lgd_guide_remote_image', esc_url_raw( $image_url ) );
		}
		// Fallback: reuse the related game's featured image.
		if ( $game_id ) {
			$thumb = get_post_thumbnail_id( $game_id );
			if ( $thumb ) { set_post_thumbnail( $guide_id, $thumb ); }
		}
	}

	private static function difficulty_from_type( $type ) {
		$t = strtolower( $type );
		if ( false !== strpos( $t, 'beginner' ) || false !== strpos( $t, 'new player' ) ) { return 'Beginner'; }
		if ( false !== strpos( $t, 'ranked' ) || false !== strpos( $t, 'competitive' ) || false !== strpos( $t, 'advanced' ) ) { return 'Advanced'; }
		return 'Intermediate';
	}

	// ── Ranking ──────────────────────────────────────────────────────────────────

	/** Guide score 0-100: 30% source, 20% freshness, 20% relevance, 10% media, 10% category, 10% confidence. */
	public static function score( $guide_id ) {
		$site  = (string) get_post_meta( $guide_id, '_lgd_guide_source_site', true );
		$date  = (string) get_post_meta( $guide_id, '_lgd_guide_source_date', true );
		$game  = (string) get_post_meta( $guide_id, '_lgd_guide_game_name', true );
		$video = (string) get_post_meta( $guide_id, '_lgd_guide_video_url', true );
		$has_img = (bool) ( get_post_thumbnail_id( $guide_id ) || get_post_meta( $guide_id, '_lgd_guide_remote_image', true ) );
		$type  = wp_get_post_terms( $guide_id, 'guide_type', array( 'fields' => 'names' ) );
		$type  = is_wp_error( $type ) || empty( $type ) ? '' : $type[0];

		// Source quality.
		$official = array( 'callofduty', 'ea.com', 'apexlegends', 'epicgames', 'fortnite', 'steampowered', 'valvesoftware', 'counter-strike', 'playstation', 'xbox', 'nintendo', 'ubisoft', 'activision' );
		$src = 65;
		foreach ( $official as $dom ) { if ( false !== stripos( $site, $dom ) ) { $src = 92; break; } }

		// Freshness.
		$fresh = 50;
		if ( $date ) {
			$age = ( time() - strtotime( $date ) ) / DAY_IN_SECONDS;
			$fresh = $age <= 30 ? 100 : ( $age <= 90 ? 80 : ( $age <= 365 ? 55 : 30 ) );
		}
		$relevance = $game ? 85 : 60;
		$media     = ( $has_img ? 60 : 0 ) + ( $video ? 40 : 0 );
		$category  = $type ? 80 : 50;
		$confidence = 70;

		$score = 0.30 * $src + 0.20 * $fresh + 0.20 * $relevance + 0.10 * $media + 0.10 * $category + 0.10 * $confidence;
		return (int) round( min( 100, max( 0, $score ) ) );
	}
}
