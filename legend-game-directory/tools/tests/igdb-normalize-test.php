<?php
/**
 * Local harness for LGD_Provider_IGDB::normalize_game() — pure logic, no network,
 * no credentials. Stubs only the WordPress functions the mapper touches.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'LGD_VERSION', '0.5.6' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $s ) ) ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function esc_url_raw( $u, $p = null ) { return (string) $u; }
function absint( $v ) { return abs( (int) $v ); }
function current_time( $t, $gmt = 0 ) { return '2026-08-06 00:00:00'; }
function __( $s, $d = '' ) { return $s; }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t ) { return true; }
function delete_transient( $k ) { return true; }
function get_option( $k, $d = array() ) { return $d; }
function home_url( $p = '' ) { return 'https://legendcreate.com' . $p; }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
class WP_Error {
	private $c, $m;
	public function __construct( $c = '', $m = '', $d = null ) { $this->c = $c; $this->m = $m; }
	public function get_error_message() { return $this->m; }
	public function get_error_code() { return $this->c; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }

interface LGD_Provider_Interface {
	public function validate_configuration();
	public function search_games( $query = '', $args = array() );
	public function get_game( $external_id );
	public function normalize_game( $data );
	public function get_source_name();
	public function get_source_url();
	public function get_rate_limit();
	public function health_check();
}
class LGD_Security { public static function settings() { return array( 'igdb_enabled' => true ); } }

// Run from anywhere:  php legend-game-directory/tools/tests/igdb-normalize-test.php
require dirname( __DIR__, 2 ) . '/includes/providers/class-lgd-provider-igdb.php';

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label\n"; }
}

/* A payload shaped like a real IGDB /v4/games row with the fields we request. */
$payload = array(
	'id'   => 1020,
	'name' => 'Grand Theft Auto V',
	'slug' => 'grand-theft-auto-v',
	'summary' => "Open-world action. <b>Los Santos</b>.\nThree criminals.",
	'first_release_date' => 1379376000,     // 2013-09-17 UTC
	'url' => 'https://www.igdb.com/games/grand-theft-auto-v',
	'rating' => 88.7654,
	'rating_count' => 2371,
	'aggregated_rating' => 94.2,
	'genres' => array( array( 'name' => 'Shooter' ), array( 'name' => 'Adventure' ), array( 'name' => 'Indie' ) ),
	'themes' => array( array( 'name' => 'Action' ), array( 'name' => 'Adventure' ) ),  // dup with genres on purpose
	'platforms' => array( array( 'name' => 'PC (Microsoft Windows)' ), array( 'name' => 'PlayStation 5' ) ),
	'cover' => array( 'image_id' => 'co2lbd' ),
	'screenshots' => array(
		array( 'image_id' => 'sc6rqx' ), array( 'image_id' => 'sc6rqy' ), array( 'image_id' => '' ),
	),
	'involved_companies' => array(
		array( 'developer' => true,  'publisher' => false, 'company' => array( 'name' => 'Rockstar North' ) ),
		array( 'developer' => false, 'publisher' => true,  'company' => array( 'name' => 'Rockstar Games' ) ),
		array( 'developer' => true,  'publisher' => true,  'company' => array( 'name' => 'Rockstar Games' ) ),
	),
	'websites' => array(
		array( 'category' => 13, 'url' => 'https://store.steampowered.com/app/271590' ),
		array( 'category' => 1,  'url' => 'https://www.rockstargames.com/V/' ),
	),
	'external_games' => array(
		array( 'category' => 5,  'uid' => 'gta-v' ),
		array( 'category' => 1,  'uid' => '271590' ),
	),
);

$p = new LGD_Provider_IGDB();
$g = $p->normalize_game( $payload );

echo "=== identity and text ===\n";
ok( '1020' === $g['external_id'], 'external_id is the IGDB id as a string' );
ok( 'Grand Theft Auto V' === $g['title'], 'title mapped' );
ok( false === strpos( $g['description'], '<b>' ), 'summary is tag-stripped' );
ok( '2013-09-17' === $g['release_date'], 'unix release date -> Y-m-d (got ' . $g['release_date'] . ')' );

echo "\n=== PRICE HONESTY (property 3) ===\n";
ok( null === $g['is_free'], 'is_free is NULL, not false — IGDB cannot know it' );
ok( null === $g['current_price'], 'current_price is NULL' );
ok( null === $g['original_price'], 'original_price is NULL' );
ok( '' === $g['currency'], 'currency is empty' );

