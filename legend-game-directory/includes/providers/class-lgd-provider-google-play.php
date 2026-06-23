<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Google Play Store provider — scrapes public store pages.
 *
 * No API key required. Uses JSON-LD structured data in the page head for
 * core fields and targeted regex for supplementary fields (IAP, ads, rating).
 *
 * Enable via: update_option( 'lgd_settings', array_merge( get_option( 'lgd_settings', [] ), ['google_play_enabled' => true] ) )
 */
final class LGD_Provider_Google_Play implements LGD_Provider_Interface {

	const STORE_BASE   = 'https://play.google.com';
	const DETAILS_PATH = '/store/apps/details';
	const SEARCH_PATH  = '/store/search';
	const RATE_LIMIT   = 5; // requests per minute — conservative for HTML scraping

	// Max response bytes for Play Store pages (they exceed LGD_Security's 1 MB default).
	const FETCH_LIMIT = 3145728; // 3 MB

	public function get_source_name() { return 'Google Play Store'; }
	public function get_source_url()  { return self::STORE_BASE . '/store/games'; }
	public function get_rate_limit()  { return self::RATE_LIMIT; }

	public function validate_configuration() {
		$settings = LGD_Security::settings();
		return empty( $settings['google_play_enabled'] )
			? new WP_Error( 'lgd_google_play_disabled', __( 'Google Play provider is disabled. Set google_play_enabled to true in LGD settings.', 'legend-game-directory' ) )
			: true;
	}

	public function health_check() {
		$valid = $this->validate_configuration();
		if ( is_wp_error( $valid ) ) { return $valid; }
		$html = $this->fetch( add_query_arg( array( 'id' => 'com.supercell.clashofclans', 'hl' => 'en', 'gl' => 'US' ), self::STORE_BASE . self::DETAILS_PATH ) );
		if ( is_wp_error( $html ) ) { return $html; }
		return false !== strpos( $html, 'application/ld+json' )
			? array( 'ok' => true, 'message' => __( 'Google Play page returned structured data.', 'legend-game-directory' ) )
			: new WP_Error( 'lgd_google_health_fail', __( 'Google Play page did not contain expected structured data. The page format may have changed.', 'legend-game-directory' ) );
	}

	// -----------------------------------------------------------------------
	// Search
	// -----------------------------------------------------------------------

	public function search_games( $query = '', $args = array() ) {
		$valid = $this->validate_configuration();
		if ( is_wp_error( $valid ) ) { return $valid; }
		if ( LGD_Security::rate_limit( 'google_play_search', self::RATE_LIMIT, 60 ) ) {
			return new WP_Error( 'lgd_google_rate_limit', __( 'Google Play rate limit reached. Try again shortly.', 'legend-game-directory' ) );
		}

		$query = sanitize_text_field( $query );
		if ( '' === $query ) { return array(); }
		$limit = min( 20, max( 1, isset( $args['limit'] ) ? absint( $args['limit'] ) : 10 ) );

		$url  = add_query_arg( array( 'q' => rawurlencode( $query ), 'c' => 'apps', 'hl' => 'en', 'gl' => 'US' ), self::STORE_BASE . self::SEARCH_PATH );
		$html = $this->fetch( $url );
		if ( is_wp_error( $html ) ) { return $html; }

		$ids = $this->extract_package_ids( $html, $limit );
		if ( empty( $ids ) ) {
			return new WP_Error( 'lgd_google_no_results', __( 'No Google Play results found for that query.', 'legend-game-directory' ) );
		}

		$games = array();
		foreach ( $ids as $id ) {
			$game = $this->get_game( $id );
			if ( ! is_wp_error( $game ) ) {
				$games[] = $game;
			}
			usleep( 700000 ); // 0.7 s between detail page requests
		}
		return $games;
	}

	// -----------------------------------------------------------------------
	// Single game
	// -----------------------------------------------------------------------

