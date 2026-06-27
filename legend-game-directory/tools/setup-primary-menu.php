<?php
/**
 * One-time tool: build the primary header menu — membership + content links up top,
 * all policy pages collapsed into an "About" dropdown. Idempotent (rebuilds cleanly).
 *
 * Run with: wp eval-file /path/to/setup-primary-menu.php
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$menu_name = 'Main Menu';
$menu      = wp_get_nav_menu_object( $menu_name );
if ( ! $menu ) {
	$menu_id = wp_create_nav_menu( $menu_name );
	if ( is_wp_error( $menu_id ) ) { echo 'FAIL create menu: ' . $menu_id->get_error_message() . "\n"; return; }
	echo "Created menu '{$menu_name}' (#{$menu_id})\n";
} else {
	$menu_id = (int) $menu->term_id;
	foreach ( (array) wp_get_nav_menu_items( $menu_id ) as $it ) { wp_delete_post( $it->ID, true ); }
	echo "Reusing menu '{$menu_name}' (#{$menu_id}) — cleared old items\n";
}

$pos = 0;
$add = function ( $title, $url, $parent = 0 ) use ( $menu_id, &$pos ) {
	$pos++;
	$id = wp_update_nav_menu_item( $menu_id, 0, array(
		'menu-item-title'     => $title,
		'menu-item-url'       => $url,
		'menu-item-status'    => 'publish',
		'menu-item-type'      => 'custom',
		'menu-item-parent-id' => $parent,
		'menu-item-position'  => $pos,
	) );
	return is_wp_error( $id ) ? 0 : (int) $id;
};

// ── Top-level: content + membership ──────────────────────────────────────────
$add( 'Home', home_url( '/' ) );
if ( post_type_exists( 'game' ) )       { $add( 'Games', get_post_type_archive_link( 'game' ) ); }
if ( post_type_exists( 'game_guide' ) ) { $add( 'Guides', get_post_type_archive_link( 'game_guide' ) ); }
if ( post_type_exists( 'lc_squad' ) )   { $add( 'Squads', get_post_type_archive_link( 'lc_squad' ) ); }

$prem = (int) get_option( 'lcc_page_premium', 0 );
if ( $prem ) { $add( 'Premium', get_permalink( $prem ) ); }

$join = (int) get_option( 'lcc_page_register', 0 );
if ( $join ) { $add( 'Join', get_permalink( $join ) ); }

$dash = (int) get_option( 'lcc_page_dashboard', 0 );
if ( $dash ) { $add( 'My Account', get_permalink( $dash ) ); }

// ── About dropdown holding all policy pages ──────────────────────────────────
$about_page = get_page_by_path( 'about' );
$about_url  = $about_page ? get_permalink( $about_page ) : home_url( '/about' );
$about_id   = $add( 'About', $about_url );

$children = array(
	'editorial-policy'     => 'Editorial Policy',
	'rating-methodology'   => 'Rating Methodology',
	'ai-disclosure'        => 'AI Disclosure',
	'corrections-policy'   => 'Corrections Policy',
	'affiliate-disclosure' => 'Affiliate Disclosure',
	'privacy-policy'       => 'Privacy Policy',
	'terms-of-service'     => 'Terms of Service',
	'contact'              => 'Contact',
);
foreach ( $children as $slug => $label ) {
	$p = get_page_by_path( $slug );
	if ( $p && $about_id ) { $add( $label, get_permalink( $p ), $about_id ); }
}

// ── Assign to a primary/header menu location ─────────────────────────────────
$locations = get_registered_nav_menus();
$target    = '';
foreach ( $locations as $slug => $desc ) {
	if ( false === stripos( $slug, 'footer' ) && preg_match( '/primary|main|header|top/i', $slug . ' ' . $desc ) ) {
		$target = $slug;
		break;
	}
}
if ( ! $target ) {
	foreach ( $locations as $slug => $desc ) {
		if ( false === stripos( $slug, 'footer' ) ) { $target = $slug; break; }
	}
}
if ( $target ) {
	$locs = get_theme_mod( 'nav_menu_locations', array() );
	$locs = is_array( $locs ) ? $locs : array();
	$locs[ $target ] = $menu_id;
	set_theme_mod( 'nav_menu_locations', $locs );
	echo "Assigned to location: {$target}\n";
} else {
	echo 'No suitable location found. Registered locations: ' . ( $locations ? implode( ', ', array_keys( $locations ) ) : '(none)' ) . "\n";
}

echo "Done. {$pos} menu items created.\n";
