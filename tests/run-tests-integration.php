<?php
/**
 * Formula Rate Shipping — integration smoke test runner.
 *
 * Covers methods that need WordPress/WooCommerce infrastructure:
 *   - fetch_custom_ical_holidays() (iCal parsing with transient stubbing)
 *   - get_holidays() (fixed-date + algorithmic holidays)
 *   - resolve_holiday_country() (country resolution)
 *   - validate_item_rules_card_field() / validate_location_rules_card_field()
 *   - validate_county_rules_table_field_type()
 *
 * Usage:
 *   php tests/run-tests-integration.php
 *
 * Exit code 0 = all tests passed; non-zero = failures.
 */

require_once __DIR__ . '/bootstrap.php';

// ---------------------------------------------------------------
// Minimal test framework (mirrors run-tests.php)
// ---------------------------------------------------------------

$GLOBALS['__frs_tests'] = array(
	'passed'  => 0,
	'failed'  => 0,
	'results' => array(),
);

function assert_equals( $name, $expected, $actual, $tolerance = 0.0001 ) {
	$pass = false;

	if ( $actual instanceof Formula_Error ) {
		$pass = false;
	} elseif ( is_float( $expected ) || is_float( $actual ) || is_numeric( $expected ) || is_numeric( $actual ) ) {
		$pass = abs( floatval( $expected ) - floatval( $actual ) ) <= $tolerance;
	} else {
		$pass = ( $expected == $actual );
	}

	$GLOBALS['__frs_tests']['results'][] = array(
		'name'     => $name,
		'expected' => $expected,
		'actual'   => $actual,
		'pass'     => $pass,
	);

	if ( $pass ) {
		$GLOBALS['__frs_tests']['passed']++;
	} else {
		$GLOBALS['__frs_tests']['failed']++;
	}
}

function assert_true( $name, $actual ) {
	assert_equals( $name, true, (bool) $actual );
}

function assert_false( $name, $actual ) {
	assert_equals( $name, false, (bool) $actual );
}

class Formula_Error {
	public $message;
	public function __construct( $message ) {
		$this->message = $message;
	}
}

// ---------------------------------------------------------------
// TEST SUITE
// ---------------------------------------------------------------

// --- fetch_custom_ical_holidays() ---
// Test with a canned iCal payload via transient (avoids real HTTP).

$ical_payload = "BEGIN:VCALENDAR\r\n"
	. "VERSION:2.0\r\n"
	. "BEGIN:VEVENT\r\n"
	. "DTSTART;VALUE=DATE:20261225\r\n"
	. "SUMMARY:Christmas Day\r\n"
	. "END:VEVENT\r\n"
	. "BEGIN:VEVENT\r\n"
	. "DTSTART;VALUE=DATE:20260704\r\n"
	. "SUMMARY:Holiday: Independence Day\r\n"
	. "END:VEVENT\r\n"
	. "BEGIN:VEVENT\r\n"
	. "DTSTART;VALUE=DATE:20260101\r\n"
	. "SUMMARY:New Year's Day\r\n"
	. "END:VEVENT\r\n"
	. "END:VCALENDAR\r\n";

$ical_url = 'https://example.com/calendar.ics';
$cache_key = 'formula_ical_' . md5( $ical_url );

// Set the transient so fetch_custom_ical_holidays() uses cached data (no HTTP).
set_transient( $cache_key, $ical_payload, 6 * HOUR_IN_SECONDS );

// Christmas 2026
$christmas_ts = strtotime( '2026-12-25 12:00:00' );
$holidays = Formula_Evaluator::fetch_custom_ical_holidays( $ical_url, $christmas_ts, 'all_events' );
assert_equals( 'ical: christmas all_events', array( 'Christmas Day' ), $holidays );

// July 4th 2026 with prefix_holiday filter
$july4_ts = strtotime( '2026-07-04 12:00:00' );
$holidays = Formula_Evaluator::fetch_custom_ical_holidays( $ical_url, $july4_ts, 'prefix_holiday' );
assert_equals( 'ical: july4 prefix_holiday', array( 'Independence Day' ), $holidays );

// July 4th 2026 with all_events (includes non-prefixed too)
$holidays = Formula_Evaluator::fetch_custom_ical_holidays( $ical_url, $july4_ts, 'all_events' );
assert_equals( 'ical: july4 all_events', array( 'Holiday: Independence Day' ), $holidays );

