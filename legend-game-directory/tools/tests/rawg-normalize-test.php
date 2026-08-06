<?php
/**
 * Local harness for LGD_Provider_RAWG — pure logic, no network, no API key.
 * Run: php legend-game-directory/tools/tests/rawg-normalize-test.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'LGD_VERSION', '0.5.7' );
define( 'LGD_RAWG_API_KEY', 'sk-secret-key-value-1234' );

function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $s ) ) ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function esc_url_raw( $u, $p = null ) { return (string) $u; }
function sanitize_title( $s ) { return preg_replace( '/[^a-z0-9\-]/', '', strtolower( (string) $s ) ); }
function absint( $v ) { return abs( (int) $v ); }
function current_time( $t, $gmt = 0 ) { return '2026-08-06 00:00:00'; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function __( $s, $d = '' ) { return $s; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( (string) $u, $c ); }
function add_query_arg( $args, $url ) { return $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . http_build_query( $args ); }
class WP_Error {
	private $c, $m;
	public function __construct( $c = '', $m = '', $d = null ) { $this->c = $c; $this->m = $m; }
	public function get_error_message() { return $this->m; }
	public function get_error_code() { return $this->c; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }

interface LGD_Provider_Interface {
	public function validate_configuration(); public function search_games( $query = '', $args = array() );
	public function get_game( $external_id ); public function normalize_game( $data );
	public function get_source_name(); public function get_source_url();
	public function get_rate_limit(); public function health_check();
}
class LGD_Security {
	public static $enabled = true;
	public static function settings() { return array( 'rawg_enabled' => self::$enabled ); }
}

require dirname( __DIR__, 2 ) . '/includes/providers/class-lgd-provider-rawg.php';

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label\n"; }
}

/* A payload shaped like a real RAWG /games/{id} detail response. */
$payload = array(
	'id' => 3498,
	'slug' => 'grand-theft-auto-v',
	'name' => 'Grand Theft Auto V',
	'description' => '<p>Rockstar Games went <b>bigger</b>.</p>',
	'description_raw' => "Rockstar Games went bigger, since their previous...",
	'released' => '2013-09-17',
	'tba' => false,
	'background_image' => 'https://media.rawg.io/media/games/456/456dea5e1c7e3cd07060c14e96612001.jpg',
	'website' => 'http://www.rockstargames.com/V/',
	'rating' => 4.4712,
	'ratings_count' => 6839,
	'metacritic' => 92,
	'platforms' => array(
		array( 'platform' => array( 'id' => 4, 'name' => 'PC' ) ),
		array( 'platform' => array( 'id' => 187, 'name' => 'PlayStation 5' ) ),
	),
	'genres' => array( array( 'name' => 'Action' ), array( 'name' => 'Adventure' ) ),
	'tags' => array( array( 'name' => 'Singleplayer' ), array( 'name' => 'Indie' ) ),
	'developers' => array( array( 'name' => 'Rockstar North' ), array( 'name' => 'Rockstar Games' ) ),
	'publishers' => array( array( 'name' => 'Rockstar Games' ) ),
	'stores' => array(
		array( 'store' => array( 'id' => 3, 'name' => 'PlayStation Store' ), 'url' => 'https://store.playstation.com/en-us/product/x' ),
		array( 'store' => array( 'id' => 1, 'name' => 'Steam' ), 'url' => 'https://store.steampowered.com/app/271590/Grand_Theft_Auto_V/' ),
	),
	'_lgd_screenshots' => array(
		array( 'image' => 'https://media.rawg.io/media/screenshots/a7c/a7c43871a54bdb3f2f0898bf2af5b452.jpg' ),
		array( 'image' => 'https://media.rawg.io/media/screenshots/cf4/cf4367daf6a1e33684bf19adb02d16d6.jpg' ),
		array( 'image' => '' ),
	),
);

$p = new LGD_Provider_RAWG();
$g = $p->normalize_game( $payload );

echo "=== identity and text ===\n";
ok( '3498' === $g['external_id'], 'external_id mapped' );
ok( 'Grand Theft Auto V' === $g['title'], 'title mapped' );
ok( false === strpos( $g['description'], '<b>' ), 'description_raw preferred over the HTML field' );
ok( '2013-09-17' === $g['release_date'], 'released passes through as Y-m-d' );
ok( 'https://rawg.io/games/grand-theft-auto-v' === $g['source_url'], 'source_url points at the RAWG page (attribution)' );

echo "\n=== PRICE HONESTY (property 2) ===\n";
ok( null === $g['is_free'], 'is_free is NULL, not false' );
ok( null === $g['current_price'] && null === $g['original_price'], 'both price fields NULL' );

