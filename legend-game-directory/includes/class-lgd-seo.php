<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LGD_SEO {
	public function __construct() {
		add_action( 'wp_head', array( $this, 'head' ), 5 );
		add_filter( 'document_title_parts', array( $this, 'title' ) );
	}

	public function title( $parts ) {
		if ( is_singular( 'game' ) ) { $custom = get_post_meta( get_queried_object_id(), '_lgd_seo_title', true ); if ( $custom ) { $parts['title'] = $custom; } }
		return $parts;
	}

	public function head() {
		if ( is_singular( 'game' ) ) { $this->game_head( get_queried_object_id() ); }
		elseif ( is_post_type_archive( 'game' ) || is_tax( array( 'game_type', 'game_platform', 'game_genre', 'game_pricing' ) ) ) { $this->archive_head(); }
		elseif ( is_front_page() ) { $this->site_head(); }
	}

	private function game_head( $id ) {
		$url = get_permalink( $id ); $description = get_post_meta( $id, '_lgd_meta_description', true );
		if ( ! $description ) { $description = wp_strip_all_tags( get_the_excerpt( $id ) ); }
		echo '<link rel="canonical" href="' . esc_url( $url ) . '">' . "\n";
		echo '<meta property="og:type" content="article"><meta property="og:title" content="' . esc_attr( get_the_title( $id ) ) . '"><meta property="og:description" content="' . esc_attr( $description ) . '"><meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
		if ( has_post_thumbnail( $id ) ) { echo '<meta property="og:image" content="' . esc_url( get_the_post_thumbnail_url( $id, 'large' ) ) . '">' . "\n"; }
		$types = array( 'VideoGame' ); if ( get_post_meta( $id, '_lgd_is_mobile', true ) ) { $types[] = 'SoftwareApplication'; }
		$schema = array(
			'@context' => 'https://schema.org', '@type' => $types, 'name' => get_the_title( $id ), 'url' => $url, 'description' => $description,
			'datePublished' => get_post_meta( $id, '_lgd_release_date', true ), 'dateModified' => get_post_meta( $id, '_lgd_last_update_date', true ),
			'author' => array( '@type' => 'Organization', 'name' => get_post_meta( $id, '_lgd_developer', true ) ),
			'publisher' => array( '@type' => 'Organization', 'name' => get_post_meta( $id, '_lgd_publisher', true ) ),
			'operatingSystem' => wp_get_post_terms( $id, 'game_platform', array( 'fields' => 'names' ) ), 'genre' => wp_get_post_terms( $id, 'game_genre', array( 'fields' => 'names' ) ),
			'applicationCategory' => 'GameApplication',
		);
		$price = get_post_meta( $id, '_lgd_current_price', true ); $currency = get_post_meta( $id, '_lgd_currency', true );
		if ( '' !== (string) $price && $currency ) { $schema['offers'] = array( '@type' => 'Offer', 'price' => (float) $price, 'priceCurrency' => $currency, 'url' => $this->store_url( $id ) ); }
		$community = get_post_meta( $id, '_lgd_community_score', true ); $count = absint( get_post_meta( $id, '_lgd_community_review_count', true ) );
		if ( '' !== (string) $community && $count > 0 ) { $schema['aggregateRating'] = array( '@type' => 'AggregateRating', 'ratingValue' => round( (float) $community / 10, 1 ), 'bestRating' => 10, 'worstRating' => 0, 'ratingCount' => $count ); }
		$this->json( array_filter( $schema ) ); $this->breadcrumbs( $id );
	}

	private function archive_head() {
		$url = is_tax() ? get_term_link( get_queried_object() ) : get_post_type_archive_link( 'game' );
		if ( ! is_wp_error( $url ) ) { echo '<link rel="canonical" href="' . esc_url( $url ) . '">' . "\n"; }
		$posts = get_posts( array( 'post_type' => 'game', 'post_status' => 'publish', 'posts_per_page' => 20, 'fields' => 'ids' ) );
		$items = array(); foreach ( $posts as $index => $id ) { $items[] = array( '@type' => 'ListItem', 'position' => $index + 1, 'url' => get_permalink( $id ), 'name' => get_the_title( $id ) ); }
		$this->json( array( '@context' => 'https://schema.org', '@type' => 'ItemList', 'itemListElement' => $items ) );
	}

	private function site_head() {
		$this->json( array( '@context' => 'https://schema.org', '@graph' => array(
			array( '@type' => 'Organization', '@id' => home_url( '/#organization' ), 'name' => get_bloginfo( 'name' ), 'url' => home_url( '/' ) ),
			array( '@type' => 'WebSite', '@id' => home_url( '/#website' ), 'name' => get_bloginfo( 'name' ), 'url' => home_url( '/' ), 'publisher' => array( '@id' => home_url( '/#organization' ) ), 'potentialAction' => array( '@type' => 'SearchAction', 'target' => home_url( '/games/?s={search_term_string}' ), 'query-input' => 'required name=search_term_string' ) ),
		) ) );
	}

	private function breadcrumbs( $id ) {
		$this->json( array( '@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => array(
			array( '@type' => 'ListItem', 'position' => 1, 'name' => __( 'Home', 'legend-game-directory' ), 'item' => home_url( '/' ) ),
			array( '@type' => 'ListItem', 'position' => 2, 'name' => __( 'Games', 'legend-game-directory' ), 'item' => get_post_type_archive_link( 'game' ) ),
			array( '@type' => 'ListItem', 'position' => 3, 'name' => get_the_title( $id ), 'item' => get_permalink( $id ) ),
		) ) );
	}

	private function store_url( $id ) { foreach ( array( '_lgd_steam_url', '_lgd_google_play_url', '_lgd_apple_app_store_url', '_lgd_itch_url', '_lgd_official_website' ) as $key ) { $url = get_post_meta( $id, $key, true ); if ( $url ) { return $url; } } return get_permalink( $id ); }
	private function json( $data ) { echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n"; }
}
