<?php
/**
 * One-time tool: create the Contact page and build + assign a Footer menu
 * containing all policy / editorial pages.
 *
 * Run with: wp eval-file /path/to/setup-contact-and-footer.php
 * Safe to run multiple times — skips what already exists.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$admin_email = sanitize_email( get_option( 'admin_email' ) );

// ── 1. Contact page ───────────────────────────────────────────────────────────
$contact_slug = 'contact';
$contact      = get_page_by_path( $contact_slug, OBJECT, 'page' );
if ( $contact ) {
	echo "EXISTS (skipped): Contact (#{$contact->ID})\n";
	$contact_id = $contact->ID;
} else {
	$content = '
<p>We welcome corrections, review requests, and questions about how Legend Game Directory works.</p>

<h2>Report an Error or Request a Review</h2>
<p>The fastest way to flag incorrect information about a specific game is the <strong>"Report incorrect information"</strong> form at the bottom of that game\'s page. Include a source link where possible so we can verify and act quickly.</p>

<h2>General Enquiries</h2>
<p>For everything else — editorial questions, affiliate enquiries, or press — email us at <a href="mailto:' . esc_attr( $admin_email ) . '">' . esc_html( $admin_email ) . '</a>.</p>

<h2>What to Include</h2>
<ul>
<li>The game title or page URL your message relates to.</li>
<li>A clear description of the issue or request.</li>
<li>A source link documenting the correct information, if reporting an error.</li>
</ul>

<p>We aim to acknowledge messages within 48 hours. See our <a href="/corrections-policy">Corrections Policy</a> for how confirmed errors are handled.</p>
';
	$contact_id = wp_insert_post( array(
		'post_title'   => 'Contact',
		'post_name'    => $contact_slug,
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_content' => trim( $content ),
	), true );
	if ( is_wp_error( $contact_id ) ) {
		echo "FAIL: Contact — " . $contact_id->get_error_message() . "\n";
		$contact_id = 0;
	} else {
		echo "CREATED #{$contact_id}: Contact\n";
	}
}

// ── 2. Footer menu ────────────────────────────────────────────────────────────
$menu_name = 'Footer';
$menu      = wp_get_nav_menu_object( $menu_name );
if ( ! $menu ) {
	$menu_id = wp_create_nav_menu( $menu_name );
	if ( is_wp_error( $menu_id ) ) {
		echo "FAIL: could not create Footer menu — " . $menu_id->get_error_message() . "\n";
		return;
	}
	echo "CREATED Footer menu (#{$menu_id})\n";
} else {
	$menu_id = $menu->term_id;
	echo "EXISTS (reusing): Footer menu (#{$menu_id})\n";
}

// Ordered list of footer links by page slug.
$footer_slugs = array(
	'about', 'editorial-policy', 'rating-methodology', 'ai-disclosure',
	'corrections-policy', 'affiliate-disclosure', 'privacy-policy',
	'terms-of-service', 'contact',
);

// Existing items in this menu, keyed by linked object/page ID, to avoid duplicates.
$existing_items = wp_get_nav_menu_items( $menu_id );
$existing_page_ids = array();
if ( $existing_items ) {
	foreach ( $existing_items as $item ) {
		if ( 'post_type' === $item->type && 'page' === $item->object ) {
			$existing_page_ids[] = (int) $item->object_id;
		}
	}
}

$position = count( (array) $existing_items );
foreach ( $footer_slugs as $slug ) {
	$page = get_page_by_path( $slug, OBJECT, 'page' );
	if ( ! $page ) {
		echo "  - missing page (skipped): {$slug}\n";
		continue;
	}
	if ( in_array( (int) $page->ID, $existing_page_ids, true ) ) {
		echo "  - already in menu: {$page->post_title}\n";
		continue;
	}
	$position++;
	$item_id = wp_update_nav_menu_item( $menu_id, 0, array(
		'menu-item-title'     => $page->post_title,
		'menu-item-object'    => 'page',
		'menu-item-object-id' => $page->ID,
		'menu-item-type'      => 'post_type',
		'menu-item-status'    => 'publish',
		'menu-item-position'  => $position,
	) );
	if ( is_wp_error( $item_id ) ) {
		echo "  - FAIL add {$page->post_title}: " . $item_id->get_error_message() . "\n";
	} else {
		echo "  + added: {$page->post_title}\n";
	}
}

// ── 3. Assign menu to a footer location if the theme has one ──────────────────
$locations    = get_registered_nav_menus(); // slug => description
$footer_loc   = '';
foreach ( $locations as $loc_slug => $desc ) {
	if ( false !== stripos( $loc_slug, 'footer' ) || false !== stripos( $desc, 'footer' ) ) {
		$footer_loc = $loc_slug;
		break;
	}
}
if ( $footer_loc ) {
	$assigned = get_theme_mod( 'nav_menu_locations', array() );
	$assigned = is_array( $assigned ) ? $assigned : array();
	$assigned[ $footer_loc ] = $menu_id;
	set_theme_mod( 'nav_menu_locations', $assigned );
	echo "ASSIGNED Footer menu to theme location: {$footer_loc}\n";
} else {
	echo "NOTE: theme has no 'footer' menu location. Registered locations: " . ( $locations ? implode( ', ', array_keys( $locations ) ) : '(none)' ) . "\n";
	echo "      Assign the Footer menu manually, or add it via a Navigation/Footer widget.\n";
}

echo "Done.\n";