echo "\n=== companies, genres, platforms ===\n";
ok( 'Rockstar North, Rockstar Games' === $g['developer'], 'developers joined (got "' . $g['developer'] . '")' );
ok( 'Rockstar Games' === $g['publisher'], 'publisher mapped' );
ok( 2 === count( $g['platforms'] ), 'platforms unwrapped from the {platform:{name}} shape' );
ok( in_array( 'Action', $g['genres'], true ), 'genres mapped' );
ok( true === $g['is_indie'], 'Indie found in TAGS, not just genres' );
ok( false === $g['is_mobile'], 'a PC/PS5 game is not mobile' );

echo "\n=== media ===\n";
ok( 2 === count( $g['screenshots'] ), 'the empty image is dropped (got ' . count( $g['screenshots'] ) . ')' );
ok( false !== strpos( $g['cover_image'], 'media.rawg.io' ), 'cover taken from background_image' );

echo "\n=== the Steam handoff ===\n";
ok( '271590' === $g['steam_app_id'], 'app id parsed out of the Steam store URL (got "' . $g['steam_app_id'] . '")' );

echo "\n=== scores ===\n";
ok( 92 === $g['metacritic'], 'metacritic mapped' );
ok( 4.47 === $g['rawg_rating'], 'rating rounded to 2dp (got ' . var_export( $g['rawg_rating'], true ) . ')' );
ok( 70 === $g['confidence'], 'confidence is 70 — RAWG is an aggregator' );

echo "\n=== search-row shape (short_screenshots, no description) ===\n";
$row = $p->normalize_game( array(
	'id' => 5286, 'slug' => 'tomb-raider', 'name' => 'Tomb Raider (2013)', 'released' => '2013-03-05',
	'short_screenshots' => array( array( 'image' => 'https://media.rawg.io/media/s1.jpg' ) ),
) );
ok( 1 === count( $row['screenshots'] ), 'short_screenshots used when the detail array is absent' );
ok( '' === $row['description'], 'a list row has no description and does not invent one' );
ok( '' === $row['steam_app_id'], 'no stores -> empty steam_app_id' );

echo "\n=== edge cases ===\n";
$tba = $p->normalize_game( array( 'id' => 1, 'name' => 'Unannounced', 'released' => null, 'tba' => true ) );
ok( '' === $tba['release_date'], 'a TBA game gets an EMPTY date, not 1970-01-01' );
ok( null === $tba['metacritic'], 'absent metacritic stays NULL, not 0' );

$spoof = $p->normalize_game( array( 'id' => 2, 'name' => 'S', 'stores' => array(
	array( 'store' => array( 'id' => 1 ), 'url' => 'https://evil.tld/app/999999/Fake/' ),
) ) );
ok( '' === $spoof['steam_app_id'], 'a store row claiming Steam on a NON-STEAM host yields nothing' );

$wrongstore = $p->normalize_game( array( 'id' => 3, 'name' => 'W', 'stores' => array(
	array( 'store' => array( 'id' => 5 ), 'url' => 'https://store.steampowered.com/app/111/X/' ),
) ) );
ok( '' === $wrongstore['steam_app_id'], 'a GOG-ided row is not read as Steam even on a Steam URL' );

$mob = $p->normalize_game( array( 'id' => 4, 'name' => 'M', 'platforms' => array(
	array( 'platform' => array( 'name' => 'iOS' ) ), array( 'platform' => array( 'name' => 'Android' ) ),
) ) );
ok( true === $mob['is_mobile'], 'iOS+Android is mobile' );
ok( false === $p->normalize_game( array( 'id' => 5, 'name' => 'X' ) )['is_mobile'], 'no platforms -> not mobile' );

echo "\n=== KEY SCRUBBING (property 1) ===\n";
$r = new ReflectionMethod( 'LGD_Provider_RAWG', 'scrub' );
$r->setAccessible( true );
$leak = 'cURL error 7: Failed to connect to https://api.rawg.io/api/games?page_size=1&key=sk-secret-key-value-1234';
$scrubbed = $r->invoke( $p, $leak );
ok( false === strpos( $scrubbed, 'sk-secret-key-value-1234' ), 'the literal key is removed from an error message' );
ok( false !== strpos( $scrubbed, 'Failed to connect' ), 'the useful part of the message survives' );
$scrubbed2 = $r->invoke( $p, 'GET /api/games?key=someothervalue&page=2 failed' );
ok( false === strpos( $scrubbed2, 'someothervalue' ), 'the regex also catches a key that is not ours' );

echo "\n=== config fails closed ===\n";
LGD_Security::$enabled = false;
ok( is_wp_error( $p->validate_configuration() ), 'disabled -> refuses' );
LGD_Security::$enabled = true;
ok( true === $p->validate_configuration(), 'enabled with a key -> valid' );

echo "\nRAWG SUMMARY: $pass passed, $fail failed.\n";
