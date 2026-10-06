<?php

declare(strict_types=1);

/**
 * PHPUnit 10 --log-junit writes a <testsuites> root without counts.
 * Use the largest nested testsuite so a real pass cannot be reported as zero tests.
 */

$file = $argv[1] ?? '';
$min_executed = isset( $argv[2] ) ? (int) $argv[2] : 5;

if ( '' === $file || ! is_readable( $file ) ) {
	fwrite( STDERR, "missing JUnit log: {$file}\n" );
	exit( 1 );
}

$xml = simplexml_load_file( $file );
if ( ! $xml instanceof SimpleXMLElement ) {
	fwrite( STDERR, "unreadable JUnit log: {$file}\n" );
	exit( 1 );
}

$best_tests    = 0;
$best_skipped  = 0;
$best_failures = 0;
$best_errors   = 0;

$suites = $xml->xpath( '//testsuite[@tests]' );
if ( ! is_array( $suites ) || [] === $suites ) {
	$suites = [ $xml ];
}

foreach ( $suites as $suite ) {
	$tests = (int) $suite['tests'];
	if ( $tests < $best_tests ) {
		continue;
	}
	$best_tests    = $tests;
	$best_skipped  = (int) $suite['skipped'];
	$best_failures = (int) $suite['failures'];
	$best_errors   = (int) $suite['errors'];
}

$executed = $best_tests - $best_skipped;
echo "real_db tests={$best_tests} skipped={$best_skipped} executed={$executed} failures={$best_failures} errors={$best_errors}\n";

if ( $best_failures > 0 || $best_errors > 0 ) {
	fwrite( STDERR, "real MariaDB JUnit reported failures or errors\n" );
	exit( 1 );
}

if ( $executed < $min_executed ) {
	fwrite( STDERR, "too few real MariaDB tests executed on PHP 8.5\n" );
	exit( 1 );
}

// A green geography lane cannot substitute for the required native operation
// class. Keep the count separate so skipped new proofs fail this job.
$required_class = $argv[3] ?? '';
if ( '' !== $required_class ) {
	$class_minimum = isset( $argv[4] ) ? (int) $argv[4] : 1;
	$class_executed = 0;
	foreach ( $xml->xpath( '//testcase' ) ?: [] as $case ) {
		if ( $required_class === (string) $case['class'] && ! isset( $case->skipped ) && ! isset( $case->failure ) && ! isset( $case->error ) ) {
			++$class_executed;
		}
	}
	echo "required_real_db_class={$required_class} executed={$class_executed} minimum={$class_minimum}\n";
	if ( $class_executed < $class_minimum ) {
		fwrite( STDERR, "too few required operation-store MariaDB proofs executed\n" );
		exit( 1 );
	}
}
