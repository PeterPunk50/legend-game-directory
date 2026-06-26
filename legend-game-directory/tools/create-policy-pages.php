<?php
/**
 * One-time tool: create all Legend Game Directory policy / editorial pages.
 * Run with: wp eval-file /path/to/create-policy-pages.php
 * Safe to run multiple times — skips pages that already exist.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$pages = array(

	array(
		'title'   => 'About Legend Game Directory',
		'slug'    => 'about',
		'content' => '
<h2>What We Do</h2>
<p>Legend Game Directory is an independent guide to free, indie, and mobile games. We track what games cost to play, how they make money, and whether they are worth your time — without sponsored rankings or paid placements.</p>

<h2>How Games Get Listed</h2>
<p>Games are imported automatically from public sources including the Apple App Store. Every listing must pass an eligibility check: it must be free to download, independently developed, or a mobile title. Paid AAA games are out of scope.</p>
<p>Raw import data is never published directly. Each game goes through our rating engine, which scores it against documented criteria, and a human editorial review before it goes live.</p>

<h2>The Monetization Grade</h2>
<p>Every game receives a Monetization Grade from A to F. The grade is assigned by a deterministic rules engine — not editorial opinion — based on whether the game uses in-app purchases, advertising, and how aggressively. An A means you can play the complete game for free with no monetization pressure. An F means the game uses predatory or deceptive monetization patterns.</p>

<h2>What We Do Not Do</h2>
<ul>
<li>We do not accept payment for reviews or listings.</li>
<li>We do not publish scores we cannot justify with documented criteria.</li>
<li>We do not use AI to assign grades, scores, or prices — only to summarize verified source data.</li>
</ul>

<h2>Contact</h2>
<p>To report an error, request a review, or ask about affiliate relationships, use our <a href="/contact">contact page</a>.</p>
',
	),

	array(
		'title'   => 'Editorial Policy',
		'slug'    => 'editorial-policy',
		'content' => '
<p><em>Last updated: June 2026</em></p>

<h2>Scope</h2>
<p>Legend Game Directory covers free-to-play, free-to-download, open-source, indie, and mobile games. We do not cover paid titles from major publishers unless a free version or demo exists.</p>

<h2>How Listings Are Created</h2>
<p>Games are discovered automatically via official storefronts and APIs. The import pipeline extracts factual data (title, developer, pricing model, platform availability) and stores it with a source URL and retrieval timestamp. No editorial content is written from memory — every published claim traces back to a stored source.</p>

<h2>Scoring</h2>
<p>The automated score is calculated by our rating engine against a fixed set of criteria: platform breadth, monetization transparency, price accuracy, source confidence, and verified data freshness. Scores are not adjusted based on developer relationships or advertiser status.</p>

<h2>Monetization Grades</h2>
<p>The A–F Monetization Grade is assigned by a deterministic algorithm, not editorial judgement. The same inputs always produce the same grade. Grades can be overridden by a human editor only when the algorithmic output is provably wrong, and every override is logged with a reason.</p>

<h2>AI Use</h2>
<p>We use AI to write game summaries and meta descriptions after the rules engine has finished. AI output is generated from stored source data only — the AI is explicitly instructed not to invent facts. See our <a href="/ai-disclosure">AI Disclosure</a> for details.</p>

<h2>Corrections</h2>
<p>Errors are corrected promptly. See our <a href="/corrections-policy">Corrections Policy</a>.</p>
',
	),

	array(
		'title'   => 'AI Disclosure',
		'slug'    => 'ai-disclosure',
		'content' => '
<p><em>Last updated: June 2026</em></p>

<h2>Where We Use AI</h2>
<p>We use OpenAI\'s GPT-4o-mini model to generate game summaries, short descriptions, pros and cons lists, and SEO meta descriptions. AI-generated content is marked with a generation timestamp stored against each listing.</p>

<h2>What AI Is Allowed to Do</h2>
<ul>
<li>Summarize factual information already present in the imported source data.</li>
<li>Write descriptions in clear, neutral language.</li>
<li>Suggest genre tags and platform classifications based on source data.</li>
</ul>

<h2>What AI Cannot Do</h2>
<ul>
<li><strong>Assign scores or grades.</strong> The automated score and Monetization Grade are produced by deterministic rule engines before AI runs. AI cannot alter them.</li>
<li><strong>Invent facts.</strong> The AI prompt explicitly prohibits inventing prices, review counts, release dates, developer names, or availability. Every factual claim in AI output must cite a source URL from the import data.</li>
<li><strong>Make recommendations based on relationships.</strong> AI has no knowledge of advertiser or affiliate relationships.</li>
</ul>

<h2>Human Review</h2>
<p>AI-generated content is reviewed by an editor before a listing is published. Listings with low source confidence or incomplete data are held in pending status regardless of AI output quality.</p>

<h2>Limitations</h2>
<p>AI summaries reflect the state of source data at the time of generation. If a game\'s pricing or availability has changed, the summary may be outdated until the next verified data refresh. Check the "Last verified" date on each listing.</p>
',
	),

	array(
		'title'   => 'Rating Methodology',
		'slug'    => 'rating-methodology',
		'content' => '
<h2>Overview</h2>
<p>Every game on Legend Game Directory receives two independent assessments: an <strong>Automated Score</strong> (0–100) and a <strong>Monetization Grade</strong> (A–F). Both are calculated by rule engines from verified source data — not editorial opinion.</p>

<h2>Automated Score</h2>
<p>The score measures how well a game satisfies criteria that matter to players looking for free, indie, or mobile games. Points are awarded for:</p>
<ul>
<li><strong>Platform availability</strong> — available on more platforms scores higher.</li>
<li><strong>Pricing transparency</strong> — verified price with a known free type scores higher than unverified data.</li>
<li><strong>Source confidence</strong> — data verified from multiple independent sources scores higher.</li>
<li><strong>Monetization clarity</strong> — clearly documented monetization model scores higher than ambiguous data.</li>
<li><strong>Data freshness</strong> — recently verified data scores higher than outdated records.</li>
</ul>
<p>Missing data does not add points. A score shows as "Rating Pending" when insufficient verified data exists.</p>

<h2>Monetization Grade</h2>
<table>
<thead><tr><th>Grade</th><th>Meaning</th></tr></thead>
<tbody>
<tr><td><strong>A</strong></td><td>Free to play with no in-app purchases and no advertising. Complete game, no monetization pressure.</td></tr>
<tr><td><strong>B</strong></td><td>Optional cosmetic or convenience IAP only. No advertising. Core gameplay fully free.</td></tr>
<tr><td><strong>C</strong></td><td>In-app purchases or non-intrusive advertising present. Gameplay not gated by monetization.</td></tr>
<tr><td><strong>D</strong></td><td>Aggressive IAP, energy systems, or intrusive advertising that affects gameplay progression.</td></tr>
<tr><td><strong>F</strong></td><td>Predatory monetization: loot boxes, deceptive pricing, or gambling mechanics.</td></tr>
<tr><td><strong>Pending</strong></td><td>Insufficient verified data to assign a grade.</td></tr>
</tbody>
</table>
<p>Grades are assigned algorithmically. A human editor may override a grade only when the source data is provably wrong, and every override is logged.</p>

<h2>Data Freshness</h2>
<p>Each listing shows when its data was last verified: <strong>Current</strong> (within 30 days), <strong>Aging</strong> (31–90 days), <strong>Outdated</strong> (over 90 days), or <strong>Unverified</strong> (no verification date recorded).</p>

<h2>Source Confidence</h2>
<p>Confidence reflects how many independent sources agree on the game\'s key facts: <strong>High</strong> (75%+), <strong>Medium</strong> (50–74%), <strong>Low</strong> (1–49%), <strong>Unverified</strong> (no sources).</p>
',
	),

	array(
		'title'   => 'Corrections Policy',
		'slug'    => 'corrections-policy',
		'content' => '
<p><em>Last updated: June 2026</em></p>
<p>We correct factual errors as soon as they are identified. There is no minimum size threshold — a wrong price, an incorrect developer name, or an outdated platform listing all warrant correction.</p>

<h2>How to Report an Error</h2>
<p>Use our <a href="/contact">contact page</a> and include: the game title, the incorrect information, and a source URL that documents the correct information. Reports without a verifiable source may be investigated but cannot be actioned immediately.</p>

<h2>What Happens Next</h2>
<ol>
<li>We check the reported error against stored source data.</li>
<li>If confirmed, the listing is updated and the "Last verified" date is refreshed.</li>
<li>If the error originated in our import pipeline, we update the source mapping to prevent recurrence.</li>
<li>Significant corrections (wrong grade, wrong price category) are noted on the listing.</li>
</ol>

<h2>Monetization Grade Disputes</h2>
<p>If you believe a Monetization Grade is wrong, provide evidence of the game\'s actual monetization model. Grade changes require documented source evidence — developer statements, official store listings, or independent reviews.</p>

<h2>Response Time</h2>
<p>We aim to acknowledge corrections within 48 hours and resolve confirmed errors within 7 days.</p>
',
	),

	array(
		'title'   => 'Affiliate Disclosure',
		'slug'    => 'affiliate-disclosure',
		'content' => '
<p><em>Last updated: June 2026</em></p>
<p>Some links on Legend Game Directory are affiliate links. This means we may earn a commission if you click a link and make a purchase, at no additional cost to you.</p>

<h2>Which Links May Be Affiliate Links</h2>
<ul>
<li>Links to paid games or in-app purchase pages on the Apple App Store, Google Play, or Steam.</li>
<li>Links to gaming hardware or accessories where noted.</li>
</ul>

<h2>What Affiliate Relationships Do Not Affect</h2>
<p>Affiliate relationships have no influence on Automated Scores, Monetization Grades, or editorial content. Grades and scores are calculated algorithmically from source data. A game does not receive a better grade or score because we have an affiliate relationship with its developer or storefront.</p>
<p>Games are listed because they meet our editorial scope, not because of commercial relationships.</p>

<h2>FTC Compliance</h2>
<p>This disclosure is made in accordance with the U.S. Federal Trade Commission\'s guidelines on endorsements and testimonials (16 CFR Part 255).</p>
',
	),

	array(
		'title'   => 'Privacy Policy',
		'slug'    => 'privacy-policy',
		'content' => '
<p><em>Last updated: June 2026</em></p>

<h2>What We Collect</h2>
<p>When you visit Legend Game Directory, our hosting provider logs standard server data (IP address, browser type, pages visited, timestamp) for security and performance monitoring. This data is not sold. If you submit a contact form, we collect your email address and message to respond to your enquiry.</p>

<h2>Analytics</h2>
<p>We use Google Analytics 4 to understand how visitors use the site. Google Analytics uses cookies. You can opt out using the <a href="https://tools.google.com/dlpage/gaoptout" rel="noopener noreferrer" target="_blank">Google Analytics Opt-out Browser Add-on</a>.</p>

<h2>Cookies</h2>
<p>We use cookies for analytics and standard WordPress functionality. No advertising cookies are set by us directly.</p>

<h2>Third-Party Services</h2>
<p>Game data is sourced from public APIs including the Apple App Store. Summaries are generated using OpenAI\'s API — source data sent to OpenAI does not include personal visitor information.</p>

<h2>Your Rights</h2>
<p>Under GDPR and applicable privacy laws, you have the right to access, correct, or request deletion of personal data we hold about you. Contact us via our <a href="/contact">contact page</a>.</p>

<h2>Data Retention</h2>
<p>Contact form submissions are retained for 12 months. Server logs are retained for 30 days.</p>
',
	),

	array(
		'title'   => 'Terms of Service',
		'slug'    => 'terms-of-service',
		'content' => '
<p><em>Last updated: June 2026</em></p>

<h2>Use of This Site</h2>
<p>Legend Game Directory provides game information for personal, non-commercial use. You may browse, link to, and share our content, provided you attribute the source.</p>

<h2>Accuracy of Information</h2>
<p>Game pricing, availability, and features change frequently. Always verify current information on the official storefront before making a purchase decision. We are not liable for decisions made based on outdated listings.</p>

<h2>AI-Generated Content</h2>
<p>Some content on this site is generated with AI assistance from stored source data. See our <a href="/ai-disclosure">AI Disclosure</a> for details.</p>

<h2>Affiliate Links</h2>
<p>Some links may be affiliate links. See our <a href="/affiliate-disclosure">Affiliate Disclosure</a>.</p>

<h2>Intellectual Property</h2>
<p>Game titles, logos, and artwork are the property of their respective developers and publishers. Their appearance on this site constitutes editorial commentary, not endorsement. Developers who believe content about their game is inaccurate should see our <a href="/corrections-policy">Corrections Policy</a>.</p>

<h2>Limitation of Liability</h2>
<p>Legend Game Directory is provided "as is". We are not liable for any damages arising from use of this site or reliance on its content.</p>

<h2>Changes</h2>
<p>We may update these terms at any time. Continued use of the site constitutes acceptance of the current terms.</p>
',
	),

);

foreach ( $pages as $p ) {
	$existing = get_page_by_path( $p['slug'], OBJECT, 'page' );
	if ( $existing ) {
		echo "EXISTS (skipped): " . $p['title'] . "\n";
		continue;
	}
	$id = wp_insert_post( array(
		'post_title'   => $p['title'],
		'post_name'    => $p['slug'],
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_content' => trim( $p['content'] ),
	), true );
	if ( is_wp_error( $id ) ) {
		echo "FAIL: " . $p['title'] . " — " . $id->get_error_message() . "\n";
	} else {
		echo "CREATED #" . $id . ": " . $p['title'] . "\n";
	}
}
echo "Done.\n";
