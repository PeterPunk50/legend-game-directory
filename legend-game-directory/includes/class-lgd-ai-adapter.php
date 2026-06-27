<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * OpenAI adapter — direct wp_remote_post, no WordPress AI Client dependency.
 * API key must be defined as LGD_OPENAI_API_KEY in wp-config.php.
 */
final class LGD_AI_Adapter {

	const API_CHAT  = 'https://api.openai.com/v1/chat/completions';
	const API_IMAGE = 'https://api.openai.com/v1/images/generations';
	const MODEL     = 'gpt-4o-mini';

	public static function available() {
		return defined( 'LGD_OPENAI_API_KEY' ) && '' !== trim( (string) LGD_OPENAI_API_KEY );
	}

	public static function provider_configured() {
		return self::available();
	}

	public static function ensure_provider_credentials() {}

	public static function summarize( $source_bundle ) {
		$ready = self::preflight( 'text' );
		if ( is_wp_error( $ready ) ) { return $ready; }

		$source_bundle = is_array( $source_bundle ) ? $source_bundle : array();
		$source_json   = wp_json_encode( $source_bundle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( strlen( $source_json ) > 100000 ) {
			return new WP_Error( 'lgd_ai_input_large', __( 'The source bundle is too large.', 'legend-game-directory' ) );
		}

		$system = 'You produce factual game-directory summaries from supplied evidence only. Never invent scores, prices, review counts, dates, developers, availability, identifiers, or offer expiries. Every factual claim must cite one supplied source URL from the input. Use neutral original wording. Return only valid JSON with exactly these fields: title (string), short_description (string), full_summary (string), free_type (string), best_for (string), seo_title (string), meta_description (string), image_prompt (string), is_free (boolean), is_indie (boolean), is_mobile (boolean), indie_confidence (number 0-100), confidence (number 0-100), platforms (array of strings), genres (array of strings), pros (array of strings), cons (array of strings), monetization_notes (array of strings), safety_notes (array of strings), source_claims (array of objects each with claim and source_url string fields).';

		$result = self::chat_request(
			array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => "Create a game record from this source bundle:\n" . $source_json ),
			),
			1800,
			true
		);
		if ( is_wp_error( $result ) ) { return $result; }

