<?php
/**
 * One-time tool: fix the Contact page (native [lgd_contact] form, no Contact Form 7
 * dependency) and create/refresh the Privacy Policy page, then set it as WordPress's
 * official privacy policy page.
 *
 * Run with: wp eval-file /path/to/setup-contact-privacy.php
 * Safe to run multiple times.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$admin_email = sanitize_email( get_option( 'admin_email' ) );

// ── 1. Contact page ───────────────────────────────────────────────────────────
$contact_html = '
<p>Use the form below to report an error, ask about a listing, request a guide, or enquire about affiliate partnerships. We aim to reply within 48 hours.</p>
[lgd_contact]
<p>Prefer email? Reach us at <a href="mailto:' . esc_attr( $admin_email ) . '">' . esc_html( $admin_email ) . '</a>. To report a problem with a specific game, the fastest route is the "Report incorrect information" form on that game\'s page.</p>
';

$contact = get_page_by_path( 'contact', OBJECT, 'page' );
if ( $contact ) {
	wp_update_post( array( 'ID' => $contact->ID, 'post_content' => trim( $contact_html ), 'post_status' => 'publish' ) );
	echo "Updated Contact (#{$contact->ID})\n";
} else {
	$id = wp_insert_post( array( 'post_title' => 'Contact', 'post_name' => 'contact', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => trim( $contact_html ) ) );
	echo is_wp_error( $id ) ? ( 'FAIL Contact: ' . $id->get_error_message() . "\n" ) : "Created Contact (#{$id})\n";
}

// ── 2. Privacy Policy page ────────────────────────────────────────────────────
$privacy_html = <<<'HTML'
<p><em>Last updated: June 2026</em></p>
<p>This Privacy Policy explains what information Legend Create ("we", "us") collects, how we use it, and the choices you have. By using this site or creating an account you agree to this policy. We are an independent gaming community and directory and are not affiliated with any third-party game or its publisher.</p>

<h2>Information We Collect</h2>
<h3>Account &amp; profile</h3>
<ul>
<li>Your email address, display name, and a securely hashed password.</li>
<li>Optional profile details: bio, favourite games, platforms, interests, and notification preferences.</li>
<li><strong>Gaming IDs</strong> (Steam, Xbox, PlayStation, Nintendo, Discord, etc.) are <strong>private by default</strong> and shown publicly only if you explicitly opt in per field.</li>
</ul>
<h3>Community activity</h3>
<ul>
<li>Squads you create or join, ratings, opinions, poll votes, points, badges, and referrals.</li>
<li>Game-testing applications and reports you choose to submit.</li>
</ul>
<h3>Payments</h3>
<ul>
<li>Premium purchases are processed by our payment provider, <strong>Fygaro</strong>, via WooCommerce. <strong>We do not store your full card details</strong> — they are handled by Fygaro's secure systems. We keep order records (product, amount, date, status) needed to provide your membership.</li>
</ul>
<h3>Technical</h3>
<ul>
<li>Standard server logs (IP address, browser type, pages visited, timestamps) for security and performance, and cookies (see below).</li>
</ul>

<h2>How We Use Your Information</h2>
<ul>
<li>To operate your account, profile, squads, guides, and membership.</li>
<li>To process Premium purchases and send account &amp; renewal emails.</li>
<li>To moderate content, prevent fraud and abuse, and improve the site.</li>
</ul>
<p>We do <strong>not</strong> sell your personal information.</p>

<h2>Third-Party Services</h2>
<ul>
<li><strong>Fygaro</strong> — payment processing (see Fygaro's own privacy policy).</li>
<li><strong>WooCommerce</strong> — checkout and order records on our own site.</li>
<li><strong>OpenAI</strong> — used to summarize public game data and draft guides. We do <strong>not</strong> send your personal member information to OpenAI.</li>
<li><strong>Email &amp; analytics</strong> — transactional email delivery and privacy-respecting analytics (no personally identifying information is sent into analytics).</li>
</ul>

<h2>Cookies</h2>
<ul>
<li><strong>Essential</strong> — to keep you logged in and operate checkout.</li>
<li><strong>Referral</strong> — if you arrive via a member's invite link, a cookie remembers the referrer for up to 30 days.</li>
<li><strong>Analytics</strong> — where enabled, to measure usage. You can opt out via your browser.</li>
</ul>

<h2>Data Retention</h2>
<p>Account and community data are retained while your account is active. Order records are retained as required for accounting and legal purposes. Server logs are kept for a limited period (typically 30 days). If you delete your account, we remove or anonymise your personal data except where we must retain certain records by law.</p>

<h2>Your Rights</h2>
<p>Depending on your location you may access, export, correct, or delete your personal data; control which profile fields are public; hide your gaming IDs; manage notifications and unsubscribe; and leave any squad. To exercise these rights, use our <a href="/contact">contact page</a>.</p>

<h2>Children</h2>
<p>You must be at least 16 to create an account. Game testing, rewards, and giveaways are restricted to users 18 and over. We do not knowingly collect personal information from children under 16, and children's profiles are not publicly searchable.</p>

<h2>Security</h2>
<p>We use industry-standard measures including hashed passwords, secure payment processing via Fygaro, access controls, and server-side verification of membership and permissions. No system is perfectly secure, but we work to protect your information.</p>

<h2>Changes</h2>
<p>We may update this policy as the platform evolves; material changes are reflected by the "Last updated" date above.</p>

<h2>Contact</h2>
<p>Questions about this policy or your data? Reach us through our <a href="/contact">contact page</a>.</p>
HTML;

$privacy = get_page_by_path( 'privacy-policy', OBJECT, 'page' );
if ( $privacy ) {
	wp_update_post( array( 'ID' => $privacy->ID, 'post_content' => $privacy_html, 'post_status' => 'publish' ) );
	$privacy_id = $privacy->ID;
	echo "Updated Privacy Policy (#{$privacy_id})\n";
} else {
	$privacy_id = wp_insert_post( array( 'post_title' => 'Privacy Policy', 'post_name' => 'privacy-policy', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => $privacy_html ) );
	echo is_wp_error( $privacy_id ) ? ( 'FAIL Privacy: ' . $privacy_id->get_error_message() . "\n" ) : "Created Privacy Policy (#{$privacy_id})\n";
}

// ── 3. Register it as WordPress's privacy policy page ─────────────────────────
if ( $privacy_id && ! is_wp_error( $privacy_id ) ) {
	update_option( 'wp_page_for_privacy_policy', (int) $privacy_id );
	echo "Set as WordPress privacy policy page.\n";
}

echo "Done.\n";
