<?php
/**
 * One-time tool: replace the Privacy Policy page content with a comprehensive
 * policy covering the membership platform (accounts, profiles, gaming IDs, squads,
 * reviews, testing, referrals, Fygaro payments, AI, analytics, cookies).
 *
 * Run with: wp eval-file /path/to/update-privacy-policy.php
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$content = <<<'HTML'
<p><em>Last updated: June 2026</em></p>
<p>This Privacy Policy explains what information Legend Create ("we", "us") collects through this site and the LegendCreate community, how we use it, and the choices you have. By creating an account or using the site you agree to this policy.</p>

<h2>Who We Are</h2>
<p>Legend Create is an independent gaming community and directory for free, indie, and mobile games. We are not affiliated with, endorsed by, or sponsored by any third-party game or its publisher.</p>

<h2>Information We Collect</h2>
<h3>Account information</h3>
<ul>
<li>Your email address, chosen display name, and a securely hashed password.</li>
<li>Your membership level (free or premium) and account status.</li>
</ul>
<h3>Profile information you provide</h3>
<ul>
<li>Optional bio, favourite games, platforms, genres, and interests.</li>
<li><strong>Gaming IDs</strong> (such as Steam, Xbox, PlayStation, Nintendo, Discord, or other in-game names). These are <strong>private by default</strong> and are only shown publicly if you explicitly choose to make a specific field public.</li>
<li>Your notification and email preferences.</li>
</ul>
<h3>Community activity</h3>
<ul>
<li>Squads you create or join, and your role within them.</li>
<li>Ratings, opinions, reviews, poll votes, and corrections you submit.</li>
<li>Contribution points, badges, and reputation.</li>
<li>Game-testing applications and structured test reports you submit, including device and performance details you choose to provide.</li>
<li>Referral records — if you invite someone, we record that you referred them and whether they activated.</li>
</ul>
<h3>Payment information</h3>
<ul>
<li>If you purchase Legend Premium, payment is processed by our payment provider, <strong>Fygaro</strong>, through WooCommerce. <strong>We do not store your full card details</strong> — they are handled by Fygaro's secure systems.</li>
<li>We retain order records (such as the product, amount, date, and status) needed to provide your membership and for accounting.</li>
</ul>
<h3>Technical information</h3>
<ul>
<li>Standard server logs (IP address, browser type, pages visited, timestamps) kept for security and performance.</li>
<li>Cookies (see below).</li>
</ul>

<h2>How We Use Your Information</h2>
<ul>
<li>To create and operate your account, profile, squads, and membership.</li>
<li>To process Premium purchases and send renewal reminders and account emails (such as email verification).</li>
<li>To calculate points, badges, reputation, and referral activations.</li>
<li>To surface relevant guides, polls, and squads, and to send notifications you have opted into.</li>
<li>To moderate content, prevent fraud and abuse, and keep the community safe.</li>
<li>To understand how the site is used so we can improve it.</li>
</ul>
<p>We do <strong>not</strong> sell your personal information.</p>

<h2>Public vs. Private Information</h2>
<p>Some profile information may appear publicly if you enable a public profile — for example your display name, avatar, favourite games, badges, and squads. <strong>Your email address, payment information, gaming IDs (unless you opt each one in), notification settings, and moderation history are never shown publicly.</strong> You control your public profile fields at any time from your dashboard.</p>

<h2>Third-Party Services</h2>
<ul>
<li><strong>Fygaro</strong> — processes Premium payments. See Fygaro's own privacy policy for how they handle payment data.</li>
<li><strong>WooCommerce</strong> — powers checkout and order records on our own site.</li>
<li><strong>OpenAI</strong> — used to generate game summaries and guide drafts from public game data. We do <strong>not</strong> send your personal member information to OpenAI.</li>
<li><strong>Email delivery</strong> — transactional emails (verification, renewals, notifications) are sent via our email provider.</li>
<li><strong>Analytics</strong> — where enabled, we use privacy-respecting analytics to measure site usage. We do not send personally identifying information into analytics.</li>
<li><strong>Hosting</strong> — our host processes server logs on our behalf.</li>
</ul>

<h2>Cookies</h2>
<ul>
<li><strong>Essential cookies</strong> — used to keep you logged in and operate checkout.</li>
<li><strong>Referral cookie</strong> — if you arrive via a member's invite link, a cookie remembers who referred you for up to 30 days so the referral can be credited when you join.</li>
<li><strong>Analytics cookies</strong> — where analytics is enabled, to measure usage. You can opt out via your browser or the analytics provider's opt-out tools.</li>
</ul>

<h2>Data Retention</h2>
<ul>
<li>Account and community data are retained while your account is active.</li>
<li>Order records are retained as required for accounting and legal purposes.</li>
<li>Server logs are retained for a limited period (typically 30 days).</li>
<li>If you delete your account, we remove or anonymise your personal data except where we must retain certain records by law.</li>
</ul>

<h2>Your Rights</h2>
<p>Depending on your location, you have the right to:</p>
<ul>
<li>Access, export, or correct the personal data we hold about you.</li>
<li>Delete your account and associated personal data.</li>
<li>Control which profile fields are public and hide your gaming IDs.</li>
<li>Manage notification preferences and unsubscribe from emails at any time.</li>
<li>Leave any squad you have joined.</li>
</ul>
<p>To exercise any of these rights, use our <a href="/contact">contact page</a>.</p>

<h2>Children</h2>
<p>You must be at least 16 years old to create an account. Game testing, rewards, and any giveaways are restricted to users 18 and over. We do not knowingly collect personal information from children under 16, and children's profiles are not publicly searchable.</p>

<h2>Security</h2>
<p>We use industry-standard measures to protect your data, including hashed passwords, secure payment processing through Fygaro, access controls, and server-side verification of membership and permissions. No system is perfectly secure, but we work to protect your information.</p>

<h2>Changes to This Policy</h2>
<p>We may update this policy as the platform evolves. Material changes will be reflected by the "Last updated" date above. Continued use of the site after changes constitutes acceptance of the updated policy.</p>

<h2>Contact</h2>
<p>Questions about this policy or your data? Reach us through our <a href="/contact">contact page</a>.</p>
HTML;

$slug = 'privacy-policy';
$page = get_page_by_path( $slug, OBJECT, 'page' );
if ( $page ) {
	wp_update_post( array( 'ID' => $page->ID, 'post_content' => $content, 'post_status' => 'publish' ) );
	echo "Updated Privacy Policy (#{$page->ID}) at /{$slug}/\n";
} else {
	$id = wp_insert_post( array(
		'post_title'   => 'Privacy Policy',
		'post_name'    => $slug,
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_content' => $content,
	) );
	echo is_wp_error( $id ) ? ( 'FAIL: ' . $id->get_error_message() . "\n" ) : "Created Privacy Policy (#{$id}) at /{$slug}/\n";
}
echo "Done.\n";