	public function get_game( $external_id ) {
		$valid = $this->validate_configuration();
		if ( is_wp_error( $valid ) ) { return $valid; }

		$package_id = $this->sanitize_package_id( (string) $external_id );
		if ( is_wp_error( $package_id ) ) { return $package_id; }

		if ( LGD_Security::rate_limit( 'google_play_detail', self::RATE_LIMIT, 60 ) ) {
			return new WP_Error( 'lgd_google_rate_limit', __( 'Google Play rate limit reached.', 'legend-game-directory' ) );
		}

		$url  = add_query_arg( array( 'id' => $package_id, 'hl' => 'en', 'gl' => 'US' ), self::STORE_BASE . self::DETAILS_PATH );
		$html = $this->fetch( $url );
		if ( is_wp_error( $html ) ) { return $html; }

		$raw = $this->parse_detail_page( $html, $package_id, $url );
		if ( is_wp_error( $raw ) ) { return $raw; }
		return $this->normalize_game( $raw );
	}

	// -----------------------------------------------------------------------
	// Normalize
	// -----------------------------------------------------------------------

	public function normalize_game( $raw ) {
		if ( ! is_array( $raw ) || empty( $raw['title'] ) || empty( $raw['package_id'] ) ) {
			return new WP_Error( 'lgd_google_incomplete', __( 'Google Play data is incomplete.', 'legend-game-directory' ) );
		}

		$price   = isset( $raw['price'] ) ? (float) $raw['price'] : 0.0;
		$is_free = $price <= 0.0;
		$has_iap = ! empty( $raw['in_app_purchases'] );
		$has_ads = ! empty( $raw['has_ads'] );

		if ( $is_free && $has_iap ) {
			$free_type = 'Freemium';
		} elseif ( $is_free ) {
			$free_type = 'Free to Play';
		} else {
			$free_type = null;
		}

		// Convert 0–5 star rating to 0–100 scale for external_user_score.
		$user_score = ( isset( $raw['rating'] ) && is_numeric( $raw['rating'] ) )
			? round( min( 5.0, max( 0.0, (float) $raw['rating'] ) ) * 20, 1 )
			: null;

		$genres = isset( $raw['category'] ) && '' !== $raw['category'] ? array( $raw['category'] ) : array();

		return array(
			'external_id'         => $raw['package_id'],
			'title'               => sanitize_text_field( $raw['title'] ),
			'description'         => sanitize_textarea_field( isset( $raw['description'] ) ? $raw['description'] : '' ),
			'developer'           => sanitize_text_field( isset( $raw['developer'] ) ? $raw['developer'] : '' ),
			'publisher'           => sanitize_text_field( isset( $raw['developer'] ) ? $raw['developer'] : '' ),
			'source_url'          => esc_url_raw( $raw['source_url'] ),
			'android_app_id'      => $raw['package_id'],
			'is_free'             => $is_free,
			'is_mobile'           => true,
			'is_indie'            => false,
			'indie_confidence'    => 0,
			'free_type'           => $free_type,
			'current_price'       => $price > 0.0 ? $price : null,
			'original_price'      => $price > 0.0 ? $price : null,
			'currency'            => 'USD',
			'in_app_purchases'    => $has_iap ? 'Yes' : null,
			'advertising'         => $has_ads ? 'Contains ads' : null,
			'platforms'           => array( 'Android' ),
			'genres'              => $genres,
			'age_rating'          => isset( $raw['content_rating'] ) ? sanitize_text_field( $raw['content_rating'] ) : null,
			'external_user_score' => $user_score,
			'screenshots'         => isset( $raw['screenshots'] ) ? array_values( array_filter( (array) $raw['screenshots'] ) ) : array(),
			'last_update_date'    => isset( $raw['last_updated_iso'] ) ? $raw['last_updated_iso'] : null,
			'confidence'          => 70, // public storefront, HTML scrape — moderate confidence
			'retrieved_at'        => current_time( 'mysql', true ),
			'raw'                 => $raw,
		);
	}

