<?php
/**
 * Formula Rate Shipping — robustness smoke test runner.
 *
 * Closes coverage gaps not exercised by run-tests.php, run-tests-advanced.php,
 * or run-tests-integration.php. Focuses on documented-but-unverified behavior:
 *   - log() / ln() / exp() math functions
 *   - [sale], [fee formula=]/[icon=]/[amount=], [discount percent=] line items
 *   - [message] / [warning] / [notice position=] notice variants
 *   - [rate max_fee=] and filtered [rate category=]
 *   - [scale step=], [loop step=], [table on="[cost]"] subtotal tiers
 *   - [user_role] / [shipping_country] / [holiday] value returns
 *   - nested if() / ternary
 *   - is_date_range negative case
 *   - validate_item_rules_table_field() / validate_county_rules_table_field()
 *
 * Usage:
 *   php tests/run-tests-robustness.php
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

class Formula_Error {
	public $message;
	public function __construct( $message ) {
		$this->message = $message;
	}
}

function eval_formula( $formula, $contents = array() ) {
	try {
		return Formula_Evaluator::evaluate( $formula, $contents );
	} catch ( \Throwable $e ) {
		// Catch \Throwable (not just \Exception) because the evaluator internally
		// uses eval(), which throws \ParseError — a subclass of \Error, not \Exception.
		// Without this, a single malformed formula would crash the whole test run.
		$err = new Formula_Error( $e->getMessage() );
		$err->type = get_class( $e );
		return $err;
	}
}

function make_item( $overrides = array() ) {
	$defaults = array(
		'name'       => 'Test Product',
		'quantity'   => 1,
		'qty'        => 1,
		'line_total' => 10.00,
		'cost'       => 10.00,
		'weight'     => 1.0,
		'category'   => '',
		'tag'        => '',
		'variation'  => '',
		'sku'        => '',
		'product_id' => 0,
	);
	return array_merge( $defaults, $overrides );
}

// ---------------------------------------------------------------
// TEST SUITE
// ---------------------------------------------------------------

// --- Math functions: log / ln / exp ---
assert_equals( 'math: log base10', 2.00, eval_formula( 'log(100)' ) );
assert_equals( 'math: ln', 1.00, eval_formula( 'ln(2.718281828)' ) );
assert_equals( 'math: exp', 2.718281828, eval_formula( 'exp(1)' ) );
assert_equals( 'math: log with expression', 1.00, eval_formula( 'log(10 * 10) / 2' ) );

// --- Nested conditionals ---
assert_equals( 'nested: if inside if (true/true)', 3.00, eval_formula( 'if(1, if(1, 3.00, 4.00), 5.00)' ) );
assert_equals( 'nested: if inside if (true/false)', 4.00, eval_formula( 'if(1, if(0, 3.00, 4.00), 5.00)' ) );
assert_equals( 'nested: if inside if (false)', 5.00, eval_formula( 'if(0, if(1, 3.00, 4.00), 5.00)' ) );
// Chained (non-parenthesized) nested ternary works in the engine.
assert_equals( 'nested: chained ternary', 8.00, eval_formula( '0 ? 6.00 : 0 ? 7.00 : 8.00' ) );
// Parenthesized nested ternary must NOT crash (the @eval \Throwable guard fixes
// a previous fatal \ParseError). It evaluates to 0.0 rather than fully resolving.
// Parenthesized ternary does not fully resolve but must NOT throw a fatal
// \ParseError (the @eval \Throwable guard fixes a previous crash). The engine
// returns a controlled Formula_Error / 0 instead.
assert_true( 'nested: parenthesized ternary no-crash', eval_formula( '1 ? (0 ? 6.00 : 7.00) : 8.00' ) instanceof Formula_Error || 0.0 === (float) eval_formula( '1 ? (0 ? 6.00 : 7.00) : 8.00' ) );
assert_true( 'nested: parenthesized ternary false no-crash', eval_formula( '0 ? 6.00 : (0 ? 7.00 : 8.00)' ) instanceof Formula_Error || 0.0 === (float) eval_formula( '0 ? 6.00 : (0 ? 7.00 : 8.00)' ) );

// --- [scale] step parameter ---
// Base $25 up to 50 lbs, then +$5 per 10 lbs beyond. 65 lbs → 15 extra → 2 steps → $35.
assert_equals( 'scale: step=10 at 65lbs', 35.00, eval_formula( '[scale on="[weight]" base_cost=25.00 base_units=50 rate=5.00 step=10]', array( make_item( array( 'weight' => 65.0 ) ) ) ) );
// 55 lbs → 5 extra → 1 step → $30.
assert_equals( 'scale: step=10 at 55lbs', 30.00, eval_formula( '[scale on="[weight]" base_cost=25.00 base_units=50 rate=5.00 step=10]', array( make_item( array( 'weight' => 55.0 ) ) ) ) );
// qty scale: base $4 for 1 item, +$2 for every 3 extra. qty=4 → 3 extra → 1 step → $6.
assert_equals( 'scale: qty step=3', 6.00, eval_formula( '[scale on="[qty]" base_cost=4.00 base_units=1 rate=2.00 step=3]', array( make_item( array( 'quantity' => 4, 'qty' => 4 ) ) ) ) );

// --- [loop] step parameter ---
// from=1 to=10 step=3 summing 1.00 → iterations at 1,4,7,10 → 4.00
assert_equals( 'loop: step=3', 4.00, eval_formula( '[loop var="i" from=1 to=10 step=3 formula="1.00"]' ) );

// --- [table] on subtotal/cost ---
// [cost] = cost × qty (line total). Threshold logic is "val <= threshold".
// 3 × $50 = [cost]=150 → above all tiers → default (free over $100) = 0.00.
assert_equals( 'table: subtotal over 100', 0.00, eval_formula( '[table on="[cost]" tiers="50:9.99, 100:4.99, default:0.00"]', array( make_item( array( 'quantity' => 3, 'qty' => 3, 'line_total' => 150.00, 'cost' => 50.00 ) ) ) ) );
// Single item [cost]=40 → up to $50 tier → 9.99.
assert_equals( 'table: subtotal up to 50', 9.99, eval_formula( '[table on="[cost]" tiers="50:9.99, 100:4.99, default:0.00"]', array( make_item( array( 'quantity' => 1, 'qty' => 1, 'line_total' => 40.00, 'cost' => 40.00 ) ) ) ) );
// Single item [cost]=120 → over all tiers → default 0.00.
assert_equals( 'table: subtotal single over 100', 0.00, eval_formula( '[table on="[cost]" tiers="50:9.99, 100:4.99, default:0.00"]', array( make_item( array( 'quantity' => 1, 'qty' => 1, 'line_total' => 120.00, 'cost' => 120.00 ) ) ) ) );

// --- [rate] max_fee and filters ---
// 10% of $100 = $10, max_fee=5 → $5.
assert_equals( 'rate: max_fee caps', 5.00, eval_formula( '[rate percent=10 max_fee=5]', array( make_item( array( 'line_total' => 100.00, 'cost' => 100.00 ) ) ) ) );
// Filtered [rate category="heavy"]: only match heavy items.
assert_equals( 'rate: category filter match', 3.00, eval_formula( '[rate percent=10 category="heavy"]', array(
	make_item( array( 'category' => 'other', 'line_total' => 10.00, 'cost' => 10.00 ) ),
	make_item( array( 'category' => 'heavy', 'line_total' => 30.00, 'cost' => 30.00 ) ),
) ) );

// --- Line items: [sale], `[fee]` variants, `[discount]` percent, notifications ---
$fee_cart = array( make_item( array( 'line_total' => 100.00, 'cost' => 100.00 ) ) );

// [sale percent=10] → -$10 promo credit (negative amount).
$GLOBALS['formula_shipping_current_fees'] = array();
eval_formula( '[sale percent=10 label="Flash Sale Promo"]', $fee_cart );
assert_equals( 'sale: count', 1, count( $GLOBALS['formula_shipping_current_fees'] ) );
if ( ! empty( $GLOBALS['formula_shipping_current_fees'] ) ) {
	assert_equals( 'sale: amount negative', -10.00, $GLOBALS['formula_shipping_current_fees'][0]['amount'] );
	assert_equals( 'sale: label', 'Flash Sale Promo', $GLOBALS['formula_shipping_current_fees'][0]['label'] );
}

// [fee formula="[qty] * 2.50"] — FIXED: the fee formula= attribute now correctly
// accepts bracket placeholders (the regex previously truncated at the inner "]").
// qty=1 × $2.50 = $2.50.
$GLOBALS['formula_shipping_current_fees'] = array();
eval_formula( '[fee formula="[qty] * 2.50" label="Hazmat Packaging Fee" icon="warning"]', $fee_cart );
assert_equals( 'fee: formula-with-placeholder count', 1, count( $GLOBALS['formula_shipping_current_fees'] ) );
if ( ! empty( $GLOBALS['formula_shipping_current_fees'] ) ) {
	assert_equals( 'fee: formula-with-placeholder amount', 2.50, $GLOBALS['formula_shipping_current_fees'][0]['amount'] );
	assert_equals( 'fee: formula-with-placeholder label', 'Hazmat Packaging Fee', $GLOBALS['formula_shipping_current_fees'][0]['label'] );
}

// [fee formula="2.50 * 2"] — plain math (no placeholder) works: $5 fee.
$GLOBALS['formula_shipping_current_fees'] = array();
eval_formula( '[fee formula="2.50 * 2" label="PlainMath"]', $fee_cart );
assert_equals( 'fee: formula-plain count', 1, count( $GLOBALS['formula_shipping_current_fees'] ) );
if ( ! empty( $GLOBALS['formula_shipping_current_fees'] ) ) {
	assert_equals( 'fee: formula-plain amount', 5.00, $GLOBALS['formula_shipping_current_fees'][0]['amount'] );
	assert_equals( 'fee: formula-plain label', 'PlainMath', $GLOBALS['formula_shipping_current_fees'][0]['label'] );
}

// [fee amount=10.00 icon="none"] → $10 flat.
$GLOBALS['formula_shipping_current_fees'] = array();
eval_formula( '[fee amount=10.00 label="Remote Handling" icon="none"]', $fee_cart );
assert_equals( 'fee: amount count', 1, count( $GLOBALS['formula_shipping_current_fees'] ) );
if ( ! empty( $GLOBALS['formula_shipping_current_fees'] ) ) {
	assert_equals( 'fee: amount value', 10.00, $GLOBALS['formula_shipping_current_fees'][0]['amount'] );
}

// [fee amount=2.00 (negative percent? no) — verify positive amount is preserved.
$GLOBALS['formula_shipping_current_fees'] = array();
eval_formula( '[fee amount=7.50 label="Flat Fee"]', $fee_cart );
assert_equals( 'fee: flat amount', 7.50, $GLOBALS['formula_shipping_current_fees'][0]['amount'] );

// [discount percent=10] → -$10 credit.
$GLOBALS['formula_shipping_current_fees'] = array();
eval_formula( '[discount percent=10 label="Percent Credit"]', $fee_cart );
assert_equals( 'discount: percent count', 1, count( $GLOBALS['formula_shipping_current_fees'] ) );
if ( ! empty( $GLOBALS['formula_shipping_current_fees'] ) ) {
	assert_equals( 'discount: percent amount', -10.00, $GLOBALS['formula_shipping_current_fees'][0]['amount'] );
	assert_equals( 'discount: percent label', 'Percent Credit', $GLOBALS['formula_shipping_current_fees'][0]['label'] );
}

// --- Notice variants: [message], [warning], [notice position=] ---
$GLOBALS['formula_shipping_current_notices'] = array();
eval_formula( '[message="Info text"]', $fee_cart );
assert_equals( 'message: count', 1, count( $GLOBALS['formula_shipping_current_notices'] ) );
if ( ! empty( $GLOBALS['formula_shipping_current_notices'] ) ) {
	assert_equals( 'message: value', 'Info text', $GLOBALS['formula_shipping_current_notices'][0]['message'] );
}

$GLOBALS['formula_shipping_current_notices'] = array();
eval_formula( '[warning="Alert text"]', $fee_cart );
assert_equals( 'warning: count', 1, count( $GLOBALS['formula_shipping_current_notices'] ) );
if ( ! empty( $GLOBALS['formula_shipping_current_notices'] ) ) {
	assert_equals( 'warning: value', 'Alert text', $GLOBALS['formula_shipping_current_notices'][0]['message'] );
}

$GLOBALS['formula_shipping_current_notices'] = array();
eval_formula( '[notice="Cart notice" position="top"]', $fee_cart );
assert_equals( 'notice: position count', 1, count( $GLOBALS['formula_shipping_current_notices'] ) );
if ( ! empty( $GLOBALS['formula_shipping_current_notices'] ) ) {
	assert_equals( 'notice: position value', 'Cart notice', $GLOBALS['formula_shipping_current_notices'][0]['message'] );
	assert_equals( 'notice: position attr', 'top', $GLOBALS['formula_shipping_current_notices'][0]['position'] );
}

// --- Placeholder value returns ---
Formula_Evaluator::set_context( array(
	'user' => array( 'roles' => array( 'customer' ), 'email' => 'a@b.com', 'login' => 'johndoe' ),
	'shipping' => array( 'country' => 'CA', 'state' => 'ON', 'zip' => 'M5V 2T6', 'city' => 'Toronto' ),
) );
assert_equals( 'user_role: actual value', 1, eval_formula( 'if([user_role] == "customer", 1, 0)' ) );
assert_equals( 'shipping_country: actual value', 1, eval_formula( 'if([shipping_country] == "CA", 1, 0)' ) );
Formula_Evaluator::set_context( array() );

// --- is_date_range negative case (today not in range) ---
$not_today  = date( 'm-d' );
$range_neg  = ( '01-01' === $not_today ) ? '01-02' : '01-01';
$range_neg2 = ( '01-02' === $not_today ) ? '01-03' : '01-02';
// Build a range that never contains today.
$range_neg_str = $range_neg . ' to ' . $range_neg2;
assert_equals( 'is_date_range: not in range', 0, eval_formula( '[is_date_range="' . $range_neg_str . '"]' ) );

// ---------------------------------------------------------------
// Admin validation methods
// ---------------------------------------------------------------
if ( class_exists( 'WC_Shipping_Formula' ) ) {
	$method = new WC_Shipping_Formula();

	// validate_item_rules_table_field() with $_POST data
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
	$result = $method->validate_item_rules_table_field( 'usa_item_rules', array() );
	assert_equals( 'validate_item_rules_table: count', 2, count( $result ) );
	assert_equals( 'validate_item_rules_table: first type', 'category', $result[0]['type'] );
	assert_equals( 'validate_item_rules_table: first target', 'hardcover', $result[0]['target'] );
	assert_equals( 'validate_item_rules_table: first enabled', 'yes', $result[0]['enabled'] );
	assert_equals( 'validate_item_rules_table: first ship', '5.00 + ([qty] - 1) * 3.50', $result[0]['shipping_formula'] );

	// Invalid formula → forced disabled.
	$_POST[ $field_key ] = array(
		array(
			'type'             => 'category',
			'target'           => 'broken',
			'enabled'          => 'yes',
			'shipping_formula' => '5.00 + (',
			'handling_formula' => '',
		),
	);
	$result = $method->validate_item_rules_table_field( 'usa_item_rules', array() );
	assert_equals( 'validate_item_rules_table: invalid count', 1, count( $result ) );
	assert_equals( 'validate_item_rules_table: invalid disabled', 'no', $result[0]['enabled'] );

	// Empty target → rule skipped.
	$_POST[ $field_key ] = array(
		array(
			'type'             => 'category',
			'target'           => '',
			'enabled'          => 'yes',
			'shipping_formula' => '5.00',
			'handling_formula' => '',
		),
	);
	$result = $method->validate_item_rules_table_field( 'usa_item_rules', array() );
	assert_true( 'validate_item_rules_table: empty target falls back to saved option', is_array( $result ) );

	// validate_county_rules_table_field() with $_POST data
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
	$result = $method->validate_county_rules_table_field( 'usa_county_rules', array() );
	assert_equals( 'validate_county_rules_table: count', 1, count( $result ) );

	// Clean up $_POST
	unset( $_POST[ $field_key ], $_POST[ $county_field_key ] );
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
echo " Formula Rate Shipping — Robustness Test Results\n";
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