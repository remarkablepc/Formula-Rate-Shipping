<?php
/**
 * Formula Rate Shipping — master test orchestrator.
 *
 * Runs all four test suites in sequence and reports a combined summary.
 * Each suite is executed as a subprocess so a failure in one does not
 * abort the others, and so WordPress is bootstrapped fresh per suite.
 *
 * Usage:
 *   php tests/run-all.php
 *
 * Exit code 0 = all tests passed; non-zero = one or more suites failed.
 */

$tests = __DIR__;
$suites = array(
	'Smoke'       => 'run-tests.php',
	'Advanced'    => 'run-tests-advanced.php',
	'Integration' => 'run-tests-integration.php',
	'Robustness'  => 'run-tests-robustness.php',
);

$php = PHP_BINARY;

$grand_total  = 0;
$grand_passed = 0;
$grand_failed = 0;
$suite_results = array();

$line = str_repeat( '=', 72 );
echo $line . "\n";
echo " Formula Rate Shipping — Master Test Runner\n";
echo $line . "\n\n";

foreach ( $suites as $label => $script ) {
	$path = $tests . DIRECTORY_SEPARATOR . $script;

	if ( ! file_exists( $path ) ) {
		echo " [MISSING] {$label}: {$script} not found\n\n";
		$suite_results[] = array( 'label' => $label, 'passed' => 0, 'failed' => 1, 'total' => 1 );
		$grand_failed++;
		continue;
	}

	$command = escapeshellarg( $php ) . ' ' . escapeshellarg( $path ) . ' 2>&1';
	$output  = array();
	$code    = 0;
	exec( $command, $output, $code );

	// Extract "Passed: X | Failed: Y | Total: Z" from output.
	$passed = 0;
	$failed = 0;
	$total  = 0;
	foreach ( $output as $line_out ) {
		if ( preg_match( '/Passed:\s*(\d+)\s*\|\s*Failed:\s*(\d+)\s*\|\s*Total:\s*(\d+)/i', $line_out, $m ) ) {
			$passed = (int) $m[1];
			$failed = (int) $m[2];
			$total  = (int) $m[3];
			break;
		}
	}

	$grand_total  += $total;
	$grand_passed += $passed;
	$grand_failed += $failed;

	$suite_results[] = array( 'label' => $label, 'passed' => $passed, 'failed' => $failed, 'total' => $total );

	$status = ( 0 === $failed ) ? 'PASS' : 'FAIL';
	printf( " [%s] %-12s Passed: %-4d Failed: %-4d Total: %d\n", $status, $label, $passed, $failed, $total );

	// On failure, show the failing [FAIL] lines for quick diagnosis.
	if ( $failed > 0 ) {
		foreach ( $output as $line_out ) {
			if ( false !== strpos( $line_out, '[FAIL]' ) ) {
				echo '        ' . trim( $line_out ) . "\n";
			}
		}
	}
	echo "\n";
}

echo $line . "\n";
echo " TOTAL   Passed: {$grand_passed}  |  Failed: {$grand_failed}  |  Total: " . ( $grand_passed + $grand_failed ) . "\n";
echo $line . "\n";

exit( $grand_failed > 0 ? 1 : 0 );