echo "\n=== companies ===\n";
ok( 'Rockstar North, Rockstar Games' === $g['developer'], 'both developers, deduped (got "' . $g['developer'] . '")' );
ok( 'Rockstar Games' === $g['publisher'], 'publisher deduped to one (got "' . $g['publisher'] . '")' );

echo "\n=== genres, themes, platforms ===\n";
ok( in_array( 'Shooter', $g['genres'], true ) && in_array( 'Action', $g['genres'], true ), 'genres and themes merged' );
ok( 1 === count( array_keys( $g['genres'], 'Adventure', true ) ), 'the genre/theme overlap is deduped, not doubled' );
ok( 2 === count( $g['platforms'] ), 'platforms mapped' );
ok( true === $g['is_indie'], 'the Indie genre is detected' );
ok( 60 === $g['indie_confidence'], 'indie confidence is 60, below a first-party store' );
ok( false === $g['is_mobile'], 'a PC/PS5 game is NOT mobile' );

echo "\n=== images ===\n";
ok( 2 === count( $g['screenshots'] ), 'the empty image_id is dropped, not turned into a broken URL' );
ok( 'https://images.igdb.com/igdb/image/upload/t_screenshot_huge/sc6rqx.jpg' === $g['screenshots'][0], 'screenshot URL built' );
ok( 'https://images.igdb.com/igdb/image/upload/t_cover_big/co2lbd.jpg' === $g['cover_image'], 'cover URL built' );

echo "\n=== the Steam handoff ===\n";
ok( '271590' === $g['steam_app_id'], 'the Steam app id is pulled from external_games category 1 (got "' . $g['steam_app_id'] . '")' );
ok( 'https://www.rockstargames.com/V/' === $g['official_website'], 'the official site is websites category 1, not the Steam link' );

echo "\n=== ratings and confidence ===\n";
ok( 88.8 === $g['igdb_rating'], 'user rating rounded to 1dp (got ' . var_export( $g['igdb_rating'], true ) . ')' );
ok( 2371 === $g['igdb_rating_count'], 'rating count mapped' );
ok( 70 === $g['confidence'], 'confidence is 70 — IGDB is community-edited' );

echo "\n=== edge cases ===\n";
$bare = $p->normalize_game( array( 'id' => 7, 'name' => 'Untitled' ) );
ok( '' === $bare['release_date'], 'an unreleased game gets an EMPTY date, not 1970-01-01' );
ok( '' === $bare['steam_app_id'], 'no external_games -> empty steam_app_id' );
ok( array() === $bare['screenshots'], 'no screenshots -> empty array' );
ok( null === $bare['is_free'], 'a bare row still refuses to claim a price' );
ok( false === $bare['is_mobile'], 'no platforms -> not mobile (empty must not read as "all mobile")' );

$mob = $p->normalize_game( array( 'id' => 8, 'name' => 'M', 'platforms' => array( array( 'name' => 'iOS' ), array( 'name' => 'Android' ) ) ) );
ok( true === $mob['is_mobile'], 'iOS+Android IS mobile' );
$mix = $p->normalize_game( array( 'id' => 9, 'name' => 'X', 'platforms' => array( array( 'name' => 'iOS' ), array( 'name' => 'PC (Microsoft Windows)' ) ) ) );
ok( false === $mix['is_mobile'], 'a PC game with an iOS port is NOT a mobile game' );

$evil = $p->normalize_game( array( 'id' => 10, 'name' => 'E', 'cover' => array( 'image_id' => '../../etc/passwd' ) ) );
ok( false === strpos( $evil['cover_image'], '..' ), 'a path-traversal image_id is scrubbed (got "' . $evil['cover_image'] . '")' );

$badsteam = $p->normalize_game( array( 'id' => 11, 'name' => 'B', 'external_games' => array( array( 'category' => 1, 'uid' => 'abc' ) ) ) );
ok( '' === $badsteam['steam_app_id'], 'a non-numeric Steam uid is discarded, not guessed at' );

echo "\n=== config fails closed ===\n";
$v = $p->validate_configuration();
ok( is_wp_error( $v ) && 'lgd_igdb_no_credentials' === $v->get_error_code(), 'enabled but no constants -> refuses with a useful code' );

echo "\nIGDB SUMMARY: $pass passed, $fail failed.\n";
