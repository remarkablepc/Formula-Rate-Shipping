<?php
/**
 * Formula Rate Shipping — smoke test runner.
 *
 * A lightweight, dependency-free test harness (no PHPUnit required).
 * Boots WordPress + WooCommerce + the plugin, then runs assertions
 * against the Formula_Evaluator engine.
 *
 * Usage:
 *   php tests/run-tests.php
 *
 * Exit code 0 = all tests passed; non-zero = failures.
 */

require_once __DIR__ . '/bootstrap.php';

// ---------------------------------------------------------------
// Minimal test framework
// ---------------------------------------------------------------

$GLOBALS['__frs_tests'] = array(
	'passed'  => 0,
	'failed'  => 0,
	'results' => array(),
);

/**
 * Assert two values are equal (loose comparison, with float tolerance).
 */
function assert_equals( $name, $expected, $actual, $tolerance = 0.0001 ) {
	$pass = false;

	// If the formula threw, it can never equal a numeric/string expectation.
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

/**
 * Assert a value is truthy.
 */
function assert_true( $name, $actual ) {
	assert_equals( $name, true, (bool) $actual );
}

/**
 * Sentinel returned when a formula throws an exception.
 */
class Formula_Error {
	public $message;
	public function __construct( $message ) {
		$this->message = $message;
	}
}

/**
 * Evaluate a formula against a cart contents array.
 *
 * Exceptions are caught and returned as a Formula_Error sentinel so that a
 * single failing formula does not abort the entire test run.
 */
function eval_formula( $formula, $contents = array() ) {
	try {
		return Formula_Evaluator::evaluate( $formula, $contents );
	} catch ( Exception $e ) {
		return new Formula_Error( $e->getMessage() );
	}
}

/**
 * Build a simple cart item array for testing.
 */
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

// --- Basic math ---
assert_equals( 'basic: literal', 5.00, eval_formula( '5.00' ) );
assert_equals( 'basic: addition', 7.50, eval_formula( '5.00 + 2.50' ) );
assert_equals( 'basic: subtraction', 2.50, eval_formula( '5.00 - 2.50' ) );
assert_equals( 'basic: multiplication', 10.00, eval_formula( '5.00 * 2' ) );
assert_equals( 'basic: division', 2.50, eval_formula( '5.00 / 2' ) );
assert_equals( 'basic: modulo', 1.00, eval_formula( '7 % 3' ) );
assert_equals( 'basic: exponentiation', 9.00, eval_formula( '3 ^ 2' ) );

// --- qty / cost / weight / items placeholders ---
$cart = array(
	make_item( array( 'quantity' => 3, 'qty' => 3, 'line_total' => 30.00, 'cost' => 10.00, 'weight' => 2.0 ) ),
);
assert_equals( 'placeholder: [qty]', 3, eval_formula( '[qty]', $cart ) );
assert_equals( 'placeholder: [cost]', 30.00, eval_formula( '[cost]', $cart ) );
assert_equals( 'placeholder: [weight]', 6.00, eval_formula( '[weight]', $cart ) );
assert_equals( 'placeholder: [items]', 1, eval_formula( '[items]', $cart ) );

// --- Tiered quantity formula ---
assert_equals( 'formula: tiered qty', 3.00, eval_formula( '1.50 + ([qty] - 1) * 0.75', $cart ) );

// --- if() function ---
assert_equals( 'if(): true branch', 0.00, eval_formula( 'if(1, 0.00, 5.00)' ) );
assert_equals( 'if(): false branch', 5.00, eval_formula( 'if(0, 0.00, 5.00)' ) );

// --- ternary ---
assert_equals( 'ternary: true', 10.00, eval_formula( '1 ? 10.00 : 20.00' ) );
assert_equals( 'ternary: false', 20.00, eval_formula( '0 ? 10.00 : 20.00' ) );

// --- logical operators (condition-only; must be inside if()/ternary) ---
assert_equals( 'logical: AND true', 1, eval_formula( 'if(1 AND 1, 1, 0)' ) );
assert_equals( 'logical: AND false', 0, eval_formula( 'if(1 AND 0, 1, 0)' ) );
assert_equals( 'logical: OR true', 1, eval_formula( 'if(0 OR 1, 1, 0)' ) );
assert_equals( 'logical: OR false', 0, eval_formula( 'if(0 OR 0, 1, 0)' ) );
assert_equals( 'logical: NOT', 1, eval_formula( 'if(NOT 0, 1, 0)' ) );
assert_equals( 'logical: ! shorthand', 1, eval_formula( 'if(!0, 1, 0)' ) );

// --- comparison operators (arithmetic-level, supported standalone) ---
assert_equals( 'compare: >', 1, eval_formula( '5 > 3' ) );
assert_equals( 'compare: <', 1, eval_formula( '3 < 5' ) );
assert_equals( 'compare: >=', 1, eval_formula( '5 >= 5' ) );
assert_equals( 'compare: <=', 1, eval_formula( '3 <= 5' ) );
assert_equals( 'compare: ==', 1, eval_formula( '5 == 5' ) );
assert_equals( 'compare: !=', 1, eval_formula( '5 != 3' ) );

// --- IN / BETWEEN / CONTAINS (condition-only; inside if()) ---
assert_equals( 'IN: match', 1, eval_formula( 'if("CA" IN ("CA", "OR", "WA"), 1, 0)' ) );
assert_equals( 'IN: no match', 0, eval_formula( 'if("TX" IN ("CA", "OR", "WA"), 1, 0)' ) );
assert_equals( 'BETWEEN: in range', 1, eval_formula( 'if(5 BETWEEN 1 AND 10, 1, 0)' ) );
assert_equals( 'BETWEEN: out of range', 0, eval_formula( 'if(15 BETWEEN 1 AND 10, 1, 0)' ) );
assert_equals( 'CONTAINS: match', 1, eval_formula( 'if("Los Angeles" CONTAINS "Los", 1, 0)' ) );
assert_equals( 'CONTAINS: no match', 0, eval_formula( 'if("Boston" CONTAINS "Los", 1, 0)' ) );

// --- math functions ---
assert_equals( 'math: min', 2.00, eval_formula( 'min(5.00, 2.00)' ) );
assert_equals( 'math: max', 5.00, eval_formula( 'max(5.00, 2.00)' ) );
assert_equals( 'math: sum', 7.00, eval_formula( 'sum(2.00, 5.00)' ) );
assert_equals( 'math: avg', 3.50, eval_formula( 'avg(2.00, 5.00)' ) );
assert_equals( 'math: clamp low', 5.00, eval_formula( 'clamp(2.00, 5.00, 25.00)' ) );
assert_equals( 'math: clamp high', 25.00, eval_formula( 'clamp(30.00, 5.00, 25.00)' ) );
assert_equals( 'math: round', 3.14, eval_formula( 'round(3.14159, 2)' ) );
assert_equals( 'math: ceil', 4.00, eval_formula( 'ceil(3.2)' ) );
assert_equals( 'math: floor', 3.00, eval_formula( 'floor(3.8)' ) );
assert_equals( 'math: trunc', 3.00, eval_formula( 'trunc(3.8)' ) );
assert_equals( 'math: abs', 5.00, eval_formula( 'abs(-5)' ) );
assert_equals( 'math: sqrt', 3.00, eval_formula( 'sqrt(9)' ) );
assert_equals( 'math: pow', 8.00, eval_formula( 'pow(2, 3)' ) );
assert_equals( 'math: mod', 1.00, eval_formula( 'mod(7, 3)' ) );

// --- [table] ---
assert_equals( 'table: weight bracket', 12.50, eval_formula( '[table on="[weight]" tiers="1:4.50, 5:8.00, 10:12.50, default:25.00"]', array( make_item( array( 'weight' => 6.0 ) ) ) ) );
assert_equals( 'table: qty bracket', 7.50, eval_formula( '[table on="[qty]" tiers="1:5.00, 3:7.50, 6:10.00, default:15.00"]', array( make_item( array( 'quantity' => 3, 'qty' => 3 ) ) ) ) );
assert_equals( 'table: default fallback', 25.00, eval_formula( '[table on="[weight]" tiers="1:4.50, 5:8.00, default:25.00"]', array( make_item( array( 'weight' => 50.0 ) ) ) ) );

// --- [scale] ---
assert_equals( 'scale: within base', 5.00, eval_formula( '[scale on="[weight]" base_cost=5.00 base_units=2 rate=1.50 step=1]', array( make_item( array( 'weight' => 2.0 ) ) ) ) );
assert_equals( 'scale: beyond base', 6.50, eval_formula( '[scale on="[weight]" base_cost=5.00 base_units=2 rate=1.50 step=1]', array( make_item( array( 'weight' => 3.0 ) ) ) ) );

// --- [loop] ---
assert_equals( 'loop: tiered sum', 17.50, eval_formula( '[loop var="i" from=1 to="[qty]" formula="if(i <= 3, 5.00, 2.50)"]', array( make_item( array( 'quantity' => 4, 'qty' => 4 ) ) ) ) );

// --- [rate percent] ---
assert_equals( 'rate: percent', 3.00, eval_formula( '[rate percent=10]', array( make_item( array( 'line_total' => 30.00, 'cost' => 30.00 ) ) ) ) );
assert_equals( 'rate: percent with min_fee', 5.00, eval_formula( '[rate percent=10 min_fee=5]', array( make_item( array( 'line_total' => 10.00, 'cost' => 10.00 ) ) ) ) );

// --- category / tag / variation / product matching ---
$cat_cart = array( make_item( array( 'category' => 'books' ) ) );
assert_equals( 'category: match', 1, eval_formula( '[category="books"]', $cat_cart ) );
assert_equals( 'category: no match', 0, eval_formula( '[category="electronics"]', $cat_cart ) );

$tag_cart = array( make_item( array( 'tag' => 'fragile' ) ) );
assert_equals( 'tag: match', 1, eval_formula( '[tag="fragile"]', $tag_cart ) );
assert_equals( 'tag: no match', 0, eval_formula( '[tag="heavy"]', $tag_cart ) );

$var_cart = array( make_item( array( 'variation' => 'Hardcover' ) ) );
assert_equals( 'variation: match', 1, eval_formula( '[variation="Hardcover"]', $var_cart ) );

$prod_cart = array( make_item( array( 'name' => 'Hardcover Book' ) ) );
assert_equals( 'product: match', 1, eval_formula( '[product="Hardcover"]', $prod_cart ) );

// --- user placeholder (guest context) ---
// By default, no user is logged in, so [user="X"] should be 0 and [user_role] should be "guest".
assert_equals( 'user: guest no match', 0, eval_formula( '[user="Subscriber"]' ) );
assert_equals( 'user_role: guest', 1, eval_formula( 'if([user_role] == "guest", 1, 0)' ) );

// --- shipping context (defaults) ---
assert_equals( 'shipping_country: default', 1, eval_formula( 'if([shipping_country] == "US", 1, 0)' ) );

// ---------------------------------------------------------------
// Edge / error handling
// ---------------------------------------------------------------
assert_true( 'edge: division by zero throws', eval_formula( '1/0' ) instanceof Formula_Error );
assert_true( 'edge: modulo by zero throws', eval_formula( '1%0' ) instanceof Formula_Error );
assert_true( 'edge: malformed if() throws', eval_formula( 'if(1, 2)' ) instanceof Formula_Error );
assert_equals( 'edge: unary minus', 5.00, eval_formula( '-5 + 10' ) );
assert_equals( 'edge: negative literal', -5.00, eval_formula( '-5' ) );
assert_equals( 'edge: loop from > to', 0.00, eval_formula( '[loop var="i" from=5 to="[qty]" formula="1.00"]', array( make_item( array( 'quantity' => 3, 'qty' => 3 ) ) ) ) );

// ---------------------------------------------------------------
// Filtered placeholders
// ---------------------------------------------------------------
$filtered_cart = array(
	make_item( array( 'category' => 'books', 'quantity' => 3, 'qty' => 3, 'line_total' => 30.00, 'cost' => 10.00, 'weight' => 2.0 ) ),
	make_item( array( 'tag' => 'fragile', 'quantity' => 2, 'qty' => 2, 'line_total' => 20.00, 'cost' => 10.00, 'weight' => 1.0 ) ),
	make_item( array( 'variation' => 'Hardcover', 'quantity' => 1, 'qty' => 1, 'line_total' => 15.00, 'cost' => 15.00, 'weight' => 3.0 ) ),
);
assert_equals( 'filtered: [qty category="books"]', 3, eval_formula( '[qty category="books"]', $filtered_cart ) );
assert_equals( 'filtered: [cost tag="fragile"]', 20.00, eval_formula( '[cost tag="fragile"]', $filtered_cart ) );
assert_equals( 'filtered: [weight variation="Hardcover"]', 3.00, eval_formula( '[weight variation="Hardcover"]', $filtered_cart ) );
assert_equals( 'filtered: [items category="books"]', 1, eval_formula( '[items category="books"]', $filtered_cart ) );

// ---------------------------------------------------------------
// Context placeholders via set_context()
// ---------------------------------------------------------------
Formula_Evaluator::set_context( array(
	'user'     => array( 'roles' => array( 'subscriber' ), 'email' => 'a@b.com', 'login' => 'johndoe' ),
	'shipping' => array( 'country' => 'CA', 'state' => 'ON', 'zip' => 'M5V 2T6', 'city' => 'Toronto' ),
) );
assert_equals( 'context: [user="subscriber"]', 1, eval_formula( '[user="subscriber"]' ) );
assert_equals( 'context: [user_role]', 1, eval_formula( 'if([user_role] == "subscriber", 1, 0)' ) );
assert_equals( 'context: [shipping_country]', 1, eval_formula( 'if([shipping_country] == "CA", 1, 0)' ) );
assert_equals( 'context: [shipping_state]', 1, eval_formula( 'if([shipping_state] == "ON", 1, 0)' ) );
assert_equals( 'context: [shipping_zip]', 1, eval_formula( 'if([shipping_zip] == "M5V 2T6", 1, 0)' ) );
assert_equals( 'context: [shipping_city]', 1, eval_formula( 'if([shipping_city] == "Toronto", 1, 0)' ) );
assert_equals( 'context: [is_international="US"]', 1, eval_formula( '[is_international="US"]' ) );
assert_equals( 'context: [is_international="CA"]', 0, eval_formula( '[is_international="CA"]' ) );
// Reset context back to empty so later guest tests behave consistently.
Formula_Evaluator::set_context( array() );

// ---------------------------------------------------------------
// Side-effect line items & notices
// ---------------------------------------------------------------
$fee_cart = array( make_item( array( 'line_total' => 100.00, 'cost' => 100.00 ) ) );
$GLOBALS['formula_shipping_current_fees']    = array();
$GLOBALS['formula_shipping_current_notices'] = array();
eval_formula( '[fee percent=5 label="Fuel"] + [notice="Hello"]', $fee_cart );
assert_equals( 'fee: count', 1, count( $GLOBALS['formula_shipping_current_fees'] ) );
assert_equals( 'fee: amount', 5.00, $GLOBALS['formula_shipping_current_fees'][0]['amount'] );
assert_equals( 'fee: label', 'Fuel', $GLOBALS['formula_shipping_current_fees'][0]['label'] );
assert_equals( 'notice: count', 1, count( $GLOBALS['formula_shipping_current_notices'] ) );
assert_equals( 'notice: message', 'Hello', $GLOBALS['formula_shipping_current_notices'][0]['message'] );

$GLOBALS['formula_shipping_current_fees'] = array();
eval_formula( '[discount amount=5.00 label="Credit"]', $fee_cart );
assert_equals( 'discount: amount is negative', -5.00, $GLOBALS['formula_shipping_current_fees'][0]['amount'] );
assert_equals( 'discount: label', 'Credit', $GLOBALS['formula_shipping_current_fees'][0]['label'] );

// ---------------------------------------------------------------
// Time & date placeholders (computed against current date)
// ---------------------------------------------------------------
$today_md    = date( 'm-d' );
$today_name  = date( 'l' );
$today_hour  = (int) date( 'G' );
$today_month = (int) date( 'n' );
$is_weekend  = ( (int) date( 'N' ) >= 6 ) ? 1 : 0;
$not_today   = ( '01-01' === $today_md ) ? '01-02' : '01-01';

assert_equals( 'time: [hour]', $today_hour, eval_formula( '[hour]' ) );
assert_equals( 'time: [day]', 1, eval_formula( 'if([day] == "' . $today_name . '", 1, 0)' ) );
assert_equals( 'time: [month]', $today_month, eval_formula( '[month]' ) );
assert_equals( 'time: [is_weekend]', $is_weekend, eval_formula( '[is_weekend]' ) );
assert_equals( 'time: [is_date] today', 1, eval_formula( '[is_date="' . $today_md . '"]' ) );
assert_equals( 'time: [is_date] not today', 0, eval_formula( '[is_date="' . $not_today . '"]' ) );
assert_equals( 'time: [is_date_range] today', 1, eval_formula( '[is_date_range="' . $today_md . ' to ' . $today_md . '"]' ) );

// Holiday placeholders (date.nager.at API-backed). Assert they evaluate to 0 or 1 without error.
$holiday_val = eval_formula( '[is_holiday]' );
assert_true( 'time: [is_holiday] is 0 or 1', ( 0 == $holiday_val || 1 == $holiday_val ) );
$holiday_name = eval_formula( 'if([holiday] == "", 1, 0)' );
assert_true( 'time: [holiday] evaluates to 0 or 1', ( 0 == $holiday_name || 1 == $holiday_name ) );

// ---------------------------------------------------------------
// Rule resolution: evaluate_item_rules() (Folded Incremental etc.)
// ---------------------------------------------------------------
if ( class_exists( 'WC_Shipping_Formula' ) ) {
	$item_rules = array(
		array(
			'type'             => 'category',
			'target'           => 'hardcover',
			'enabled'          => 'yes',
			'shipping_formula' => '5.00 + ([qty] - 1) * 3.50',
			'handling_formula' => '3.50',
		),
		array(
			'type'             => 'category',
			'target'           => 'mini-book',
			'enabled'          => 'yes',
			'shipping_formula' => '1.50 + ([qty] - 1) * 0.75',
			'handling_formula' => '1.25',
		),
	);
	$mixed_cart = array(
		array( 'category' => 'hardcover', 'quantity' => 1, 'qty' => 1, 'line_total' => 20.00, 'cost' => 20.00, 'weight' => 2.0 ),
		array( 'category' => 'mini-book', 'quantity' => 1, 'qty' => 1, 'line_total' => 5.00, 'cost' => 5.00, 'weight' => 0.5 ),
	);

	// Folded Incremental (README headline example: $9.25 total)
	$fi = WC_Shipping_Formula::evaluate_item_rules( $item_rules, 'merge_difference', $mixed_cart );
	assert_equals( 'rules: folded ship', 5.75, $fi['shipping'] );
	assert_equals( 'rules: folded hand', 3.50, $fi['handling'] );
	assert_equals( 'rules: folded total', 9.25, $fi['shipping'] + $fi['handling'] );

	// Sum
	$sum = WC_Shipping_Formula::evaluate_item_rules( $item_rules, 'sum', $mixed_cart );
	assert_equals( 'rules: sum ship', 6.50, $sum['shipping'] );
	assert_equals( 'rules: sum hand', 4.75, $sum['handling'] );

	// Max
	$max = WC_Shipping_Formula::evaluate_item_rules( $item_rules, 'max', $mixed_cart );
	assert_equals( 'rules: max ship', 5.00, $max['shipping'] );
	assert_equals( 'rules: max hand', 3.50, $max['handling'] );

	// First match (default branch sums applied rules)
	$fm = WC_Shipping_Formula::evaluate_item_rules( $item_rules, 'first_match', $mixed_cart );
	assert_equals( 'rules: first_match ship', 6.50, $fm['shipping'] );
	assert_equals( 'rules: first_match hand', 4.75, $fm['handling'] );

	// ---------------------------------------------------------------
	// End-to-end: calculate_shipping()
	// ---------------------------------------------------------------
	$method = new WC_Shipping_Formula();
	$method->usa_item_rules               = $item_rules;
	$method->usa_rule_resolution          = 'merge_difference';
	$method->usa_default_shipping_formula = '';
	$method->usa_default_handling_formula = '';
	$method->usa_min_shipping             = '';
	$method->usa_max_shipping             = '';
	$method->round_rates                  = 'no';
	$method->handling_combine             = 'yes';
	$method->title                        = 'Shipping and Handling';
	$method->handling_title               = 'Handling Fee';
	$method->settings['usa_county_rules'] = array();
	$method->settings['usa_location_rule_resolution'] = 'first_match';

	$package = array(
		'destination' => array( 'country' => 'US', 'state' => 'CA', 'postcode' => '90210', 'city' => 'Los Angeles' ),
		'contents'    => $mixed_cart,
	);

	$method->calculate_shipping( $package );
	assert_equals( 'calculate_shipping: rate count', 1, count( $method->rates ) );
	if ( ! empty( $method->rates ) ) {
		$rate = reset( $method->rates );
		assert_equals( 'calculate_shipping: cost', 9.25, $rate->cost );
	}
} else {
	echo " [SKIP] WC_Shipping_Formula not available; rule-resolution tests skipped.\n";
}

// ---------------------------------------------------------------
// Report
// ---------------------------------------------------------------

$results = $GLOBALS['__frs_tests']['results'];
$passed  = $GLOBALS['__frs_tests']['passed'];
$failed  = $GLOBALS['__frs_tests']['failed'];

echo "\n";
echo "==========================================\n";
echo " Formula Rate Shipping — Smoke Test Results\n";
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