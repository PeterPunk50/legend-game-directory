<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LGD_Importer {
	public static function import_external_id( $provider_id, $external_id ) {
		$provider = LGD_Provider_Registry::get( sanitize_key( $provider_id ) );
		if ( ! $provider ) { return new WP_Error( 'lgd_provider_missing', __( 'Unknown provider.', 'legend-game-directory' ) ); }
		$data = $provider->get_game( $external_id );
		return is_wp_error( $data ) ? $data : self::upsert( $provider_id, $data );
	}

	public static function import_search( $provider_id, $query = '', $args = array() ) {
		$provider = LGD_Provider_Registry::get( sanitize_key( $provider_id ) );
		if ( ! $provider ) { return new WP_Error( 'lgd_provider_missing', __( 'Unknown provider.', 'legend-game-directory' ) ); }
		$items = $provider->search_games( $query, $args );
		if ( is_wp_error( $items ) ) { return $items; }
		$results = array();
		foreach ( array_slice( (array) $items, 0, 50 ) as $item ) { $results[] = self::upsert( $provider_id, $item ); }
		return $results;
	}

	public static function upsert( $provider_id, $data ) {
		global $wpdb;
		$provider_id = sanitize_key( $provider_id );
		if ( ! is_array( $data ) || empty( $data['external_id'] ) || empty( $data['title'] ) || empty( $data['source_url'] ) ) { return new WP_Error( 'lgd_normalized_incomplete', __( 'Normalized provider data is incomplete.', 'legend-game-directory' ) ); }
		if ( ! self::eligible( $data ) ) {
			LGD_Logger::log( 'import_rejected', 'Game was outside the Free, Indie, or Mobile scope.', array( 'provider' => $provider_id, 'external_id' => $data['external_id'], 'title' => $data['title'] ), 'warning' );
			return new WP_Error( 'lgd_out_of_scope', __( 'Game is outside the approved content scope.', 'legend-game-directory' ) );
		}

		$source_table = LGD_Database::table( 'sources' );
		$game_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT game_id FROM {$source_table} WHERE provider=%s AND external_id=%s", $provider_id, (string) $data['external_id'] ) );
		$is_new = ! $game_id;
		if ( ! $game_id ) { $game_id = self::find_duplicate( $data ); }
		$old_score = $game_id ? get_post_meta( $game_id, '_lgd_automated_score', true ) : null;
		$postarr = array(
			'ID' => $game_id, 'post_type' => 'game', 'post_title' => sanitize_text_field( $data['title'] ),
			'post_excerpt' => sanitize_textarea_field( isset( $data['description'] ) ? wp_trim_words( $data['description'], 35 ) : '' ),
			'post_content' => wp_kses_post( isset( $data['description'] ) ? $data['description'] : '' ),
			'post_status' => $game_id ? get_post_status( $game_id ) : 'pending',
		);
		$game_id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $game_id ) ) { return $game_id; }

		self::save_fields( $game_id, $provider_id, $data );
		self::save_taxonomies( $game_id, $data );
		self::save_source( $game_id, $provider_id, $data );

		$source_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$source_table} WHERE game_id=%d AND status='active'", $game_id ) );
		update_post_meta( $game_id, '_lgd_source_count', $source_count );
		update_post_meta( $game_id, '_lgd_last_verified', current_time( 'mysql', true ) );
		update_post_meta( $game_id, '_lgd_verification_status', (float) $data['confidence'] >= 80 ? 'verified_source' : 'needs_review' );

		$facts = ( isset( $data['rating_facts'] ) && is_array( $data['rating_facts'] ) ) ? $data['rating_facts'] : LGD_Rating_Engine::derive_facts( $data );
		$rating = LGD_Rating_Engine::calculate( $facts, isset( $data['confidence'] ) ? $data['confidence'] : 0 );
		LGD_Rating_Engine::save( $game_id, $rating );
		$flags = self::mandatory_flags( $data, $old_score, $rating['score'], $is_new );
		update_post_meta( $game_id, '_lgd_mandatory_review_flags', $flags );

		$settings = LGD_Security::settings();
		if ( ! empty( $settings['enable_ai'] ) ) {
			$summary = LGD_AI_Adapter::summarize( array( 'provider' => $provider_id, 'retrieved_at' => $data['retrieved_at'], 'source_url' => $data['source_url'], 'facts' => self::facts_for_ai( $data ) ) );
			if ( ! is_wp_error( $summary ) ) { self::save_ai_summary( $game_id, $summary ); }
		}

		if ( $is_new && self::can_auto_publish( $game_id, $data, $flags ) ) { wp_update_post( array( 'ID' => $game_id, 'post_status' => 'publish' ) ); }
		LGD_Logger::log( $is_new ? 'game_created' : 'game_updated', $is_new ? 'Game draft created.' : 'Game source data refreshed.', array( 'provider' => $provider_id, 'external_id' => $data['external_id'], 'flags' => $flags ), 'info', 'game', $game_id );
		return $game_id;
	}

	private static function eligible( $data ) {
		$is_free = ! empty( $data['is_free'] ) || in_array( isset( $data['free_type'] ) ? $data['free_type'] : '', array( 'Permanently Free', 'Free to Play', 'Freemium', 'Free Demo', 'Temporarily Free', 'Open Source' ), true );
		$is_indie = ! empty( $data['is_indie'] ) && isset( $data['indie_confidence'] ) && (float) $data['indie_confidence'] >= 50;
		return $is_free || $is_indie || ! empty( $data['is_mobile'] );
	}

	private static function find_duplicate( $data ) {
		global $wpdb;
		// Match by exact title across any status (pending records have no slug, so a name match would miss them).
		// Only merge when the match is unambiguous (exactly one existing record).
		$title = sanitize_text_field( $data['title'] );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='game' AND post_status<>'trash' AND post_title=%s ORDER BY ID ASC LIMIT 2", $title ) );
		return 1 === count( $ids ) ? (int) $ids[0] : 0;
	}

	private static function save_fields( $game_id, $provider_id, $data ) {
		$map = array(
			'description' => '_lgd_short_description', 'developer' => '_lgd_developer', 'publisher' => '_lgd_publisher',
			'release_date' => '_lgd_release_date', 'last_update_date' => '_lgd_last_update_date', 'free_type' => '_lgd_free_type',
			'is_indie' => '_lgd_is_indie', 'indie_confidence' => '_lgd_indie_confidence', 'is_mobile' => '_lgd_is_mobile',
			'current_price' => '_lgd_current_price', 'original_price' => '_lgd_original_price', 'currency' => '_lgd_currency',
			'temporary_free_start' => '_lgd_temporary_free_start', 'temporary_free_end' => '_lgd_temporary_free_end',
			'in_app_purchases' => '_lgd_in_app_purchases', 'advertising' => '_lgd_advertising', 'multiplayer' => '_lgd_multiplayer',
			'online_requirement' => '_lgd_online_requirement', 'offline_support' => '_lgd_offline_support', 'controller_support' => '_lgd_controller_support',
			'age_rating' => '_lgd_age_rating', 'supported_languages' => '_lgd_supported_languages', 'requirements' => '_lgd_system_requirements',
			'screenshots' => '_lgd_official_screenshots', 'trailer_url' => '_lgd_trailer_url', 'steam_sentiment' => '_lgd_steam_sentiment',
			'steam_review_count' => '_lgd_steam_review_count', 'android_app_id' => '_lgd_android_app_id', 'ios_app_id' => '_lgd_ios_app_id',
			'external_critic_score' => '_lgd_external_critic_score', 'external_user_score' => '_lgd_external_user_score',
		);
		foreach ( $map as $source => $meta ) { if ( array_key_exists( $source, $data ) && null !== $data[ $source ] ) { update_post_meta( $game_id, $meta, $data[ $source ] ); } }
		$url_map = array( 'steam' => '_lgd_steam_url', 'apple' => '_lgd_apple_app_store_url', 'google_play' => '_lgd_google_play_url', 'itch' => '_lgd_itch_url' );
		if ( isset( $url_map[ $provider_id ] ) ) { update_post_meta( $game_id, $url_map[ $provider_id ], esc_url_raw( $data['source_url'] ) ); }
		if ( 'official_site' === $provider_id ) { update_post_meta( $game_id, '_lgd_official_website', esc_url_raw( $data['source_url'] ) ); }
		$ids = (array) get_post_meta( $game_id, '_lgd_provider_ids', true ); $ids[ $provider_id ] = sanitize_text_field( $data['external_id'] ); update_post_meta( $game_id, '_lgd_provider_ids', $ids );
	}

	private static function save_taxonomies( $game_id, $data ) {
		$types = array();
		if ( ! empty( $data['is_free'] ) || ! empty( $data['free_type'] ) ) { $types[] = 'Free Games'; }
		if ( ! empty( $data['is_indie'] ) && (float) $data['indie_confidence'] >= 50 ) { $types[] = 'Indie Games'; }
		if ( ! empty( $data['is_mobile'] ) ) { $types[] = 'Mobile Games'; }
		wp_set_object_terms( $game_id, $types, 'game_type', false );
		wp_set_object_terms( $game_id, isset( $data['platforms'] ) ? LGD_Security::sanitize_string_list( $data['platforms'] ) : array(), 'game_platform', false );
		wp_set_object_terms( $game_id, isset( $data['genres'] ) ? LGD_Security::sanitize_string_list( $data['genres'] ) : array(), 'game_genre', false );
		if ( ! empty( $data['free_type'] ) ) { wp_set_object_terms( $game_id, sanitize_text_field( $data['free_type'] ), 'game_pricing', false ); }
	}

	private static function save_source( $game_id, $provider_id, $data ) {
		global $wpdb;
		$facts = self::facts_for_ai( $data ); $json = wp_json_encode( $facts );
		if ( strlen( $json ) > 100000 ) { $json = wp_json_encode( array( 'truncated' => true, 'source_url' => $data['source_url'] ) ); }
		$wpdb->replace( LGD_Database::table( 'sources' ), array(
			'game_id' => $game_id, 'provider' => $provider_id, 'external_id' => sanitize_text_field( $data['external_id'] ),
			'source_url' => esc_url_raw( $data['source_url'] ), 'retrieved_at' => ! empty( $data['retrieved_at'] ) ? $data['retrieved_at'] : current_time( 'mysql', true ),
			'source_hash' => hash( 'sha256', $json ), 'facts' => $json, 'confidence' => isset( $data['confidence'] ) ? (float) $data['confidence'] : 0, 'status' => 'active',
		), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s' ) );
	}

	private static function facts_for_ai( $data ) {
		unset( $data['raw'], $data['rating_facts'] );
		return map_deep( $data, function( $value ) { return is_string( $value ) ? sanitize_textarea_field( $value ) : $value; } );
	}

	private static function mandatory_flags( $data, $old_score, $new_score, $is_new ) {
		$flags = array();
		if ( ! empty( $data['safety_notes'] ) ) { $flags[] = 'safety_warning'; }
		if ( ! empty( $data['sponsored'] ) ) { $flags[] = 'sponsored_content'; }
		if ( isset( $data['confidence'] ) && (float) $data['confidence'] < 60 ) { $flags[] = 'low_confidence'; }
		if ( $is_new ) { $flags[] = 'new_record'; }
		if ( null !== $old_score && '' !== $old_score && null !== $new_score && abs( (float) $old_score - (float) $new_score ) >= 15 ) { $flags[] = 'major_score_change'; }
		if ( 'Temporarily Free' === ( isset( $data['free_type'] ) ? $data['free_type'] : '' ) && empty( $data['temporary_free_end'] ) ) { $flags[] = 'missing_offer_expiry'; }
		return array_values( array_unique( $flags ) );
	}

	private static function save_ai_summary( $game_id, $summary ) {
		wp_update_post( array( 'ID' => $game_id, 'post_excerpt' => sanitize_textarea_field( $summary['short_description'] ), 'post_content' => wp_kses_post( wpautop( $summary['full_summary'] ) ) ) );
		foreach ( array( 'best_for', 'pros', 'cons', 'monetization_notes', 'safety_notes', 'seo_title', 'meta_description', 'image_prompt' ) as $key ) { update_post_meta( $game_id, '_lgd_' . $key, $summary[ $key ] ); }
		update_post_meta( $game_id, '_lgd_ai_generated_at', current_time( 'mysql', true ) );
	}

	private static function can_auto_publish( $game_id, $data, $flags ) {
		$settings = LGD_Security::settings();
		if ( 'review_everything' === $settings['publication_mode'] || ! empty( $flags ) ) { return false; }
		if ( (float) $data['confidence'] < (float) $settings['min_publish_confidence'] ) { return false; }
		return 'trusted_sources' === $settings['publication_mode'] || 'advanced_automation' === $settings['publication_mode'];
	}
}
