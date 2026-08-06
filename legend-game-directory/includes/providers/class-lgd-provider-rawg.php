<?php
/**
 * RAWG provider — the read direction that fills the directory.
 *
 * Same job as the IGDB provider sitting beside this one: every other provider
 * answers "tell me about this ONE game on this ONE store", so none of them can
 * tell you a game exists before you already knew its store id. This answers
 * "what games exist".
 *
 * It exists because IGDB turned out to be unreachable in practice: IGDB v4 has
 * no auth path that avoids a Twitch application, Twitch requires 2FA on the
 * account to register one, and Twitch's SMS enrolment would not complete for a
 * Barbados number. RAWG needs a plain API key and nothing else — no OAuth, no
 * app review, no phone. The IGDB provider is kept, registered and DISABLED
 * rather than deleted, because it is written, deployed and proven to fail
 * closed; if the Twitch account is ever cleared it is one constant away.
 *
 * Five properties are deliberate:
 *
 *  1. THE KEY TRAVELS IN THE QUERY STRING, AND THAT IS NOT OUR CHOICE. RAWG
 *     authenticates with `?key=`, so unlike IGDB (whose secret went in a POST
 *     body precisely to stay out of URLs) this key WILL appear in any URL that
 *     is logged. We cannot fix RAWG's design, so we do the part we control:
 *     rawg_scrub() strips the key out of every error message before it can
 *     reach a log, an admin notice or a WP_Error a caller might print. Treat
 *     the key as low-value-but-real: it is a quota credential, not an account
 *     credential, and it should still never be echoed.
 *
 *  2. NO PRICES. RAWG carries store LINKS but not prices. is_free,
 *     current_price and original_price come back null — "not known from this
 *     source" — never false, which would be a claim and wrong for most of a
 *     free-games directory. The Steam app id is extracted from the store link
 *     so the existing Steam provider can answer price. That pairing is the
 *     whole point of returning `steam_app_id`.
 *
 *  3. SCREENSHOTS ARE BEST-EFFORT AND NEVER FATAL. RAWG's detail endpoint does
 *     not include them; they need a second request. A game with no screenshots
 *     is still a game, so a failed or empty screenshot call degrades to an empty
 *     array rather than failing the import. This is the one place here where a
 *     partial answer is better than none — identity and description are the
 *     import, art is enrichment.
 *
 *  4. ATTRIBUTION IS A LICENCE TERM, NOT A COURTESY. RAWG's free tier requires
 *     visible credit and a link back. get_source_name()/get_source_url() are
 *     what the UI attributes with, and normalize_game() returns `source_url`
 *     pointing at the game's own RAWG page. Do not strip those.
 *
 *  5. CONFIDENCE IS 70. RAWG is aggregated and community-edited. It is broad
 *     and usually right, but it is not a first-party listing the way an App
 *     Store page is, and a directory that scores its own certainty should not
 *     claim first-party certainty for an aggregator.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LGD_Provider_RAWG implements LGD_Provider_Interface {

	const API_BASE = 'https://api.rawg.io/api/';

	/** Hosts this provider may reach. Passed explicitly on every call. */
	private function hosts() {
		return array( 'api.rawg.io' );
	}

	/** The key from wp-config, trimmed. '' when the constant is absent. */
	private function api_key() {
		return defined( 'LGD_RAWG_API_KEY' ) ? trim( (string) LGD_RAWG_API_KEY ) : '';
	}

	/**
	 * Property 1: remove the API key from anything that might be shown or logged.
	 *
	 * Applied to EVERY error message this class returns. WordPress transport
	 * errors quote the failing URL, and that URL carries ?key= — so without this
	 * a DNS blip writes the credential into the audit log.
	 */
	private function scrub( $text ) {
		$key = $this->api_key();
		$text = (string) $text;
		if ( '' !== $key ) { $text = str_replace( $key, '[key]', $text ); }
		return preg_replace( '/([?&]key=)[^&\s]+/i', '$1[redacted]', $text );
	}

	/** A WP_Error whose message has been through scrub(). The only error path here. */
	private function error( $code, $message ) {
		return new WP_Error( $code, $this->scrub( $message ) );
	}

	public function validate_configuration() {
		$settings = LGD_Security::settings();
		if ( empty( $settings['rawg_enabled'] ) ) {
			return new WP_Error( 'lgd_rawg_disabled', __( 'RAWG is disabled.', 'legend-game-directory' ) );
		}
		if ( '' === $this->api_key() ) {
			return new WP_Error(
				'lgd_rawg_no_key',
				__( 'RAWG needs an API key. Get one free at rawg.io/apidocs and define LGD_RAWG_API_KEY in wp-config.php — never in the database.', 'legend-game-directory' )
			);
		}
		return true;
	}

	/* ------------------------------------------------------------- requests */

	/**
	 * One GET against a RAWG endpoint, with the key appended and the response decoded.
	 *
	 * $query is merged AFTER the key so a caller can never accidentally overwrite
	 * it, and the whole URL is built with add_query_arg so values are encoded.
	 */
	private function get( $path, $query = array() ) {
		$key = $this->api_key();
		if ( '' === $key ) {
			return new WP_Error( 'lgd_rawg_no_key', __( 'No RAWG API key is configured.', 'legend-game-directory' ) );
		}
		$url = add_query_arg(
			array_merge( (array) $query, array( 'key' => $key ) ),
			self::API_BASE . ltrim( (string) $path, '/' )
		);

		$response = LGD_Security::safe_remote_get( $url, $this->hosts() );
		if ( is_wp_error( $response ) ) {
			// Property 1: the message may quote the URL, key and all.
			return $this->error( 'lgd_rawg_request_failed', $response->get_error_message() );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return $this->error( 'lgd_rawg_bad_json', __( 'RAWG returned a response that could not be read.', 'legend-game-directory' ) );
		}
		return $data;
	}

	/* ------------------------------------------------------------- lookups */

	public function search_games( $query = '', $args = array() ) {
		$valid = $this->validate_configuration();
		if ( is_wp_error( $valid ) ) { return $valid; }

		$query = trim( wp_strip_all_tags( (string) $query ) );
		if ( '' === $query ) {
			return new WP_Error( 'lgd_rawg_no_query', __( 'RAWG search needs something to search for.', 'legend-game-directory' ) );
		}
		$limit = isset( $args['limit'] ) ? max( 1, min( 40, (int) $args['limit'] ) ) : 10;

		$data = $this->get( 'games', array( 'search' => $query, 'page_size' => $limit ) );
		if ( is_wp_error( $data ) ) { return $data; }

		$games = array();
		foreach ( (array) ( isset( $data['results'] ) ? $data['results'] : array() ) as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			// The list endpoint carries short_screenshots but no description or
			// companies. Normalising it directly would produce a thin record that
			// LOOKS complete, so search results are normalised from the list row
			// and the caller re-fetches with get_game() before importing.
			$games[] = $this->normalize_game( $row );
		}
		return $games;
	}

	/**
	 * One game by RAWG id or slug, with screenshots.
	 *
	 * RAWG accepts either an integer id or the slug on this endpoint, so both are
	 * allowed through — a slug is how a human finds a game on rawg.io, and
	 * refusing it would make the admin importer harder to use for no gain.
	 */
	public function get_game( $external_id ) {
		$valid = $this->validate_configuration();
		if ( is_wp_error( $valid ) ) { return $valid; }

		$ref = $this->sanitize_ref( $external_id );
		if ( '' === $ref ) {
			return new WP_Error( 'lgd_rawg_invalid_id', __( 'Invalid RAWG game id or slug.', 'legend-game-directory' ) );
		}

		$data = $this->get( 'games/' . rawurlencode( $ref ) );
		if ( is_wp_error( $data ) ) { return $data; }
		if ( empty( $data['id'] ) ) {
			return new WP_Error( 'lgd_rawg_missing', __( 'RAWG returned no game for that id.', 'legend-game-directory' ) );
		}

		// Property 3: enrichment, never fatal.
		$shots = $this->get( 'games/' . (int) $data['id'] . '/screenshots', array( 'page_size' => 12 ) );
		if ( ! is_wp_error( $shots ) && ! empty( $shots['results'] ) ) {
			$data['_lgd_screenshots'] = $shots['results'];
		}

		return $this->normalize_game( $data );
	}

	/** ids stay numeric, slugs keep the lowercase-hyphen shape RAWG uses. */
	private function sanitize_ref( $value ) {
		$value = trim( (string) $value );
		if ( ctype_digit( $value ) ) { return (string) absint( $value ); }
		$slug = preg_replace( '/[^a-z0-9\-]/', '', strtolower( $value ) );
		return (string) $slug;
	}

	/* ----------------------------------------------------------- normalize */

	public function normalize_game( $data ) {
		$genres    = $this->names( isset( $data['genres'] ) ? $data['genres'] : array() );
		$tags      = $this->names( isset( $data['tags'] ) ? $data['tags'] : array() );
		$developer = $this->names( isset( $data['developers'] ) ? $data['developers'] : array() );
		$publisher = $this->names( isset( $data['publishers'] ) ? $data['publishers'] : array() );

		// platforms[] entries wrap the real record: {platform: {name: ...}}.
		$platforms = array();
		foreach ( (array) ( isset( $data['platforms'] ) ? $data['platforms'] : array() ) as $row ) {
			if ( ! empty( $row['platform']['name'] ) ) { $platforms[] = sanitize_text_field( $row['platform']['name'] ); }
		}

		$screens = $this->screenshots( $data );
		$cover   = ! empty( $data['background_image'] ) ? esc_url_raw( $data['background_image'] ) : '';

		// description_raw is the plain-text twin of description (which is HTML).
		// Preferring it means no tag-stripping guesswork; the fallback still strips.
		$description = ! empty( $data['description_raw'] )
			? trim( (string) $data['description_raw'] )
			: wp_strip_all_tags( isset( $data['description'] ) ? $data['description'] : '' );

		$is_indie = in_array( 'Indie', $genres, true ) || in_array( 'Indie', $tags, true );

		return array(
			'external_id'  => (string) ( isset( $data['id'] ) ? absint( $data['id'] ) : 0 ),
			'title'        => sanitize_text_field( isset( $data['name'] ) ? $data['name'] : '' ),
			'source_url'   => ! empty( $data['slug'] )
				? 'https://rawg.io/games/' . sanitize_title( $data['slug'] )   // property 4: attribution target
				: 'https://rawg.io/',
			'description'  => $description,
			'developer'    => implode( ', ', $developer ),
			'publisher'    => implode( ', ', $publisher ),
			// RAWG gives a clean Y-m-d already. `tba` games have released = null.
			'release_date' => ! empty( $data['released'] ) ? sanitize_text_field( $data['released'] ) : '',
			'platforms'    => $platforms,
			'genres'       => $genres,

			// Property 2. RAWG links to stores; it does not price them.
			'is_free'        => null,
			'free_type'      => '',
			'current_price'  => null,
			'original_price' => null,
			'currency'       => '',

			'is_indie'         => $is_indie,
			'indie_confidence' => $is_indie ? 60 : 0,
			'is_mobile'        => $this->looks_mobile( $platforms ),
			'screenshots'      => $screens,
			'cover_image'      => $cover,

			// The handoff: Steam for the price RAWG cannot give.
			'steam_app_id'     => $this->steam_app_id( isset( $data['stores'] ) ? $data['stores'] : array() ),
			'official_website' => ! empty( $data['website'] ) ? esc_url_raw( $data['website'] ) : '',

			'rawg_rating'       => isset( $data['rating'] ) ? round( (float) $data['rating'], 2 ) : null,
			'rawg_rating_count' => isset( $data['ratings_count'] ) ? absint( $data['ratings_count'] ) : 0,
			'metacritic'        => isset( $data['metacritic'] ) && null !== $data['metacritic'] ? absint( $data['metacritic'] ) : null,

			'confidence'   => 70,
			'retrieved_at' => current_time( 'mysql', true ),
			'raw'          => $data,
		);
	}

	/* --------------------------------------------------------------- bits */

	/**
	 * Screenshots from whichever shape we have.
	 *
	 * get_game() attaches the dedicated endpoint's results as _lgd_screenshots;
	 * a search row carries short_screenshots instead. Both are [{image: url}].
	 */
	private function screenshots( $data ) {
		$rows = array();
		if ( ! empty( $data['_lgd_screenshots'] ) ) { $rows = (array) $data['_lgd_screenshots']; }
		elseif ( ! empty( $data['short_screenshots'] ) ) { $rows = (array) $data['short_screenshots']; }

		$out = array();
		foreach ( array_slice( $rows, 0, 12 ) as $row ) {
			if ( ! empty( $row['image'] ) ) { $out[] = esc_url_raw( $row['image'] ); }
		}
		return array_values( array_unique( $out ) );
	}

	/** Flatten RAWG's {name: ...} wrappers to a clean list. */
	private function names( $rows ) {
		$out = array();
		foreach ( (array) $rows as $row ) {
			if ( ! empty( $row['name'] ) ) { $out[] = sanitize_text_field( $row['name'] ); }
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * The Steam app id, dug out of the store link.
	 *
	 * RAWG store id 1 is Steam. Unlike IGDB there is no bare app id field — only
	 * the storefront URL — so it is parsed out of the /app/<digits>/ path. The
	 * host is checked first: a store row whose URL is not actually on Steam
	 * yields nothing rather than whatever digits happen to be in the path.
	 */
	private function steam_app_id( $stores ) {
		foreach ( (array) $stores as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$store_id = isset( $row['store']['id'] ) ? (int) $row['store']['id'] : 0;
			$url      = isset( $row['url'] ) ? (string) $row['url'] : '';
			if ( 1 !== $store_id || '' === $url ) { continue; }

			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( 'store.steampowered.com' !== $host && 'steampowered.com' !== $host ) { continue; }
			if ( preg_match( '#/app/(\d+)#', (string) wp_parse_url( $url, PHP_URL_PATH ), $m ) ) { return $m[1]; }
		}
		return '';
	}

	/** Mobile only if EVERY platform is mobile — a PC game with an iOS port is not one. */
	private function looks_mobile( $platforms ) {
		if ( empty( $platforms ) ) { return false; }
		foreach ( $platforms as $name ) {
			if ( ! preg_match( '/\b(ios|iphone|ipad|android)\b/i', $name ) ) { return false; }
		}
		return true;
	}

	public function get_source_name() { return 'RAWG'; }
	public function get_source_url() { return 'https://rawg.io/'; }

	/**
	 * The real constraint is a MONTHLY quota (20,000 on the free tier), not a
	 * per-minute rate — RAWG publishes no per-second limit. 60 is a sane ceiling
	 * for this registry's per-minute contract; the quota is what to watch.
	 */
	public function get_rate_limit() { return 60; }

	/**
	 * A real check: one live request, not a config echo. A provider that reports
	 * healthy without having talked to the API is how a dead key stays invisible
	 * until an import run fails.
	 */
	public function health_check() {
		$valid = $this->validate_configuration();
		if ( is_wp_error( $valid ) ) { return $valid; }
		$data = $this->get( 'games', array( 'page_size' => 1 ) );
		if ( is_wp_error( $data ) ) { return $data; }
		if ( ! isset( $data['results'] ) ) {
			return $this->error( 'lgd_rawg_health_shape', __( 'RAWG answered, but not with a game list.', 'legend-game-directory' ) );
		}
		return array(
			'ok'      => true,
			'message' => sprintf(
				/* translators: %s: total number of games RAWG reports */
				__( 'RAWG answered a live query (%s games in the catalogue).', 'legend-game-directory' ),
				number_format_i18n( isset( $data['count'] ) ? (int) $data['count'] : 0 )
			),
		);
	}
}
