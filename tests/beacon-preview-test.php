<?php
/**
 * beacon-preview-test.php — the view beacon must not fire where recording is impossible.
 *
 * WHY THIS EXISTS
 * ---------------
 * On 2026-08-19 09:11:18 this arrived as a Telegram CRITICAL:
 *
 *   Editor request failed, POST /wp-json/cloudscale-site-analytics/v1/record/11057
 *   -> 404: {"error":"Post is not published."}
 *   Page: /?p=11057&preview=true (post (single))
 *
 * Post 11057 was published 16 seconds later (post_modified 2026-08-19 09:11:34). Nothing was
 * broken: the author previewed a draft, cspv_enqueue_beacon() saw is_singular() and shipped
 * record mode, and cspv_record_view() refused the draft with a 404 exactly as designed. The
 * alert fired because the workflow was working.
 *
 * The fix belongs here and not in the monitor. CS Monitor's editorLogSeverity() ends in
 * `return 'critical'` for any same-origin failure with a real status, and that is correct --
 * a 404 on a route that should have answered is a genuine fault, so teaching it to forgive
 * 404s in general would hide real breakage. The request is what should not exist.
 *
 * WHAT IS ASSERTED
 * ----------------
 * Only the record/fetch/nothing decision, which is the thing that regressed. The real
 * cspv_enqueue_beacon() is sliced out of beacon.php and executed against stubbed WordPress
 * functions -- never reimplemented, because a copy of the logic would keep passing after the
 * original changed. That is the same arrangement as cyber-devtools' editor-severity-test.php.
 *
 * Usage: php tests/beacon-preview-test.php
 * Exit 0 = all pass, 1 = failure.
 *
 * @package CloudScale_Site_Analytics
 */

$repo = dirname( __DIR__ );
$src  = (string) file_get_contents( $repo . '/beacon.php' );

echo "Beacon enqueue mode\n";
echo "-------------------\n";

// Slice the function out of the shipped file. beacon.php calls add_action() at the top level
// and depends on ABSPATH, so it cannot simply be included.
$at = strpos( $src, 'function cspv_enqueue_beacon() {' );
if ( false === $at ) {
	echo "  FAIL  cspv_enqueue_beacon() not found in beacon.php\n";
	exit( 1 );
}
$end = strpos( $src, "\n}\n", $at );
if ( false === $end ) {
	echo "  FAIL  could not find the end of cspv_enqueue_beacon()\n";
	exit( 1 );
}
eval( substr( $src, $at, $end - $at + 3 ) );

// --- Scenario state driven by each case ------------------------------------
$GLOBALS['t'] = array();

// --- WordPress stubs -------------------------------------------------------
define( 'CSPV_PLUGIN_URL', 'https://example.test/wp-content/plugins/cloudscale-site-analytics/' );
define( 'CSPV_VERSION', '9.9.9' );

function cspv_tracking_paused() { return ! empty( $GLOBALS['t']['paused'] ); }
function is_singular()          { return ! empty( $GLOBALS['t']['singular'] ); }
function is_home()              { return ! empty( $GLOBALS['t']['listing'] ); }
function is_front_page()        { return false; }
function is_archive()           { return false; }
function is_search()            { return false; }
function is_preview()           { return ! empty( $GLOBALS['t']['preview'] ); }
function get_post_status()      { return $GLOBALS['t']['status'] ?? 'publish'; }
function get_post_type()        { return $GLOBALS['t']['type'] ?? 'post'; }
function get_the_ID()           { return 11057; }
function rest_url( $p = '' )    { return 'https://example.test/wp-json/' . $p; }
function wp_create_nonce( $a )  { return 'nonce'; }
function add_filter()           { }
function get_option( $name, $default = false ) {
	return $GLOBALS['t']['options'][ $name ] ?? $default;
}
function wp_enqueue_script()    { $GLOBALS['t']['enqueued'] = true; }
function wp_localize_script( $handle, $object, $data ) { $GLOBALS['t']['data'] = $data; }

/**
 * Run cspv_enqueue_beacon() under one scenario and report what it decided.
 *
 * @param  array $state Stub state for this scenario.
 * @return string       'record', 'fetch', or 'nothing'.
 */
function cspv_test_mode( array $state ) {
	$GLOBALS['t'] = $state;
	cspv_enqueue_beacon();
	if ( empty( $GLOBALS['t']['enqueued'] ) ) {
		return 'nothing';
	}
	$data = $GLOBALS['t']['data'] ?? array();
	// A record-mode payload is the one that carries the URL the beacon POSTs to.
	if ( 'record' === ( $data['mode'] ?? '' ) && ! empty( $data['apiUrl'] ) ) {
		return 'record';
	}
	return $data['mode'] ?? 'nothing';
}

$cases = array(
	// The reported case. A draft preview must not POST anything.
	array(
		'name'  => 'a draft preview enqueues no beacon at all',
		'state' => array( 'singular' => true, 'preview' => true, 'status' => 'draft' ),
		'want'  => 'nothing',
	),
	// The endpoint 404s on every non-published status, not just draft, so the guard
	// must key on the status and not on is_preview() alone.
	array(
		'name'  => 'a private post enqueues no beacon',
		'state' => array( 'singular' => true, 'status' => 'private' ),
		'want'  => 'nothing',
	),
	array(
		'name'  => 'a future-dated post enqueues no beacon',
		'state' => array( 'singular' => true, 'status' => 'future' ),
		'want'  => 'nothing',
	),
	// Previewing a published revision would succeed at the endpoint, so no alert --
	// but the author re-reading their own draft is not a view, and counting it
	// inflates the number the plugin exists to report.
	array(
		'name'  => 'a preview of a published post records nothing',
		'state' => array( 'singular' => true, 'preview' => true, 'status' => 'publish' ),
		'want'  => 'nothing',
	),
	// THE GUARD MUST NOT SWALLOW THE FEATURE. Without this case the whole file is
	// satisfied by `return;` at the top of cspv_enqueue_beacon().
	array(
		'name'  => 'an ordinary published post still records',
		'state' => array( 'singular' => true, 'status' => 'publish' ),
		'want'  => 'record',
	),
	// Listing pages never recorded; they must keep fetching counts.
	array(
		'name'  => 'a listing page still fetches counts',
		'state' => array( 'listing' => true ),
		'want'  => 'fetch',
	),
	// A draft preview of an untracked post type: still nothing, and still no crash.
	array(
		'name'  => 'a draft preview of an untracked type enqueues nothing',
		'state' => array(
			'singular' => true,
			'preview'  => true,
			'status'   => 'draft',
			'type'     => 'attachment',
		),
		'want'  => 'nothing',
	),
	// The existing kill switch must keep working.
	array(
		'name'  => 'the tracking kill switch still stops everything',
		'state' => array( 'singular' => true, 'status' => 'publish', 'paused' => true ),
		'want'  => 'nothing',
	),
);

$fails = 0;
foreach ( $cases as $case ) {
	$got = cspv_test_mode( $case['state'] );
	if ( $got === $case['want'] ) {
		echo "  PASS  {$case['name']}\n";
	} else {
		echo "  FAIL  {$case['name']} -- wanted {$case['want']}, got {$got}\n";
		++$fails;
	}
}

$ran = count( $cases );
echo "\n";
if ( $fails > 0 ) {
	echo "FAIL  {$fails} of {$ran} checks failed\n";
	exit( 1 );
}
echo "OK  {$ran} checks ran, all passed\n";
exit( 0 );