		self::record_usage( $result['usage']['input'], $result['usage']['output'], 'summary' );
		$decoded = json_decode( $result['text'], true );
		$valid   = self::validate_output( $decoded, $source_bundle );
		return is_wp_error( $valid ) ? $valid : $decoded;
	}

	/**
	 * Extract ORIGINAL, paraphrased research notes from a fetched source page.
	 * Never copies sentences; never invents facts. Separates facts from community
	 * opinion and flags conflicts. Returns a structured array or WP_Error.
	 */
	public static function extract_evidence( $context ) {
		$ready = self::preflight( 'text' );
		if ( is_wp_error( $ready ) ) { return $ready; }

		$context = is_array( $context ) ? $context : array();
		$json    = wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( strlen( $json ) > 120000 ) {
			$context['text'] = mb_substr( (string) ( $context['text'] ?? '' ), 0, 50000 );
			$json            = wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		$system = 'You extract STRUCTURED, ORIGINAL research notes from a supplied game source page for an editorial team. '
			. 'NEVER copy or closely paraphrase sentences from the source — restate each point as a short, original factual note in your own words. '
			. 'Do NOT invent facts, statistics, weapons, characters, maps, abilities, prices, or patch changes that are not present in the source. '
			. 'Separate verified facts (stated by the source) from community opinion. Identify any conflicting or contradictory claims. '
			. 'Return ONLY valid JSON with exactly these fields: '
			. 'publisher (string), date_published (string or empty), season (string or empty), patch (string or empty), '
			. 'facts (array of short original factual statements grounded in the source), '
			. 'community_observations (array of short statements that are opinion/community advice rather than official fact), '
			. 'conflicts (array of strings describing contradictions or disputed points), '
			. 'claims_needing_verification (array of strings an editor should double-check). '
			. 'If the source has little usable information, return empty arrays. Keep every item concise.';

		$result = self::chat_request(
			array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => "Extract research notes from this source.\n" . $json ),
			),
			1800,
			true
		);
		if ( is_wp_error( $result ) ) { return $result; }

		self::record_usage( $result['usage']['input'], $result['usage']['output'], 'research' );
		$data = json_decode( $result['text'], true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'lgd_ai_research_malformed', __( 'AI research extraction returned malformed JSON.', 'legend-game-directory' ) );
		}
		return $data;
	}

	/**
	 * Generate an original, evergreen game guide from a game-context bundle.
	 * Returns a structured array (title, difficulty, reading_time, key_points, body_html, seo fields)
	 * or WP_Error. Content is original advice — the model is instructed not to fabricate specifics.
	 */
	public static function generate_guide( $context, $guide_type ) {
		$ready = self::preflight( 'text' );
		if ( is_wp_error( $ready ) ) { return $ready; }

		$context      = is_array( $context ) ? $context : array();
		$context_json = wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$guide_type   = sanitize_text_field( $guide_type );

		$system = 'You are an expert game-guide writer for an independent game directory. '
			. 'Write ORIGINAL, evergreen, genuinely useful guidance in clear neutral second-person voice. '
			. 'Never copy or paraphrase text from any specific source. '
			. 'Do NOT invent unverifiable specifics: exact prices, exact stats or damage numbers, specific item names, '
			. 'level or boss names, patch numbers, dates, or quotes. When unsure of a specific, keep advice general and '
			. 'transferable for the game\'s genre and known design. Prefer durable strategy, onboarding steps, common '
			. 'beginner mistakes, and decision-making frameworks over fragile facts. '
			. 'Return ONLY valid JSON with exactly these fields: '
			. 'title (string, compelling and specific), '
			. 'difficulty (one of "Beginner","Intermediate","Advanced"), '
			. 'reading_time (integer minutes), '
			. 'key_points (array of 3-5 short strings), '
			. 'body_html (string of clean semantic HTML using ONLY these tags: h2, h3, p, ul, ol, li, strong, em; '
			. 'open with a short intro paragraph, then several h2 sections, no h1, no inline styles, no links, no images), '
			. 'seo_title (string <= 60 chars), '
			. 'meta_description (string <= 160 chars).';

		$user = 'Write a "' . $guide_type . '" guide for the following game. '
			. 'Use the context only as background — do not restate it verbatim or fabricate details beyond it:' . "\n"
			. $context_json;

		$result = self::chat_request(
			array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $user ),
			),
			3200,
			true
		);
		if ( is_wp_error( $result ) ) { return $result; }

		self::record_usage( $result['usage']['input'], $result['usage']['output'], 'guide' );
		$data = json_decode( $result['text'], true );
		if ( ! is_array( $data ) || empty( $data['title'] ) || empty( $data['body_html'] ) ) {
			return new WP_Error( 'lgd_ai_guide_malformed', __( 'AI guide output was malformed.', 'legend-game-directory' ) );
		}
		return $data;
	}

	/**
	 * Rewrite a long/raw store description into a concise, original editorial overview.
	 * Returns array( short_description, overview_html ) or WP_Error. Original wording only —
	 * all store boilerplate, URLs, legal notices, and cross-promotion are stripped.
	 */
	public static function rewrite_overview( $context ) {
		$ready = self::preflight( 'text' );
		if ( is_wp_error( $ready ) ) { return $ready; }

		$context      = is_array( $context ) ? $context : array();
		$context_json = wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( strlen( $context_json ) > 60000 ) {
			$context_json = substr( $context_json, 0, 60000 );
		}

		$system = 'You rewrite long app-store game descriptions into concise, ORIGINAL editorial overviews '
			. 'for an independent game directory. Summarize entirely in your own words — never copy or closely '
			. 'paraphrase the source phrasing. '
			. 'Remove ALL store boilerplate: privacy policy, terms of service, support contacts, permission/camera '
			. 'notices, age-rating disclaimers, "download now" marketing, review-count brags, every URL, and any '
			. 'cross-promotion of other games. Keep only what a player needs: what the game is, its core gameplay, '
			. 'modes, and a few notable features. Stay factual and neutral; do not invent specifics. '
			. 'Return ONLY valid JSON with exactly these fields: '
			. 'short_description (string, 1-2 sentences, max 240 chars, plain text, no HTML), '
			. 'overview_html (string of clean HTML using ONLY these tags: p, ul, li, strong, em — '
			. 'exactly one short intro <p>, then one <ul> of 3-6 concise feature bullets, then optionally one '
			. 'closing <p>; no headings, no links, no images, no inline styles, no store text).';

		$user = 'Rewrite this game description into a concise original overview.' . "\n" . $context_json;

		$result = self::chat_request(
			array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $user ),
			),
			1200,
			true
		);
		if ( is_wp_error( $result ) ) { return $result; }

		self::record_usage( $result['usage']['input'], $result['usage']['output'], 'overview' );
		$data = json_decode( $result['text'], true );
		if ( ! is_array( $data ) || empty( $data['overview_html'] ) || empty( $data['short_description'] ) ) {
			return new WP_Error( 'lgd_ai_overview_malformed', __( 'AI overview output was malformed.', 'legend-game-directory' ) );
		}
		return $data;
	}

	public static function research_candidates( $query ) {
		$settings = LGD_Security::settings();
		if ( empty( $settings['enable_ai_web_search'] ) ) {
			return new WP_Error( 'lgd_web_search_disabled', __( 'AI web research is disabled.', 'legend-game-directory' ) );
		}
		$ready = self::preflight( 'web_search' );
		if ( is_wp_error( $ready ) ) { return $ready; }

		$system = 'You are a game-discovery researcher. Given a query, return a JSON object with a "candidates" array. Each candidate has: title (string), source_url (string), reason (string). Only include free, indie, or mobile games. Return only valid JSON.';
		$result = self::chat_request(
			array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => sanitize_text_field( $query ) ),
			),
			1000,
			true
		);
		if ( is_wp_error( $result ) ) { return $result; }

		self::record_usage( $result['usage']['input'], $result['usage']['output'], 'web_search' );
		$data = json_decode( $result['text'], true );
		if ( ! is_array( $data ) || ! isset( $data['candidates'] ) ) {
			return new WP_Error( 'lgd_ai_malformed', __( 'AI web research returned malformed JSON.', 'legend-game-directory' ) );
		}
		return $data['candidates'];
	}

	public static function generate_artwork( $game_id, $prompt, $aspect_ratio = '16:9' ) {
		$settings = LGD_Security::settings();
		if ( empty( $settings['enable_ai_images'] ) ) {
			return new WP_Error( 'lgd_ai_images_disabled', __( 'AI artwork is disabled.', 'legend-game-directory' ) );
		}
		$ready = self::preflight( 'image' );
		if ( is_wp_error( $ready ) ) { return $ready; }

		$safe_prompt = 'Original editorial game-discovery artwork. No recognizable characters, logos, protected art, fake screenshots, UI, or readable brand names. ' . sanitize_textarea_field( $prompt );
		$size        = ( '1:1' === $aspect_ratio ) ? '1024x1024' : ( str_starts_with( $aspect_ratio, '9:' ) ? '1024x1792' : '1792x1024' );
		$key         = trim( (string) LGD_OPENAI_API_KEY );

		$response = wp_remote_post( self::API_IMAGE, array(
			'timeout' => 90,
			'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'model'           => 'dall-e-3',
				'prompt'          => $safe_prompt,
				'n'               => 1,
				'size'            => $size,
				'response_format' => 'b64_json',
				'quality'         => 'standard',
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			LGD_Logger::log( 'ai_image_error', 'AI artwork HTTP error.', array( 'error' => $response->get_error_message() ), 'error', 'game', $game_id );
			return new WP_Error( 'lgd_ai_image_failure', __( 'AI artwork generation failed.', 'legend-game-directory' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code ) {
			$msg = isset( $body['error']['message'] ) ? $body['error']['message'] : 'Unknown error';
			LGD_Logger::log( 'ai_image_error', 'OpenAI image API error.', array( 'code' => $code, 'error' => $msg ), 'error', 'game', $game_id );
			return new WP_Error( 'lgd_ai_image_failure', $msg );
		}

		$b64   = isset( $body['data'][0]['b64_json'] ) ? $body['data'][0]['b64_json'] : '';
		$bytes = base64_decode( $b64, true );
		if ( false === $bytes || strlen( $bytes ) > 10485760 ) {
			return new WP_Error( 'lgd_ai_image_size', __( 'AI artwork exceeded the size limit.', 'legend-game-directory' ) );
		}

		$upload = wp_upload_bits( 'lgd-ai-editorial-' . absint( $game_id ) . '-' . time() . '.png', null, $bytes );
		if ( ! empty( $upload['error'] ) ) { return new WP_Error( 'lgd_ai_image_upload', $upload['error'] ); }

		$attachment_id = wp_insert_attachment( array(
			'post_mime_type' => 'image/png',
			'post_title'     => 'AI-generated editorial artwork',
			'post_status'    => 'inherit',
		), $upload['file'], $game_id );
		if ( is_wp_error( $attachment_id ) ) { return $attachment_id; }

		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sprintf( __( 'Original editorial artwork for %s', 'legend-game-directory' ), get_the_title( $game_id ) ) );
		update_post_meta( $attachment_id, '_lgd_ai_generated', 1 );
		update_post_meta( $attachment_id, '_lgd_ai_prompt', $safe_prompt );
		update_post_meta( $attachment_id, '_lgd_ai_generated_at', current_time( 'mysql', true ) );
		update_post_meta( $game_id, '_lgd_ai_artwork', array(
			'attachment_id' => $attachment_id,
			'prompt'        => $safe_prompt,
			'generated_at'  => current_time( 'mysql', true ),
		) );
		self::record_usage( 0, 0, 'image' );
		return $attachment_id;
	}

	// ── Private ────────────────────────────────────────────────────────────────

	private static function chat_request( $messages, $max_tokens, $json_mode = false ) {
		$key     = trim( (string) LGD_OPENAI_API_KEY );
		$payload = array(
			'model'      => self::MODEL,
			'messages'   => $messages,
			'max_tokens' => (int) $max_tokens,
		);
		if ( $json_mode ) { $payload['response_format'] = array( 'type' => 'json_object' ); }

		$response = wp_remote_post( self::API_CHAT, array(
			'timeout' => 60,
			'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $payload ),
		) );

		if ( is_wp_error( $response ) ) {
			LGD_Logger::log( 'ai_error', 'OpenAI HTTP error.', array( 'error' => $response->get_error_message() ), 'error' );
			return new WP_Error( 'lgd_ai_failure', __( 'The AI request failed.', 'legend-game-directory' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code ) {
			$msg = isset( $body['error']['message'] ) ? $body['error']['message'] : 'Unknown error (HTTP ' . $code . ')';
			LGD_Logger::log( 'ai_error', 'OpenAI API error.', array( 'code' => $code, 'message' => $msg ), 'error' );
			return new WP_Error( 'lgd_ai_failure', $msg );
		}

		return array(
			'text'  => isset( $body['choices'][0]['message']['content'] ) ? $body['choices'][0]['message']['content'] : '',
			'usage' => array(
				'input'  => (int) ( isset( $body['usage']['prompt_tokens'] ) ? $body['usage']['prompt_tokens'] : 0 ),
				'output' => (int) ( isset( $body['usage']['completion_tokens'] ) ? $body['usage']['completion_tokens'] : 0 ),
			),
		);
	}

	private static function validate_output( $data, $source_bundle ) {
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'lgd_ai_malformed', __( 'AI returned malformed JSON.', 'legend-game-directory' ) );
		}
		$required = array( 'title', 'short_description', 'full_summary', 'free_type', 'best_for', 'seo_title', 'meta_description', 'image_prompt', 'is_free', 'is_indie', 'is_mobile', 'indie_confidence', 'confidence', 'platforms', 'genres', 'pros', 'cons', 'monetization_notes', 'safety_notes', 'source_claims' );
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				return new WP_Error( 'lgd_ai_missing_key', sprintf( __( 'AI output is missing %s.', 'legend-game-directory' ), $key ) );
			}
		}
		$urls = array();
		array_walk_recursive( $source_bundle, function( $value, $key ) use ( &$urls ) {
			if ( is_string( $value ) && ( false !== strpos( $key, 'url' ) || 0 === strpos( $value, 'https://' ) ) ) { $urls[] = esc_url_raw( $value ); }
		} );
		$urls = array_unique( array_filter( $urls ) );
		// Normalize for comparison: decode percent-encoding, lowercase, strip trailing slash.
		$norm_urls = array_map( function( $u ) { return strtolower( rtrim( urldecode( $u ), '/' ) ); }, $urls );
		foreach ( (array) $data['source_claims'] as $claim ) {
			if ( empty( $claim['claim'] ) || empty( $claim['source_url'] ) ) {
				return new WP_Error( 'lgd_ai_unsupported_claim', __( 'AI output contains a malformed source claim.', 'legend-game-directory' ) );
			}
			// Only enforce URL-in-bundle check when the bundle has known source URLs to compare against.
			if ( ! empty( $norm_urls ) ) {
				$claim_norm = strtolower( rtrim( urldecode( esc_url_raw( $claim['source_url'] ) ), '/' ) );
				if ( ! in_array( $claim_norm, $norm_urls, true ) ) {
					return new WP_Error( 'lgd_ai_unsupported_claim', __( 'AI output contains a claim without a supplied source.', 'legend-game-directory' ) );
				}
			}
		}
		return true;
	}

	private static function preflight( $kind ) {
		$settings = LGD_Security::settings();
		if ( empty( $settings['enable_ai'] ) ) {
			return new WP_Error( 'lgd_ai_disabled', __( 'AI is disabled.', 'legend-game-directory' ) );
		}
		if ( ! self::available() ) {
			return new WP_Error( 'lgd_ai_no_key', __( 'No OpenAI API key is configured. Define LGD_OPENAI_API_KEY in wp-config.php.', 'legend-game-directory' ) );
		}
		$daily   = (array) get_option( 'lgd_ai_usage_' . gmdate( 'Ymd' ), array( 'requests' => 0, 'cost' => 0 ) );
		$monthly = (array) get_option( 'lgd_ai_usage_' . gmdate( 'Ym' ),  array( 'requests' => 0, 'cost' => 0 ) );
		if ( (int) $daily['requests'] >= (int) $settings['ai_daily_request_limit'] || (float) $monthly['cost'] >= (float) $settings['ai_monthly_cost_limit'] ) {
			return new WP_Error( 'lgd_ai_budget', sprintf( __( 'AI %s request blocked by the configured budget.', 'legend-game-directory' ), $kind ) );
		}
		return true;
	}

	private static function record_usage( $input_tokens, $output_tokens, $purpose ) {
		$settings = LGD_Security::settings();
		$cost     = ( $input_tokens / 1000000 * (float) $settings['ai_estimated_input_rate'] )
				  + ( $output_tokens / 1000000 * (float) $settings['ai_estimated_output_rate'] );
		foreach ( array( gmdate( 'Ymd' ), gmdate( 'Ym' ) ) as $period ) {
			$opt_key = 'lgd_ai_usage_' . $period;
			$stats   = wp_parse_args( get_option( $opt_key, array() ), array( 'requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost' => 0 ) );
			$stats['requests']++;
			$stats['input_tokens']  += $input_tokens;
			$stats['output_tokens'] += $output_tokens;
			$stats['cost']          += $cost;
			update_option( $opt_key, $stats, false );
		}
		LGD_Logger::log( 'ai_request', 'AI request completed.', array( 'purpose' => $purpose, 'input_tokens' => $input_tokens, 'output_tokens' => $output_tokens, 'estimated_cost' => $cost ) );
	}
}
