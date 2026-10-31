<?php
/**
 * dashboard-cache-test.php, the Dashboard widget's period-scale numbers come from a cache.
 *
 * WHY THIS EXISTS
 * ---------------
 * Measured on production (2026-10-31, three runs, stable): wp-admin/index.php had a TTFB of
 * about 900ms against about 250ms on other admin pages, and about 560ms of the 584ms of query
 * time was this plugin's widget. cspv_insights_kpi() and cspv_insights_smart_summary() ran the
 * referrer, unique-visitor and country queries for 30 days and the 30 days before them on every
 * Dashboard visit, and the 28-day and 6-month chart series added another 80ms. None of it needs
 * to be live to the second, so it is cached for ten minutes. The fix is one line to undo by
 * accident: a render path that calls the insights functions directly again is fast in a test
 * site with ten rows and slow on production.
 *
 * WHAT IS ASSERTED
 * ----------------
 * 1. The rule, with fixtures for both sides: in dashboard-widget.php, cspv_insights_kpi() and
 *    cspv_insights_smart_summary() are called only inside cspv_dashboard_insights_cached(), the
 *    28-day and 6-month queries live only in cspv_dashboard_long_series(), and that is called
 *    only from inside a cspv_dashboard_cached() call. Then the rule is applied to the real file.
 * 2. The cache itself, by executing the real cspv_dashboard_cached() and
 *    cspv_dashboard_insights_cached() sliced out of dashboard-widget.php against stubbed
 *    WordPress functions (never reimplemented): a repeat in the same hour is a hit, a different
 *    host, window or hour is a miss, an empty result is cached, a value round-trips through
 *    serialisation, the TTL is ten minutes and a TTL of 0 bypasses the cache.
 *
 * Usage: php tests/dashboard-cache-test.php
 * Exit 0 = all pass, 1 = failure.
 *
 * @package CloudScale_Site_Analytics
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions, WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals

$repo = dirname( __DIR__ );
$src  = (string) file_get_contents( $repo . '/dashboard-widget.php' );

$fails = 0;
$check = function ( $ok, $label ) use ( &$fails ) {
	echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $label . "\n";
	if ( ! $ok ) {
		++$fails;
	}
};

echo "Dashboard widget cache\n";
echo "----------------------\n";

/**
 * Split PHP source into top-level functions: name => body (signature to the next top-level
 * function). Code outside any function is under the key ''.
 */
