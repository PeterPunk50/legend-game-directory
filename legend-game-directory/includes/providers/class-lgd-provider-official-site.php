<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LGD_Provider_Official_Site implements LGD_Provider_Interface {
	public function validate_configuration() {
		$settings = LGD_Security::settings();
		return empty( $settings['official_site_enabled'] )
			? new WP_Error( 'lgd_official_disabled', __( 'Official-site metadata is disabled.', 'legend-game-directory' ) )
			: true;
	}

	public function search_games( $query = '', $args = array() ) {
		unset( $query );
		$urls  = isset( $args['urls'] ) ? array_slice( (array) $args['urls'], 0, 25 ) : array();
		$games = array();
		foreach ( $urls as $url ) {
			$game = $this->get_game( $url );
			if ( ! is_wp_error( $game ) ) { $games[] = $game; }
		}
		return $games;
	}

	public function get_game( $external_id ) {
		$valid = $this->validate_configuration();
		if ( is_wp_error( $valid ) ) { return $valid; }

		$url      = esc_url_raw( $external_id );
		$response = LGD_Security::safe_remote_get( $url );
		if ( is_wp_error( $response ) ) { return $response; }

		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		if ( false === stripos( $content_type, 'text/html' ) ) {
			return new WP_Error( 'lgd_official_mime', __( 'Official-site provider accepts HTML structured metadata only.', 'legend-game-directory' ) );
		}

		$html = wp_remote_retrieve_body( $response );
		$og   = $this->extract_og_meta( $html, $url );

		// 1. Try JSON-LD VideoGame / SoftwareApplication / Game schema.
		preg_match_all( '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches );
		foreach ( isset( $matches[1] ) ? $matches[1] : array() as $json ) {
			$decoded = json_decode( html_entity_decode( $json, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), true );
			$nodes   = isset( $decoded['@graph'] ) ? $decoded['@graph'] : array( $decoded );
			foreach ( (array) $nodes as $node ) {
				$type = isset( $node['@type'] ) ? (array) $node['@type'] : array();
				if ( array_intersect( array( 'VideoGame', 'SoftwareApplication', 'MobileApplication', 'Game' ), $type ) ) {
					$node['_lgd_source_url'] = $url;
					// Add og:image when JSON-LD has no image field.
					if ( empty( $node['image'] ) && ! empty( $og['_lgd_image'] ) ) {
						$node['image'] = $og['_lgd_image'];
					}
					return $this->normalize_game( $node );
				}
			}
		}

		// 2. No schema found — fall back to og/meta tags alone (title is mandatory).
		if ( ! empty( $og['name'] ) ) {
			return $this->normalize_game( $og );
		}

		return new WP_Error( 'lgd_official_no_schema', __( 'No supported public game structured metadata was found.', 'legend-game-directory' ) );
	}

	public function normalize_game( $data ) {
		if ( empty( $data['name'] ) || empty( $data['_lgd_source_url'] ) ) {
			return new WP_Error( 'lgd_official_incomplete', __( 'Structured metadata is incomplete.', 'legend-game-directory' ) );
		}

		$author    = isset( $data['author']['name'] ) ? $data['author']['name']
		           : ( isset( $data['publisher']['name'] ) ? $data['publisher']['name'] : '' );
		$systems   = isset( $data['operatingSystem'] ) ? LGD_Security::sanitize_string_list( $data['operatingSystem'] ) : array();
		$is_mobile = (bool) preg_grep( '/android|ios/i', $systems );
		$price     = isset( $data['offers']['price'] ) && is_numeric( $data['offers']['price'] ) ? (float) $data['offers']['price'] : null;

		// og-only mode (no offers): treat as free/open-source so scope check passes.
		$is_free   = ( null !== $price && 0.0 === $price ) || ( null === $price && ! empty( $data['_lgd_og_only'] ) );
		$free_type = ( null !== $price && 0.0 === $price ) ? 'Permanently Free' : ( ! empty( $data['_lgd_og_only'] ) ? 'Open Source' : '' );

		// Collect artwork: JSON-LD image → og:image (_lgd_image).
		$images = array();
		foreach ( array( 'image', '_lgd_image' ) as $key ) {
			if ( ! empty( $data[ $key ] ) ) {
				$img = esc_url_raw( is_array( $data[ $key ] ) ? $data[ $key ][0] : $data[ $key ] );
				if ( $img && ! in_array( $img, $images, true ) ) { $images[] = $img; }
			}
		}

		return array(
			'external_id'    => hash( 'sha256', $data['_lgd_source_url'] ),
			'title'          => sanitize_text_field( $data['name'] ),
			'source_url'     => esc_url_raw( $data['_lgd_source_url'] ),
			'description'    => sanitize_textarea_field( isset( $data['description'] ) ? $data['description'] : '' ),
			'developer'      => sanitize_text_field( $author ),
			'publisher'      => sanitize_text_field( isset( $data['publisher']['name'] ) ? $data['publisher']['name'] : '' ),
			'release_date'   => sanitize_text_field( isset( $data['datePublished'] ) ? $data['datePublished'] : '' ),
			'platforms'      => $systems,
			'genres'         => isset( $data['genre'] ) ? LGD_Security::sanitize_string_list( $data['genre'] ) : array(),
			'is_free'        => $is_free, 'free_type' => $free_type,
			'is_indie'       => false, 'indie_confidence' => 0, 'is_mobile' => $is_mobile,
			'current_price'  => $price,
			'currency'       => sanitize_text_field( isset( $data['offers']['priceCurrency'] ) ? $data['offers']['priceCurrency'] : '' ),
			'age_rating'     => sanitize_text_field( isset( $data['contentRating'] ) ? $data['contentRating'] : '' ),
			'screenshots'    => $images,
			'confidence'     => 65,
			'retrieved_at'   => current_time( 'mysql', true ),
			'raw'            => $data,
		);
	}

	// -------------------------------------------------------------------------

	/**
	 * Extract og/twitter/meta tags from HTML into a node-shaped array.
	 * Used as a fallback when no JSON-LD schema is present.
	 */
	private function extract_og_meta( $html, $url ) {
		$meta = array( '_lgd_source_url' => $url, '_lgd_og_only' => true );

		// Title: og:title → twitter:title → <title>
		foreach ( array( array( 'og:title', 'property' ), array( 'twitter:title', 'name' ) ) as $t ) {
			$v = $this->meta_attr( $html, $t[0], $t[1] );
			if ( $v ) { $meta['name'] = html_entity_decode( $v, ENT_QUOTES ); break; }
		}
		if ( empty( $meta['name'] ) && preg_match( '#<title[^>]*>([^<]+)</title>#i', $html, $m ) ) {
			$meta['name'] = trim( html_entity_decode( $m[1], ENT_QUOTES ) );
		}

		// Description
		foreach ( array( array( 'og:description', 'property' ), array( 'description', 'name' ) ) as $t ) {
			$v = $this->meta_attr( $html, $t[0], $t[1] );
			if ( $v ) { $meta['description'] = html_entity_decode( $v, ENT_QUOTES ); break; }
		}

		// Image: og:image → twitter:image
		foreach ( array( array( 'og:image', 'property' ), array( 'twitter:image', 'name' ), array( 'twitter:image:src', 'name' ) ) as $t ) {
			$v = $this->meta_attr( $html, $t[0], $t[1] );
			if ( $v ) {
				$img = html_entity_decode( $v, ENT_QUOTES );
				// Resolve relative URLs.
				if ( strpos( $img, '//' ) === 0 ) {
					$img = ( wp_parse_url( $url, PHP_URL_SCHEME ) ?: 'https' ) . ':' . $img;
				} elseif ( strpos( $img, '/' ) === 0 ) {
					$p = wp_parse_url( $url );
					$img = $p['scheme'] . '://' . $p['host'] . $img;
				}
				$meta['_lgd_image'] = esc_url_raw( $img );
				break;
			}
		}

		return $meta;
	}

	/** Extract a single <meta> content value; handles both attribute orderings. */
	private function meta_attr( $html, $key, $attr ) {
		$k = preg_quote( $key, '#' );
		if ( preg_match( '#<meta\s[^>]*' . $attr . '=["\']' . $k . '["\'][^>]*content=["\']([^"\']*)["\']#i', $html, $m ) ) { return $m[1]; }
		if ( preg_match( '#<meta\s[^>]*content=["\']([^"\']*)["\'][^>]*' . $attr . '=["\']' . $k . '["\']#i', $html, $m ) ) { return $m[1]; }
		return '';
	}

	public function get_source_name() { return 'Approved official website structured metadata'; }
	public function get_source_url() { return ''; }
	public function get_rate_limit() { return 20; }
	public function health_check() {
		$valid = $this->validate_configuration();
		return is_wp_error( $valid ) ? $valid : array( 'ok' => true, 'message' => __( 'Official-site structured metadata is enabled for approved domains.', 'legend-game-directory' ) );
	}
}
