<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LGD_Artwork_Fetcher {
	const TIMEOUT     = 15;
	const FETCH_LIMIT = 2097152; // 2 MB

	/**
	 * Fetch the best available artwork URL from any public HTTPS page.
	 * Priority: og:image → twitter:image → JSON-LD image.
	 *
	 * @param string $url
	 * @return string|WP_Error Absolute image URL or WP_Error.
	 */
	public static function fetch_og_image( $url ) {
		$url = esc_url_raw( $url, array( 'https', 'http' ) );
		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'lgd_aw_invalid', __( 'Invalid artwork source URL.', 'legend-game-directory' ) );
		}
		$response = wp_remote_get( $url, array(
			'timeout'             => self::TIMEOUT,
			'redirection'         => 3,
			'reject_unsafe_urls'  => true,
			'limit_response_size' => self::FETCH_LIMIT,
			'headers'             => array(
				'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
				'Accept'     => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
			),
		) );
		if ( is_wp_error( $response ) ) { return $response; }
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'lgd_aw_http', sprintf( __( 'HTTP %d from artwork URL.', 'legend-game-directory' ), $code ) );
		}
		return self::extract_image( wp_remote_retrieve_body( $response ), $url );
	}

	/**
	 * Fetch og:image for a game that has _lgd_official_website set and prepend it
	 * to _lgd_official_screenshots so card() and sideload can use it immediately.
	 *
	 * Works for any game regardless of scope (paid indie, PC-only, etc.) because it
	 * only updates meta — it does not run through the importer's eligibility check.
	 *
	 * @param int $game_id
	 * @return bool True if an image was stored.
	 */
	public static function fetch_for_game( $game_id ) {
		$site_url = get_post_meta( $game_id, '_lgd_official_website', true );
		if ( ! $site_url ) { return false; }
		$image = self::fetch_og_image( $site_url );
		if ( is_wp_error( $image ) || ! $image ) { return false; }
		$current = get_post_meta( $game_id, '_lgd_official_screenshots', true );
		$current = is_array( $current ) ? $current : array();
		if ( ! in_array( $image, $current, true ) ) {
			array_unshift( $current, $image );
		}
		update_post_meta( $game_id, '_lgd_official_screenshots', array_values( array_filter( $current ) ) );
		return true;
	}

	/**
	 * Download _lgd_official_screenshots[0] as a WP media attachment and set it as
	 * the featured image. Skips if a thumbnail already exists.
	 *
	 * @param int $game_id
	 * @return int|WP_Error Attachment ID or WP_Error.
	 */
	public static function sideload_for_game( $game_id ) {
		if ( get_post_thumbnail_id( $game_id ) ) {
			return new WP_Error( 'lgd_aw_exists', __( 'Featured image already set.', 'legend-game-directory' ) );
		}
		$screens = get_post_meta( $game_id, '_lgd_official_screenshots', true );
		if ( ! is_array( $screens ) || empty( $screens[0] ) ) {
			return new WP_Error( 'lgd_aw_no_screens', __( 'No screenshot URL stored for this game.', 'legend-game-directory' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$att = media_sideload_image( $screens[0], $game_id, get_the_title( $game_id ), 'id' );
		if ( is_wp_error( $att ) ) { return $att; }
		set_post_thumbnail( $game_id, $att );
		return $att;
	}

	// -------------------------------------------------------------------------

	private static function extract_image( $html, $base_url ) {
		$img = self::meta_value( $html, 'og:image', 'property' );
		if ( ! $img ) { $img = self::meta_value( $html, 'twitter:image', 'name' ); }
		if ( ! $img ) { $img = self::meta_value( $html, 'twitter:image:src', 'name' ); }

		// JSON-LD fallback.
		if ( ! $img && preg_match( '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m ) ) {
			$ld    = json_decode( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), true );
			$nodes = isset( $ld['@graph'] ) ? $ld['@graph'] : array( $ld );
			foreach ( (array) $nodes as $node ) {
				if ( ! empty( $node['image'] ) ) {
					$img = is_array( $node['image'] ) ? $node['image'][0] : $node['image'];
					break;
				}
			}
		}

		if ( ! $img ) {
			return new WP_Error( 'lgd_aw_no_image', __( 'No artwork found on the page.', 'legend-game-directory' ) );
		}

		$img = html_entity_decode( (string) $img, ENT_QUOTES, 'UTF-8' );

		// Resolve protocol-relative and root-relative URLs.
		if ( strpos( $img, '//' ) === 0 ) {
			$img = ( wp_parse_url( $base_url, PHP_URL_SCHEME ) ?: 'https' ) . ':' . $img;
		} elseif ( strpos( $img, '/' ) === 0 ) {
			$p   = wp_parse_url( $base_url );
			$img = $p['scheme'] . '://' . $p['host'] . $img;
		}

		return esc_url_raw( $img );
	}

	/**
	 * Extract a <meta> tag content value by attribute type and key.
	 * Handles both attribute orderings (content first or last).
	 */
	private static function meta_value( $html, $key, $attr ) {
		$k = preg_quote( $key, '#' );
		// attr="key" ... content="val"
		if ( preg_match( '#<meta\s[^>]*' . $attr . '=["\']' . $k . '["\'][^>]*content=["\']([^"\']*)["\']#i', $html, $m ) ) {
			return $m[1];
		}
		// content="val" ... attr="key"
		if ( preg_match( '#<meta\s[^>]*content=["\']([^"\']*)["\'][^>]*' . $attr . '=["\']' . $k . '["\']#i', $html, $m ) ) {
			return $m[1];
		}
		return '';
	}
}