$functions_of = function ( $php ) {
	// Comments are dropped first: a docblock that names a function is not a call to it.
	$code = '';
	foreach ( token_get_all( $php ) as $tok ) {
		if ( is_array( $tok ) && in_array( $tok[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		$code .= is_array( $tok ) ? $tok[1] : $tok;
	}
	$out   = array( '' => '' );
	$parts = preg_split( '/^(?=function\s+\w+\s*\()/m', $code );
	foreach ( $parts as $part ) {
		if ( preg_match( '/^function\s+(\w+)\s*\(/', $part, $m ) ) {
			$out[ $m[1] ] = $part;
		} else {
			$out[''] .= $part;
		}
	}
	return $out;
};

/** The rule. Returns a list of violations; empty means the source obeys it. */
$violations = function ( $php ) use ( $functions_of ) {
	$found = array();
	foreach ( $functions_of( $php ) as $name => $body ) {
		if ( 'cspv_dashboard_insights_cached' !== $name && preg_match( '/\bcspv_insights_(kpi|smart_summary)\s*\(/', $body, $m ) ) {
			$found[] = ( '' === $name ? 'top level' : $name ) . " calls cspv_insights_{$m[1]}() outside the cache";
		}
		if ( 'cspv_dashboard_long_series' !== $name && preg_match( "/%%Y-%%u|GROUP BY day'/", $body ) ) {
			$found[] = ( '' === $name ? 'top level' : $name ) . ' runs a 28-day or 6-month series query outside cspv_dashboard_long_series()';
		}
		if ( 'cspv_dashboard_long_series' !== $name ) {
			$calls  = preg_match_all( '/(?<!function )\bcspv_dashboard_long_series\s*\(/', $body );
			$cached = preg_match_all( '/cspv_dashboard_cached\s*\([^;]*?function\s*\([^)]*\)[^{]*\{\s*return\s+cspv_dashboard_long_series\s*\(/s', $body );
			if ( $calls > $cached ) {
				$found[] = $name . ' calls cspv_dashboard_long_series() outside cspv_dashboard_cached()';
			}
		}
	}
	if ( ! preg_match( '/\bcspv_dashboard_insights_cached\s*\(/', $functions_of( $php )['cspv_render_dashboard_widget'] ?? '' ) ) {
		$found[] = 'cspv_render_dashboard_widget does not use cspv_dashboard_insights_cached()';
	}
	return $found;
};

// 1a. Fixtures for the rule.
$good = "<?php\nfunction cspv_dashboard_insights_cached( \$h ) {\n  return cspv_dashboard_cached( 'insights', array(), function () { \$k = cspv_insights_kpi( 1, 2, 3 ); return cspv_insights_smart_summary( 1, 2, 3, 30, \$k ); } );\n}\n"
	. "function cspv_dashboard_long_series( \$t ) {\n  \$q = \"SELECT DATE_FORMAT(viewed_at, '%%Y-%%u') AS week_key\";\n}\n"
	. "function cspv_dashboard_query_data() {\n  \$s = cspv_dashboard_cached( 'series', array(), function () use ( \$t ) {\n    return cspv_dashboard_long_series( \$t );\n  } );\n}\n"
	. "function cspv_render_dashboard_widget() {\n  \$items = cspv_dashboard_insights_cached( 'h', 30 );\n}\n";
$check( array() === $violations( $good ), 'fixture: a render path that goes through the cache passes' );
$check( array() === $violations( str_replace( "function cspv_render_dashboard_widget() {\n", "/** Never call cspv_insights_kpi( directly. */\nfunction cspv_render_dashboard_widget() {\n  // not cspv_insights_smart_summary( either\n", $good ) ), 'fixture: a comment naming the insights functions is not a call' );

$direct = str_replace( "\$items = cspv_dashboard_insights_cached( 'h', 30 );", "\$k = cspv_insights_kpi( 1, 2, 3 ); \$items = cspv_insights_smart_summary( 1, 2, 3, 30, \$k );", $good );
$v      = $violations( $direct );
$check( (bool) preg_grep( '/cspv_render_dashboard_widget calls cspv_insights_kpi/', $v ), 'fixture: the render calling cspv_insights_kpi() directly is refused' );
$check( (bool) preg_grep( '/does not use cspv_dashboard_insights_cached/', $v ), 'fixture: a render that stops using the cached wrapper is refused' );

$top = str_replace( "<?php\n", "<?php\n\$x = cspv_insights_smart_summary( 1, 2, 3, 30, array() );\n", $good );
$check( (bool) preg_grep( '/top level calls cspv_insights_smart_summary/', $violations( $top ) ), 'fixture: a top-level template call to cspv_insights_smart_summary() is refused' );

$uncached = str_replace( "\$s = cspv_dashboard_cached( 'series', array(), function () use ( \$t ) {\n    return cspv_dashboard_long_series( \$t );\n  } );", "\$s = cspv_dashboard_long_series( \$t );", $good );
$check( (bool) preg_grep( '/calls cspv_dashboard_long_series\(\) outside/', $violations( $uncached ) ), 'fixture: calling cspv_dashboard_long_series() without the cache is refused' );

$inline = str_replace( "\$s = cspv_dashboard_cached(", "\$q = \"SELECT DATE_FORMAT(viewed_at, '%%Y-%%u')\"; \$s = cspv_dashboard_cached(", $good );
$check( (bool) preg_grep( '/series query outside/', $violations( $inline ) ), 'fixture: the 6-month query inlined back into cspv_dashboard_query_data() is refused' );

// 1b. The rule on the real file.
$real = $violations( $src );
foreach ( $real as $msg ) {
	echo "        $msg\n";
}
$check( array() === $real, 'dashboard-widget.php: the widget reaches the insights and long series only through the cache' );

// 2. The real helpers, executed.
$fns = $functions_of( $src );
foreach ( array( 'cspv_dashboard_cached', 'cspv_dashboard_insights_cached' ) as $fn ) {
	if ( empty( $fns[ $fn ] ) ) {
		$check( false, "$fn() is defined in dashboard-widget.php" );
		echo "\nFAILED: $fails check(s).\n";
		exit( 1 );
	}
}

define( 'CSPV_VERSION', '0.0.test' );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['t_store']   = array();
$GLOBALS['t_ttls']    = array();
$GLOBALS['t_ttl']     = null;
$GLOBALS['t_kpi']     = 0;
$GLOBALS['t_summary'] = array();

function apply_filters( $tag, $value ) {
	return ( 'cspv_dashboard_cache_ttl' === $tag && null !== $GLOBALS['t_ttl'] ) ? $GLOBALS['t_ttl'] : $value;
}
function wp_json_encode( $v ) {
	return json_encode( $v );
}
function wp_timezone() {
	return new DateTimeZone( 'UTC' );
}
// Stored serialised, as the options table and Redis both do, so the round trip is real.
function get_transient( $k ) {
	return isset( $GLOBALS['t_store'][ $k ] ) ? unserialize( $GLOBALS['t_store'][ $k ] ) : false;
}
function set_transient( $k, $v, $ttl ) {
	$GLOBALS['t_store'][ $k ] = serialize( $v );
	$GLOBALS['t_ttls'][ $k ]  = $ttl;
	return true;
}
function cspv_insights_kpi( $from, $to, $host ) {
	++$GLOBALS['t_kpi'];
	return array( 'total_views' => 10, 'from' => $from, 'to' => $to, 'host' => $host );
}
function cspv_insights_smart_summary( $from, $to, $host, $period, $kpi ) {
	return $GLOBALS['t_summary'];
}

eval( $fns['cspv_dashboard_cached'] );          // phpcs:ignore Squiz.PHP.Eval.Discouraged -- executes the real helper, see header
eval( $fns['cspv_dashboard_insights_cached'] ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- executes the real helper, see header

$tz                   = new DateTimeZone( 'UTC' );
$at                   = function ( $s ) use ( $tz ) {
	return new DateTimeImmutable( $s, $tz );
};
$GLOBALS['t_summary'] = array(
	array( 'icon' => 'x', 'text' => 'Traffic is up 5%', 'type' => 'positive', 'detail' => null ),
	array( 'icon' => 'y', 'text' => 'Top 2 countries', 'type' => 'neutral', 'detail' => array( array( 'cc' => 'ZA', 'pct' => 60 ), array( 'cc' => 'GB', 'pct' => 20 ) ) ),
);

$first  = cspv_dashboard_insights_cached( 'example.com', 30, $at( '2026-10-31 09:05:00' ) );
$second = cspv_dashboard_insights_cached( 'example.com', 30, $at( '2026-10-31 09:50:00' ) );
$check( 1 === $GLOBALS['t_kpi'], 'a second load in the same hour is a hit (the KPI queries ran once)' );
$check( $first === $GLOBALS['t_summary'] && $second === $first, 'nested arrays round-trip through the serialised cache unchanged' );
$check( array( 600 ) === array_values( array_unique( $GLOBALS['t_ttls'] ) ), 'the entry is kept for 10 minutes' );

cspv_dashboard_insights_cached( 'example.com', 30, $at( '2026-10-31 10:00:00' ) );
$check( 2 === $GLOBALS['t_kpi'], 'a new hour is a miss' );
cspv_dashboard_insights_cached( 'other.example', 30, $at( '2026-10-31 10:10:00' ) );
$check( 3 === $GLOBALS['t_kpi'], 'a different host is a miss' );
cspv_dashboard_insights_cached( 'example.com', 7, $at( '2026-10-31 10:10:00' ) );
$check( 4 === $GLOBALS['t_kpi'], 'a different window length is a miss' );
cspv_dashboard_insights_cached( 'example.com', 30, $at( '2026-11-01 10:10:00' ) );
$check( 5 === $GLOBALS['t_kpi'], 'the same hour on a new day is a miss' );

$GLOBALS['t_summary'] = array();
$GLOBALS['t_kpi']     = 0;
cspv_dashboard_insights_cached( 'empty.example', 30, $at( '2026-10-31 11:00:00' ) );
$empty = cspv_dashboard_insights_cached( 'empty.example', 30, $at( '2026-10-31 11:30:00' ) );
$check( 1 === $GLOBALS['t_kpi'] && array() === $empty, 'an empty summary is cached, not recomputed on every load' );

$GLOBALS['t_ttl'] = 0;
$GLOBALS['t_kpi'] = 0;
$before           = count( $GLOBALS['t_store'] );
cspv_dashboard_insights_cached( 'nocache.example', 30, $at( '2026-10-31 12:00:00' ) );
cspv_dashboard_insights_cached( 'nocache.example', 30, $at( '2026-10-31 12:00:00' ) );
$check( 2 === $GLOBALS['t_kpi'] && count( $GLOBALS['t_store'] ) === $before, 'a TTL of 0 from the cspv_dashboard_cache_ttl filter bypasses the cache' );
$GLOBALS['t_ttl'] = null;

$keys = array_keys( $GLOBALS['t_store'] );
$check( array() === array_filter( $keys, function ( $k ) { return 0 !== strpos( $k, 'cspv_dw_' ) || strlen( $k ) > 172; } ), 'keys are prefixed cspv_dw_ and fit the transient name limit' );

$v1 = cspv_dashboard_cached( 'scalar', array( 'a' => 1 ), function () { return 0; } );
$v2 = cspv_dashboard_cached( 'scalar', array( 'a' => 1 ), function () { return 99; } );
$check( 0 === $v1 && 0 === $v2, 'a cached zero is a hit, not mistaken for a miss' );

echo "\n";
if ( $fails ) {
	echo "FAILED: $fails check(s).\n";
	exit( 1 );
}
echo "All dashboard cache checks passed.\n";
exit( 0 );