	// -----------------------------------------------------------------------
	// HTTP fetch — bypasses LGD_Security::safe_remote_get() size cap (1 MB)
	// but still validates the URL via validate_public_url().
	// -----------------------------------------------------------------------

	private function fetch( $url ) {
		$valid = LGD_Security::validate_public_url( $url, array( 'play.google.com' ) );
		if ( is_wp_error( $valid ) ) { return $valid; }

		$response = wp_remote_get( $valid, array(
			'timeout'             => 25,
			'redirection'         => 3,
			'reject_unsafe_urls'  => true,
			'limit_response_size' => self::FETCH_LIMIT,
			'headers'             => array(
				// Mobile Chrome UA — Play Store returns more consistent SSR content.
				'User-Agent'      => 'Mozilla/5.0 (Linux; Android 11; Pixel 5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
				'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				'Accept-Language' => 'en-US,en;q=0.9',
			),
		) );

		if ( is_wp_error( $response ) ) { return $response; }
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) { return new WP_Error( 'lgd_google_not_found', __( 'App not found on Google Play.', 'legend-game-directory' ) ); }
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'lgd_google_http_' . $code, sprintf( __( 'Google Play returned HTTP %d.', 'legend-game-directory' ), $code ) );
		}
		return (string) wp_remote_retrieve_body( $response );
	}

	// -----------------------------------------------------------------------
	// Search result parsing — extract package IDs from search page HTML.
	// -----------------------------------------------------------------------

	private function extract_package_ids( $html, $limit ) {
		// Play Store search results embed app links as /store/apps/details?id=<package>
		preg_match_all(
			'#/store/apps/details\?id=([A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)+)#',
			$html,
			$matches
		);
		$ids = array_values( array_unique( isset( $matches[1] ) ? $matches[1] : array() ) );
		return array_slice( $ids, 0, $limit );
	}

	// -----------------------------------------------------------------------
	// Detail page parsing
	// -----------------------------------------------------------------------

	private function parse_detail_page( $html, $package_id, $source_url ) {
		$raw = array(
			'package_id' => $package_id,
			'source_url' => $source_url,
		);

		// 1. JSON-LD structured data (in <head>, most reliable).
		if ( preg_match( '#<script type="application/ld\+json"[^>]*>(.*?)</script>#si', $html, $m ) ) {
			$ld = json_decode( trim( $m[1] ), true );
			if ( is_array( $ld ) ) {
				if ( ! empty( $ld['name'] ) )        { $raw['title']     = html_entity_decode( $ld['name'], ENT_QUOTES ); }
				if ( ! empty( $ld['description'] ) ) { $raw['description'] = html_entity_decode( $ld['description'], ENT_QUOTES ); }
				if ( ! empty( $ld['author']['name'] ) ) { $raw['developer'] = html_entity_decode( $ld['author']['name'], ENT_QUOTES ); }
				if ( isset( $ld['offers']['price'] ) ) { $raw['price'] = (float) $ld['offers']['price']; }
				if ( ! empty( $ld['aggregateRating']['ratingValue'] ) ) { $raw['rating'] = (float) $ld['aggregateRating']['ratingValue']; }
				if ( ! empty( $ld['applicationCategory'] ) ) { $raw['category'] = $this->clean_category( $ld['applicationCategory'] ); }
				if ( ! empty( $ld['operatingSystem'] ) )      { $raw['os'] = $ld['operatingSystem']; }
			}
		}

		// 2. OG / meta fallbacks.
		if ( empty( $raw['title'] ) && preg_match( '/<meta\s+property="og:title"\s+content="([^"]+)"/i', $html, $m ) ) {
			$raw['title'] = html_entity_decode( $m[1], ENT_QUOTES );
		}
		if ( empty( $raw['description'] ) ) {
			if ( preg_match( '/<meta\s+name="description"\s+content="([^"]+)"/i', $html, $m ) ) {
				$raw['description'] = html_entity_decode( $m[1], ENT_QUOTES );
			} elseif ( preg_match( '/<meta\s+property="og:description"\s+content="([^"]+)"/i', $html, $m ) ) {
				$raw['description'] = html_entity_decode( $m[1], ENT_QUOTES );
			}
		}

		// 3. Screenshots from Play CDN (play-lh.googleusercontent.com).
		preg_match_all(
			'#https://play-lh\.googleusercontent\.com/[A-Za-z0-9_\-]+=w\d+[A-Za-z0-9\-]*#',
			$html,
			$sm
		);
		if ( ! empty( $sm[0] ) ) {
			$raw['screenshots'] = array_values( array_unique( array_slice( $sm[0], 0, 8 ) ) );
		}

		// 4. Last updated date — Play Store renders "Updated on MMM DD, YYYY".
		if ( preg_match( '/Updated\s+on\s+((?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s+\d{1,2},?\s+\d{4})/i', $html, $m ) ) {
			$ts = strtotime( $m[1] );
			if ( $ts ) { $raw['last_updated_iso'] = gmdate( 'Y-m-d', $ts ); }
		}
		// Fallback: datePublished in embedded JSON.
		if ( empty( $raw['last_updated_iso'] ) && preg_match( '/"datePublished"\s*:\s*"(\d{4}-\d{2}-\d{2})"/i', $html, $m ) ) {
			$raw['last_updated_iso'] = $m[1];
		}

		// 5. Content rating.
		if ( preg_match( '/(?:content-rating|contentRating)[^>]*>\s*(Everyone(?:\s+10\+)?|Teen|Mature\s+17\+|Adults\s+only\s+18\+|Rating\s+pending)/i', $html, $m ) ) {
			$raw['content_rating'] = trim( $m[1] );
		} elseif ( preg_match( '/"(Everyone(?:\s+10\+)?|Teen|Mature\s+17\+|Adults\s+only\s+18\+|Rating\s+pending)"/', $html, $m ) ) {
			$raw['content_rating'] = trim( $m[1] );
		}

		// 6. IAP and ads disclosures (Google Play renders these as text labels).
		$raw['in_app_purchases'] = (
			false !== stripos( $html, 'in-app purchases' ) ||
			false !== stripos( $html, 'in-app items' )
		);
		$raw['has_ads'] = ( false !== stripos( $html, 'contains ads' ) );

		if ( empty( $raw['title'] ) ) {
			LGD_Logger::log(
				'google_play_parse_failed',
				'Could not extract title from Google Play page.',
				array( 'package_id' => $package_id, 'url' => $source_url ),
				'warning'
			);
			return new WP_Error( 'lgd_google_parse_failed', __( 'Could not extract game data from Google Play page. The page format may have changed.', 'legend-game-directory' ) );
		}

		return $raw;
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	private function sanitize_package_id( $id ) {
		// Preserve original case and dots; strip anything else.
		$id = preg_replace( '/[^a-zA-Z0-9._]/', '', $id );
		if ( ! preg_match( '/^[a-zA-Z][a-zA-Z0-9_]*(?:\.[a-zA-Z][a-zA-Z0-9_]*)+$/', $id ) ) {
			return new WP_Error( 'lgd_google_invalid_id', __( 'The value does not look like a valid Android package ID (e.g. com.example.game).', 'legend-game-directory' ) );
		}
		return $id;
	}

	private function clean_category( $cat ) {
		// Google Play sends "GameAction", "GamePuzzle", etc. Strip the "Game" prefix
		// and insert a space before each subsequent uppercase letter.
		$cat = preg_replace( '/^Game/', '', (string) $cat );
		$cat = preg_replace( '/(?<=[a-z])(?=[A-Z])/', ' ', $cat );
		return trim( $cat );
	}
}
