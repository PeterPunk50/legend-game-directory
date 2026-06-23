<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LGD_AI_Adapter {
	public static function available() {
		return class_exists( '\\WordPress\\AiClient\\AiClient' ) && class_exists( '\\WordPress\\OpenAiAiProvider\\Provider\\OpenAiProvider' );
	}

	/**
	 * Inject the OpenAI API key into the core AI Client provider registry.
	 * The connector ("AI Provider for OpenAI") registers the provider class on init:5 but never
	 * sets credentials. We read the key from the LGD_OPENAI_API_KEY constant (defined in wp-config.php,
	 * never stored in options or the repo) and configure the provider once. Hooked on init:6.
	 */
	public static function ensure_provider_credentials() {
		if ( ! self::available() || ! defined( 'LGD_OPENAI_API_KEY' ) ) { return; }
		$key = trim( (string) LGD_OPENAI_API_KEY );
		if ( '' === $key ) { return; }
		try {
			$registry = \WordPress\AiClient\AiClient::defaultRegistry();
			if ( ! in_array( 'openai', (array) $registry->getRegisteredProviderIds(), true ) || $registry->isProviderConfigured( 'openai' ) ) { return; }
			$registry->setProviderRequestAuthentication( 'openai', new \WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication( $key ) );
		} catch ( \Throwable $e ) {
			LGD_Logger::log( 'ai_key_inject_failed', 'Could not configure OpenAI provider credentials.', array( 'error' => $e->getMessage() ), 'warning' );
		}
	}

	public static function provider_configured() {
		try {
			$registry = \WordPress\AiClient\AiClient::defaultRegistry();
			return in_array( 'openai', (array) $registry->getRegisteredProviderIds(), true ) && $registry->isProviderConfigured( 'openai' );
		} catch ( \Throwable $e ) { return false; }
	}

	public static function schema() {
		$strings = array( 'title', 'short_description', 'full_summary', 'free_type', 'best_for', 'seo_title', 'meta_description', 'image_prompt' );
		$properties = array();
		foreach ( $strings as $key ) { $properties[ $key ] = array( 'type' => 'string' ); }
		foreach ( array( 'is_free', 'is_indie', 'is_mobile' ) as $key ) { $properties[ $key ] = array( 'type' => 'boolean' ); }
		foreach ( array( 'indie_confidence', 'confidence' ) as $key ) { $properties[ $key ] = array( 'type' => 'number', 'minimum' => 0, 'maximum' => 100 ); }
		foreach ( array( 'platforms', 'genres', 'pros', 'cons', 'monetization_notes', 'safety_notes' ) as $key ) { $properties[ $key ] = array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ); }
		$properties['source_claims'] = array( 'type' => 'array', 'items' => array(
			'type' => 'object', 'properties' => array( 'claim' => array( 'type' => 'string' ), 'source_url' => array( 'type' => 'string' ) ),
			'required' => array( 'claim', 'source_url' ), 'additionalProperties' => false,
		) );
		return array( 'type' => 'object', 'properties' => $properties, 'required' => array_keys( $properties ), 'additionalProperties' => false );
	}

	public static function summarize( $source_bundle ) {
		$ready = self::preflight( 'text' );
		if ( is_wp_error( $ready ) ) { return $ready; }
		$source_bundle = is_array( $source_bundle ) ? $source_bundle : array();
		$source_json = wp_json_encode( $source_bundle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( strlen( $source_json ) > 100000 ) { return new WP_Error( 'lgd_ai_input_large', __( 'The source bundle is too large.', 'legend-game-directory' ) ); }
		$system = 'You produce factual game-directory summaries from supplied evidence only. Never invent scores, prices, review counts, dates, developers, availability, identifiers, or offer expiries. Every factual claim must cite one supplied source URL. Use neutral original wording. Return only the required JSON.';
		try {
			$builder = \WordPress\AiClient\AiClient::prompt( "Create a game record from this source bundle:\n" . $source_json )
				->usingProvider( 'openai' )->usingSystemInstruction( $system )->usingMaxTokens( 1800 )
				->asOutputMimeType( 'application/json' )->asOutputSchema( self::schema() );
			$result = $builder->generateTextResult();
			self::record_usage( $result, 'summary' );
			$decoded = json_decode( $result->toText(), true );
		} catch ( Throwable $e ) {
			LGD_Logger::log( 'ai_error', 'AI summary failed.', array( 'error' => $e->getMessage() ), 'error' );
			return new WP_Error( 'lgd_ai_failure', __( 'The AI summary request failed.', 'legend-game-directory' ) );
		}
		$valid = self::validate_output( $decoded, $source_bundle );
		return is_wp_error( $valid ) ? $valid : $decoded;
	}

	public static function research_candidates( $query ) {
		$settings = LGD_Security::settings();
		if ( empty( $settings['enable_ai_web_search'] ) ) { return new WP_Error( 'lgd_web_search_disabled', __( 'AI web research is disabled.', 'legend-game-directory' ) ); }
		$ready = self::preflight( 'web_search' );
		if ( is_wp_error( $ready ) ) { return $ready; }
		$allowed = array_values( array_filter( (array) $settings['approved_domains'] ) );
		$schema = array(
			'type' => 'object', 'properties' => array( 'candidates' => array( 'type' => 'array', 'items' => array(
				'type' => 'object', 'properties' => array( 'title' => array( 'type' => 'string' ), 'source_url' => array( 'type' => 'string' ), 'reason' => array( 'type' => 'string' ) ),
				'required' => array( 'title', 'source_url', 'reason' ), 'additionalProperties' => false,
			) ) ), 'required' => array( 'candidates' ), 'additionalProperties' => false,
		);
		try {
			$search = new \WordPress\AiClient\Tools\DTO\WebSearch( $allowed, (array) $settings['blocked_domains'] );
			$result = \WordPress\AiClient\AiClient::prompt( sanitize_text_field( $query ) )
				->usingProvider( 'openai' )->usingSystemInstruction( 'Find candidate free, indie, or mobile games only. Return source links for later provider verification; do not assert unverified facts.' )
				->usingWebSearch( $search )->usingMaxTokens( 1000 )->asOutputMimeType( 'application/json' )->asOutputSchema( $schema )->generateTextResult();
			self::record_usage( $result, 'web_search' );
			$data = json_decode( $result->toText(), true );
		} catch ( Throwable $e ) {
			LGD_Logger::log( 'ai_web_error', 'AI web research failed.', array( 'error' => $e->getMessage() ), 'error' );
			return new WP_Error( 'lgd_ai_web_failure', __( 'AI web research failed.', 'legend-game-directory' ) );
		}
		if ( ! is_array( $data ) || ! isset( $data['candidates'] ) ) { return new WP_Error( 'lgd_ai_malformed', __( 'AI web research returned malformed JSON.', 'legend-game-directory' ) ); }
		return $data['candidates'];
	}

	public static function generate_artwork( $game_id, $prompt, $aspect_ratio = '16:9' ) {
		$settings = LGD_Security::settings();
		if ( empty( $settings['enable_ai_images'] ) ) { return new WP_Error( 'lgd_ai_images_disabled', __( 'AI artwork is disabled.', 'legend-game-directory' ) ); }
		$ready = self::preflight( 'image' );
		if ( is_wp_error( $ready ) ) { return $ready; }
		$prompt = 'Original editorial game-discovery artwork. Do not use recognizable characters, logos, protected game art, fake screenshots, UI, or readable brand names. ' . sanitize_textarea_field( $prompt );
		try {
			$result = \WordPress\AiClient\AiClient::prompt( $prompt )->usingProvider( 'openai' )
				->asOutputMimeType( 'image/webp' )->asOutputMediaAspectRatio( sanitize_text_field( $aspect_ratio ) )->generateImageResult();
			self::record_usage( $result, 'image' );
			$array = $result->toArray();
			$file = self::find_file( $array );
		} catch ( Throwable $e ) {
			LGD_Logger::log( 'ai_image_error', 'AI artwork failed.', array( 'error' => $e->getMessage() ), 'error', 'game', $game_id );
			return new WP_Error( 'lgd_ai_image_failure', __( 'AI artwork generation failed.', 'legend-game-directory' ) );
		}
		if ( empty( $file['base64Data'] ) || empty( $file['mimeType'] ) || ! in_array( $file['mimeType'], array( 'image/webp', 'image/png', 'image/jpeg' ), true ) ) { return new WP_Error( 'lgd_ai_image_invalid', __( 'AI returned an unsupported image.', 'legend-game-directory' ) ); }
		$bytes = base64_decode( $file['base64Data'], true );
		if ( false === $bytes || strlen( $bytes ) > 10485760 ) { return new WP_Error( 'lgd_ai_image_size', __( 'AI artwork exceeded the size limit.', 'legend-game-directory' ) ); }
		$extension = 'image/webp' === $file['mimeType'] ? 'webp' : ( 'image/png' === $file['mimeType'] ? 'png' : 'jpg' );
		$upload = wp_upload_bits( 'lgd-ai-editorial-' . absint( $game_id ) . '-' . time() . '.' . $extension, null, $bytes );
		if ( ! empty( $upload['error'] ) ) { return new WP_Error( 'lgd_ai_image_upload', $upload['error'] ); }
		$attachment_id = wp_insert_attachment( array( 'post_mime_type' => $file['mimeType'], 'post_title' => 'AI-generated editorial artwork', 'post_status' => 'inherit' ), $upload['file'], $game_id );
		if ( is_wp_error( $attachment_id ) ) { return $attachment_id; }
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sprintf( __( 'Original editorial artwork for %s', 'legend-game-directory' ), get_the_title( $game_id ) ) );
		update_post_meta( $attachment_id, '_lgd_ai_generated', 1 );
		update_post_meta( $attachment_id, '_lgd_ai_prompt', $prompt );
		update_post_meta( $attachment_id, '_lgd_ai_generated_at', current_time( 'mysql', true ) );
		update_post_meta( $game_id, '_lgd_ai_artwork', array( 'attachment_id' => $attachment_id, 'prompt' => $prompt, 'generated_at' => current_time( 'mysql', true ) ) );
		return $attachment_id;
	}

	private static function validate_output( $data, $source_bundle ) {
		if ( ! is_array( $data ) ) { return new WP_Error( 'lgd_ai_malformed', __( 'AI returned malformed JSON.', 'legend-game-directory' ) ); }
		$required = array_keys( self::schema()['properties'] );
		foreach ( $required as $key ) { if ( ! array_key_exists( $key, $data ) ) { return new WP_Error( 'lgd_ai_missing_key', sprintf( __( 'AI output is missing %s.', 'legend-game-directory' ), $key ) ); } }
		$urls = array();
		array_walk_recursive( $source_bundle, function( $value, $key ) use ( &$urls ) { if ( is_string( $value ) && ( false !== strpos( $key, 'url' ) || 0 === strpos( $value, 'https://' ) ) ) { $urls[] = esc_url_raw( $value ); } } );
		$urls = array_unique( array_filter( $urls ) );
		foreach ( (array) $data['source_claims'] as $claim ) {
			if ( empty( $claim['claim'] ) || empty( $claim['source_url'] ) || ! in_array( esc_url_raw( $claim['source_url'] ), $urls, true ) ) { return new WP_Error( 'lgd_ai_unsupported_claim', __( 'AI output contains a claim without a supplied source.', 'legend-game-directory' ) ); }
		}
		return true;
	}

	private static function preflight( $kind ) {
		$settings = LGD_Security::settings();
		if ( empty( $settings['enable_ai'] ) ) { return new WP_Error( 'lgd_ai_disabled', __( 'AI is disabled.', 'legend-game-directory' ) ); }
		if ( ! self::available() ) { return new WP_Error( 'lgd_ai_unavailable', __( 'The WordPress AI Client and OpenAI provider are not available.', 'legend-game-directory' ) ); }
		self::ensure_provider_credentials();
		if ( ! self::provider_configured() ) { return new WP_Error( 'lgd_ai_no_key', __( 'No OpenAI API key is configured. Define LGD_OPENAI_API_KEY in wp-config.php.', 'legend-game-directory' ) ); }
		$daily = (array) get_option( 'lgd_ai_usage_' . gmdate( 'Ymd' ), array( 'requests' => 0, 'cost' => 0 ) );
		$monthly = (array) get_option( 'lgd_ai_usage_' . gmdate( 'Ym' ), array( 'requests' => 0, 'cost' => 0 ) );
		if ( (int) $daily['requests'] >= (int) $settings['ai_daily_request_limit'] || (float) $monthly['cost'] >= (float) $settings['ai_monthly_cost_limit'] ) { return new WP_Error( 'lgd_ai_budget', sprintf( __( 'AI %s request blocked by the configured budget.', 'legend-game-directory' ), $kind ) ); }
		return true;
	}

	private static function record_usage( $result, $purpose ) {
		$settings = LGD_Security::settings();
		$usage = $result->getTokenUsage();
		$input = $usage->getPromptTokens(); $output = $usage->getCompletionTokens();
		$cost = ( $input / 1000000 * (float) $settings['ai_estimated_input_rate'] ) + ( $output / 1000000 * (float) $settings['ai_estimated_output_rate'] );
		foreach ( array( gmdate( 'Ymd' ), gmdate( 'Ym' ) ) as $period ) {
			$key = 'lgd_ai_usage_' . $period; $stats = wp_parse_args( get_option( $key, array() ), array( 'requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost' => 0 ) );
			$stats['requests']++; $stats['input_tokens'] += $input; $stats['output_tokens'] += $output; $stats['cost'] += $cost; update_option( $key, $stats, false );
		}
		LGD_Logger::log( 'ai_request', 'AI request completed.', array( 'purpose' => $purpose, 'input_tokens' => $input, 'output_tokens' => $output, 'estimated_cost' => $cost ) );
	}

	private static function find_file( $value ) {
		if ( is_array( $value ) && isset( $value['base64Data'], $value['mimeType'] ) ) { return $value; }
		if ( is_array( $value ) ) { foreach ( $value as $child ) { $found = self::find_file( $child ); if ( $found ) { return $found; } } }
		return array();
	}
}
