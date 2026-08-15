<?php
/**
 * Formula Rate Shipping — advanced smoke test runner.
 *
 * Covers pure-logic methods not exercised by run-tests.php:
 *   - normalize_country_reference()
 *   - environment_target_matches() (device/OS)
 *   - evaluate_user_placeholder() (role/email/login/meta)
 *   - detect_client_context() (UA parsing)
 *   - value_matches_pattern() (glob wildcards)
 *   - item_matches_target() (variation arrays, partial matches)
 *   - customer_matches_location() (country/state/city/postcode/county)
 *   - [os] / [browser] / [device] / [is_mobile] placeholders
 *
 * Usage:
 *   php tests/run-tests-advanced.php
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

function eval_formula( $formula, $contents = array() ) {
	try {
		return Formula_Evaluator::evaluate( $formula, $contents );
	} catch ( Exception $e ) {
		return new Formula_Error( $e->getMessage() );
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

// --- normalize_country_reference() ---
assert_equals( 'country: US code passthrough', 'US', Formula_Evaluator::normalize_country_reference( 'US' ) );
assert_equals( 'country: lowercase code', 'US', Formula_Evaluator::normalize_country_reference( 'us' ) );
assert_equals( 'country: USA alias', 'US', Formula_Evaluator::normalize_country_reference( 'USA' ) );
assert_equals( 'country: United States', 'US', Formula_Evaluator::normalize_country_reference( 'United States' ) );
assert_equals( 'country: CAN alias', 'CA', Formula_Evaluator::normalize_country_reference( 'CAN' ) );
assert_equals( 'country: Canada', 'CA', Formula_Evaluator::normalize_country_reference( 'Canada' ) );
// Note: 'UK' is a 2-letter code, so it passes the ISO code check before the alias map.
assert_equals( 'country: UK code', 'UK', Formula_Evaluator::normalize_country_reference( 'UK' ) );
assert_equals( 'country: Great Britain', 'GB', Formula_Evaluator::normalize_country_reference( 'Great Britain' ) );
assert_equals( 'country: quoted value', 'US', Formula_Evaluator::normalize_country_reference( '"US"' ) );
assert_equals( 'country: empty string', '', Formula_Evaluator::normalize_country_reference( '' ) );
assert_equals( 'country: whitespace trimmed', 'CA', Formula_Evaluator::normalize_country_reference( '  ca  ' ) );

// --- environment_target_matches() ---
// Device matching
assert_true( 'device: empty rule matches', Formula_Evaluator::environment_target_matches( 'mobile', '', 'device' ) );
assert_true( 'device: exact match', Formula_Evaluator::environment_target_matches( 'mobile', 'mobile', 'device' ) );
assert_true( 'device: alias phone→mobile', Formula_Evaluator::environment_target_matches( 'mobile', 'phone', 'device' ) );
assert_true( 'device: alias smartphone→mobile', Formula_Evaluator::environment_target_matches( 'mobile', 'smartphone', 'device' ) );
assert_true( 'device: alias laptop→desktop', Formula_Evaluator::environment_target_matches( 'desktop', 'laptop', 'device' ) );
assert_true( 'device: alias pc→desktop', Formula_Evaluator::environment_target_matches( 'desktop', 'pc', 'device' ) );
assert_true( 'device: alias ipad→tablet', Formula_Evaluator::environment_target_matches( 'tablet', 'ipad', 'device' ) );
assert_true( 'device: comma list match', Formula_Evaluator::environment_target_matches( 'tablet', 'mobile, tablet', 'device' ) );
assert_true( 'device: ALL matches', Formula_Evaluator::environment_target_matches( 'desktop', 'ALL', 'device' ) );
assert_true( 'device: case-insensitive', Formula_Evaluator::environment_target_matches( 'MOBILE', 'Mobile', 'device' ) );
assert_false( 'device: no match', Formula_Evaluator::environment_target_matches( 'desktop', 'mobile', 'device' ) );
assert_false( 'device: comma list no match', Formula_Evaluator::environment_target_matches( 'tablet', 'mobile, desktop', 'device' ) );

// OS matching
assert_true( 'os: empty rule matches', Formula_Evaluator::environment_target_matches( 'Windows 11', '', 'os' ) );
assert_true( 'os: exact match', Formula_Evaluator::environment_target_matches( 'Windows 11', 'Windows 11', 'os' ) );
assert_true( 'os: win11 alias', Formula_Evaluator::environment_target_matches( 'Windows 11', 'win11', 'os' ) );
assert_true( 'os: win10 alias', Formula_Evaluator::environment_target_matches( 'Windows 10', 'win10', 'os' ) );
assert_true( 'os: windows generic matches version', Formula_Evaluator::environment_target_matches( 'Windows 10', 'Windows', 'os' ) );
assert_true( 'os: version matches generic', Formula_Evaluator::environment_target_matches( 'Windows', 'Windows 10', 'os' ) );
assert_true( 'os: macos ventura alias', Formula_Evaluator::environment_target_matches( 'MacOS Ventura', 'ventura', 'os' ) );
assert_true( 'os: macos generic', Formula_Evaluator::environment_target_matches( 'MacOS Sonoma', 'MacOS', 'os' ) );
assert_true( 'os: ios version', Formula_Evaluator::environment_target_matches( 'iOS 17', 'iOS 17', 'os' ) );
assert_true( 'os: ios generic matches version', Formula_Evaluator::environment_target_matches( 'iOS 17', 'iOS', 'os' ) );
assert_true( 'os: android version', Formula_Evaluator::environment_target_matches( 'Android 14', 'Android 14', 'os' ) );
assert_true( 'os: android generic matches version', Formula_Evaluator::environment_target_matches( 'Android 14', 'Android', 'os' ) );
assert_true( 'os: chromeos', Formula_Evaluator::environment_target_matches( 'ChromeOS', 'chromeos', 'os' ) );
assert_true( 'os: linux', Formula_Evaluator::environment_target_matches( 'Linux', 'linux', 'os' ) );
assert_true( 'os: comma list match', Formula_Evaluator::environment_target_matches( 'MacOS Ventura', 'Windows, MacOS', 'os' ) );
assert_true( 'os: ALL matches', Formula_Evaluator::environment_target_matches( 'Linux', 'ALL', 'os' ) );
assert_false( 'os: no match', Formula_Evaluator::environment_target_matches( 'Windows 10', 'MacOS', 'os' ) );
assert_false( 'os: comma list no match', Formula_Evaluator::environment_target_matches( 'Linux', 'Windows, MacOS', 'os' ) );

// --- evaluate_user_placeholder() ---
Formula_Evaluator::set_context( array(
	'user' => array(
		'roles' => array( 'subscriber', 'customer' ),
		'email' => 'jane@example.com',
		'login' => 'jane_doe',
		'meta'  => array(
			'membership_level' => 'gold',
			'vip_status'       => array( 'vip', 'platinum' ),
			'empty_meta'       => '',
		),
	),
) );

// Role matching
assert_true( 'user: role match', Formula_Evaluator::evaluate_user_placeholder( 'subscriber', 'user_role' ) );
assert_true( 'user: role match case-insensitive', Formula_Evaluator::evaluate_user_placeholder( 'SUBSCRIBER', 'user_role' ) );
assert_true( 'user: role match second role', Formula_Evaluator::evaluate_user_placeholder( 'customer', 'user_role' ) );
assert_false( 'user: role no match', Formula_Evaluator::evaluate_user_placeholder( 'administrator', 'user_role' ) );

// Empty roles context
Formula_Evaluator::set_context( array(
	'user' => array( 'roles' => array(), 'email' => '', 'login' => '' ),
) );
assert_false( 'user: role empty roles', Formula_Evaluator::evaluate_user_placeholder( 'subscriber', 'user_role' ) );

// Restore full context
Formula_Evaluator::set_context( array(
	'user' => array(
		'roles' => array( 'subscriber', 'customer' ),
		'email' => 'jane@example.com',
		'login' => 'jane_doe',
		'meta'  => array(
			'membership_level' => 'gold',
			'vip_status'       => array( 'vip', 'platinum' ),
			'empty_meta'       => '',
		),
	),
) );

// Email matching
assert_true( 'user: email match', Formula_Evaluator::evaluate_user_placeholder( 'jane@example.com', 'user_email' ) );
assert_true( 'user: email case-insensitive', Formula_Evaluator::evaluate_user_placeholder( 'JANE@EXAMPLE.COM', 'user_email' ) );
assert_false( 'user: email no match', Formula_Evaluator::evaluate_user_placeholder( 'other@example.com', 'user_email' ) );

// Login matching
assert_true( 'user: login match', Formula_Evaluator::evaluate_user_placeholder( 'jane_doe', 'user_login' ) );
assert_false( 'user: login no match', Formula_Evaluator::evaluate_user_placeholder( 'john_doe', 'user_login' ) );

// Meta matching
assert_true( 'user: meta match', Formula_Evaluator::evaluate_user_placeholder( 'gold', 'user_meta', 'membership_level' ) );
assert_true( 'user: meta match case-insensitive', Formula_Evaluator::evaluate_user_placeholder( 'GOLD', 'user_meta', 'membership_level' ) );
assert_false( 'user: meta no match', Formula_Evaluator::evaluate_user_placeholder( 'silver', 'user_meta', 'membership_level' ) );
assert_true( 'user: meta array match', Formula_Evaluator::evaluate_user_placeholder( 'vip', 'user_meta', 'vip_status' ) );
assert_true( 'user: meta array second value', Formula_Evaluator::evaluate_user_placeholder( 'platinum', 'user_meta', 'vip_status' ) );
assert_false( 'user: meta array no match', Formula_Evaluator::evaluate_user_placeholder( 'bronze', 'user_meta', 'vip_status' ) );
assert_false( 'user: meta missing key', Formula_Evaluator::evaluate_user_placeholder( 'gold', 'user_meta', 'nonexistent_key' ) );
assert_false( 'user: meta empty value', Formula_Evaluator::evaluate_user_placeholder( 'gold', 'user_meta', 'empty_meta' ) );

// Auto matching (role OR email OR login)
assert_true( 'user: auto role match', Formula_Evaluator::evaluate_user_placeholder( 'subscriber', 'auto' ) );
assert_true( 'user: auto email match', Formula_Evaluator::evaluate_user_placeholder( 'jane@example.com', 'auto' ) );
assert_true( 'user: auto login match', Formula_Evaluator::evaluate_user_placeholder( 'jane_doe', 'auto' ) );
assert_false( 'user: auto no match', Formula_Evaluator::evaluate_user_placeholder( 'administrator', 'auto' ) );

// Reset context
Formula_Evaluator::set_context( array() );

// --- detect_client_context() ---
$orig_ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';

// Windows 11 desktop Chrome (22H2 marker)
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 22H2';
$client = Formula_Evaluator::detect_client_context();
assert_equals( 'ua: windows 11 os', 'Windows 11', $client['os'] );
assert_equals( 'ua: chrome browser', 'Chrome', $client['browser'] );
assert_equals( 'ua: desktop device', 'desktop', $client['device'] );
assert_equals( 'ua: desktop not mobile', 0, $client['is_mobile'] );

// Windows 10 desktop Firefox
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0';
$client = Formula_Evaluator::detect_client_context();
assert_equals( 'ua: windows 10 os', 'Windows 10', $client['os'] );
assert_equals( 'ua: firefox browser', 'Firefox', $client['browser'] );

// MacOS Catalina Safari (10_15 maps to Catalina)
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15';
$client = Formula_Evaluator::detect_client_context();
assert_equals( 'ua: macos os', 'MacOS Catalina', $client['os'] );
assert_equals( 'ua: safari browser', 'Safari', $client['browser'] );

// MacOS Sonoma Safari (14_5 maps to Sonoma)
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15';
$client = Formula_Evaluator::detect_client_context();
assert_equals( 'ua: macos sonoma os', 'MacOS Sonoma', $client['os'] );

// iPhone iOS Safari
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1';
$client = Formula_Evaluator::detect_client_context();
assert_equals( 'ua: ios os', 'iOS 17.1', $client['os'] );
assert_equals( 'ua: ios mobile device', 'mobile', $client['device'] );
assert_equals( 'ua: ios is_mobile', 1, $client['is_mobile'] );

// Android Chrome
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36';
$client = Formula_Evaluator::detect_client_context();
assert_equals( 'ua: android os', 'Android 14', $client['os'] );
assert_equals( 'ua: android mobile device', 'mobile', $client['device'] );
assert_equals( 'ua: android is_mobile', 1, $client['is_mobile'] );

// iPad tablet
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPad; CPU OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1';
$client = Formula_Evaluator::detect_client_context();
assert_equals( 'ua: ipad tablet device', 'tablet', $client['device'] );
assert_equals( 'ua: ipad is_mobile', 1, $client['is_mobile'] );

// Edge browser
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0';
$client = Formula_Evaluator::detect_client_context();
assert_equals( 'ua: edge browser', 'Edge', $client['browser'] );

// Opera browser
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 OPR/106.0.0.0';
$client = Formula_Evaluator::detect_client_context();
assert_equals( 'ua: opera browser', 'Opera', $client['browser'] );

// Restore original UA
$_SERVER['HTTP_USER_AGENT'] = $orig_ua;

// --- value_matches_pattern() ---
assert_true( 'pattern: exact match', Formula_Evaluator::value_matches_pattern( 'CA', 'CA' ) );
assert_true( 'pattern: case-insensitive', Formula_Evaluator::value_matches_pattern( 'ca', 'CA' ) );
assert_true( 'pattern: wildcard *', Formula_Evaluator::value_matches_pattern( '90210', '90*' ) );
assert_true( 'pattern: wildcard * middle', Formula_Evaluator::value_matches_pattern( 'Los Angeles', 'Los*Angeles' ) );
assert_true( 'pattern: wildcard ?', Formula_Evaluator::value_matches_pattern( 'CA', 'C?' ) );
assert_true( 'pattern: wildcard ? middle', Formula_Evaluator::value_matches_pattern( 'M5V', 'M?V' ) );
assert_false( 'pattern: no match', Formula_Evaluator::value_matches_pattern( 'TX', 'CA' ) );
assert_false( 'pattern: wildcard no match', Formula_Evaluator::value_matches_pattern( '90210', '91*' ) );

// --- item_matches_target() ---
$item = make_item( array(
	'name'      => 'Hardcover Book',
	'category'  => 'books',
	'tag'       => 'fragile',
	'variation' => 'Hardcover',
) );

assert_true( 'item: empty type matches', Formula_Evaluator::item_matches_target( $item, '', '' ) );
assert_true( 'item: default type matches', Formula_Evaluator::item_matches_target( $item, 'default', '' ) );
assert_true( 'item: name partial match', Formula_Evaluator::item_matches_target( $item, 'product', 'hardcover' ) );
assert_true( 'item: category exact match', Formula_Evaluator::item_matches_target( $item, 'category', 'books' ) );
assert_true( 'item: category partial match', Formula_Evaluator::item_matches_target( $item, 'category', 'book' ) );
assert_true( 'item: tag exact match', Formula_Evaluator::item_matches_target( $item, 'tag', 'fragile' ) );
assert_true( 'item: variation string match', Formula_Evaluator::item_matches_target( $item, 'variation', 'hardcover' ) );
assert_false( 'item: category no match', Formula_Evaluator::item_matches_target( $item, 'category', 'electronics' ) );
assert_false( 'item: tag no match', Formula_Evaluator::item_matches_target( $item, 'tag', 'heavy' ) );

// Variation as array (WooCommerce variation attributes)
$item_var_array = make_item( array(
	'variation' => array( 'attribute_pa_color' => 'Red', 'attribute_pa_size' => 'Large' ),
) );
assert_true( 'item: variation array match', Formula_Evaluator::item_matches_target( $item_var_array, 'variation', 'red' ) );
assert_true( 'item: variation array second attr', Formula_Evaluator::item_matches_target( $item_var_array, 'variation', 'large' ) );
assert_false( 'item: variation array no match', Formula_Evaluator::item_matches_target( $item_var_array, 'variation', 'blue' ) );

// --- customer_matches_location() ---
if ( class_exists( 'WC_Shipping_Formula' ) ) {
	$dest = array(
		'country'  => 'US',
		'state'    => 'CA',
		'postcode' => '90210',
		'city'     => 'Los Angeles',
	);

	// Country matching
	assert_true( 'loc: country match', WC_Shipping_Formula::customer_matches_location( $dest, 'US', '', '' ) );
	assert_true( 'loc: country alias match', WC_Shipping_Formula::customer_matches_location( $dest, 'USA', '', '' ) );
	assert_true( 'loc: country lowercase', WC_Shipping_Formula::customer_matches_location( $dest, 'us', '', '' ) );
	assert_true( 'loc: country ALL', WC_Shipping_Formula::customer_matches_location( $dest, 'ALL', '', '' ) );
	assert_true( 'loc: country empty', WC_Shipping_Formula::customer_matches_location( $dest, '', '', '' ) );
	assert_true( 'loc: country comma list', WC_Shipping_Formula::customer_matches_location( $dest, 'CA, US, MX', '', '' ) );
	assert_false( 'loc: country no match', WC_Shipping_Formula::customer_matches_location( $dest, 'CA', '', '' ) );
	assert_false( 'loc: country comma list no match', WC_Shipping_Formula::customer_matches_location( $dest, 'CA, MX', '', '' ) );

	// State matching
	assert_true( 'loc: state match', WC_Shipping_Formula::customer_matches_location( $dest, 'US', 'CA', '' ) );
	assert_true( 'loc: state lowercase', WC_Shipping_Formula::customer_matches_location( $dest, 'US', 'ca', '' ) );
	assert_true( 'loc: state comma list', WC_Shipping_Formula::customer_matches_location( $dest, 'US', 'CA, OR, WA', '' ) );
	assert_true( 'loc: state space list', WC_Shipping_Formula::customer_matches_location( $dest, 'US', 'CA OR WA', '' ) );
	assert_true( 'loc: state empty', WC_Shipping_Formula::customer_matches_location( $dest, 'US', '', '' ) );
	assert_false( 'loc: state no match', WC_Shipping_Formula::customer_matches_location( $dest, 'US', 'TX', '' ) );

	// Location pattern (city/postcode/county)
	assert_true( 'loc: city exact', WC_Shipping_Formula::customer_matches_location( $dest, 'US', 'CA', 'Los Angeles' ) );
	assert_true( 'loc: city partial', WC_Shipping_Formula::customer_matches_location( $dest, 'US', 'CA', 'Los' ) );
	assert_true( 'loc: postcode exact', WC_Shipping_Formula::customer_matches_location( $dest, 'US', 'CA', '90210' ) );
	assert_true( 'loc: postcode wildcard', WC_Shipping_Formula::customer_matches_location( $dest, 'US', 'CA', '90*' ) );
	assert_false( 'loc: pattern no match', WC_Shipping_Formula::customer_matches_location( $dest, 'US', 'CA', 'New York' ) );
	assert_false( 'loc: wildcard no match', WC_Shipping_Formula::customer_matches_location( $dest, 'US', 'CA', '91*' ) );

	// County matching
	$dest_county = array(
		'country'  => 'US',
		'state'    => 'CA',
		'postcode' => '90210',
		'city'     => 'Los Angeles',
		'county'   => 'Los Angeles County',
	);
	assert_true( 'loc: county exact', WC_Shipping_Formula::customer_matches_location( $dest_county, 'US', 'CA', 'Los Angeles County' ) );
	assert_true( 'loc: county partial', WC_Shipping_Formula::customer_matches_location( $dest_county, 'US', 'CA', 'Los Angeles' ) );
	assert_true( 'loc: county wildcard', WC_Shipping_Formula::customer_matches_location( $dest_county, 'US', 'CA', 'Los*' ) );
	assert_false( 'loc: county no match', WC_Shipping_Formula::customer_matches_location( $dest_county, 'US', 'CA', 'Orange County' ) );

	// User role location rule
	Formula_Evaluator::set_context( array(
		'user' => array( 'roles' => array( 'subscriber' ), 'email' => 'a@b.com', 'login' => 'johndoe' ),
	) );
	assert_true( 'loc: user role match', WC_Shipping_Formula::customer_matches_location( $dest, 'US', 'CA', '', 'user_role', 'subscriber' ) );
	assert_false( 'loc: user role no match', WC_Shipping_Formula::customer_matches_location( $dest, 'US', 'CA', '', 'user_role', 'administrator' ) );
	assert_true( 'loc: user auto match', WC_Shipping_Formula::customer_matches_location( $dest, 'US', 'CA', '', 'location', 'subscriber' ) );
	assert_false( 'loc: user auto no match', WC_Shipping_Formula::customer_matches_location( $dest, 'US', 'CA', '', 'location', 'administrator' ) );

	// User email location rule
	assert_true( 'loc: user email match', WC_Shipping_Formula::customer_matches_location( $dest, 'US', 'CA', '', 'user_email', 'a@b.com' ) );
	assert_false( 'loc: user email no match', WC_Shipping_Formula::customer_matches_location( $dest, 'US', 'CA', '', 'user_email', 'x@y.com' ) );

	// User meta location rule
	Formula_Evaluator::set_context( array(
		'user' => array(
			'roles' => array( 'subscriber' ),
			'email' => 'a@b.com',
			'login' => 'johndoe',
			'meta'  => array( 'membership_level' => 'gold' ),
		),
	) );
	assert_true( 'loc: user meta match', WC_Shipping_Formula::customer_matches_location( $dest, 'US', 'CA', '', 'user_meta', '', 'membership_level', 'gold' ) );
	assert_false( 'loc: user meta no match', WC_Shipping_Formula::customer_matches_location( $dest, 'US', 'CA', '', 'user_meta', '', 'membership_level', 'silver' ) );

	// Reset context
	Formula_Evaluator::set_context( array() );
} else {
	echo " [SKIP] WC_Shipping_Formula not available; location-rule tests skipped.\n";
}

// --- [os] / [browser] / [device] / [is_mobile] placeholders ---
$orig_ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';

// Windows desktop
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
assert_equals( 'placeholder: [os]', 1, eval_formula( 'if([os] == "Windows 10", 1, 0)' ) );
assert_equals( 'placeholder: [browser]', 1, eval_formula( 'if([browser] == "Chrome", 1, 0)' ) );
assert_equals( 'placeholder: [device]', 1, eval_formula( 'if([device] == "desktop", 1, 0)' ) );
assert_equals( 'placeholder: [is_mobile] desktop', 0, eval_formula( '[is_mobile]' ) );

// iPhone
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1';
assert_equals( 'placeholder: [os] iOS', 1, eval_formula( 'if([os] == "iOS 17.1", 1, 0)' ) );
assert_equals( 'placeholder: [device] mobile', 1, eval_formula( 'if([device] == "mobile", 1, 0)' ) );
assert_equals( 'placeholder: [is_mobile] mobile', 1, eval_formula( '[is_mobile]' ) );

// Restore original UA
$_SERVER['HTTP_USER_AGENT'] = $orig_ua;

// ---------------------------------------------------------------
// Report
// ---------------------------------------------------------------

$results = $GLOBALS['__frs_tests']['results'];
$passed  = $GLOBALS['__frs_tests']['passed'];
$failed  = $GLOBALS['__frs_tests']['failed'];

echo "\n";
echo "==========================================\n";
echo " Formula Rate Shipping — Advanced Test Results\n";
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