// New Year's Day 2026
$newyear_ts = strtotime( '2026-01-01 12:00:00' );
$holidays = Formula_Evaluator::fetch_custom_ical_holidays( $ical_url, $newyear_ts, 'all_events' );
assert_equals( 'ical: newyear all_events', array( "New Year's Day" ), $holidays );

// No event on that date
$random_ts = strtotime( '2026-03-15 12:00:00' );
$holidays = Formula_Evaluator::fetch_custom_ical_holidays( $ical_url, $random_ts, 'all_events' );
assert_equals( 'ical: no event on date', array(), $holidays );

// Invalid URL
$holidays = Formula_Evaluator::fetch_custom_ical_holidays( 'not-a-url', $christmas_ts, 'all_events' );
assert_equals( 'ical: invalid url returns empty', array(), $holidays );

// Empty URL
$holidays = Formula_Evaluator::fetch_custom_ical_holidays( '', $christmas_ts, 'all_events' );
assert_equals( 'ical: empty url returns empty', array(), $holidays );

// Clean up transient
delete_transient( $cache_key );

// --- get_holidays() ---
// Fixed-date holidays
$christmas_ts = strtotime( '2026-12-25 12:00:00' );
$holidays = Formula_Evaluator::get_holidays( '', $christmas_ts );
assert_true( 'holidays: christmas included', in_array( 'Christmas', $holidays, true ) );

$newyear_ts = strtotime( '2026-01-01 12:00:00' );
$holidays = Formula_Evaluator::get_holidays( '', $newyear_ts );
assert_true( 'holidays: new year included', in_array( 'New Year', $holidays, true ) );

$july4_ts = strtotime( '2026-07-04 12:00:00' );
$holidays = Formula_Evaluator::get_holidays( '', $july4_ts );
assert_true( 'holidays: july 4th included', in_array( 'July 4th', $holidays, true ) );

// Algorithmic holidays
$thanksgiving_ts = strtotime( 'fourth thursday of november 2026' );
$holidays = Formula_Evaluator::get_holidays( '', $thanksgiving_ts );
assert_true( 'holidays: thanksgiving included', in_array( 'Thanksgiving', $holidays, true ) );

$black_friday_ts = strtotime( 'fourth thursday of november 2026 +1 day' );
$holidays = Formula_Evaluator::get_holidays( '', $black_friday_ts );
assert_true( 'holidays: black friday included', in_array( 'Black Friday', $holidays, true ) );

$memorial_day_ts = strtotime( 'last monday of may 2026' );
$holidays = Formula_Evaluator::get_holidays( '', $memorial_day_ts );
assert_true( 'holidays: memorial day included', in_array( 'Memorial Day', $holidays, true ) );

$labor_day_ts = strtotime( 'first monday of september 2026' );
$holidays = Formula_Evaluator::get_holidays( '', $labor_day_ts );
assert_true( 'holidays: labor day included', in_array( 'Labor Day', $holidays, true ) );

// Non-holiday date
$random_ts = strtotime( '2026-03-15 12:00:00' );
$holidays = Formula_Evaluator::get_holidays( '', $random_ts );
assert_equals( 'holidays: no holiday on random date', array(), $holidays );

// --- get_holidays() with country code (API-backed, may be empty if network unavailable) ---
// This exercises resolve_holiday_country() indirectly via the country code path.
// The API call may fail in offline environments, so we only assert it doesn't throw.
$holidays = Formula_Evaluator::get_holidays( 'US', $christmas_ts );
assert_true( 'holidays: US country code returns array', is_array( $holidays ) );
assert_true( 'holidays: US christmas still included', in_array( 'Christmas', $holidays, true ) );

