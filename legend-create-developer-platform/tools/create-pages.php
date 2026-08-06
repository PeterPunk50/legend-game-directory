<?php
/**
 * One-shot: create the LCDP front-end pages (idempotent — skips existing slugs).
 * Run: wp eval-file wp-content/plugins/legend-create-developer-platform/tools/create-pages.php
 */
$pages = array(
	'developers'          => array( 'title' => 'Developer Services',     'content' => "[lcdp_developer_hero]\n[lcdp_pricing]\n[lcdp_memberships]\n[lcdp_addons]" ),
	'playtest-crew'       => array( 'title' => 'Join the Playtest Crew', 'content' => "[lcdp_playtest_crew]\n[lcdp_tester_apply_form]" ),
	'submit-game'         => array( 'title' => 'Submit Your Game',       'content' => '[lcdp_game_submit_form]' ),
	'developer-dashboard' => array( 'title' => 'Developer Dashboard',    'content' => '[lcdp_developer_dashboard]' ),
	'tester-dashboard'    => array( 'title' => 'Tester Dashboard',       'content' => '[lcdp_tester_dashboard]' ),
);
foreach ( $pages as $slug => $p ) {
	if ( get_page_by_path( $slug ) ) {
		WP_CLI::log( "exists: /$slug/" );
		continue;
	}
	$id = wp_insert_post( array(
		'post_type'    => 'page',
		'post_title'   => $p['title'],
		'post_name'    => $slug,
		'post_content' => $p['content'],
		'post_status'  => 'publish',
	) );
	WP_CLI::log( ( $id && ! is_wp_error( $id ) ) ? "created: /$slug/ (#$id)" : "FAILED: /$slug/" );
}
