<?php
/**
 * IGDB provider — the read direction that fills the directory itself.
 *
 * Every other provider here answers "tell me about this ONE game on this ONE
 * store". IGDB answers "what games exist", which is the directory's actual
 * content problem: Apple covers iOS, Google Play covers Android, Steam covers
 * PC storefront facts, and none of them can tell you a game exists before you
 * already knew its store id.
 *
 * Four things about this provider differ from the others, all deliberate:
 *
 *  1. IT IS THE ONLY POST PROVIDER. IGDB's query language (Apicalypse) travels
 *     in the request BODY, so this needs LGD_Security::safe_remote_post(), which
 *     runs the same allowlist + DNS + private-IP validation as the GET helper.
 *     Nothing here calls wp_remote_post directly.
 *
 *  2. AUTH IS TWITCH, AND THE SECRET NEVER TOUCHES THE DATABASE. IGDB is owned
 *     by Twitch, so the credential is a Twitch application's client id/secret,
 *     read from wp-config constants exactly like LGD_OPENAI_API_KEY —
 *     LGD_IGDB_CLIENT_ID / LGD_IGDB_CLIENT_SECRET. The SECRET is never stored,
 *     logged or echoed. The short-lived bearer token derived from it IS cached
 *     in a transient, which is a deliberate and different call: it expires on
 *     its own, it is re-mintable from the constants at any time, and the
 *     alternative is a token request on every single lookup.
 *
 *  3. IGDB HAS NO PRICES, AND THIS FILE DOES NOT PRETEND OTHERWISE. It carries
 *     no price, no "free to play" flag and no sale data. Rather than default
 *     is_free to false — which is a CLAIM, and wrong for the many free games in
 *     this directory — the price fields come back null, meaning "not known from
 *     this source". What IGDB does carry is the game's Steam app id, so the
 *     pairing that actually answers price is IGDB for discovery and identity,
 *     then the existing Steam provider for storefront facts. normalize_game()
 *     surfaces that id as `steam_app_id` precisely so the handoff is possible.
 *
 *  4. CONFIDENCE IS 70, NOT 80+. IGDB is community-edited. It is broad and
 *     usually right, but it is not a first-party source the way an App Store
 *     listing is, and a directory that scores its own certainty should not
 *     claim first-party certainty for a wiki.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LGD_Provider_IGDB implements LGD_Provider_Interface {

	const TOKEN_TRANSIENT = 'lgd_igdb_token';
	const API_BASE        = 'https://api.igdb.com/v4/';
	const TOKEN_URL       = 'https://id.twitch.tv/oauth2/token';
	const IMAGE_BASE      = 'https://images.igdb.com/igdb/image/upload/';

	/** The hosts this provider is allowed to reach. Passed explicitly on every call. */
	private function hosts() {
		return array( 'api.igdb.com', 'id.twitch.tv' );
	}

	/** Credentials from wp-config, trimmed. Either may be '' — see validate_configuration(). */
	private function credentials() {
		$id     = defined( 'LGD_IGDB_CLIENT_ID' ) ? trim( (string) LGD_IGDB_CLIENT_ID ) : '';
		$secret = defined( 'LGD_IGDB_CLIENT_SECRET' ) ? trim( (string) LGD_IGDB_CLIENT_SECRET ) : '';
		return array( $id, $secret );
	}

	public function validate_configuration() {
		$settings = LGD_Security::settings();
		if ( empty( $settings['igdb_enabled'] ) ) {
			return new WP_Error( 'lgd_igdb_disabled', __( 'IGDB is disabled.', 'legend-game-directory' ) );
		}
		list( $id, $secret ) = $this->credentials();
		if ( '' === $id || '' === $secret ) {
			return new WP_Error(
				'lgd_igdb_no_credentials',
				__( 'IGDB needs a Twitch application. Define LGD_IGDB_CLIENT_ID and LGD_IGDB_CLIENT_SECRET in wp-config.php — never in the database.', 'legend-game-directory' )
			);
		}
		return true;
	}

	/* ---------------------------------------------------------------- auth */

	/**
	 * A bearer token, from cache or freshly minted.
	 *
	 * Twitch client-credentials tokens are long-lived (weeks). The transient is
	 * set to expire EARLY — a minute short of what Twitch reports, floored at an
	 * hour and capped at 30 days — so a request is never made with a token that
	 * expires between the cache read and the API call.
	 *
	 * @param bool $force Skip the cache; used once after a 401.
	 */
	private function token( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TOKEN_TRANSIENT );
			if ( is_string( $cached ) && '' !== $cached ) { return $cached; }
		}
		list( $id, $secret ) = $this->credentials();
		if ( '' === $id || '' === $secret ) {
			return new WP_Error( 'lgd_igdb_no_credentials', __( 'IGDB credentials are not configured.', 'legend-game-directory' ) );
		}

		// Credentials go in the BODY, not the query string: a URL is logged by
		// proxies and servers, and a client secret has no business in one.
		$response = LGD_Security::safe_remote_post( self::TOKEN_URL, $this->hosts(), array(
			'body' => array( 'client_id' => $id, 'client_secret' => $secret, 'grant_type' => 'client_credentials' ),
		) );
		if ( is_wp_error( $response ) ) {
			// The transport error may name the URL; it never carries the secret,
			// because the secret was in the body. Pass the message through.
			return new WP_Error( 'lgd_igdb_token_failed', sprintf(
				/* translators: %s: error text from the token endpoint */
				__( 'Could not get an IGDB access token: %s', 'legend-game-directory' ), $response->get_error_message()
			) );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['access_token'] ) || ! is_string( $data['access_token'] ) ) {
			return new WP_Error( 'lgd_igdb_token_shape', __( 'Twitch did not return an access token. Check the client id and secret.', 'legend-game-directory' ) );
		}

		$ttl = isset( $data['expires_in'] ) ? (int) $data['expires_in'] - MINUTE_IN_SECONDS : 0;
		$ttl = max( HOUR_IN_SECONDS, min( $ttl, 30 * DAY_IN_SECONDS ) );
		set_transient( self::TOKEN_TRANSIENT, $data['access_token'], $ttl );
		return $data['access_token'];
	}

	/**
	 * One Apicalypse query against an IGDB endpoint.
	 *
	 * Retries EXACTLY ONCE on a 401, with a freshly minted token, because the
	 * only honest reading of a 401 here is "the cached token died early". Any
	 * second 401 is a credential problem and is returned rather than looped on.
	 */
	private function query( $endpoint, $apicalypse, $retrying = false ) {
		$token = $this->token( $retrying );
		if ( is_wp_error( $token ) ) { return $token; }
		list( $id ) = $this->credentials();

		$response = LGD_Security::safe_remote_post( self::API_BASE . $endpoint, $this->hosts(), array(
			'headers' => array(
				'Client-ID'     => $id,
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'text/plain',
				'Accept'        => 'application/json',
			),
			'body' => $apicalypse,
		) );

		if ( is_wp_error( $response ) && ! $retrying && false !== strpos( $response->get_error_message(), '401' ) ) {
			delete_transient( self::TOKEN_TRANSIENT );
			return $this->query( $endpoint, $apicalypse, true );
		}
		if ( is_wp_error( $response ) ) { return $response; }

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'lgd_igdb_bad_json', __( 'IGDB returned a response that could not be read.', 'legend-game-directory' ) );
		}
		return $decoded;
	}

	/** The field list every lookup asks for. One place, so search and get agree. */
	private function fields() {
		return 'fields name,slug,summary,first_release_date,url,rating,rating_count,aggregated_rating,'
			. 'category,genres.name,themes.name,platforms.name,player_perspectives.name,'
			. 'cover.image_id,screenshots.image_id,'
			. 'involved_companies.developer,involved_companies.publisher,involved_companies.company.name,'
			. 'websites.url,websites.category,external_games.category,external_games.uid;';
	}

	/* ------------------------------------------------------------- lookups */

	/**
	 * Search by name.
	 *
	 * `where version_parent = null` drops the edition clutter — Deluxe, GOTY and
	 * regional variants are separate IGDB records that would otherwise fill a
	 * result list with near-duplicates of the same game.
	 */
	public function search_games( $query = '', $args = array() ) {
		$valid = $this->validate_configuration();
		if ( is_wp_error( $valid ) ) { return $valid; }

		$query = trim( wp_strip_all_tags( (string) $query ) );
		if ( '' === $query ) {
			return new WP_Error( 'lgd_igdb_no_query', __( 'IGDB search needs something to search for.', 'legend-game-directory' ) );
		}
		$limit = isset( $args['limit'] ) ? max( 1, min( 50, (int) $args['limit'] ) ) : 10;

		// Apicalypse has no parameter binding, so the search term is quoted and
		// every quote and backslash inside it is escaped. The term is the only
		// caller-controlled value that reaches the query string.
		$safe = str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $query );

		$rows = $this->query( 'games', 'search "' . $safe . '"; ' . $this->fields()
			. ' where version_parent = null; limit ' . $limit . ';' );
		if ( is_wp_error( $rows ) ) { return $rows; }

		$games = array();
		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) ) { $games[] = $this->normalize_game( $row ); }
		}
		return $games;
	}

	public function get_game( $external_id ) {
		$valid = $this->validate_configuration();
		if ( is_wp_error( $valid ) ) { return $valid; }

		$igdb_id = absint( $external_id );
		if ( ! $igdb_id ) {
			return new WP_Error( 'lgd_igdb_invalid_id', __( 'Invalid IGDB game id.', 'legend-game-directory' ) );
		}
		$rows = $this->query( 'games', $this->fields() . ' where id = ' . $igdb_id . ';' );
		if ( is_wp_error( $rows ) ) { return $rows; }
		if ( empty( $rows[0] ) || ! is_array( $rows[0] ) ) {
			return new WP_Error( 'lgd_igdb_missing', __( 'IGDB returned no game for that id.', 'legend-game-directory' ) );
		}
		return $this->normalize_game( $rows[0] );
	}

	/* ----------------------------------------------------------- normalize */

	public function normalize_game( $data ) {
		$genres = $this->names( isset( $data['genres'] ) ? $data['genres'] : array() );
		$themes = $this->names( isset( $data['themes'] ) ? $data['themes'] : array() );

		$platforms = array();
		foreach ( (array) ( isset( $data['platforms'] ) ? $data['platforms'] : array() ) as $p ) {
			if ( ! empty( $p['name'] ) ) { $platforms[] = sanitize_text_field( $p['name'] ); }
		}

		$developer = array();
		$publisher = array();
		foreach ( (array) ( isset( $data['involved_companies'] ) ? $data['involved_companies'] : array() ) as $inv ) {
			$name = isset( $inv['company']['name'] ) ? sanitize_text_field( $inv['company']['name'] ) : '';
			if ( '' === $name ) { continue; }
			if ( ! empty( $inv['developer'] ) ) { $developer[] = $name; }
			if ( ! empty( $inv['publisher'] ) ) { $publisher[] = $name; }
		}

		$screens = array();
		foreach ( array_slice( (array) ( isset( $data['screenshots'] ) ? $data['screenshots'] : array() ), 0, 12 ) as $shot ) {
			if ( ! empty( $shot['image_id'] ) ) { $screens[] = $this->image_url( $shot['image_id'], 't_screenshot_huge' ); }
		}
		$cover = ! empty( $data['cover']['image_id'] ) ? $this->image_url( $data['cover']['image_id'], 't_cover_big' ) : '';

		// IGDB dates are unix timestamps; an unreleased game simply has none.
		$release = ! empty( $data['first_release_date'] )
			? gmdate( 'Y-m-d', (int) $data['first_release_date'] )
			: '';

		// Indie is a GENRE in IGDB (id 32, name "Indie"). Believing it is fair;
		// claiming certainty about it is not, hence a lower confidence than the
		// first-party stores get.
		$is_indie = in_array( 'Indie', $genres, true );

		return array(
			'external_id'  => (string) ( isset( $data['id'] ) ? absint( $data['id'] ) : 0 ),
			'title'        => sanitize_text_field( isset( $data['name'] ) ? $data['name'] : '' ),
			'source_url'   => isset( $data['url'] ) ? esc_url_raw( $data['url'] ) : '',
			'description'  => wp_strip_all_tags( isset( $data['summary'] ) ? $data['summary'] : '' ),
			'developer'    => implode( ', ', array_unique( $developer ) ),
			'publisher'    => implode( ', ', array_unique( $publisher ) ),
			'release_date' => $release,
			'platforms'    => $platforms,
			'genres'       => array_values( array_unique( array_merge( $genres, $themes ) ) ),

			// Property 3: IGDB carries no pricing at all. null is "unknown from
			// this source" and is NOT the same as free or paid — a directory of
			// free games must never learn "not free" from a source that cannot
			// know it.
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

			// The handoff that makes IGDB + Steam worth pairing: where IGDB knows
			// the Steam listing, the existing Steam provider can be asked for the
			// price and review sentiment IGDB does not have.
			'steam_app_id'     => $this->steam_app_id( isset( $data['external_games'] ) ? $data['external_games'] : array() ),
			'official_website' => $this->official_site( isset( $data['websites'] ) ? $data['websites'] : array() ),

			'igdb_rating'       => isset( $data['rating'] ) ? round( (float) $data['rating'], 1 ) : null,
			'igdb_rating_count' => isset( $data['rating_count'] ) ? absint( $data['rating_count'] ) : 0,
			'igdb_critic_rating' => isset( $data['aggregated_rating'] ) ? round( (float) $data['aggregated_rating'], 1 ) : null,

			'confidence'  => 70,
			'retrieved_at' => current_time( 'mysql', true ),
			'raw'         => $data,
		);
	}

	/* --------------------------------------------------------------- bits */

	/** Flatten an IGDB expanded relation to a clean list of names. */
	private function names( $rows ) {
		$out = array();
		foreach ( (array) $rows as $row ) {
			if ( ! empty( $row['name'] ) ) { $out[] = sanitize_text_field( $row['name'] ); }
		}
		return array_values( array_unique( $out ) );
	}

	/** IGDB's image CDN. $size is one of IGDB's documented t_* presets. */
	private function image_url( $image_id, $size ) {
		$image_id = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $image_id );
		if ( '' === $image_id ) { return ''; }
		return esc_url_raw( self::IMAGE_BASE . $size . '/' . $image_id . '.jpg' );
	}

	/**
	 * The Steam app id, when IGDB records one.
	 *
	 * external_games.category 1 is Steam in IGDB's enum. The uid is the app id as
	 * a string; anything non-numeric is discarded rather than guessed at.
	 */
	private function steam_app_id( $external ) {
		foreach ( (array) $external as $row ) {
			if ( ! is_array( $row ) || 1 !== (int) ( isset( $row['category'] ) ? $row['category'] : 0 ) ) { continue; }
			$uid = isset( $row['uid'] ) ? preg_replace( '/\D/', '', (string) $row['uid'] ) : '';
			if ( '' !== $uid ) { return $uid; }
		}
		return '';
	}

	/** websites.category 1 is the official site in IGDB's enum. */
	private function official_site( $websites ) {
		foreach ( (array) $websites as $row ) {
			if ( ! is_array( $row ) || 1 !== (int) ( isset( $row['category'] ) ? $row['category'] : 0 ) ) { continue; }
			if ( ! empty( $row['url'] ) ) { return esc_url_raw( $row['url'] ); }
		}
		return '';
	}

	/** Mobile if EVERY platform named is a mobile one — a PC game with an iOS port is not a mobile game. */
	private function looks_mobile( $platforms ) {
		if ( empty( $platforms ) ) { return false; }
		foreach ( $platforms as $name ) {
			if ( ! preg_match( '/\b(ios|iphone|ipad|android)\b/i', $name ) ) { return false; }
		}
		return true;
	}

	public function get_source_name() { return 'IGDB'; }
	public function get_source_url() { return 'https://www.igdb.com/'; }

	/** IGDB documents 4 requests per second; this registry counts per minute. */
	public function get_rate_limit() { return 240; }

	/**
	 * A real check, not a config echo: mint a token and ask IGDB for one row.
	 * A provider that reports healthy without having talked to the API is how a
	 * dead credential stays invisible until an import run fails.
	 */
	public function health_check() {
		$valid = $this->validate_configuration();
		if ( is_wp_error( $valid ) ) { return $valid; }
		$rows = $this->query( 'games', 'fields id,name; limit 1;' );
		if ( is_wp_error( $rows ) ) { return $rows; }
		return array(
			'ok'      => true,
			'message' => sprintf(
				/* translators: %d: number of rows IGDB returned for the probe query */
				__( 'IGDB answered a live query (%d row).', 'legend-game-directory' ),
				count( (array) $rows )
			),
		);
	}
}