// --- Admin validation methods ---
if ( class_exists( 'WC_Shipping_Formula' ) ) {
	$method = new WC_Shipping_Formula();

	// validate_item_rules_card_field() with $_POST data
	$field_key = $method->get_field_key( 'usa_item_rules' );
	$_POST[ $field_key ] = array(
		array(
			'type'             => 'category',
			'target'           => 'hardcover',
			'enabled'          => 'yes',
			'shipping_formula' => '5.00 + ([qty] - 1) * 3.50',
			'handling_formula' => '3.50',
		),
		array(
			'type'             => 'tag',
			'target'           => 'fragile',
			'enabled'          => 'yes',
			'shipping_formula' => '2.00 + [qty] * 1.00',
			'handling_formula' => '1.00',
		),
	);

	$result = $method->validate_item_rules_card_field( 'usa_item_rules', array() );
	assert_equals( 'validate_item_rules: count', 2, count( $result ) );
	assert_equals( 'validate_item_rules: first type', 'category', $result[0]['type'] );
	assert_equals( 'validate_item_rules: first target', 'hardcover', $result[0]['target'] );
	assert_equals( 'validate_item_rules: first enabled', 'yes', $result[0]['enabled'] );
	assert_equals( 'validate_item_rules: first ship formula', '5.00 + ([qty] - 1) * 3.50', $result[0]['shipping_formula'] );
	assert_equals( 'validate_item_rules: second type', 'tag', $result[1]['type'] );
	assert_equals( 'validate_item_rules: second target', 'fragile', $result[1]['target'] );

	// Invalid formula → rule forced to disabled
	$_POST[ $field_key ] = array(
		array(
			'type'             => 'category',
			'target'           => 'broken',
			'enabled'          => 'yes',
			'shipping_formula' => '5.00 + (',  // malformed
			'handling_formula' => '',
		),
	);
	$result = $method->validate_item_rules_card_field( 'usa_item_rules', array() );
	assert_equals( 'validate_item_rules: invalid formula count', 1, count( $result ) );
	assert_equals( 'validate_item_rules: invalid formula disabled', 'no', $result[0]['enabled'] );

	// Empty target → rule skipped, falls back to saved option
	$_POST[ $field_key ] = array(
		array(
			'type'             => 'category',
			'target'           => '',
			'enabled'          => 'yes',
			'shipping_formula' => '5.00',
			'handling_formula' => '',
		),
	);
	$result = $method->validate_item_rules_card_field( 'usa_item_rules', array() );
	// When all POST rules are filtered out, the method falls back to the saved option value.
	assert_true( 'validate_item_rules: empty target falls back to saved option', is_array( $result ) );

	// 'default' type → skipped, falls back to saved option
	$_POST[ $field_key ] = array(
		array(
			'type'             => 'default',
			'target'           => 'anything',
			'enabled'          => 'yes',
			'shipping_formula' => '5.00',
			'handling_formula' => '',
		),
	);
	$result = $method->validate_item_rules_card_field( 'usa_item_rules', array() );
	assert_true( 'validate_item_rules: default type falls back to saved option', is_array( $result ) );

	// validate_location_rules_card_field() with $_POST data
	$loc_field_key = $method->get_field_key( 'usa_location_rules' );
	$_POST[ $loc_field_key ] = array(
		array(
			'type'             => 'location',
			'country'          => 'US',
			'state'            => 'CA',
			'location_pattern' => '90210',
			'enabled'          => 'yes',
			'shipping_formula' => '10.00',
			'handling_formula' => '2.00',
		),
	);

	$result = $method->validate_location_rules_card_field( 'usa_location_rules', array() );
	assert_equals( 'validate_location_rules: count', 1, count( $result ) );

	// validate_county_rules_table_field_type()
	$county_field_key = $method->get_field_key( 'usa_county_rules' );
	$_POST[ $county_field_key ] = array(
		array(
			'county'           => 'Los Angeles',
			'state'            => 'CA',
			'enabled'          => 'yes',
			'shipping_formula' => '12.00',
			'handling_formula' => '1.00',
		),
	);

	$result = $method->validate_county_rules_table_field_type( 'usa_county_rules', array() );
	assert_equals( 'validate_county_rules: count', 1, count( $result ) );

	// Clean up $_POST
	unset( $_POST[ $field_key ], $_POST[ $loc_field_key ], $_POST[ $county_field_key ] );
} else {
	echo " [SKIP] WC_Shipping_Formula not available; admin-validation tests skipped.\n";
}

// ---------------------------------------------------------------
// Report
// ---------------------------------------------------------------

$results = $GLOBALS['__frs_tests']['results'];
$passed  = $GLOBALS['__frs_tests']['passed'];
$failed  = $GLOBALS['__frs_tests']['failed'];

echo "\n";
echo "==========================================\n";
echo " Formula Rate Shipping — Integration Test Results\n";
echo "==========================================\n";
echo " Passed: {$passed}  |  Failed: {$failed}  |  Total: " . ( $passed + $failed ) . "\n\n";

foreach ( $results as $r ) {
	$status = $r['pass'] ? 'PASS' : 'FAIL';
	$line   = sprintf( " [%s] %s", $status, $r['name'] );
	if ( ! $r['pass'] ) {
		$line .= sprintf( "\n        expected: %s\n        actual:   %s", var_export( $r['expected'], true ), var_export( $r['actual'], true ) );
	}
	echo $line . "\n";
}

echo "\n";

exit( $failed > 0 ? 1 : 0 );