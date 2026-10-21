#!/usr/bin/env php
<?php
/**
 * A production deploy fails (exit 4) when the public download on S3 was not updated.
 *
 * WHY THIS EXISTS: from 1.10.1059 to 1.10.1062 the S3 upload failed on every deploy (the AWS
 * credential helper could not reach the keychain), the deploy printed a warning and exited 0,
 * and the public zip served 1.10.1058 until the owner noticed by hand.
 *
 * Runs the real S3 blocks of deploy-wordpress.sh (between the s3-publish and s3-exit markers)
 * in bash with a stub upload script and a stub aws CLI. Pins:
 *   - upload succeeds first time: exit 0, one upload;
 *   - upload fails once then succeeds: exit 0, exactly two uploads (one retry);
 *   - upload fails twice: exit 4, the message says the release is live and the file is not;
 *   - no aws CLI: exit 4, no upload attempted;
 *   - SKIP_S3_PUBLISH=1, a standby (PI_ENV=qa) and another site profile: exit 0, no upload.
 *
 * Standalone: no network, no AWS. Gated by build.sh.
 *
 * Usage: php tests/deploy-s3-exit-test.php
 * Exit 0 = all pass, 1 = failure.
 *
 * @package CloudScale_Site_Analytics
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions, WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals

$fails  = 0;
$check  = function ( $ok, $label ) use ( &$fails ) {
	echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $label . "\n";
	if ( ! $ok ) {
		++$fails;
	}
};
// The deploy script is local tooling, ignored by git, so a fresh clone or worktree has none.
$path = dirname( __DIR__ ) . '/deploy-wordpress.sh';
if ( ! is_file( $path ) ) {
	echo "  SKIP  no deploy-wordpress.sh in this checkout (local tooling, not in git)\n";
	exit( 0 );
}
$script = file_get_contents( $path );

$block = function ( $name ) use ( $script ) {
	if ( ! preg_match( '/^# ' . $name . '-begin\n(.*?)^# ' . $name . '-end$/ms', $script, $m ) ) {
		return null;
	}
	return $m[1];
};
$publish = $block( 's3-publish' );
$exit    = $block( 's3-exit' );
$check( null !== $publish, 'the s3-publish block is marked in deploy-wordpress.sh' );
$check( null !== $exit, 'the s3-exit block is marked in deploy-wordpress.sh' );
if ( null === $publish || null === $exit ) {
	exit( 1 );
}
$check( strrpos( $script, '# s3-exit-end' ) > strrpos( $script, 'DEPLOYER_REFUSED' ), 'the failing exit comes after every other closing message' );

/**
 * Run both blocks with an upload stub that fails the first $fail_n calls.
 * Returns [exit code, output, upload calls].
 */
$run = function ( $fail_n, $env, $with_aws = true ) use ( $publish, $exit ) {
	$dir = sys_get_temp_dir() . '/csdt-s3x-' . bin2hex( random_bytes( 4 ) );
	mkdir( $dir . '/bin', 0700, true );
	file_put_contents( $dir . '/calls', '' );
	file_put_contents(
		$dir . '/backup-s3.sh',
		"n=\$(wc -c < \"$dir/calls\" | tr -d ' '); printf x >> \"$dir/calls\"\n" .
		"[ \"\$n\" -lt $fail_n ] && { echo stub-upload-failed; exit 1; }\necho stub-upload-ok\n"
	);
	if ( $with_aws ) {
		file_put_contents( $dir . '/bin/aws', "#!/bin/bash\nexit 0\n" );
		chmod( $dir . '/bin/aws', 0700 );
	}
	$vars = array_merge(
		array(
			'_DEPLOY_PI_PROFILE' => 'wordpress',
			'PI_ENV'             => 'prod',
			'SCRIPT_DIR'         => $dir,
		),
		$env
	);
	$pre  = '';
	foreach ( $vars as $k => $v ) {
		$pre .= $k . '=' . escapeshellarg( $v ) . "\n";
	}
	file_put_contents( $dir . '/run.sh', $pre . $publish . "\necho END_OF_DEPLOY\n" . $exit );
	$out  = array();
	$code = 0;
	exec( 'env -i PATH=' . escapeshellarg( $dir . '/bin:/usr/bin:/bin' ) . ' /bin/bash ' . escapeshellarg( $dir . '/run.sh' ) . ' 2>&1', $out, $code );
	$calls = strlen( (string) file_get_contents( $dir . '/calls' ) );
	exec( 'rm -rf ' . escapeshellarg( $dir ) );
	return array( $code, implode( "\n", $out ), $calls );
};

list( $c, $o, $n ) = $run( 0, array() );
$check( 0 === $c && 1 === $n, 'upload succeeds first time: exit 0, one upload' );

list( $c, $o, $n ) = $run( 1, array() );
$check( 0 === $c && 2 === $n, 'upload fails once then succeeds: exit 0, exactly one retry' );

list( $c, $o, $n ) = $run( 2, array() );
$check( 4 === $c && 2 === $n, 'upload fails twice: exit 4 after two attempts' );
$check( false !== strpos( $o, 'DEPLOY FAILED (exit 4): the release is live' ), 'the failure says the release is live and the public file is not' );
$check( strpos( $o, 'END_OF_DEPLOY' ) < strpos( $o, 'DEPLOY FAILED' ), 'the exit happens only after the rest of the deploy has run' );

list( $c, $o, $n ) = $run( 0, array(), false );
$check( 4 === $c && 0 === $n, 'no aws CLI: exit 4, no upload attempted' );

list( $c, $o, $n ) = $run( 5, array( 'SKIP_S3_PUBLISH' => '1' ) );
$check( 0 === $c && 0 === $n, 'SKIP_S3_PUBLISH=1: exit 0, no upload' );

list( $c, $o, $n ) = $run( 5, array( 'PI_ENV' => 'qa' ) );
$check( 0 === $c && 0 === $n, 'a standby deploy never uploads and never fails on S3' );

list( $c, $o, $n ) = $run( 5, array( '_DEPLOY_PI_PROFILE' => 'help' ) );
$check( 0 === $c && 0 === $n, 'another site profile never uploads and never fails on S3' );

echo $fails ? "\nFAILED: $fails check(s)\n" : "\nAll deploy S3 exit checks passed.\n";
exit( $fails ? 1 : 0 );
