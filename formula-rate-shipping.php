<?php

/**

 * Plugin Name: Formula Rate Shipping

 * Plugin URI:  https://github.com/google-antigravity/FormulaRateShipping

 * Description: Calculate dynamic shipping and handling rates using custom formulas, priority item rules (Categories, Tags & Attributes), location overrides, and an interactive real-time tester.

 * Version: 0.67.1

 * Author:      RemarkablePC

 * License:     GPL-2.0+

 * Text Domain: formula-rate-shipping

 *

 * @package FormulaRateShipping

 */



if ( ! defined( 'ABSPATH' ) ) {

	exit;

}



// Ensure WooCommerce is loaded before initializing the shipping method.

if ( ! function_exists( 'formula_rate_shipping_check_dependencies' ) ) {

	function formula_rate_shipping_check_dependencies() {

		if ( ! class_exists( 'WC_Shipping_Method' ) ) {

			return;

		}

	}

	add_action( 'plugins_loaded', 'formula_rate_shipping_check_dependencies', 0 );

}



/**

 * Check if WooCommerce is installed and active. If not, display an admin notice.

 */

if ( ! function_exists( 'formula_rate_shipping_woocommerce_missing_notice' ) ) {

	function formula_rate_shipping_woocommerce_missing_notice() {

		if ( is_admin() && current_user_can( 'activate_plugins' ) && ! class_exists( 'WooCommerce' ) && ! class_exists( 'WC_Shipping_Method' ) ) {

			echo '<div class="notice notice-warning is-dismissible"><p>' .

				sprintf(

					/* translators: %s: WooCommerce plugin link */

					__( '<strong>WooCommerce Formula Rate Shipping</strong> requires %s to be installed and active.', 'formula-rate-shipping' ),

					'<a href="https://wordpress.org/plugins/woocommerce/" target="_blank">WooCommerce</a>'

				) .

				'</p></div>';

		}

	}

	add_action( 'admin_notices', 'formula_rate_shipping_woocommerce_missing_notice' );

}



// Auto-USA injection removed - plugin is now region-agnostic

// Users must manually add the shipping method to their desired shipping zones



// ==========================================

// 1. FORMULA EVALUATOR CLASS

// ==========================================



if ( ! class_exists( 'Formula_Evaluator' ) ) {

class Formula_Evaluator {



	/**

	 * Context data for formula evaluation (user info, shipping address, etc.)

	 */

	private static $context_data = null;



	/**

	 * Set context data for formula evaluation.

	 */

	public static function set_context( $context ) {

		self::$context_data = is_array( $context ) ? $context : array();

	}



	/**

	 * Get context data, gathering from WooCommerce if not set.

	 */

	public static function get_context() {

		if ( null === self::$context_data ) {

			self::$context_data = self::gather_context_data();

		}

		return self::$context_data;

	}



	/**

	 * Gather context data from WooCommerce.

	 */

	private static function gather_context_data() {

		$context = array(

			'user'            => array(),

			'shipping'        => array(),

			'cart'            => array(),

		);



		// Gather user data

		if ( function_exists( 'wp_get_current_user' ) ) {

			$current_user = wp_get_current_user();

			if ( $current_user && $current_user->ID > 0 ) {

				$context['user']['id']    = $current_user->ID;

				$context['user']['email'] = $current_user->user_email;

				$context['user']['login'] = $current_user->user_login;

				$context['user']['roles'] = (array) $current_user->roles;

				

				// Check for common user meta / roles

				$context['user']['is_subscriber']   = in_array( 'subscriber', $context['user']['roles'], true ) ? 1 : 0;

				$context['user']['is_customer']     = in_array( 'customer', $context['user']['roles'], true ) ? 1 : 0;

				$context['user']['is_administrator'] = in_array( 'administrator', $context['user']['roles'], true ) ? 1 : 0;

				

				// Check user meta for custom roles/memberships

				$user_meta = get_user_meta( $current_user->ID );

				if ( is_array( $user_meta ) ) {

					foreach ( $user_meta as $meta_key => $meta_val ) {

						if ( is_array( $meta_val ) && count( $meta_val ) === 1 ) {

							$context['user']['meta'][ $meta_key ] = $meta_val[0];

						} else {

							$context['user']['meta'][ $meta_key ] = $meta_val;

						}

					}

				}

			}

		}



		// Gather shipping address data from WooCommerce customer

		if ( function_exists( 'WC' ) && WC() && isset( WC()->customer ) && WC()->customer ) {

			$customer = WC()->customer;

			$context['shipping']['country'] = $customer->get_shipping_country();

			$context['shipping']['state']   = $customer->get_shipping_state();

			$context['shipping']['zip']     = $customer->get_shipping_postcode();

			$context['shipping']['city']    = $customer->get_shipping_city();

		}



		return $context;

	}



	/**

	 * Get the store base country used as the warehouse origin.

	 */

	private static function get_store_base_country() {

		if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) && method_exists( WC()->countries, 'get_base_country' ) ) {

			$base_country = WC()->countries->get_base_country();

			if ( ! empty( $base_country ) ) {

				return strtoupper( $base_country );

			}

		}



		$default_country = get_option( 'woocommerce_default_country', 'US' );

		if ( is_string( $default_country ) && false !== strpos( $default_country, ':' ) ) {

			$default_country = explode( ':', $default_country, 2 )[0];

		}



		return strtoupper( is_string( $default_country ) && '' !== $default_country ? $default_country : 'US' );

	}



	/**

	 * Normalize a country input to a WooCommerce ISO country code when possible.

	 */

	public static function normalize_country_reference( $country_value ) {
		$country_value = trim( (string) $country_value );
		// Strip single or double quotes
		$country_value = preg_replace( '/^[\'"]|[\'"]$/', '', $country_value );
		$country_value = trim( $country_value );

		if ( '' === $country_value ) {
			return '';
		}

		$country_code = strtoupper( $country_value );
		if ( preg_match( '/^[A-Z]{2}$/', $country_code ) ) {
			return $country_code;
		}

		if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) && ! empty( WC()->countries->countries ) && is_array( WC()->countries->countries ) ) {
			foreach ( WC()->countries->countries as $code => $label ) {
				if ( strtolower( trim( (string) $label ) ) === strtolower( $country_value ) ) {
					return strtoupper( $code );
				}
			}
		}

		$common_countries = array(
			'UNITED STATES'            => 'US',
			'UNITED STATES OF AMERICA' => 'US',
			'USA'                      => 'US',
			'CANADA'                   => 'CA',
			'CAN'                      => 'CA',
			'UNITED KINGDOM'           => 'GB',
			'GREAT BRITAIN'            => 'GB',
			'UK'                       => 'GB',
			'MEXICO'                   => 'MX',
			'MEX'                      => 'MX',
			'GERMANY'                  => 'DE',
			'FRANCE'                   => 'FR',
			'AUSTRALIA'                => 'AU',
			'ITALY'                    => 'IT',
			'SPAIN'                    => 'ES',
			'NEW ZEALAND'              => 'NZ',
			'JAPAN'                    => 'JP',
			'CHINA'                    => 'CN',
			'BRAZIL'                   => 'BR',
			'INDIA'                    => 'IN',
		);

		if ( isset( $common_countries[ $country_code ] ) ) {
			return $common_countries[ $country_code ];
		}

		return $country_code;
	}



	/**

	 * Normalize device aliases for rule matching.

	 */

	private static function normalize_device_reference( $value ) {

		$lower_value = strtolower( trim( (string) $value ) );

		if ( '' === $lower_value ) {

			return '';

		}



		$aliases = array(

			'phone'      => 'mobile',

			'cell'       => 'mobile',

			'smartphone' => 'mobile',

			'computer'   => 'desktop',

			'laptop'     => 'desktop',

			'pc'         => 'desktop',

			'ipad'       => 'tablet',

		);



		return isset( $aliases[ $lower_value ] ) ? $aliases[ $lower_value ] : $lower_value;

	}



	/**

	 * Normalize OS aliases so rules can use short names like Win10 or Ventura.

	 */

	private static function normalize_os_reference( $value ) {

		$value = trim( (string) $value );

		if ( '' === $value ) {

			return '';

		}



		$lower_value = strtolower( preg_replace( '/\s+/', ' ', str_replace( '_', ' ', $value ) ) );

		$windows_aliases = array(

			'/^(?:windows\s*11|win\s*11|win11)$/'          => 'Windows 11',

			'/^(?:windows\s*10|win\s*10|win10)$/'          => 'Windows 10',

			'/^(?:windows\s*8\.1|win\s*8\.1|win8\.1)$/' => 'Windows 8.1',

			'/^(?:windows\s*8|win\s*8|win8)$/'             => 'Windows 8',

			'/^(?:windows\s*7|win\s*7|win7)$/'             => 'Windows 7',

			'/^(?:windows\s+vista|vista)$/'                 => 'Windows Vista',

			'/^(?:windows\s+xp|xp)$/'                       => 'Windows XP',

			'/^(?:windows|win)$/'                            => 'Windows',

		);



		foreach ( $windows_aliases as $pattern => $normalized ) {

			if ( preg_match( $pattern, $lower_value ) ) {

				return $normalized;

			}

		}



		$mac_aliases = array(

			'/^(?:mac(?:\s*os)?|os\s*x|macos)\s+sequoia$|^sequoia$/'         => 'MacOS Sequoia',

			'/^(?:mac(?:\s*os)?|os\s*x|macos)\s+sonoma$|^sonoma$/'           => 'MacOS Sonoma',

			'/^(?:mac(?:\s*os)?|os\s*x|macos)\s+ventura$|^ventura$/'         => 'MacOS Ventura',

			'/^(?:mac(?:\s*os)?|os\s*x|macos)\s+monterey$|^monterey$/'       => 'MacOS Monterey',

			'/^(?:mac(?:\s*os)?|os\s*x|macos)\s+big sur$|^big sur$/'         => 'MacOS Big Sur',

			'/^(?:mac(?:\s*os)?|os\s*x|macos)\s+catalina$|^catalina$/'       => 'MacOS Catalina',

			'/^(?:mac(?:\s*os)?|os\s*x|macos)\s+high sierra$|^high sierra$/' => 'MacOS High Sierra',

			'/^(?:mac(?:\s*os)?|os\s*x|macos)$/'                              => 'MacOS',

		);



		foreach ( $mac_aliases as $pattern => $normalized ) {

			if ( preg_match( $pattern, $lower_value ) ) {

				return $normalized;

			}

		}



		if ( preg_match( '/^ios\s+([0-9]+(?:\.[0-9]+)?)$/', $lower_value, $matches ) ) {

			return 'iOS ' . $matches[1];

		}

		if ( 'ios' === $lower_value ) {

			return 'iOS';

		}

		if ( preg_match( '/^android\s+([0-9]+(?:\.[0-9]+)?)$/', $lower_value, $matches ) ) {

			return 'Android ' . $matches[1];

		}

		if ( 'android' === $lower_value ) {

			return 'Android';

		}

		if ( in_array( $lower_value, array( 'chrome os', 'chromeos' ), true ) ) {

			return 'ChromeOS';

		}

		if ( 'linux' === $lower_value ) {

			return 'Linux';

		}



		return ucwords( $value );

	}



	/**

	 * Match device/OS targets against the detected client context.

	 */

	public static function environment_target_matches( $client_value, $rule_value, $environment_type ) {

		$raw_rule = trim( (string) $rule_value );

		if ( '' === $raw_rule ) {

			return true;

		}

		if ( false !== strpos( $raw_rule, ',' ) ) {

			$tokens = array_filter( array_map( 'trim', explode( ',', $raw_rule ) ) );

		} else {

			$tokens = array( $raw_rule );

		}

		foreach ( $tokens as $token ) {

			if ( 'ALL' === strtoupper( trim( (string) $token ) ) ) {

				return true;

			}

		}

		if ( empty( $tokens ) ) {

			return true;

		}



		if ( 'device' === $environment_type ) {

			$normalized_client = self::normalize_device_reference( $client_value );

			foreach ( $tokens as $token ) {

				if ( $normalized_client === self::normalize_device_reference( $token ) ) {

					return true;

				}

			}

			return false;

		}



		$normalized_client = strtolower( self::normalize_os_reference( $client_value ) );

		foreach ( $tokens as $token ) {

			$normalized_target = strtolower( self::normalize_os_reference( $token ) );

			if ( '' === $normalized_target ) {

				continue;

			}

			if ( $normalized_client === $normalized_target ) {

				return true;

			}

			if ( 0 === strpos( $normalized_client, $normalized_target . ' ' ) || 0 === strpos( $normalized_target, $normalized_client . ' ' ) ) {

				return true;

			}

		}



		return false;

	}



	/**

	 * Evaluate a user target against the current user context.

	 */

	public static function evaluate_user_placeholder( $target_value, $match_type = 'auto', $meta_key = '' ) {

		$target_value = trim( (string) $target_value );

		$match_type   = strtolower( trim( (string) $match_type ) );

		$meta_key     = trim( (string) $meta_key );

		$context      = self::get_context();

		$user_data    = isset( $context['user'] ) ? $context['user'] : array();



		if ( 'user' === $match_type ) {

			$match_type = 'user_role';

		}



		if ( '' === $match_type ) {

			$match_type = 'auto';

		}



		switch ( $match_type ) {

			case 'user_role':

			case 'role':

				if ( empty( $user_data['roles'] ) || ! is_array( $user_data['roles'] ) ) {

					return false;

				}

				foreach ( $user_data['roles'] as $role ) {

					if ( strtolower( trim( (string) $role ) ) === strtolower( $target_value ) ) {

						return true;

					}

				}

				return false;



			case 'user_email':

			case 'email':

				return ! empty( $user_data['email'] ) && strtolower( trim( (string) $user_data['email'] ) ) === strtolower( $target_value );



			case 'user_login':

			case 'login':

				return ! empty( $user_data['login'] ) && strtolower( trim( (string) $user_data['login'] ) ) === strtolower( $target_value );



			case 'user_meta':

			case 'meta':

				if ( '' === $meta_key || empty( $user_data['meta'] ) || ! array_key_exists( $meta_key, $user_data['meta'] ) ) {

					return false;

				}

				return self::user_meta_matches( $user_data['meta'][ $meta_key ], $target_value );



			case 'auto':

			default:

				return self::evaluate_user_placeholder( $target_value, 'user_role' )

					|| self::evaluate_user_placeholder( $target_value, 'user_email' )

					|| self::evaluate_user_placeholder( $target_value, 'user_login' );

		}

	}



	/**

	 * Compare a stored user meta value against the expected value.

	 */

	private static function user_meta_matches( $stored_value, $expected_value ) {

		if ( is_array( $stored_value ) ) {

			foreach ( $stored_value as $value ) {

				if ( self::user_meta_matches( $value, $expected_value ) ) {

					return true;

				}

			}

			return false;

		}



		if ( is_object( $stored_value ) ) {

			$stored_value = wp_json_encode( $stored_value );

		}



		$stored_string   = strtolower( trim( (string) $stored_value ) );

		$expected_string = strtolower( trim( (string) $expected_value ) );



		if ( '' === $expected_string ) {

			return '' !== $stored_string;

		}



		return $stored_string === $expected_string;

	}



	/**

	 * Detect client context (OS, browser, device, is_mobile).

	 */

	public static function detect_client_context() {

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ) : '';



		$os = 'Unknown';

		if ( preg_match( '/iPhone|iPad|iPod/i', $user_agent ) ) {

			$os = self::simplify_os_name( 'iOS', $user_agent );

		} elseif ( preg_match( '/Android/i', $user_agent ) ) {

			$os = self::simplify_os_name( 'Android', $user_agent );

		} elseif ( preg_match( '/CrOS/i', $user_agent ) ) {

			$os = 'ChromeOS';

		} elseif ( preg_match( '/Linux/i', $user_agent ) ) {

			$os = self::simplify_os_name( 'Linux', $user_agent );

		} elseif ( preg_match( '/Macintosh|Mac OS X/i', $user_agent ) ) {

			$os = self::simplify_os_name( 'MacOS', $user_agent );

		} elseif ( preg_match( '/Windows/i', $user_agent ) ) {

			$os = self::simplify_os_name( 'Windows', $user_agent );

		}



		$browser = 'Unknown';

		if ( preg_match( '/Edg\/|EdgA\/|Edge\//i', $user_agent ) ) {

			$browser = 'Edge';

		} elseif ( preg_match( '/OPR\/|Opera\//i', $user_agent ) ) {

			$browser = 'Opera';

		} elseif ( preg_match( '/Brave/i', $user_agent ) ) {

			$browser = 'Brave';

		} elseif ( preg_match( '/Vivaldi/i', $user_agent ) ) {

			$browser = 'Vivaldi';

		} elseif ( preg_match( '/Waterfox/i', $user_agent ) ) {

			$browser = 'Waterfox';

		} elseif ( preg_match( '/LibreWolf/i', $user_agent ) ) {

			$browser = 'LibreWolf';

		} elseif ( preg_match( '/Floorp/i', $user_agent ) ) {

			$browser = 'Floorp';

		} elseif ( preg_match( '/Arc\//i', $user_agent ) ) {

			$browser = 'Arc';

		} elseif ( preg_match( '/Firefox/i', $user_agent ) ) {

			$browser = 'Firefox';

		} elseif ( preg_match( '/Chrome/i', $user_agent ) ) {

			$browser = 'Chrome';

		} elseif ( preg_match( '/Safari/i', $user_agent ) ) {

			$browser = 'Safari';

		}



		$is_tablet = (bool) preg_match( '/iPad|Tablet|Nexus 7|Nexus 10|SM-T|Kindle|Silk/i', $user_agent );

		if ( ! $is_tablet && preg_match( '/Android/i', $user_agent ) && ! preg_match( '/Mobile/i', $user_agent ) ) {

			$is_tablet = true;

		}

		$is_mobile = function_exists( 'wp_is_mobile' ) && wp_is_mobile() ? 1 : ( ( 'iOS' === $os || 'Android' === $os || $is_tablet ) ? 1 : 0 );

		$device    = $is_tablet ? 'tablet' : ( ( 'iOS' === $os || 'Android' === $os ) ? 'mobile' : ( $is_mobile ? 'mobile' : 'desktop' ) );



		return array(

			'os'        => $os,

			'browser'   => $browser,

			'device'    => $device,

			'is_mobile' => $is_mobile,

		);

	}



	/**

	 * Simplify raw OS names into user-friendly versioned labels.

	 * Handles exotic user-agent strings and maps them to common names.

	 */

	private static function simplify_os_name( $os_base, $user_agent ) {

		$os_base = strtolower( trim( $os_base ) );



		if ( 'windows' === $os_base ) {

			if ( preg_match( '/Windows NT 10\.0/i', $user_agent ) ) {

				$build = '';

				if ( preg_match( '/Windows NT 10\.0(?:; Win64; x64)?(?:; Build (\d+))?/i', $user_agent, $m ) && ! empty( $m[1] ) ) {

					$build = ' ' . $m[1];

				}

				// Windows 11 reports NT 10.0 with a build >= 22000.

				if ( preg_match( '/Windows NT 10\.0.*?Build (22\d{4})/i', $user_agent, $m ) || preg_match( '/Windows NT 10\.0.*?(22H2|23H2|24H2)/i', $user_agent ) ) {

					return 'Windows 11';

				}

				return 'Windows 10' . $build;

			} elseif ( preg_match( '/Windows NT 6\.3/i', $user_agent ) ) {

				return 'Windows 8.1';

			} elseif ( preg_match( '/Windows NT 6\.2/i', $user_agent ) ) {

				return 'Windows 8';

			} elseif ( preg_match( '/Windows NT 6\.1/i', $user_agent ) ) {

				return 'Windows 7';

			} elseif ( preg_match( '/Windows NT 6\.0/i', $user_agent ) ) {

				return 'Windows Vista';

			} elseif ( preg_match( '/Windows NT 5\.1/i', $user_agent ) ) {

				return 'Windows XP';

			}

			return 'Windows';

		}



		if ( 'macos' === $os_base ) {

			if ( preg_match( '/Mac OS X 10_15|Mac OS X 10\.15/i', $user_agent ) ) {

				return 'MacOS Catalina';

			} elseif ( preg_match( '/Mac OS X 11_|Mac OS X 11\./i', $user_agent ) ) {

				return 'MacOS Big Sur';

			} elseif ( preg_match( '/Mac OS X 12_|Mac OS X 12\./i', $user_agent ) ) {

				return 'MacOS Monterey';

			} elseif ( preg_match( '/Mac OS X 13_|Mac OS X 13\./i', $user_agent ) ) {

				return 'MacOS Ventura';

			} elseif ( preg_match( '/Mac OS X 14_|Mac OS X 14\./i', $user_agent ) ) {

				return 'MacOS Sonoma';

			} elseif ( preg_match( '/Mac OS X 15_|Mac OS X 15\./i', $user_agent ) ) {

				return 'MacOS Sequoia';

			} elseif ( preg_match( '/Mac OS X 10_(\d+)/i', $user_agent, $m ) ) {

				$minor = (int) $m[1];

				if ( $minor >= 13 ) {

					return 'MacOS High Sierra';

				} elseif ( $minor >= 12 ) {

					return 'MacOS Sierra';

				} elseif ( $minor >= 11 ) {

					return 'MacOS El Capitan';

				} elseif ( $minor >= 10 ) {

					return 'MacOS Yosemite';

				} elseif ( $minor >= 9 ) {

					return 'MacOS Mavericks';

				}

				return 'MacOS';

			}

			return 'MacOS';

		}



		if ( 'android' === $os_base ) {

			if ( preg_match( '/Android (\d+(?:\.\d+)?)/i', $user_agent, $m ) ) {

				return 'Android ' . $m[1];

			}

			return 'Android';

		}



		if ( 'ios' === $os_base ) {

			if ( preg_match( '/OS (\d+)_(\d+)/i', $user_agent, $m ) ) {

				return 'iOS ' . $m[1] . '.' . $m[2];

			}

			return 'iOS';

		}



		if ( 'linux' === $os_base ) {

			// Detect common Linux distros from UA hints (snap packages, custom UAs, etc.)
			if ( preg_match( '/Linux Mint/i', $user_agent ) ) {
				return 'Linux Mint';
			} elseif ( preg_match( '/Ubuntu/i', $user_agent ) ) {
				return 'Ubuntu';
			} elseif ( preg_match( '/Fedora/i', $user_agent ) ) {
				return 'Fedora';
			} elseif ( preg_match( '/Arch Linux/i', $user_agent ) ) {
				return 'Arch Linux';
			} elseif ( preg_match( '/Pop!_OS|Pop_OS/i', $user_agent ) ) {
				return 'Pop!_OS';
			} elseif ( preg_match( '/Manjaro/i', $user_agent ) ) {
				return 'Manjaro';
			} elseif ( preg_match( '/Debian/i', $user_agent ) ) {
				return 'Debian';
			} elseif ( preg_match( '/openSUSE|OpenSUSE/i', $user_agent ) ) {
				return 'openSUSE';
			} elseif ( preg_match( '/CentOS/i', $user_agent ) ) {
				return 'CentOS';
			}
			return 'Linux';

		}



		return ucfirst( $os_base );

	}



	/**

	 * Resolve country for holiday queries.

	 */

	private static function resolve_holiday_country( $country_arg, $context ) {

		if ( 'CUSTOMER_BILLING' === $country_arg || 'BILLING' === $country_arg ) {

			if ( function_exists( 'WC' ) && WC() && isset( WC()->customer ) && method_exists( WC()->customer, 'get_billing_country' ) ) {

				$billing = WC()->customer->get_billing_country();

				if ( ! empty( $billing ) ) {

					return strtoupper( $billing );

				}

			}

			return isset( $context['shipping']['country'] ) ? $context['shipping']['country'] : 'US';

		} elseif ( 'CUSTOMER' === $country_arg || 'CUSTOMER_SHIPPING' === $country_arg ) {

			return isset( $context['shipping']['country'] ) ? $context['shipping']['country'] : 'US';

		} elseif ( 'STORE' === $country_arg || empty( $country_arg ) ) {

			$option = get_option( 'woocommerce_formula_rate_shipping_settings', array() );

			$setting_country = isset( $option['holiday_country'] ) ? $option['holiday_country'] : 'store';

			if ( 'customer_billing' === $setting_country ) {

				return self::resolve_holiday_country( 'CUSTOMER_BILLING', $context );

			} elseif ( 'customer' === $setting_country || 'customer_shipping' === $setting_country ) {

				return isset( $context['shipping']['country'] ) ? $context['shipping']['country'] : 'US';

			} elseif ( 'store' === $setting_country || empty( $setting_country ) ) {

				return function_exists( 'WC' ) && WC() && isset( WC()->countries ) ? WC()->countries->get_base_country() : 'US';

			} else {

				return strtoupper( $setting_country );

			}

		}

		return $country_arg;

	}



	/**

	 * Fetch algorithmic and public API bank holidays.

	 */

	public static function get_holidays( $country_code = '', $target_timestamp = null ) {

		if ( null === $target_timestamp ) {

			$target_timestamp = current_time( 'timestamp' );

		}



		$year      = date( 'Y', $target_timestamp );

		$month_day = date( 'm-d', $target_timestamp );

		$full_date = date( 'Y-m-d', $target_timestamp );



		$holidays = array();



		$fixed = array(

			'01-01' => 'New Year',

			'02-14' => 'Valentines Day',

			'07-04' => 'July 4th',

			'10-31' => 'Halloween',

			'11-11' => 'Veterans Day',

			'12-24' => 'Christmas Eve',

			'12-25' => 'Christmas',

			'12-31' => 'New Years Eve',

		);

		if ( isset( $fixed[ $month_day ] ) ) {

			$holidays[] = $fixed[ $month_day ];

		}



		$thanksgiving = date( 'Y-m-d', strtotime( "fourth thursday of november {$year}" ) );

		$black_friday = date( 'Y-m-d', strtotime( "{$thanksgiving} +1 day" ) );

		$cyber_monday = date( 'Y-m-d', strtotime( "{$thanksgiving} +4 days" ) );

		$memorial_day = date( 'Y-m-d', strtotime( "last monday of may {$year}" ) );

		$labor_day    = date( 'Y-m-d', strtotime( "first monday of september {$year}" ) );



		if ( $full_date === $thanksgiving ) { $holidays[] = 'Thanksgiving'; }

		if ( $full_date === $black_friday ) { $holidays[] = 'Black Friday'; }

		if ( $full_date === $cyber_monday ) { $holidays[] = 'Cyber Monday'; }

		if ( $full_date === $memorial_day ) { $holidays[] = 'Memorial Day'; }

		if ( $full_date === $labor_day )    { $holidays[] = 'Labor Day'; }



		if ( function_exists( 'easter_date' ) ) {

			$easter_ts = @easter_date( (int) $year );

			if ( $easter_ts ) {

				$easter_date = date( 'Y-m-d', $easter_ts );

				$good_friday = date( 'Y-m-d', strtotime( '-2 days', $easter_ts ) );

				if ( $full_date === $easter_date ) { $holidays[] = 'Easter'; }

				if ( $full_date === $good_friday ) { $holidays[] = 'Good Friday'; }

			}

		}



		if ( ! empty( $country_code ) && strlen( $country_code ) === 2 ) {
			$country_code  = strtoupper( $country_code );
			$transient_key = "formula_holidays_{$country_code}_{$year}";
			$api_holidays  = get_transient( $transient_key );

			if ( false === $api_holidays ) {
				$api_url  = "https://date.nager.at/api/v3/PublicHolidays/{$year}/{$country_code}";
				$response = wp_remote_get( $api_url, array( 'timeout' => 3 ) );
				if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
					$body = wp_remote_retrieve_body( $response );
					$data = json_decode( $body, true );
					$api_holidays = array();
					if ( is_array( $data ) ) {
						foreach ( $data as $item ) {
							if ( isset( $item['date'] ) && isset( $item['name'] ) ) {
								$api_holidays[ $item['date'] ] = $item['name'];
							}
						}
					}
					set_transient( $transient_key, $api_holidays, 30 * DAY_IN_SECONDS );
				} else {
					set_transient( $transient_key, array(), 1 * DAY_IN_SECONDS );
				}
			}

			if ( is_array( $api_holidays ) && isset( $api_holidays[ $full_date ] ) ) {
				$holidays[] = $api_holidays[ $full_date ];
			}
		}

		// Check Custom iCal / Google / Apple Calendar URL if configured
		$holiday_provider = get_option( 'formula_shipping_holiday_provider', 'built_in' );
		if ( 'custom_ical' === $holiday_provider ) {
			$ical_url = get_option( 'formula_shipping_custom_ical_url', '' );
			$ical_filter = get_option( 'formula_shipping_custom_ical_filter', 'all_events' );
			if ( ! empty( $ical_url ) ) {
				$custom_holidays = self::fetch_custom_ical_holidays( $ical_url, $target_timestamp, $ical_filter );
				if ( ! empty( $custom_holidays ) ) {
					$holidays = array_merge( $holidays, $custom_holidays );
				}
			}
		}

		return array_unique( $holidays );
	}

	public static function fetch_custom_ical_holidays( $url, $target_timestamp, $filter_mode = 'all_events' ) {
		if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return array();
		}

		$cache_key = 'formula_ical_' . md5( $url );
		$ical_data = get_transient( $cache_key );

		if ( false === $ical_data ) {
			$response = wp_remote_get( $url, array( 'timeout' => 8, 'sslverify' => false ) );
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				return array();
			}
			$ical_data = wp_remote_retrieve_body( $response );
			set_transient( $cache_key, $ical_data, 6 * HOUR_IN_SECONDS );
		}

		if ( empty( $ical_data ) ) {
			return array();
		}

		$target_date = date( 'Ymd', $target_timestamp );
		$holidays    = array();

		preg_match_all( '/BEGIN:VEVENT(.*?)END:VEVENT/s', $ical_data, $events );
		if ( ! empty( $events[1] ) ) {
			foreach ( $events[1] as $event_block ) {
				$dtstart = '';
				$summary = '';
				if ( preg_match( '/DTSTART;?[^:]*:([0-9]{8})/i', $event_block, $m ) ) {
					$dtstart = $m[1];
				}
				if ( preg_match( '/SUMMARY:(.*?)[\r\n]/i', $event_block, $m ) ) {
					$summary = trim( $m[1] );
				}

				if ( $dtstart === $target_date && '' !== $summary ) {
					if ( 'prefix_holiday' === $filter_mode ) {
						if ( preg_match( '/^Holiday:\s*(.+)$/i', $summary, $sm ) ) {
							$holidays[] = trim( $sm[1] );
						}
					} else {
						$holidays[] = $summary;
					}
				}
			}
		}

		return array_unique( $holidays );
	}



	/**

	 * Resolve context-aware variables in the formula.

	 */

	public static function resolve_context_variables( $formula, $contents ) {

		$context = self::get_context();



		// Environmental / Client Context Placeholders

		$client = self::detect_client_context();



		$formula = preg_replace_callback( '/\[os\]/i', function() use ( $client ) {

			return '"' . $client['os'] . '"';

		}, $formula );



		$formula = preg_replace_callback( '/\[browser\]/i', function() use ( $client ) {

			return '"' . $client['browser'] . '"';

		}, $formula );



		$formula = preg_replace_callback( '/\[device\]/i', function() use ( $client ) {

			return '"' . $client['device'] . '"';

		}, $formula );



		$formula = preg_replace_callback( '/\[is_mobile\]/i', function() use ( $client ) {

			return $client['is_mobile'] ? '1' : '0';

		}, $formula );



		// Temporal / Time Placeholders

		$now_ts = current_time( 'timestamp' );



		$formula = preg_replace_callback( '/\[hour\]/i', function() use ( $now_ts ) {

			return sprintf( '%d', date( 'G', $now_ts ) );

		}, $formula );



		$formula = preg_replace_callback( '/\[day\]/i', function() use ( $now_ts ) {

			return '"' . date( 'l', $now_ts ) . '"';

		}, $formula );



		$formula = preg_replace_callback( '/\[month\]/i', function() use ( $now_ts ) {

			return sprintf( '%d', date( 'n', $now_ts ) );

		}, $formula );



		$formula = preg_replace_callback( '/\[is_weekend\]/i', function() use ( $now_ts ) {

			$day_num = date( 'N', $now_ts );

			return ( 6 == $day_num || 7 == $day_num ) ? '1' : '0';

		}, $formula );



		// [is_date="MM-DD"] or [is_date="YYYY-MM-DD"]

		$formula = preg_replace_callback( '/\[is_date\s*=\s*[\'"]([^\'"]+)[\'"]\]/i', function( $matches ) use ( $now_ts ) {

			$target = trim( $matches[1] );

			if ( strlen( $target ) === 5 ) {

				return date( 'm-d', $now_ts ) === $target ? '1' : '0';

			} elseif ( strlen( $target ) === 10 ) {

				return date( 'Y-m-d', $now_ts ) === $target ? '1' : '0';

			}

			return '0';

		}, $formula );



		// [is_date_range="MM-DD to MM-DD"]

		$formula = preg_replace_callback( '/\[is_date_range\s*=\s*[\'"]([^\'"]+)[\'"]\]/i', function( $matches ) use ( $now_ts ) {

			$range_str = trim( $matches[1] );

			$parts = preg_split( '/\s+to\s+/i', $range_str );

			if ( count( $parts ) === 2 ) {

				$start = trim( $parts[0] );

				$end   = trim( $parts[1] );

				$current_md = date( 'm-d', $now_ts );

				if ( $start <= $end ) {

					return ( $current_md >= $start && $current_md <= $end ) ? '1' : '0';

				} else {

					return ( $current_md >= $start || $current_md <= $end ) ? '1' : '0';

				}

			}

			return '0';

		}, $formula );



		// [is_holiday] or [is_holiday="Christmas"] or [is_holiday country="US"]

		$formula = preg_replace_callback( '/\[is_holiday(?:\s*=\s*[\'"]([^\'"]+)[\'"])?(?:\s+country\s*=\s*[\'"]([^\'"]+)[\'"])?\]/i', function( $matches ) use ( $now_ts, $context ) {

			$target_name = ! empty( $matches[1] ) ? strtolower( trim( $matches[1] ) ) : '';

			$country_arg = ! empty( $matches[2] ) ? strtoupper( trim( $matches[2] ) ) : '';



			$country = self::resolve_holiday_country( $country_arg, $context );

			$holidays = self::get_holidays( $country, $now_ts );



			if ( empty( $holidays ) ) {

				return '0';

			}



			if ( empty( $target_name ) ) {

				return count( $holidays ) > 0 ? '1' : '0';

			}



			foreach ( $holidays as $h ) {

				if ( strtolower( trim( $h ) ) === $target_name || strpos( strtolower( trim( $h ) ), $target_name ) !== false ) {

					return '1';

				}

			}

			return '0';

		}, $formula );



		// [holiday]

		$formula = preg_replace_callback( '/\[holiday(?:\s+country\s*=\s*[\'"]([^\'"]+)[\'"])?\]/i', function( $matches ) use ( $now_ts, $context ) {

			$country_arg = ! empty( $matches[1] ) ? strtoupper( trim( $matches[1] ) ) : '';

			$country = self::resolve_holiday_country( $country_arg, $context );

			$holidays = self::get_holidays( $country, $now_ts );

			return ! empty( $holidays ) ? '"' . reset( $holidays ) . '"' : '""';

		}, $formula );



		// [message="..."] (no icon), [notice="..."] (info icon), [warning="..."] (warning icon)
		$formula = preg_replace_callback( '/\[(message|notice|warning)\s*=\s*[\'"]([^\'"]+)[\'"](?:\s+position\s*=\s*[\'"]([^\'"]+)[\'"])?\]/i', function( $matches ) {
			if ( isset( $GLOBALS['formula_shipping_current_notices'] ) && is_array( $GLOBALS['formula_shipping_current_notices'] ) ) {
				$pos = ! empty( $matches[3] ) ? strtolower( trim( $matches[3] ) ) : 'cart_table';
				$GLOBALS['formula_shipping_current_notices'][] = array(
					'type'     => strtolower( $matches[1] ),
					'message'  => trim( $matches[2] ),
					'position' => $pos,
				);
			}
			return '0';
		}, $formula );

		// [fee ...], [discount ...], [sale ...] - Dynamic Order Total Line Items
		$formula = preg_replace_callback( '/\[(fee|discount|sale)([^\]]*)\]/i', function( $matches ) use ( $contents ) {
			$type_tag = strtolower( $matches[1] );
			$attr_str = $matches[2];

			$label = '';
			if ( preg_match( '/label\s*=\s*[\'"]([^\'"]+)[\'"]/i', $attr_str, $m ) ) {
				$label = trim( $m[1] );
			} elseif ( 'fee' === $type_tag ) {
				$label = 'Surcharge';
			} elseif ( 'sale' === $type_tag ) {
				$label = 'Sale Discount';
			} else {
				$label = 'Shipping Credit';
			}

			$icon = '';
			if ( preg_match( '/icon\s*=\s*[\'"]([^\'"]+)[\'"]/i', $attr_str, $m ) ) {
				$icon = strtolower( trim( $m[1] ) );
			}

			$fee_amount = 0.0;
			if ( preg_match( '/amount\s*=\s*([0-9\.]+)/i', $attr_str, $m ) ) {
				$fee_amount = floatval( $m[1] );
			} elseif ( preg_match( '/percent\s*=\s*([0-9\.]+)/i', $attr_str, $m ) ) {
				$pct = floatval( $m[1] );
				$subtotal = 0.0;
				foreach ( $contents as $item ) {
					$subtotal += floatval( isset( $item['line_total'] ) ? $item['line_total'] : ( isset( $item['price'] ) ? $item['price'] * ( isset( $item['qty'] ) ? $item['qty'] : 1 ) : 0 ) );
				}
				$fee_amount = ( $subtotal * $pct ) / 100.0;
			} elseif ( preg_match( '/formula\s*=\s*[\'"]([^\'"]+)[\'"]/i', $attr_str, $m ) ) {
				try {
					$fee_amount = floatval( self::evaluate( $m[1], $contents ) );
				} catch ( Exception $e ) {
					$fee_amount = 0.0;
				}
			}

			if ( 'discount' === $type_tag || 'sale' === $type_tag ) {
				$fee_amount = -abs( $fee_amount );
			}

			if ( isset( $GLOBALS['formula_shipping_current_fees'] ) && is_array( $GLOBALS['formula_shipping_current_fees'] ) ) {
				$GLOBALS['formula_shipping_current_fees'][] = array(
					'type'   => $type_tag,
					'label'  => $label,
					'amount' => $fee_amount,
					'icon'   => $icon,
				);
			}

			return '0';
		}, $formula );



		// [on_sale] or [on_sale category="X"]

		$formula = preg_replace_callback( '/\[on_sale(?:\s+(category|tag|variation)\s*=\s*[\'"]([^\'"]+)[\'"])?\]/i', function( $matches ) use ( $contents ) {

			$target_type = ! empty( $matches[1] ) ? strtolower( $matches[1] ) : '';

			$target_val  = ! empty( $matches[2] ) ? strtolower( trim( $matches[2] ) ) : '';



			foreach ( $contents as $item ) {

				if ( self::item_matches_target( $item, $target_type, $target_val ) ) {

					$product = isset( $item['data'] ) && is_a( $item['data'], 'WC_Product' ) ? $item['data'] : null;

					if ( $product && method_exists( $product, 'is_on_sale' ) && $product->is_on_sale() ) {

						return '1';

					}

				}

			}

			return '0';

		}, $formula );



		// Resolve [user="X"] - checks user role, email, or login

		$formula = preg_replace_callback(

			'/\[user\s*=\s*[\'"]([^\'"]+)[\'"]\]/i',

			function( $matches ) {

				return self::evaluate_user_placeholder( $matches[1], 'auto' ) ? '1' : '0';

			},

			$formula

		);



		// Resolve [user_role] - returns the primary user role as a string value

		$formula = preg_replace_callback(

			'/\[user_role\]/i',

			function() use ( $context ) {

				$user_data = isset( $context['user'] ) ? $context['user'] : array();

				if ( ! empty( $user_data['roles'] ) && is_array( $user_data['roles'] ) ) {

					return reset( $user_data['roles'] );

				}

				return 'guest';

			},

			$formula

		);

		

		// Resolve [category="X"] - returns 1 if cart contains items in category, 0 otherwise

		$formula = preg_replace_callback(

			'/\[category\s*=\s*[\'"]([^\'"]+)[\'"]\]/i',

			function( $matches ) use ( $contents ) {

				$target_val = strtolower( trim( $matches[1] ) );

				foreach ( $contents as $item ) {

					if ( self::item_matches_target( $item, 'category', $target_val ) ) {

						return '1';

					}

				}

				return '0';

			},

			$formula

		);

		

		// Resolve [tag="X"] - returns 1 if cart contains items with tag, 0 otherwise

		$formula = preg_replace_callback(

			'/\[tag\s*=\s*[\'"]([^\'"]+)[\'"]\]/i',

			function( $matches ) use ( $contents ) {

				$target_val = strtolower( trim( $matches[1] ) );

				foreach ( $contents as $item ) {

					if ( self::item_matches_target( $item, 'tag', $target_val ) ) {

						return '1';

					}

				}

				return '0';

			},

			$formula

		);

		

		// Resolve [variation="X"] - returns 1 if cart contains variation, 0 otherwise

		$formula = preg_replace_callback(

			'/\[variation\s*=\s*[\'"]([^\'"]+)[\'"]\]/i',

			function( $matches ) use ( $contents ) {

				$target_val = strtolower( trim( $matches[1] ) );

				foreach ( $contents as $item ) {

					if ( self::item_matches_target( $item, 'variation', $target_val ) ) {

						return '1';

					}

				}

				return '0';

			},

			$formula

		);

		

		// Resolve [product="X"] - returns 1 if cart contains item whose name, SKU, or product ID contains X, 0 otherwise

		$formula = preg_replace_callback(

			'/\[product\s*=\s*[\'"]([^\'"]+)[\'"]\]/i',

			function( $matches ) use ( $contents ) {

				$target_val = strtolower( trim( $matches[1] ) );

				foreach ( $contents as $item ) {

					if ( self::item_matches_target( $item, 'product', $target_val ) ) {

						return '1';

					}

				}

				return '0';

			},

			$formula

		);

		

		// Resolve [shipping_country]

		$formula = preg_replace_callback(

			'/\[shipping_country\]/i',

			function() use ( $context ) {

				return isset( $context['shipping']['country'] ) ? $context['shipping']['country'] : 'US';

			},

			$formula

		);



		// Resolve [is_international]

		$formula = preg_replace_callback(

			'/\[is_international(?:\s*=\s*[\'"]([^\'"]+)[\'"])?\]/i',

			function( $matches ) use ( $context ) {

				$shipping_country = isset( $context['shipping']['country'] ) ? self::normalize_country_reference( $context['shipping']['country'] ) : '';

				$warehouse_country = ! empty( $matches[1] ) ? self::normalize_country_reference( $matches[1] ) : self::get_store_base_country();

				if ( '' === $shipping_country ) {

					return '0';

				}

				return $shipping_country !== $warehouse_country ? '1' : '0';

			},

			$formula

		);

		

		// Resolve [shipping_state]

		$formula = preg_replace_callback(

			'/\[shipping_state\]/i',

			function() use ( $context ) {

				return isset( $context['shipping']['state'] ) ? $context['shipping']['state'] : '';

			},

			$formula

		);

		

		// Resolve [shipping_zip]

		$formula = preg_replace_callback(

			'/\[shipping_zip\]/i',

			function() use ( $context ) {

				return isset( $context['shipping']['zip'] ) ? $context['shipping']['zip'] : '';

			},

			$formula

		);

		

		// Resolve [shipping_city]

		$formula = preg_replace_callback(

			'/\[shipping_city\]/i',

			function() use ( $context ) {

				return isset( $context['shipping']['city'] ) ? $context['shipping']['city'] : '';

			},

			$formula

		);

		

		return $formula;

	}

	

	/**

	 * Resolve logical operators: IN, BETWEEN, CONTAINS

	 * These are syntactic sugar that expand to existing operators before Shunting-yard evaluation.

	 * 

	 * IN: value IN (A, B, C) → value == A OR value == B OR value == C

	 * BETWEEN: value BETWEEN low AND high → value >= low AND value <= high

	 * CONTAINS: value CONTAINS "text" → 1 or 0 (substring check)

	 */

	public static function resolve_logical_operators( $formula ) {

		$max_iterations = 50;

		$iteration = 0;

		

		// Resolve IN operator: value IN (A, B, C)

		// Match patterns like: [shipping_state] IN ("CA", "OR", "WA") or 5 IN (1, 2, 3)

		while ( preg_match( '/([^\s(]+)\s+IN\s*\(([^)]+)\)/i', $formula, $in_match ) && $iteration < $max_iterations ) {

			$iteration++;

			$left_value = trim( $in_match[1] );

			$right_list = trim( $in_match[2] );

			

			// Split the list by commas

			$values = array_map( 'trim', explode( ',', $right_list ) );

			

			// Build OR chain: left == val1 OR left == val2 OR ...

			$or_parts = array();

			foreach ( $values as $val ) {

				$or_parts[] = $left_value . ' == ' . $val;

			}

			$replacement = implode( ' OR ', $or_parts );

			

			$formula = str_replace( $in_match[0], $replacement, $formula );

		}

		

		// Resolve BETWEEN operator: value BETWEEN low AND high

		// Match patterns like: [weight] BETWEEN 5 AND 50

		$iteration = 0;

		while ( preg_match( '/([^\s(]+)\s+BETWEEN\s+([^\s]+)\s+AND\s+([^\s)]+)/i', $formula, $between_match ) && $iteration < $max_iterations ) {

			$iteration++;

			$value = trim( $between_match[1] );

			$low = trim( $between_match[2] );

			$high = trim( $between_match[3] );

			

			// Replace with: value >= low AND value <= high

			$replacement = $value . ' >= ' . $low . ' AND ' . $value . ' <= ' . $high;

			

			$formula = str_replace( $between_match[0], $replacement, $formula );

		}

		

		// Resolve CONTAINS operator: value CONTAINS "text"

		// Match patterns like: [shipping_city] CONTAINS "Los" or [user_role] CONTAINS "admin"

		$iteration = 0;

		while ( preg_match( '/([^\s(]+)\s+CONTAINS\s+[\'"]([^\'"]+)[\'"]/i', $formula, $contains_match ) && $iteration < $max_iterations ) {

			$iteration++;

			$haystack = trim( $contains_match[1] );

			$needle = trim( $contains_match[2] );

			

			// For CONTAINS, we need to evaluate it at runtime since it's a string operation

			// We'll replace it with a special marker that gets evaluated later

			// For now, we'll use a simple approach: if both sides are literals, evaluate immediately

			// Otherwise, we need to defer evaluation

			

			// Check if haystack is a resolved value (number or quoted string)

			$haystack_val = self::evaluate_simple( $haystack );

			$haystack_str = strtolower( strval( $haystack_val ) );

			$needle_lower = strtolower( $needle );

			

			$result = ( strpos( $haystack_str, $needle_lower ) !== false ) ? '1' : '0';

			

			$formula = str_replace( $contains_match[0], $result, $formula );

		}

		

		return $formula;

	}

	

	/**

	 * Process logical operators (AND, OR, NOT, !) in a condition expression.

	 * Returns 1 or 0 based on the evaluation.

	 */

	public static function evaluate_logical_expression( $expr ) {

		$expr = trim( $expr );

		if ( '' === $expr ) {

			return 0;

		}

		

		// Handle parentheses

		if ( preg_match( '/^\((.+)\)$/', $expr, $m ) ) {

			return self::evaluate_logical_expression( $m[1] );

		}

		

		// Handle OR (lowest precedence)

		$parts = preg_split( '/\s+OR\s+/i', $expr );

		if ( count( $parts ) > 1 ) {

			foreach ( $parts as $part ) {

				if ( self::evaluate_logical_expression( trim( $part ) ) ) {

					return 1;

				}

			}

			return 0;

		}

		

		// Handle AND

		$parts = preg_split( '/\s+AND\s+/i', $expr );

		if ( count( $parts ) > 1 ) {

			foreach ( $parts as $part ) {

				if ( ! self::evaluate_logical_expression( trim( $part ) ) ) {

					return 0;

				}

			}

			return 1;

		}

		

		// Handle NOT prefix

		if ( preg_match( '/^NOT\s+(.+)$/i', $expr, $m ) ) {

			return self::evaluate_logical_expression( trim( $m[1] ) ) ? 0 : 1;

		}

		

		// Handle ! prefix

		if ( preg_match( '/^!(.+)$/', $expr, $m ) ) {

			return self::evaluate_logical_expression( trim( $m[1] ) ) ? 0 : 1;

		}

		

		// Handle comparison operators

		if ( preg_match( '/^(.+?)\s*(>=|<=|==|!=|>|<)\s*(.+)$/', $expr, $m ) ) {

			$left  = self::strip_quotes( trim( $m[1] ) );

			$op    = $m[2];

			$right = self::strip_quotes( trim( $m[3] ) );

			

			// Try numeric comparison first

			$left_num  = is_numeric( $left ) ? floatval( $left ) : $left;

			$right_num = is_numeric( $right ) ? floatval( $right ) : $right;

			

			if ( is_numeric( $left_num ) && is_numeric( $right_num ) ) {

				switch ( $op ) {

					case '>':  return ( $left_num > $right_num ) ? 1 : 0;

					case '<':  return ( $left_num < $right_num ) ? 1 : 0;

					case '>=': return ( $left_num >= $right_num ) ? 1 : 0;

					case '<=': return ( $left_num <= $right_num ) ? 1 : 0;

					case '==': return ( $left_num == $right_num ) ? 1 : 0;

					case '!=': return ( $left_num != $right_num ) ? 1 : 0;

				}

			}

			

			// String comparison

			$left_str  = strval( $left );

			$right_str = strval( $right );

			switch ( $op ) {

				case '==': return ( $left_str === $right_str ) ? 1 : 0;

				case '!=': return ( $left_str !== $right_str ) ? 1 : 0;

				case '>':  return strcmp( $left_str, $right_str ) > 0 ? 1 : 0;

				case '<':  return strcmp( $left_str, $right_str ) < 0 ? 1 : 0;

				case '>=': return strcmp( $left_str, $right_str ) >= 0 ? 1 : 0;

				case '<=': return strcmp( $left_str, $right_str ) <= 0 ? 1 : 0;

			}

		}

		

		// If it's a plain number, return truthy/falsy

		if ( is_numeric( $expr ) ) {

			return floatval( $expr ) != 0 ? 1 : 0;

		}

		

		// Try to evaluate as arithmetic expression

		try {

			$result = self::evaluate_simple( $expr );

			return $result != 0 ? 1 : 0;

		} catch ( Exception $e ) {

			return 0;

		}

	}



	/**

	 * Strip surrounding single or double quotes from a string value.

	 * Allows formulas to use either 'CA' or "CA" interchangeably in comparisons.

	 */

	private static function strip_quotes( $str ) {

		$str = (string) $str;

		if ( strlen( $str ) >= 2 ) {

			$first = $str[0];

			$last  = $str[ strlen( $str ) - 1 ];

			if ( ( '"' === $first && '"' === $last ) || ( "'" === $first && "'" === $last ) ) {

				return substr( $str, 1, -1 );

			}

		}

		return $str;

	}



	/**

	 * Resolve ternary expressions: condition ? true_value : false_value

	 * This is a C-style ternary operator alternative to if().

	 */

	public static function resolve_ternary( $formula ) {

		// Process from innermost to outermost to handle nesting

		$max_iterations = 50;

		$iteration = 0;

		

		// Match: condition ? true_value : false_value

		// The condition cannot contain ? or : at depth 0

		while ( preg_match( '/([^?:]+)\s*\?\s*([^?:]+?)\s*:\s*([^?:]+)/', $formula, $ternary_match ) && $iteration < $max_iterations ) {

			$iteration++;

			

			$condition_expr = trim( $ternary_match[1] );

			$true_expr      = trim( $ternary_match[2] );

			$false_expr     = trim( $ternary_match[3] );

			

			// Evaluate condition

			$condition_val = self::evaluate_simple( $condition_expr );

			$result_expr   = ( $condition_val != 0 ) ? $true_expr : $false_expr;

			$result_val    = self::evaluate_simple( $result_expr );

			

			$formula = substr_replace( $formula, sprintf( '%.4f', $result_val ), strpos( $formula, $ternary_match[0] ), strlen( $ternary_match[0] ) );

		}

		

		return $formula;

	}



	/**

	 * Resolves placeholders and evaluates the formula.

	 */

	
	/**
	 * Test whether $actual matches $pattern.
	 *
	 * Supports * (any sequence of characters) and ? (any single character).
	 * Falls back to a simple case-insensitive equality check when no wildcards
	 * are present, so existing exact-match rules are unaffected.
	 *
	 * @param string $actual  The real value to test.
	 * @param string $pattern The rule value, possibly containing * or ?.
	 * @return bool
	 */
	public static function value_matches_pattern( $actual, $pattern ) {

		$actual  = (string) $actual;
		$pattern = (string) $pattern;

		// Short-circuit: no wildcards – plain case-insensitive equality.
		if ( strpos( $pattern, '*' ) === false && strpos( $pattern, '?' ) === false ) {
			return ( strcasecmp( $actual, $pattern ) === 0 );
		}

		// Convert glob-style pattern to a PCRE regex.
		$regex = '/^' . str_replace( array( '\\*', '\\?' ), array( '.*', '.' ), preg_quote( $pattern, '/' ) ) . '$/iu';

		return (bool) preg_match( $regex, $actual );

	}

public static function evaluate( $formula, $contents ) {

		$formula = trim( $formula );

		if ( empty( $formula ) ) {

			return 0.0;

		}



		if ( ! is_array( $contents ) ) {

			$contents = array();

		}

		

		// 0. Resolve context-aware variables first

		$formula = self::resolve_context_variables( $formula, $contents );



		// 0.5. Resolve logical operators: IN, BETWEEN, CONTAINS

		$formula = self::resolve_logical_operators( $formula );



		// 1. Resolve rate placeholders: [rate percent=10 min_fee=5 category="heavy"]

		$formula = preg_replace_callback(

			'/\[rate\s+([^\]]+)\]/i',

			function( $matches ) use ( $contents ) {

				$attributes_str = $matches[1];

				preg_match_all( '/(percent|min_fee|max_fee|category|tag|variation)\s*=\s*(?:[\'"]([^\'"]+)[\'"]|([0-9.]+))/i', $attributes_str, $attr_matches, PREG_SET_ORDER );

				

				$percent     = 0.0;

				$min_fee     = null;

				$max_fee     = null;

				$target_type = '';

				$target_val  = '';



				foreach ( $attr_matches as $match ) {

					$key     = strtolower( $match[1] );

					$val_str = ! empty( $match[2] ) ? $match[2] : ( isset( $match[3] ) ? $match[3] : '' );



					if ( 'percent' === $key ) {

						$percent = floatval( $val_str );

					} elseif ( 'min_fee' === $key ) {

						$min_fee = floatval( $val_str );

					} elseif ( 'max_fee' === $key ) {

						$max_fee = floatval( $val_str );

					} elseif ( in_array( $key, array( 'category', 'tag', 'variation' ), true ) ) {

						$target_type = $key;

						$target_val  = strtolower( trim( $val_str ) );

					}

				}



				$matching_cost = 0.0;

				foreach ( $contents as $item ) {

					if ( self::item_matches_target( $item, $target_type, $target_val ) ) {

						$qty = isset( $item['quantity'] ) ? floatval( $item['quantity'] ) : 1.0;

						$line_total = isset( $item['line_total'] ) ? floatval( $item['line_total'] ) : ( isset( $item['cost'] ) ? floatval( $item['cost'] ) * $qty : 0.0 );

						$matching_cost += $line_total;

					}

				}



				$calculated_fee = $matching_cost * ( $percent / 100 );

				if ( null !== $min_fee && $calculated_fee < $min_fee ) {

					$calculated_fee = $min_fee;

				}

				if ( null !== $max_fee && $calculated_fee > $max_fee ) {

					$calculated_fee = $max_fee;

				}



				return sprintf( '%.4f', $calculated_fee );

			},

			$formula

		);



		// 2. Resolve qty, cost, weight, items, volume, length, width, and height placeholders

		$formula = preg_replace_callback(

			'/\[(qty|cost|weight|items|volume|length|width|height)(?:\s+(category|tag|variation)\s*=\s*[\'"]([^\'"]+)[\'"])?\]/i',

			function ( $matches ) use ( $contents ) {

				$type        = strtolower( $matches[1] );

				$target_type = ! empty( $matches[2] ) ? strtolower( $matches[2] ) : '';

				$target_val  = ! empty( $matches[3] ) ? strtolower( trim( $matches[3] ) ) : '';



				$calc_qty    = 0;

				$calc_cost   = 0.0;

				$calc_weight = 0.0;

				$calc_items  = 0;

				$calc_volume = 0.0;

				$calc_length = 0.0;

				$calc_width  = 0.0;

				$calc_height = 0.0;



				foreach ( $contents as $item ) {

					if ( self::item_matches_target( $item, $target_type, $target_val ) ) {

						$qty = isset( $item['quantity'] ) ? intval( $item['quantity'] ) : ( isset( $item['qty'] ) ? intval( $item['qty'] ) : 1 );

						$line_total = isset( $item['line_total'] ) ? floatval( $item['line_total'] ) : ( isset( $item['cost'] ) ? floatval( $item['cost'] ) * $qty : 0.0 );

						$calc_qty   += $qty;

						$calc_cost  += $line_total;

						$calc_items += 1;



						// Resolve weight and dimensions from item data or WooCommerce product

						$product = isset( $item['data'] ) && is_a( $item['data'], 'WC_Product' ) ? $item['data'] : null;

						$item_weight = 0.0;

						if ( isset( $item['weight'] ) ) {

							$item_weight = floatval( $item['weight'] );

						} elseif ( $product && method_exists( $product, 'get_weight' ) ) {

							$item_weight = floatval( $product->get_weight() );

						}

						$calc_weight += $item_weight * $qty;



						if ( $product ) {

							$l = method_exists( $product, 'get_length' ) ? floatval( $product->get_length() ) : 0.0;

							$w = method_exists( $product, 'get_width' ) ? floatval( $product->get_width() ) : 0.0;

							$h = method_exists( $product, 'get_height' ) ? floatval( $product->get_height() ) : 0.0;



							$vol = $l * $w * $h;

							$calc_volume += $vol * $qty;



							if ( $l > $calc_length ) { $calc_length = $l; }

							if ( $w > $calc_width ) { $calc_width = $w; }

							if ( $h > $calc_height ) { $calc_height = $h; }

						}

					}

				}



				if ( 'qty' === $type ) {

					return sprintf( '%d', $calc_qty );

				} elseif ( 'cost' === $type ) {

					return sprintf( '%.4f', $calc_cost );

				} elseif ( 'weight' === $type ) {

					return sprintf( '%.4f', $calc_weight );

				} elseif ( 'volume' === $type ) {

					return sprintf( '%.4f', $calc_volume );

				} elseif ( 'length' === $type ) {

					return sprintf( '%.4f', $calc_length );

				} elseif ( 'width' === $type ) {

					return sprintf( '%.4f', $calc_width );

				} elseif ( 'height' === $type ) {

					return sprintf( '%.4f', $calc_height );

				} else {

					return sprintf( '%d', $calc_items );

				}

			},

			$formula

		);



		// 2.5. Resolve math functions: min(), max(), round(), ceil(), floor(), abs()

		$formula = self::resolve_math_functions( $formula );



		// 3. Resolve if() function calls: if(condition, true_value, false_value)

		// Process from innermost to outermost to handle nesting

		$max_iterations = 50;

		$iteration = 0;

		while ( preg_match( '/if\s*\(([^()]*(?:\([^()]*\)[^()]*)*)\)/i', $formula, $if_match ) && $iteration < $max_iterations ) {

			$iteration++;

			$inner_args = $if_match[1];

			// Split by commas at depth 0 only

			$parts = self::split_if_args( $inner_args );

			if ( count( $parts ) !== 3 ) {

				throw new Exception( 'if() requires exactly 3 arguments: if(condition, true_value, false_value)' );

			}

			$condition_expr = trim( $parts[0] );

			$true_expr        = trim( $parts[1] );

			$false_expr       = trim( $parts[2] );



			// Evaluate condition using a temporary evaluator

			$condition_val = self::evaluate_simple( $condition_expr );

			$result_expr   = ( $condition_val != 0 ) ? $true_expr : $false_expr;

			$result_val    = self::evaluate_simple( $result_expr );



			$formula = substr_replace( $formula, sprintf( '%.4f', $result_val ), strpos( $formula, $if_match[0] ), strlen( $if_match[0] ) );

		}



		// 4. Resolve ternary expressions: condition ? true_value : false_value

		$formula = self::resolve_ternary( $formula );



		$clean_formula = preg_replace( '/\s+/', '', $formula );



		if ( ! preg_match( '/^[0-9\+\-\*\/\%\^\(\)\.\>\<\=\!]+$/', $clean_formula ) ) {

			throw new Exception( 'Invalid characters in formula expression.' );

		}



		// Tokenize: numbers, comparison operators (>=, <=, ==, !=, >, <), arithmetic operators (+, -, *, /, %, ^), parentheses

		preg_match_all( '/[0-9]+(?:\.[0-9]+)?|>=|<=|==|!=|[><+\-\*\/\%\^\(\)]/', $clean_formula, $matches );

		$tokens = isset( $matches[0] ) ? $matches[0] : array();



		if ( empty( $tokens ) ) {

			return 0.0;

		}



		$processed_tokens = array();

		$prev_token = null;

		foreach ( $tokens as $token ) {

			if ( '-' === $token || '+' === $token ) {

				$is_unary = ( null === $prev_token || in_array( $prev_token, array( '+', '-', '*', '/', '%', '^', '(', '>=', '<=', '==', '!=', '>', '<' ), true ) );

				if ( $is_unary ) {

					if ( '-' === $token ) {

						$processed_tokens[] = 'u-';

					}

					$prev_token = $token;

					continue;

				}

			}

			$processed_tokens[] = $token;

			$prev_token = $token;

		}



		$outputQueue = array();

		$operatorStack = array();

		$precedence = array(

			'^'   => 6,

			'u-'  => 5,

			'*'   => 4,

			'/'   => 4,

			'%'   => 4,

			'+'   => 3,

			'-'   => 3,

			'>'   => 2,

			'<'   => 2,

			'>='  => 2,

			'<='  => 2,

			'=='  => 1,

			'!='  => 1,

		);



		foreach ( $processed_tokens as $token ) {

			if ( is_numeric( $token ) ) {

				$outputQueue[] = floatval( $token );

			} elseif ( isset( $precedence[ $token ] ) ) {

				$o1 = $token;

				while ( ! empty( $operatorStack ) ) {

					$o2 = end( $operatorStack );

					if ( '(' === $o2 ) {

						break;

					}

					

					$p1 = $precedence[ $o1 ];

					$p2 = $precedence[ $o2 ];



					$is_left_assoc = ( 'u-' !== $o1 && '^' !== $o1 );

					if ( ( $is_left_assoc && $p1 <= $p2 ) || ( ! $is_left_assoc && $p1 < $p2 ) ) {

						$outputQueue[] = array_pop( $operatorStack );

					} else {

						break;

					}

				}

				$operatorStack[] = $o1;

			} elseif ( '(' === $token ) {

				$operatorStack[] = $token;

			} elseif ( ')' === $token ) {

				$matched = false;

				while ( ! empty( $operatorStack ) ) {

					$op = array_pop( $operatorStack );

					if ( '(' === $op ) {

						$matched = true;

						break;

					}

					$outputQueue[] = $op;

				}

				if ( ! $matched ) {

					throw new Exception( 'Mismatched parentheses (extra closing parenthesis).' );

				}

			} else {

				throw new Exception( 'Unknown token in expression: ' . $token );

			}

		}



		while ( ! empty( $operatorStack ) ) {

			$op = array_pop( $operatorStack );

			if ( '(' === $op || ')' === $op ) {

				throw new Exception( 'Mismatched parentheses (extra opening parenthesis).' );

			}

			$outputQueue[] = $op;

		}



		$stack = array();

		foreach ( $outputQueue as $token ) {

			if ( is_numeric( $token ) ) {

				$stack[] = $token;

			} elseif ( 'u-' === $token ) {

				if ( empty( $stack ) ) {

					throw new Exception( 'Invalid expression syntax.' );

				}

				$val = array_pop( $stack );

				$stack[] = -$val;

			} else {

				if ( count( $stack ) < 2 ) {

					throw new Exception( 'Invalid expression syntax.' );

				}

				$val2 = array_pop( $stack );

				$val1 = array_pop( $stack );



				switch ( $token ) {

					case '+':

						$stack[] = $val1 + $val2;

						break;

					case '-':

						$stack[] = $val1 - $val2;

						break;

					case '*':

						$stack[] = $val1 * $val2;

						break;

					case '^':

						$stack[] = pow( $val1, $val2 );

						break;

					case '/':

						if ( 0.0 === floatval( $val2 ) ) {

							throw new Exception( 'Division by zero.' );

						}

						$stack[] = $val1 / $val2;

						break;

					case '%':

						if ( 0.0 === floatval( $val2 ) ) {

							throw new Exception( 'Modulo by zero.' );

						}

						$stack[] = fmod( $val1, $val2 );

						break;

					case '>':

						$stack[] = ( $val1 > $val2 ) ? 1.0 : 0.0;

						break;

					case '<':

						$stack[] = ( $val1 < $val2 ) ? 1.0 : 0.0;

						break;

					case '>=':

						$stack[] = ( $val1 >= $val2 ) ? 1.0 : 0.0;

						break;

					case '<=':

						$stack[] = ( $val1 <= $val2 ) ? 1.0 : 0.0;

						break;

					case '==':

						$stack[] = ( $val1 == $val2 ) ? 1.0 : 0.0;

						break;

					case '!=':

						$stack[] = ( $val1 != $val2 ) ? 1.0 : 0.0;

						break;

				}

			}

		}



		if ( 1 !== count( $stack ) ) {

			throw new Exception( 'Malformed expression evaluation.' );

		}



		return round( $stack[0], 4 );

	}



	/**

	 * Resolve math helper functions: min(), max(), round(), ceil(), floor(), abs().

	 * Processes innermost calls first to support nesting.

	 */

	private static function resolve_math_functions( $formula ) {

		$max_iterations = 50;

		$iteration = 0;

		while ( preg_match( '/(min|max|sum|avg|average|clamp|round|ceil|floor|abs)\s*\(([^()]*(?:\([^()]*\)[^()]*)*)\)/i', $formula, $m ) && $iteration < $max_iterations ) {

			$iteration++;

			$func_name = strtolower( $m[1] );

			$args_str  = $m[2];

			$parts     = self::split_if_args( $args_str );



			$arg_vals = array();

			foreach ( $parts as $p ) {

				$arg_vals[] = self::evaluate_simple( trim( $p ) );

			}



			switch ( $func_name ) {

				case 'min':

					$result = min( $arg_vals );

					break;

				case 'max':

					$result = max( $arg_vals );

					break;

				case 'sum':

					$result = array_sum( $arg_vals );

					break;

				case 'avg':

				case 'average':

					$count  = count( $arg_vals );

					$result = $count > 0 ? array_sum( $arg_vals ) / $count : 0;

					break;

				case 'clamp':

					$val   = isset( $arg_vals[0] ) ? $arg_vals[0] : 0;

					$min_v = isset( $arg_vals[1] ) ? $arg_vals[1] : 0;

					$max_v = isset( $arg_vals[2] ) ? $arg_vals[2] : $val;

					if ( $val < $min_v ) { $val = $min_v; }

					if ( $val > $max_v ) { $val = $max_v; }

					$result = $val;

					break;

				case 'abs':

					$result = abs( isset( $arg_vals[0] ) ? $arg_vals[0] : 0 );

					break;

				case 'ceil':

					$result = ceil( isset( $arg_vals[0] ) ? $arg_vals[0] : 0 );

					break;

				case 'floor':

					$result = floor( isset( $arg_vals[0] ) ? $arg_vals[0] : 0 );

					break;

				case 'round':

					$precision = isset( $arg_vals[1] ) ? intval( $arg_vals[1] ) : 0;

					$result    = round( isset( $arg_vals[0] ) ? $arg_vals[0] : 0, $precision );

					break;

				default:

					$result = 0;

			}



			$formula = substr_replace( $formula, sprintf( '%.4f', $result ), strpos( $formula, $m[0] ), strlen( $m[0] ) );

		}

		return $formula;

	}



	/**

	 * Split if() arguments by commas at depth 0 only (ignores commas inside nested parentheses).

	 */

	private static function split_if_args( $args_string ) {

		$parts = array();

		$current = '';

		$depth = 0;

		$len = strlen( $args_string );

		for ( $i = 0; $i < $len; $i++ ) {

			$ch = $args_string[ $i ];

			if ( '(' === $ch ) {

				$depth++;

				$current .= $ch;

			} elseif ( ')' === $ch ) {

				$depth--;

				$current .= $ch;

			} elseif ( ',' === $ch && 0 === $depth ) {

				$parts[] = $current;

				$current = '';

			} else {

				$current .= $ch;

			}

		}

		if ( '' !== $current ) {

			$parts[] = $current;

		}

		return $parts;

	}



	/**

	 * Evaluate a simple arithmetic expression (no placeholders, no if() calls).

	 * Used internally by the if() resolver for condition and branch evaluation.

	 */

	private static function evaluate_simple( $expr ) {

		$expr = trim( $expr );

		if ( '' === $expr ) {

			return 0.0;

		}

		// If it's a plain number, return it directly

		if ( is_numeric( $expr ) ) {

			return floatval( $expr );

		}

		// Use the full evaluator with empty contents (placeholders already resolved)

		try {

			return self::evaluate( $expr, array() );

		} catch ( Exception $e ) {

			// Fallback: try PHP eval for simple expressions

			$clean = preg_replace( '/\s+/', '', $expr );

			if ( preg_match( '/^[0-9\+\-\*\/\^\(\)\.\>\<\=\!]+$/', $clean ) ) {

				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

				$val = @eval( 'return (' . $clean . ');' );

				return is_numeric( $val ) ? floatval( $val ) : 0.0;

			}

			return 0.0;

		}

	}



	/**

	 * Helper to check if a WooCommerce cart item matches a target category, tag, variation, or attribute term (safely handles arrays vs strings).

	 */

	public static function item_matches_target( $item, $target_type, $target_val ) {

		if ( empty( $target_type ) ) {

			return true;

		}



		if ( 'default' === $target_type ) {

			return true;

		}



		if ( empty( $target_val ) ) {

			return true;

		}



		$target_val_clean = strtolower( trim( strval( $target_val ) ) );



		// Match against item array direct fields safely (simulator or WooCommerce cart item array)

		if ( isset( $item['name'] ) && is_string( $item['name'] ) && strpos( strtolower( trim( $item['name'] ) ), $target_val_clean ) !== false ) {

			return true;

		}

		if ( isset( $item['category'] ) && is_string( $item['category'] ) && ( strtolower( trim( $item['category'] ) ) === $target_val_clean || strpos( strtolower( trim( $item['category'] ) ), $target_val_clean ) !== false ) ) {

			return true;

		}

		if ( isset( $item['tag'] ) && is_string( $item['tag'] ) && ( strtolower( trim( $item['tag'] ) ) === $target_val_clean || strpos( strtolower( trim( $item['tag'] ) ), $target_val_clean ) !== false ) ) {

			return true;

		}

		

		// Safely handle variation field whether string or WooCommerce variation attribute array

		if ( isset( $item['variation'] ) ) {

			if ( is_string( $item['variation'] ) ) {

				$v_str = strtolower( trim( $item['variation'] ) );

				if ( $v_str === $target_val_clean || strpos( $v_str, $target_val_clean ) !== false ) {

					return true;

				}

			} elseif ( is_array( $item['variation'] ) ) {

				foreach ( $item['variation'] as $v_k => $v_v ) {

					if ( is_string( $v_v ) ) {

						$v_str = strtolower( trim( $v_v ) );

						if ( $v_str === $target_val_clean || strpos( $v_str, $target_val_clean ) !== false ) {

							return true;

						}

					}

				}

			}

		}



		$product_id   = isset( $item['product_id'] ) ? intval( $item['product_id'] ) : 0;

		$variation_id = isset( $item['variation_id'] ) ? intval( $item['variation_id'] ) : 0;

		$product      = isset( $item['data'] ) ? $item['data'] : null;



		$check_ids = array_filter( array( $product_id, $variation_id ) );

		if ( $product && is_a( $product, 'WC_Product' ) ) {

			$parent_id = $product->get_parent_id();

			if ( $parent_id > 0 ) {

				$check_ids[] = $parent_id;

			}

			$prod_id = $product->get_id();

			if ( $prod_id > 0 ) {

				$check_ids[] = $prod_id;

			}

		}

		if ( $variation_id > 0 && $product_id > 0 ) {

			$check_ids[] = $product_id;

		}

		$check_ids = array_unique( array_filter( $check_ids ) );



		if ( 'product' === $target_type ) {

			if ( isset( $item['sku'] ) && is_string( $item['sku'] ) ) {

				$item_sku = strtolower( trim( $item['sku'] ) );

				if ( '' !== $item_sku && ( $item_sku === $target_val_clean || false !== strpos( $item_sku, $target_val_clean ) ) ) {

					return true;

				}

			}



			foreach ( $check_ids as $cid ) {

				if ( strval( $cid ) === $target_val_clean ) {

					return true;

				}

			}



			if ( $product && is_callable( array( $product, 'get_sku' ) ) ) {

				$sku = strtolower( trim( $product->get_sku() ) );

				if ( '' !== $sku && ( $sku === $target_val_clean || false !== strpos( $sku, $target_val_clean ) ) ) {

					return true;

				}

			}



			if ( $product && is_callable( array( $product, 'get_name' ) ) ) {

				$name = strtolower( trim( $product->get_name() ) );

				if ( '' !== $name && false !== strpos( $name, $target_val_clean ) ) {

					return true;

				}

			}

		}



		if ( 'category' === $target_type || 'tag' === $target_type ) {

			$taxonomy        = ( 'category' === $target_type ) ? 'product_cat' : 'product_tag';

			$target_val_slug = sanitize_title( $target_val_clean );

			$norm_target     = rtrim( str_replace( array( '-', '_', ' ' ), '', $target_val_clean ), 's' );



			// Check via WooCommerce term ID getters first if $product is available

			if ( 'category' === $target_type && $product && is_a( $product, 'WC_Product' ) ) {

				$cat_ids = method_exists( $product, 'get_category_ids' ) ? $product->get_category_ids() : array();

				if ( $product->get_parent_id() > 0 && function_exists( 'wc_get_product' ) ) {

					$parent_prod = wc_get_product( $product->get_parent_id() );

					if ( $parent_prod && method_exists( $parent_prod, 'get_category_ids' ) ) {

						$cat_ids = array_merge( $cat_ids, $parent_prod->get_category_ids() );

					}

				}

				$cat_ids = array_unique( array_filter( $cat_ids ) );

				foreach ( $cat_ids as $cat_id ) {

					$term = get_term( $cat_id, 'product_cat' );

					if ( $term && ! is_wp_error( $term ) ) {

						$t_slug    = strtolower( trim( $term->slug ) );

						$t_name    = strtolower( trim( $term->name ) );

						$norm_slug = rtrim( str_replace( array( '-', '_', ' ' ), '', $t_slug ), 's' );

						$norm_name = rtrim( str_replace( array( '-', '_', ' ' ), '', $t_name ), 's' );



						if ( $t_slug === $target_val_clean || $t_name === $target_val_clean || $t_slug === $target_val_slug || strval( $cat_id ) === $target_val_clean ) {

							return true;

						}

						if ( ! empty( $norm_target ) && ( $norm_slug === $norm_target || $norm_name === $norm_target || strpos( $norm_slug, $norm_target ) !== false || strpos( $norm_target, $norm_slug ) !== false || strpos( $norm_name, $norm_target ) !== false || strpos( $norm_target, $norm_name ) !== false ) ) {

							return true;

						}

					}

				}

			}



			// Check all product IDs and parent IDs via taxonomy terms

			foreach ( $check_ids as $cid ) {

				if ( $cid <= 0 ) {

					continue;

				}



				$term_list = array();

				if ( function_exists( 'wc_get_product_term_ids' ) ) {

					$wc_term_ids = wc_get_product_term_ids( $cid, $taxonomy );

					if ( is_array( $wc_term_ids ) ) {

						foreach ( $wc_term_ids as $tid ) {

							$t_obj = get_term( $tid, $taxonomy );

							if ( $t_obj && ! is_wp_error( $t_obj ) ) {

								$term_list[] = $t_obj;

							}

						}

					}

				}



				$terms = get_the_terms( $cid, $taxonomy );

				if ( is_array( $terms ) && ! is_wp_error( $terms ) ) {

					$term_list = array_merge( $term_list, $terms );

				}

				$terms_post = wp_get_post_terms( $cid, $taxonomy );

				if ( is_array( $terms_post ) && ! is_wp_error( $terms_post ) ) {

					$term_list = array_merge( $term_list, $terms_post );

				}



				foreach ( $term_list as $term ) {

					if ( ! is_object( $term ) || empty( $term->slug ) ) {

						continue;

					}

					$t_slug    = strtolower( trim( $term->slug ) );

					$t_name    = strtolower( trim( $term->name ) );

					$norm_slug = rtrim( str_replace( array( '-', '_', ' ' ), '', $t_slug ), 's' );

					$norm_name = rtrim( str_replace( array( '-', '_', ' ' ), '', $t_name ), 's' );



					if ( $t_slug === $target_val_clean || $t_name === $target_val_clean || $t_slug === $target_val_slug || strval( $term->term_id ) === $target_val_clean ) {

						return true;

					}

					if ( ! empty( $norm_target ) && ( $norm_slug === $norm_target || $norm_name === $norm_target || strpos( $norm_slug, $norm_target ) !== false || strpos( $norm_target, $norm_slug ) !== false || strpos( $norm_name, $norm_target ) !== false || strpos( $norm_target, $norm_name ) !== false ) ) {

						return true;

					}

				}

			}

		} elseif ( 'variation' === $target_type || 'attribute' === $target_type ) {

			if ( $variation_id > 0 && strval( $variation_id ) === $target_val_clean ) {

				return true;

			}

			if ( $product_id > 0 && strval( $product_id ) === $target_val_clean ) {

				return true;

			}

			if ( $product && is_callable( array( $product, 'get_sku' ) ) ) {

				$sku = strtolower( trim( $product->get_sku() ) );

				if ( ! empty( $sku ) && ( $sku === $target_val_clean || strpos( $sku, $target_val_clean ) !== false ) ) {

					return true;

				}

			}

			if ( $product && is_callable( array( $product, 'get_name' ) ) ) {

				$name = strtolower( trim( $product->get_name() ) );

				if ( strpos( $name, $target_val_clean ) !== false ) {

					return true;

				}

			}

			if ( $product && is_callable( array( $product, 'get_variation_attributes' ) ) ) {

				$variation_attributes = $product->get_variation_attributes();

				foreach ( $variation_attributes as $attr_name => $attr_val ) {

					if ( is_string( $attr_val ) ) {

						$v_clean = strtolower( trim( $attr_val ) );

						if ( $v_clean === $target_val_clean || strpos( $v_clean, $target_val_clean ) !== false ) {

							return true;

						}

					}

				}

			}

			if ( $product && is_callable( array( $product, 'get_attributes' ) ) ) {

				$attributes = $product->get_attributes();

				foreach ( $attributes as $attr ) {

					if ( is_object( $attr ) && method_exists( $attr, 'get_options' ) ) {

						$opts = $attr->get_options();

						if ( is_array( $opts ) ) {

							foreach ( $opts as $opt ) {

								if ( is_string( $opt ) && strpos( strtolower( $opt ), $target_val_clean ) !== false ) {

									return true;

								}

							}

						}

					}

				}

			}

		}



		return false;

	}

}

}



// ==========================================

// 2. WOOCOMMERCE METHOD INITIALIZATION

// ==========================================



add_action( 'woocommerce_shipping_init', 'formula_rate_shipping_init' );

add_action( 'plugins_loaded', 'formula_rate_shipping_init', 11 );

function formula_rate_shipping_init() {

	if ( ! class_exists( 'WC_Shipping_Method' ) || class_exists( 'WC_Shipping_Formula' ) ) {

		return;

	}



	class WC_Shipping_Formula extends WC_Shipping_Method {



		public $applicable_countries;

		public $min_shipping;

		public $max_shipping;

		public $default_shipping_formula;

		public $default_handling_formula;

		public $location_rules;

		public $item_rules;

		public $rule_resolution;

		public $round_rates;

		public $handling_combine;

		public $handling_title;

		public $auto_inject;



		// Legacy USA aliases for backward compatibility

		public $usa_countries;

		public $usa_min_shipping;

		public $usa_max_shipping;

		public $usa_default_shipping_formula;

		public $usa_default_handling_formula;

		public $usa_county_rules;

		public $usa_item_rules;

		public $usa_rule_resolution;



		/**

		 * Constructor.

		 */

		public function __construct( $instance_id = 0 ) {

			$this->id                 = 'formula_rate_shipping';

			$this->instance_id        = absint( $instance_id );

			$this->method_title       = __( 'Formula Rate Shipping', 'formula-rate-shipping' );

			$this->method_description = __( 'Calculate dynamic shipping and handling rates using custom formulas, priority item rules (Categories, Tags & Attributes), location overrides, and an interactive real-time tester.', 'formula-rate-shipping' );

			$this->supports           = array(

				'shipping-zones',

				'instance-settings',

				'instance-settings-modal',

			);



			$this->init();

		}



		/**

		 * Initialize shipping method settings.

		 */

		public function init() {

			$this->init_form_fields();

			$this->init_settings();



			// Run migration from old usa_* settings to new generic settings (one-time)

			$this->maybe_migrate_settings();



			// Zone Settings (generic names, backward compatible with usa_* fallback)

			$this->auto_inject                  = $this->get_option( 'auto_inject', 'yes' );

			$this->applicable_countries         = $this->get_option( 'applicable_countries', $this->get_option( 'usa_countries', '' ) );

			$this->min_shipping                 = $this->get_option( 'min_shipping', $this->get_option( 'usa_min_shipping', '' ) );

			$this->max_shipping                 = $this->get_option( 'max_shipping', $this->get_option( 'usa_max_shipping', '' ) );

			$this->default_shipping_formula     = $this->get_option( 'default_shipping_formula', $this->get_option( 'usa_default_shipping_formula', '4.50 + ([qty] - 1) * 2.50' ) );

			$this->default_handling_formula     = $this->get_option( 'default_handling_formula', $this->get_option( 'usa_default_handling_formula', '3.00' ) );

			$this->location_rules               = $this->get_option( 'location_rules', $this->get_option( 'usa_county_rules', array() ) );

			

			// Default rules array with Rule #5 = Variation -> Hardcover

			$default_item_rules = array(

				array(

					'type'             => 'category',

					'target'           => 'paperback',

					'shipping_formula' => '1.50 + ([qty] - 1) * 0.75',

					'handling_formula' => '1.25',

				),

				array(

					'type'             => 'category',

					'target'           => 'non-fiction',

					'shipping_formula' => '4.50 + ([qty] - 1) * 2.50',

					'handling_formula' => '3.00',

				),

				array(

					'type'             => 'category',

					'target'           => 'pivotal-press',

					'shipping_formula' => '4.50 + ([qty] - 1) * 2.50',

					'handling_formula' => '3.00',

				),

				array(

					'type'             => 'category',

					'target'           => 'childrens-books',

					'shipping_formula' => '4.50 + ([qty] - 1) * 2.00',

					'handling_formula' => '3.00',

				),

				array(

					'type'             => 'variation',

					'target'           => 'Hardcover',

					'shipping_formula' => '[category="books"] ? 4.50 + ([qty] - 1) * 3.00 : 5.00 + ([qty] - 1) * 3.50',

					'handling_formula' => '3.50',

				),

			);



			$saved_item_rules = $this->get_option( 'item_rules', $this->get_option( 'usa_item_rules', $default_item_rules ) );

			if ( empty( $saved_item_rules ) || ! is_array( $saved_item_rules ) ) {

				$saved_item_rules = $default_item_rules;

			}



			// Automatic Database Migration: Convert old 'hardback' or 'hardcover' tag rules into Variation SKU / Attribute -> Hardcover

			// AND upgrade Hardcover shipping formula to new prepopulated formula with category check

			$migrated = false;

			foreach ( $saved_item_rules as $k => $r ) {

				$t = isset( $r['target'] ) ? strtolower( trim( $r['target'] ) ) : '';

				if ( 'hardback' === $t || 'hardcover' === $t ) {

					if ( 'variation' !== ( isset( $r['type'] ) ? $r['type'] : '' ) || 'Hardcover' !== $r['target'] ) {

						$saved_item_rules[ $k ]['type']   = 'variation';

						$saved_item_rules[ $k ]['target'] = 'Hardcover';

						$migrated                         = true;

					}

					$new_formula = '[category="books"] ? 4.50 + ([qty] - 1) * 3.00 : 5.00 + ([qty] - 1) * 3.50';

					if ( isset( $r['shipping_formula'] ) ) {

						$cleaned_formula = str_replace( ' ', '', $r['shipping_formula'] );

						$cleaned_old_1   = str_replace( ' ', '', '5.00 + ([qty] - 1) * 3.50' );

						$cleaned_old_2   = str_replace( ' ', '', '4.50 + ([qty] - 1) * 2.50' );

						if ( $cleaned_formula === $cleaned_old_1 || $cleaned_formula === $cleaned_old_2 || empty( $cleaned_formula ) ) {

							$saved_item_rules[ $k ]['shipping_formula'] = $new_formula;

							$migrated                                   = true;

						}

					}

				}

			}

			if ( $migrated ) {

				$this->settings['item_rules'] = $saved_item_rules;

				update_option( $this->get_option_key(), $this->settings );

			}



			$this->item_rules        = $saved_item_rules;

			$this->rule_resolution   = $this->get_option( 'rule_resolution', $this->get_option( 'usa_rule_resolution', 'first_match' ) );



			// General Settings

			$this->title            = $this->get_option( 'title', __( 'Shipping and Handling', 'formula-rate-shipping' ) );

			$this->handling_combine = $this->get_option( 'handling_combine', 'yes' );

			$this->handling_title   = $this->get_option( 'handling_title', __( 'Handling Fee', 'formula-rate-shipping' ) );

			$this->round_rates      = $this->get_option( 'round_rates', 'no' );



			// Legacy property aliases for backward compatibility

			$this->usa_countries                = $this->applicable_countries;

			$this->usa_min_shipping             = $this->min_shipping;

			$this->usa_max_shipping             = $this->max_shipping;

			$this->usa_default_shipping_formula = $this->default_shipping_formula;

			$this->usa_default_handling_formula = $this->default_handling_formula;

			$this->usa_county_rules             = $this->location_rules;

			$this->usa_item_rules               = $this->item_rules;

			$this->usa_rule_resolution          = $this->rule_resolution;



			add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );

		}



		/**

		 * Migrate old usa_* settings to new generic setting names (one-time migration).

		 * This preserves existing client data when updating from older versions.

		 */

		private function maybe_migrate_settings() {

			// Check if migration has already been run

			if ( get_option( 'formula_rate_shipping_migrated_v017' ) ) {

				return;

			}



			$settings = get_option( $this->get_option_key(), array() );

			if ( empty( $settings ) || ! is_array( $settings ) ) {

				return;

			}



			$migrated = false;



			// Map old usa_* keys to new generic keys

			$key_map = array(

				'usa_countries'            => 'applicable_countries',

				'usa_min_shipping'         => 'min_shipping',

				'usa_max_shipping'         => 'max_shipping',

				'usa_default_shipping_formula' => 'default_shipping_formula',

				'usa_default_handling_formula' => 'default_handling_formula',

				'usa_county_rules'         => 'location_rules',

				'usa_item_rules'           => 'item_rules',

				'usa_rule_resolution'      => 'rule_resolution',

			);



			foreach ( $key_map as $old_key => $new_key ) {

				if ( isset( $settings[ $old_key ] ) && ! isset( $settings[ $new_key ] ) ) {

					$settings[ $new_key ] = $settings[ $old_key ];

					$migrated = true;

				}

			}



			if ( $migrated ) {

				update_option( $this->get_option_key(), $settings );

				update_option( 'formula_rate_shipping_migrated_v017', true );

			}

		}



		/**

		 * Define settings fields. Only custom cards are listed so WooCommerce modal renders exclusively via our custom card generators.

		 */

		public function init_form_fields() {

			$this->instance_form_fields = array(

				'general_settings_card' => array(

					'type' => 'general_settings_card',

				),

				'usa_county_rules' => array(

					'type' => 'location_rules_card',

				),

				'usa_item_rules_card' => array(

					'type' => 'item_rules_card',

				),

				'live_preview' => array(

					'type' => 'live_preview_panel',

				),

				'import_export_card' => array(

					'type' => 'import_export_card',

				),

				'documentation_card' => array(

					'type' => 'documentation_card',

				),

			);

		}



		/**

		 * WooCommerce settings API validation hook for 'item_rules_card' field type.

		 */

		public function validate_item_rules_card_field( $key, $value ) {

			return $this->validate_item_rules_table_field( $key, $value );

		}



		/**

		 * WooCommerce settings API validation hook for 'location_rules_card' field type.

		 */

		public function validate_location_rules_card_field( $key, $value ) {

			return $this->validate_county_rules_table_field( $key, $value );

		}



		/**

		 * WooCommerce settings API validation hook for 'county_rules_table' field type.

		 */

		public function validate_county_rules_table_field_type( $key, $value ) {

			return $this->validate_county_rules_table_field( $key, $value );

		}



		/**

		 * Save all options from custom cards in WooCommerce shipping zone modal.

		 */

		public function process_admin_options() {

			$post_data = $this->get_post_data();

			if ( empty( $post_data ) ) {

				$post_data = $_POST;

			}



			$this->settings['title']                        = isset( $post_data[ $this->get_field_key( 'title' ) ] ) ? sanitize_text_field( $post_data[ $this->get_field_key( 'title' ) ] ) : ( isset( $_POST['title'] ) ? sanitize_text_field( $_POST['title'] ) : $this->get_option( 'title', 'Shipping and Handling' ) );



			$this->settings['auto_inject']                  = ( isset( $post_data[ $this->get_field_key( 'auto_inject' ) ] ) || isset( $_POST['auto_inject'] ) ) ? 'yes' : 'no';



			$this->settings['handling_combine']             = ( isset( $post_data[ $this->get_field_key( 'handling_combine' ) ] ) || isset( $_POST['handling_combine'] ) ) ? 'yes' : 'no';



			$this->settings['handling_title']               = isset( $post_data[ $this->get_field_key( 'handling_title' ) ] ) ? sanitize_text_field( $post_data[ $this->get_field_key( 'handling_title' ) ] ) : ( isset( $_POST['handling_title'] ) ? sanitize_text_field( $_POST['handling_title'] ) : $this->get_option( 'handling_title', 'Handling Fee' ) );



			$this->settings['round_rates']                  = ( isset( $post_data[ $this->get_field_key( 'round_rates' ) ] ) || isset( $_POST['round_rates'] ) ) ? 'yes' : 'no';



			$this->settings['holiday_country']              = isset( $post_data[ $this->get_field_key( 'holiday_country' ) ] ) ? sanitize_text_field( $post_data[ $this->get_field_key( 'holiday_country' ) ] ) : $this->get_option( 'holiday_country', 'store' );



			$this->settings['usa_min_shipping']             = isset( $post_data[ $this->get_field_key( 'usa_min_shipping' ) ] ) ? sanitize_text_field( $post_data[ $this->get_field_key( 'usa_min_shipping' ) ] ) : '';



			$this->settings['usa_max_shipping']             = isset( $post_data[ $this->get_field_key( 'usa_max_shipping' ) ] ) ? sanitize_text_field( $post_data[ $this->get_field_key( 'usa_max_shipping' ) ] ) : '';



			$this->settings['usa_default_shipping_formula'] = isset( $post_data[ $this->get_field_key( 'usa_default_shipping_formula' ) ] ) ? sanitize_text_field( $post_data[ $this->get_field_key( 'usa_default_shipping_formula' ) ] ) : '4.50 + ([qty] - 1) * 2.50';



			$this->settings['usa_default_handling_formula'] = isset( $post_data[ $this->get_field_key( 'usa_default_handling_formula' ) ] ) ? sanitize_text_field( $post_data[ $this->get_field_key( 'usa_default_handling_formula' ) ] ) : '3.00';



			$this->settings['usa_county_rules']             = $this->validate_county_rules_table_field( 'usa_county_rules', null );



			$this->settings['usa_item_rules']               = $this->validate_item_rules_table_field( 'usa_item_rules', null );



			$this->settings['usa_rule_resolution']          = isset( $post_data[ $this->get_field_key( 'usa_rule_resolution' ) ] ) ? sanitize_text_field( $post_data[ $this->get_field_key( 'usa_rule_resolution' ) ] ) : ( isset( $_POST['usa_rule_resolution'] ) ? sanitize_text_field( $_POST['usa_rule_resolution'] ) : 'first_match' );



			return update_option( $this->get_option_key(), apply_filters( 'woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings ), 'yes' );

		}



		/**

		 * Generate HTML for Card 1: General Settings & Default Rules.

		 */

		public function generate_general_settings_card_html( $key, $data ) {

			$title             = $this->get_option( 'title', __( 'Shipping and Handling', 'formula-rate-shipping' ) );

			$auto_inject       = $this->get_option( 'auto_inject', 'yes' );

			$handling_combine  = $this->get_option( 'handling_combine', 'yes' );

			$handling_title    = $this->get_option( 'handling_title', __( 'Handling Fee', 'formula-rate-shipping' ) );

			$round_rates       = $this->get_option( 'round_rates', 'no' );

			$holiday_country   = $this->get_option( 'holiday_country', 'store' );

			$usa_min_shipping  = $this->get_option( 'usa_min_shipping', '' );

			$usa_max_shipping  = $this->get_option( 'usa_max_shipping', '' );

			$default_ship_f    = $this->get_option( 'usa_default_shipping_formula', '4.50 + ([qty] - 1) * 2.50' );

			$default_hand_f    = $this->get_option( 'usa_default_handling_formula', '3.00' );



			$help_box_html = '

				<div class="formula-shipping-help-box-wrapper" style="margin-top: 18px;">

					<a href="#" class="formula-help-toggle-btn" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600; text-decoration: none; color: #0284c7; font-size: 13px;"><span class="dashicons dashicons-editor-help" style="font-size: 18px; width: 18px; height: 18px; margin:0;"></span> ' . __( 'Show Formula Quick Reference', 'formula-rate-shipping' ) . '</a>

					<div class="formula-shipping-help-box" style="display: none; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px 18px; margin-top: 10px;">

						<p style="margin:0 0 10px 0; font-size:12px; color:#334155; line-height:1.5;">' . __( 'Enter fixed amounts or dynamic math formulas using placeholders:', 'formula-rate-shipping' ) . '</p>

						<p style="margin:0 0 10px 0; font-size:12px; color:#475569; line-height:1.6;">' . __( 'Section 2 now uses compact mini-tab target groups. Device and OS are entered separately, and OS aliases like Win10, Win11, Ventura, Sonoma, or Monterey are accepted. Formula validation only runs on Enter or when the field loses focus.', 'formula-rate-shipping' ) . '</p>

						

						<div style="display:grid; grid-template-columns:1fr 1fr; gap:12px 20px; font-size:12px; color:#475569; line-height:1.6;">

							<div>

								<p style="margin:0 0 4px 0; font-weight:700; color:#1e293b;"><span class="dashicons dashicons-archive"></span> Cart & Items:</p>

								<code>[qty]</code> ' . __( 'item count', 'formula-rate-shipping' ) . ' | <code>[cost]</code> ' . __( 'subtotal', 'formula-rate-shipping' ) . '<br>

								<code>[weight]</code> ' . __( 'total weight', 'formula-rate-shipping' ) . ' | <code>[items]</code> ' . __( 'unique items', 'formula-rate-shipping' ) . '<br>

								<code>[on_sale]</code> ' . __( '1 if sale item in cart', 'formula-rate-shipping' ) . '<br>

								<code>[rate percent=10 min_fee=5 max_fee=50]</code>

							</div>

							<div>

								<p style="margin:0 0 4px 0; font-weight:700; color:#1e293b;"><span class="dashicons dashicons-admin-users"></span> User, Location & Device:</p>

								<code>[user="Subscriber"]</code> ' . __( 'role/email/login &rarr; 1 or 0', 'formula-rate-shipping' ) . '<br>

								<code>[user_role]</code> ' . __( 'role text (or "guest")', 'formula-rate-shipping' ) . '<br>

								<code>[os]</code> <code>[browser]</code> <code>[device]</code> <code>[is_mobile]</code><br>

								<code>[shipping_country]</code> <code>[shipping_state]</code> <code>[shipping_zip]</code><br>

								<code>[is_international]</code> <code>[is_international="US"]</code>

							</div>

							<div>

								<p style="margin:0 0 4px 0; font-weight:700; color:#1e293b;"><span class="dashicons dashicons-tag"></span> Item Filters & Product Name:</p>

								<code>[qty category="books"]</code> <code>[cost tag="fragile"]</code><br>

								<code>[weight variation="Hardcover"]</code><br>

								<code>[product="My Book"]</code> ' . __( 'product name, SKU, or product ID substring match', 'formula-rate-shipping' ) . '<br>

								<code>[message="Custom Title Subtext"]</code>

							</div>

							<div>

								<p style="margin:0 0 4px 0; font-weight:700; color:#1e293b;"><span class="dashicons dashicons-calculator"></span> Math Functions & Logic:</p>

								<code>if(cond, true, false)</code> | <code>cond ? true : false</code><br>

								<code>sum(a,b)</code> <code>min(a,b)</code> <code>max(a,b)</code> <code>ceil(v)</code> <code>floor(v)</code> <code>round(v)</code><br>

								<code>IN ("CA", "WA")</code> | <code>BETWEEN 5 AND 50</code> | <code>CONTAINS "text"</code><br>

								<code>AND</code> <code>OR</code> <code>NOT</code> <code>!</code> | <code>+</code> <code>-</code> <code>*</code> <code>/</code> <code>%</code>

							</div>

						</div>

						

						<p style="margin:12px 0 6px 0; font-size:12px; color:#0f172a; font-weight:700; border-bottom:1px solid #e2e8f0; padding-bottom:4px;"><span class="dashicons dashicons-lightbulb"></span> ' . __( 'Quick Examples:', 'formula-rate-shipping' ) . '</p>

						<ul style="margin:0 0 10px 0; padding-left:18px; font-size:12px; color:#475569; line-height:1.7;">

							<li><strong>4.50 + ([qty] - 1) * 2.50</strong> &rarr; ' . __( 'tiered shipping', 'formula-rate-shipping' ) . '</li>

							<li><strong>ceil([weight] / 5) * 4.00</strong> &rarr; ' . __( 'round up per 5 lbs box', 'formula-rate-shipping' ) . '</li>

							<li><strong>if([shipping_state] IN ("CA", "OR", "WA"), 12.00, 8.00)</strong> &rarr; ' . __( 'west coast rate', 'formula-rate-shipping' ) . '</li>

							<li><strong>if([weight] BETWEEN 5 AND 50, 15.00, 25.00)</strong> &rarr; ' . __( 'weight range rate', 'formula-rate-shipping' ) . '</li>

							<li><strong>if([user="Subscriber"], 0.00, 5.00)</strong> &rarr; ' . __( 'free for subscribers', 'formula-rate-shipping' ) . '</li>

						</ul>

						

						<p style="margin:10px 0 0 0; font-size:12px;">

							<a href="#formula-documentation-card" style="font-weight:600; color:#0284c7; text-decoration:none;"><span class="dashicons dashicons-book"></span> ' . __( 'View Full Formula Documentation', 'formula-rate-shipping' ) . ' &rarr;</a>

						</p>

					</div>

				</div>

			';



			ob_start();

			?>

			<tr valign="top">

				<td colspan="2" style="padding: 0; margin-bottom: 28px;">

					<div class="formula-section-card card-theme-general">

						<div class="card-header">

							<h3><span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e( '1. General Settings & Default Shipping Rules', 'formula-rate-shipping' ); ?></h3>

							<a class="card-badge" href="https://www.paypal.com/ncp/payment/EKH76RTYHH24S" target="_blank" rel="noopener noreferrer">

								<span class="dashicons dashicons-heart" style="color: #dc2626; font-size: 16px; width: 16px; height: 16px; vertical-align: middle;"></span>

								<?php esc_html_e( 'Say Thanks', 'formula-rate-shipping' ); ?>

							</a>

						</div>

						<div class="card-summary-bar" id="formula_card_summary_general">

							<div class="card-summary-text">

								<span>Auto-Inject: <?php echo 'yes' === $auto_inject ? 'Enabled' : 'Disabled'; ?></span>

								<span>Rounding: <?php echo 'yes' === $round_rates ? 'Enabled' : 'Disabled'; ?></span>

								<?php if ( ! empty( $usa_min_shipping ) || ! empty( $usa_max_shipping ) ) : ?>

									<span>Min/Max Charges: <?php echo esc_html( $usa_min_shipping ?: '$0' ); ?> / <?php echo esc_html( $usa_max_shipping ?: 'None' ); ?></span>

								<?php endif; ?>

							</div>

							<span class="card-toggle-icon dashicons dashicons-arrow-up-alt2"></span>

						</div>

						<div class="card-body" style="padding: 24px 28px;">

							<!-- Auto-Inject Setting -->

							<div class="formula-field-group" style="margin-bottom: 20px; padding: 12px 14px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px;">

								<label for="<?php echo esc_attr( $this->get_field_key( 'auto_inject' ) ); ?>" style="display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 13px; color: #166534; cursor: pointer; margin: 0;">

									<input type="checkbox" name="<?php echo esc_attr( $this->get_field_key( 'auto_inject' ) ); ?>" id="<?php echo esc_attr( $this->get_field_key( 'auto_inject' ) ); ?>" value="1" <?php checked( $auto_inject, 'yes' ); ?> style="margin: 0;" />

									<?php esc_html_e( 'Auto-Inject rates into all WooCommerce shipping calculations (Recommended)', 'formula-rate-shipping' ); ?>

								</label>

								<p class="description" style="margin-top: 4px; margin-left: 22px; font-size: 11px; color: #15803d; line-height: 1.4;"><?php esc_html_e( 'Automatically presents Formula Rate Shipping at checkout without requiring manual shipping zone assignment.', 'formula-rate-shipping' ); ?></p>

							</div>



							<!-- Row 1: Shipping Name (Left) + Subtle Checkboxes under Shipping Name, Handling Fee Name (Right) -->

							<div class="formula-two-col-grid" id="formula_row1_grid" style="margin-bottom: 20px;">

								<div class="formula-field-group">

									<label for="<?php echo esc_attr( $this->get_field_key( 'title' ) ); ?>"><?php esc_html_e( 'Shipping Method Name *', 'formula-rate-shipping' ); ?></label>

									<input type="text" name="<?php echo esc_attr( $this->get_field_key( 'title' ) ); ?>" id="<?php echo esc_attr( $this->get_field_key( 'title' ) ); ?>" value="<?php echo esc_attr( $title ); ?>" placeholder="Shipping and Handling" required />

									

									<div class="formula-subtle-checkbox-wrapper" style="margin-top: 6px; display: flex; align-items: flex-start; gap: 6px;">

										<input type="checkbox" name="<?php echo esc_attr( $this->get_field_key( 'handling_combine' ) ); ?>" id="<?php echo esc_attr( $this->get_field_key( 'handling_combine' ) ); ?>" value="1" <?php checked( $handling_combine, 'yes' ); ?> style="margin-top: 2px;" />

										<label for="<?php echo esc_attr( $this->get_field_key( 'handling_combine' ) ); ?>" style="margin: 0; font-weight: 400; font-size: 11px; color: #64748b; line-height: 1.4; cursor: pointer;">

											<?php esc_html_e( 'Combine Handling with Shipping into a single rate line item at checkout.', 'formula-rate-shipping' ); ?>

										</label>

									</div>

								</div>



								<div class="formula-field-group" id="formula_handling_title_group">

									<label for="<?php echo esc_attr( $this->get_field_key( 'handling_title' ) ); ?>"><?php esc_html_e( 'Handling Fee Name', 'formula-rate-shipping' ); ?></label>

									<input type="text" name="<?php echo esc_attr( $this->get_field_key( 'handling_title' ) ); ?>" id="<?php echo esc_attr( $this->get_field_key( 'handling_title' ) ); ?>" value="<?php echo esc_attr( $handling_title ); ?>" placeholder="Handling Fee" />

									<p class="description" style="margin-top: 6px; font-size: 11px; color: #64748b; line-height: 1.4;"><?php esc_html_e( 'Label displayed on separate handling line item when uncombined.', 'formula-rate-shipping' ); ?></p>

								</div>

							</div>



							<!-- Rounding Setting -->

							<div class="formula-field-group" style="margin-bottom: 20px; padding: 12px 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px;">

								<label for="<?php echo esc_attr( $this->get_field_key( 'round_rates' ) ); ?>" style="display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 13px; color: #1e293b; cursor: pointer; margin: 0;">

									<input type="checkbox" name="<?php echo esc_attr( $this->get_field_key( 'round_rates' ) ); ?>" id="<?php echo esc_attr( $this->get_field_key( 'round_rates' ) ); ?>" value="1" <?php checked( $round_rates, 'yes' ); ?> style="margin: 0;" />

									<?php esc_html_e( 'Round Shipping & Handling rates up to nearest 5 or 0 cents (e.g. $4.73 → $4.75)', 'formula-rate-shipping' ); ?>

								</label>

								<p class="description" style="margin-top: 4px; margin-left: 22px; font-size: 11px; color: #64748b; line-height: 1.4;"><?php esc_html_e( 'Rounds calculated rates up to the next $0.05 increment so amounts end neatly in .00, .05, .10, etc.', 'formula-rate-shipping' ); ?></p>

							</div>



							<!-- Holiday Calendar Settings -->
							<div class="formula-field-group" style="margin-top: 6px; margin-bottom: 14px; padding: 14px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px;">
								<label for="<?php echo esc_attr( $this->get_field_key( 'holiday_country' ) ); ?>" style="font-weight: 600; font-size: 13px; color: #1e293b; margin-bottom: 6px; display: block;">
									<?php esc_html_e( 'Default Holiday Calendar Country & Source', 'formula-rate-shipping' ); ?>
								</label>

								<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 10px;">
									<div>
										<label style="font-size: 11px; font-weight: 600; color: #475569; margin-bottom: 4px; display: block;">Calendar Source</label>
										<select name="<?php echo esc_attr( $this->get_field_key( 'holiday_provider' ) ); ?>" id="formula_holiday_provider" class="formula-holiday-country-select" style="width: 100%;">
											<option value="built_in" <?php selected( get_option( 'formula_shipping_holiday_provider', 'built_in' ), 'built_in' ); ?>>Built-in Public Holidays (TimeAndDate / Standard)</option>
											<option value="custom_ical" <?php selected( get_option( 'formula_shipping_holiday_provider' ), 'custom_ical' ); ?>>Custom iCal / Google / Apple Calendar URL (.ics)</option>
										</select>
									</div>

									<div>
										<label style="font-size: 11px; font-weight: 600; color: #475569; margin-bottom: 4px; display: block;">Country Region</label>
										<select name="<?php echo esc_attr( $this->get_field_key( 'holiday_country' ) ); ?>" id="<?php echo esc_attr( $this->get_field_key( 'holiday_country' ) ); ?>" class="formula-holiday-country-select" style="width: 100%;">
											<option value="store" <?php selected( $holiday_country, 'store' ); ?>><?php esc_html_e( 'Store Base Country (Warehouse Origin)', 'formula-rate-shipping' ); ?></option>
											<option value="customer_shipping" <?php selected( $holiday_country, 'customer_shipping' ); ?>><?php esc_html_e( 'Customer Shipping Country (Gift Recipient / Destination)', 'formula-rate-shipping' ); ?></option>
											<option value="customer_billing" <?php selected( $holiday_country, 'customer_billing' ); ?>><?php esc_html_e( 'Customer Billing Country (Purchaser / Gifter)', 'formula-rate-shipping' ); ?></option>
											<option value="US" <?php selected( $holiday_country, 'US' ); ?>>United States (US)</option>
											<option value="CA" <?php selected( $holiday_country, 'CA' ); ?>>Canada (CA)</option>
											<option value="GB" <?php selected( $holiday_country, 'GB' ); ?>>United Kingdom (GB)</option>
											<option value="AU" <?php selected( $holiday_country, 'AU' ); ?>>Australia (AU)</option>
											<option value="DE" <?php selected( $holiday_country, 'DE' ); ?>>Germany (DE)</option>
											<option value="FR" <?php selected( $holiday_country, 'FR' ); ?>>France (FR)</option>
										</select>
									</div>
								</div>

								<!-- Custom iCal Feed Options -->
								<div id="formula_custom_ical_wrap" style="display: none; margin-top: 10px; padding-top: 10px; border-top: 1px dashed #cbd5e1;">
									<label for="formula_custom_ical_url" style="font-size: 12px; font-weight: 600; color: #334155; display: block; margin-bottom: 4px;">Custom iCal Feed URL (.ics)</label>
									<input type="url" name="formula_shipping_custom_ical_url" id="formula_custom_ical_url" value="<?php echo esc_attr( get_option( 'formula_shipping_custom_ical_url', '' ) ); ?>" placeholder="https://calendar.google.com/calendar/ical/.../public/basic.ics" style="width: 100%; font-size: 12px; margin-bottom: 8px;" />
									
									<label style="font-size: 11px; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Calendar Event Filter Mode</label>
									<select name="formula_shipping_custom_ical_filter" id="formula_custom_ical_filter" style="width: 100%; max-width: 100%; box-sizing: border-box; height: 36px; padding: 6px 28px 6px 10px; font-size: 12px; border: 1px solid #cbd5e1; border-radius: 4px; background: #ffffff;">
										<option value="all_events" <?php selected( get_option( 'formula_shipping_custom_ical_filter', 'all_events' ), 'all_events' ); ?>>Use All Calendar Events (Any event today counts as a holiday)</option>
										<option value="prefix_holiday" <?php selected( get_option( 'formula_shipping_custom_ical_filter' ), 'prefix_holiday' ); ?>>Filter by 'Holiday:' Prefix (Only events titled "Holiday: Festivus", "Holiday: Star Wars Day", etc.)</option>
									</select>
									<p class="description" style="margin: 4px 0 0; font-size: 11px; color: #64748b; line-height: 1.4;">Paste your public Google Calendar, Apple iCloud, or Outlook iCal (.ics) URL. When using 'Holiday:' prefix mode, events titled <code>Holiday: Festivus</code>, <code>Holiday: Star Wars Day</code>, or <code>Holiday: Summer Sale</code> evaluate <code>[holiday]</code> cleanly to <code>Festivus</code>, <code>Star Wars Day</code>, or <code>Summer Sale</code>.</p>
								</div>
							</div>



							<!-- Row 2: Min Shipping & Max Shipping (Two Columns on One Line) -->

							<div class="formula-two-col-grid" style="margin-bottom: 20px;">

								<div class="formula-field-group">

									<label for="<?php echo esc_attr( $this->get_field_key( 'usa_min_shipping' ) ); ?>"><?php esc_html_e( 'Minimum Shipping Charge ($)', 'formula-rate-shipping' ); ?></label>

									<input type="text" name="<?php echo esc_attr( $this->get_field_key( 'usa_min_shipping' ) ); ?>" id="<?php echo esc_attr( $this->get_field_key( 'usa_min_shipping' ) ); ?>" value="<?php echo esc_attr( $usa_min_shipping ); ?>" placeholder="e.g. 5.00 (Leave blank for no minimum)" />

								</div>

								<div class="formula-field-group">

									<label for="<?php echo esc_attr( $this->get_field_key( 'usa_max_shipping' ) ); ?>"><?php esc_html_e( 'Maximum Shipping Charge ($)', 'formula-rate-shipping' ); ?></label>

									<input type="text" name="<?php echo esc_attr( $this->get_field_key( 'usa_max_shipping' ) ); ?>" id="<?php echo esc_attr( $this->get_field_key( 'usa_max_shipping' ) ); ?>" value="<?php echo esc_attr( $usa_max_shipping ); ?>" placeholder="e.g. 50.00 (Leave blank for no maximum)" />

								</div>

							</div>



							<!-- Row 3: Default Shipping & Handling Formulas (Default Rule above Location Rules) -->

							<div class="formula-default-rule-box" style="padding: 18px 20px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">

								<h4 style="margin: 0 0 8px 0; font-size: 14px; color: #1e293b; font-weight: 700;"><?php esc_html_e( 'Default Shipping & Handling Formulas', 'formula-rate-shipping' ); ?></h4>

								<p style="margin: 0 0 14px 0; font-size: 12px; color: #64748b;"><?php esc_html_e( 'These formulas act as the base/default rule for all cart items that do not match an explicit category or tag rule.', 'formula-rate-shipping' ); ?></p>

								

								<div class="formula-two-col-grid" id="formula_default_formulas_grid" style="margin-bottom: 0;">

									<div class="formula-field-group">

										<label for="<?php echo esc_attr( $this->get_field_key( 'usa_default_shipping_formula' ) ); ?>"><?php esc_html_e( 'Default Shipping Formula', 'formula-rate-shipping' ); ?></label>

										<input type="text" name="<?php echo esc_attr( $this->get_field_key( 'usa_default_shipping_formula' ) ); ?>" id="<?php echo esc_attr( $this->get_field_key( 'usa_default_shipping_formula' ) ); ?>" value="<?php echo esc_attr( $default_ship_f ); ?>" placeholder="e.g. 4.50 + ([qty] - 1) * 2.50" />

									</div>

									<div class="formula-field-group">

										<label for="<?php echo esc_attr( $this->get_field_key( 'usa_default_handling_formula' ) ); ?>"><?php esc_html_e( 'Default Handling Formula', 'formula-rate-shipping' ); ?></label>

										<input type="text" name="<?php echo esc_attr( $this->get_field_key( 'usa_default_handling_formula' ) ); ?>" id="<?php echo esc_attr( $this->get_field_key( 'usa_default_handling_formula' ) ); ?>" value="<?php echo esc_attr( $default_hand_f ); ?>" placeholder="e.g. 3.00" />

									</div>

								</div>

								<?php echo $help_box_html; ?>

							</div>

						</div>

					</div>

				</td>

			</tr>

			<?php

			return ob_get_clean();

		}



		/**

		 * Generate HTML for Card 2: Location & Regional Overrides.

		 */

		public function generate_location_rules_card_html( $key, $data ) {

			$field_key  = $this->get_field_key( $key );

			$resolution = $this->get_option( 'usa_location_rule_resolution', $this->get_option( 'rule_resolution', 'first_match' ) );

			$rules      = $this->get_option( $key, array() );

			if ( is_string( $rules ) ) {

				$rules = json_decode( $rules, true );

			}

			if ( ! is_array( $rules ) ) {

				$rules = array();

			}

			$enabled_rules_count = 0;

			foreach ( $rules as $r ) {

				if ( ! isset( $r['enabled'] ) || 'no' !== $r['enabled'] ) {

					$enabled_rules_count++;

				}

			}

			$total_rules_count = count( $rules );



			$wp_roles_list = array();

			if ( function_exists( 'wp_roles' ) ) {

				$wp_roles_obj = wp_roles();

				if ( isset( $wp_roles_obj->roles ) && is_array( $wp_roles_obj->roles ) ) {

					foreach ( $wp_roles_obj->roles as $role_key => $role_data ) {

						$role_name = isset( $role_data['name'] ) ? translate_user_role( $role_data['name'] ) : $role_key;

						$wp_roles_list[] = array(

							'value' => $role_key,

							'label' => $role_name . ' (' . $role_key . ')',

						);

					}

				}

			}

			$country_suggestions = array(

				array(

					'value' => 'ALL',

					'label' => __( 'All Countries (ALL)', 'formula-rate-shipping' ),

				),

			);

			$country_state_suggestions = array();

			$browser_suggestions = array(

				array(

					'value' => 'ALL',

					'label' => __( 'All Browsers (ALL)', 'formula-rate-shipping' ),

				),

				array(

					'value' => 'Chrome',

					'label' => 'Chrome',

				),

				array(

					'value' => 'Firefox',

					'label' => 'Firefox',

				),

				array(

					'value' => 'Safari',

					'label' => 'Safari',

				),

				array(

					'value' => 'Edge',

					'label' => 'Edge',

				),

				array(

					'value' => 'Opera',

					'label' => 'Opera',

				),

				array(

					'value' => 'Brave',

					'label' => 'Brave',

				),

				array(

					'value' => 'Vivaldi',

					'label' => 'Vivaldi',

				),

				array(

					'value' => 'Samsung Internet',

					'label' => 'Samsung Internet',

				),

				array(

					'value' => 'UC Browser',

					'label' => 'UC Browser',

				),

				array(

					'value' => 'Yandex Browser',

					'label' => 'Yandex Browser',

				),

				array(

					'value' => 'Internet Explorer',

					'label' => 'Internet Explorer',

				),

				array(

					'value' => 'Floorp',

					'label' => 'Floorp',

				),

				array(

					'value' => 'Waterfox',

					'label' => 'Waterfox',

				),

				array(

					'value' => 'LibreWolf',

					'label' => 'LibreWolf',

				),

				array(

					'value' => 'Arc',

					'label' => 'Arc',

				),

				array(

					'value' => 'Unknown',

					'label' => 'Unknown (Other / Exotic)',

				),

			);

			$device_suggestions = array(

				array(

					'value' => 'ALL',

					'label' => __( 'All Devices (ALL)', 'formula-rate-shipping' ),

				),

				array(

					'value' => 'mobile',

					'label' => 'Mobile',

				),

				array(

					'value' => 'desktop',

					'label' => 'Desktop',

				),

				array(

					'value' => 'tablet',

					'label' => 'Tablet',

				),

			);

			$os_suggestions = array(

				array(

					'value' => 'ALL',

					'label' => __( 'All Operating Systems (ALL)', 'formula-rate-shipping' ),

				),

				array(

					'value' => 'Windows',

					'label' => 'Windows',

				),

				array(

					'value' => 'Windows 11',

					'label' => 'Windows 11',

				),

				array(

					'value' => 'Windows 10',

					'label' => 'Windows 10',

				),

				array(

					'value' => 'Windows 8.1',

					'label' => 'Windows 8.1',

				),

				array(

					'value' => 'Windows 8',

					'label' => 'Windows 8',

				),

				array(

					'value' => 'Windows 7',

					'label' => 'Windows 7',

				),

				array(

					'value' => 'Windows Vista',

					'label' => 'Windows Vista',

				),

				array(

					'value' => 'Windows XP',

					'label' => 'Windows XP',

				),

				array(

					'value' => 'MacOS',

					'label' => 'MacOS',

				),

				array(

					'value' => 'MacOS Sequoia',

					'label' => 'MacOS Sequoia',

				),

				array(

					'value' => 'MacOS Sonoma',

					'label' => 'MacOS Sonoma',

				),

				array(

					'value' => 'MacOS Ventura',

					'label' => 'MacOS Ventura',

				),

				array(

					'value' => 'Ventura',

					'label' => 'Ventura',

				),

				array(

					'value' => 'MacOS Monterey',

					'label' => 'MacOS Monterey',

				),

				array(

					'value' => 'Monterey',

					'label' => 'Monterey',

				),

				array(

					'value' => 'MacOS Big Sur',

					'label' => 'MacOS Big Sur',

				),

				array(

					'value' => 'Big Sur',

					'label' => 'Big Sur',

				),

				array(

					'value' => 'MacOS Catalina',

					'label' => 'MacOS Catalina',

				),

				array(

					'value' => 'Catalina',

					'label' => 'Catalina',

				),

				array(

					'value' => 'MacOS High Sierra',

					'label' => 'MacOS High Sierra',

				),

				array(

					'value' => 'ChromeOS',

					'label' => 'ChromeOS',

				),

				array(

					'value' => 'iOS',

					'label' => 'iOS',

				),

				array(

					'value' => 'Android',

					'label' => 'Android',

				),

				array(

					'value' => 'Linux',

					'label' => 'Linux',

				),

				array(

					'value' => 'Unknown',

					'label' => 'Unknown (Other / Exotic)',

				),

			);

			if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) && is_array( WC()->countries->countries ) ) {

				foreach ( WC()->countries->countries as $country_code => $country_name ) {

					$country_code = strtoupper( (string) $country_code );

					$country_suggestions[] = array(

						'value' => $country_code,

						'label' => sprintf( '%s (%s)', $country_name, $country_code ),

					);

					$country_state_suggestions[ $country_code ] = array(

						array(

							'value' => 'ALL',

							'label' => __( 'All States / Provinces (ALL)', 'formula-rate-shipping' ),

						),

					);

					$states = WC()->countries->get_states( $country_code );

					if ( is_array( $states ) ) {

						foreach ( $states as $state_code => $state_name ) {

							$country_state_suggestions[ $country_code ][] = array(

								'value' => strtoupper( (string) $state_code ),

								'label' => sprintf( '%s (%s)', $state_name, strtoupper( (string) $state_code ) ),

							);

						}

					}

				}

			}

			ob_start();

			?>

			<script type="text/javascript">

				window.formulaWpRoles = <?php echo json_encode( $wp_roles_list ); ?>;

				window.formulaCountrySuggestions = <?php echo wp_json_encode( $country_suggestions ); ?>;

				window.formulaCountryStateSuggestions = <?php echo wp_json_encode( $country_state_suggestions ); ?>;

				window.formulaBrowserSuggestions = <?php echo wp_json_encode( $browser_suggestions ); ?>;

				window.formulaDeviceSuggestions = <?php echo wp_json_encode( $device_suggestions ); ?>;

				window.formulaOsSuggestions = <?php echo wp_json_encode( $os_suggestions ); ?>;

				window.formulaImportNonce = "<?php echo esc_js( wp_create_nonce( 'formula_shipping_import_export' ) ); ?>";

			</script>

			<tr valign="top">

				<td colspan="2" style="padding:0;">

					<div class="formula-section-card card-theme-location" style="margin-top: 20px; margin-bottom: 28px;">

						<div class="card-header">

							<h3><span class="dashicons dashicons-location-alt"></span> <?php esc_html_e( '2. Location, User & Environment Rules', 'formula-rate-shipping' ); ?></h3>

							<span class="card-badge"><?php esc_html_e( 'Context Rules', 'formula-rate-shipping' ); ?></span>

						</div>

						<div class="card-summary-bar" id="formula_card_summary_location">

							<div class="card-summary-text">

								<span>Rules: <?php echo esc_html( $enabled_rules_count ); ?> of <?php echo esc_html( $total_rules_count ); ?> enabled</span>

								<span>Resolution: <?php echo esc_html( $resolution === 'merge_difference' ? 'Folded Incremental' : ( $resolution === 'first_match' ? 'First Match Override' : ( $resolution === 'sum' ? 'Sum Matches' : 'Highest Cost' ) ) ); ?></span>

							</div>

							<span class="card-toggle-icon dashicons dashicons-arrow-up-alt2"></span>

						</div>

						<div class="card-body" style="padding: 24px 28px;">

							<p class="description" style="margin-top: 0; margin-bottom: 16px; font-size: 13px; color: #475569;">

								<?php esc_html_e( 'Apply shipping and handling formulas for locations, customer details, devices, operating systems, or browsers. Evaluated in top-to-bottom priority order.', 'formula-rate-shipping' ); ?>

							</p>



							<div class="formula-shipping-table-responsive">

								<table class="widefat wc_input_table formula-shipping-rules-table" id="formula_shipping_rules_table_usa_county_rules">

									<thead>

										<tr>

											<th style="width: 55px; text-align: center;"><?php esc_html_e( 'Priority', 'formula-rate-shipping' ); ?></th>

											<th style="width: 155px;"><?php esc_html_e( 'Type', 'formula-rate-shipping' ); ?></th>

											<th><?php esc_html_e( 'Target Matching', 'formula-rate-shipping' ); ?></th>

										</tr>

									</thead>

									<tbody>

										<?php

										$index = 0;

										foreach ( $rules as $rule ) {

											$rule_type   = isset( $rule['rule_type'] ) ? $rule['rule_type'] : 'location';

											if ( 'user' === $rule_type ) {

												$rule_type = 'user_role';

											}

											$country     = isset( $rule['country'] ) ? $rule['country'] : '';

											$state       = isset( $rule['state'] ) ? $rule['state'] : '';

											$location    = isset( $rule['location'] ) ? $rule['location'] : '';

											$user        = isset( $rule['user'] ) ? $rule['user'] : '';

											$device_target = isset( $rule['device_target'] ) ? $rule['device_target'] : '';

											$os_target   = isset( $rule['os_target'] ) ? $rule['os_target'] : '';

											$user_meta_key = isset( $rule['user_meta_key'] ) ? $rule['user_meta_key'] : '';

											$user_meta_value = isset( $rule['user_meta_value'] ) ? $rule['user_meta_value'] : '';

											$enabled     = ! isset( $rule['enabled'] ) || 'no' !== $rule['enabled'];

											$ship        = isset( $rule['shipping_formula'] ) ? $rule['shipping_formula'] : '';

											$hand        = isset( $rule['handling_formula'] ) ? $rule['handling_formula'] : '';

											$bg_class    = ( $index % 2 === 0 ) ? 'formula-rule-bg-white' : 'formula-rule-bg-gray';

											$geo_display = ( 'location' === $rule_type ) ? 'block' : 'none';

											$usr_display = ( 'location' !== $rule_type && 'user_meta' !== $rule_type ) ? 'block' : 'none';

											$meta_display = ( 'user_meta' === $rule_type ) ? 'block' : 'none';

											if ( 'device_os' === $rule_type && '' === $device_target && '' === $os_target && '' !== trim( $user ) ) {

												if ( preg_match( '/^(all|mobile|desktop|tablet|phone|cell|computer|laptop|pc|ipad)$/i', trim( $user ) ) ) {

													$device_target = $user;

												} else {

													$os_target = $user;

												}

											}

											$user_title = __( 'User Role', 'formula-rate-shipping' );

											$user_placeholder = __( 'subscriber, customer', 'formula-rate-shipping' );

											if ( 'user_login' === $rule_type ) {

												$user_title = __( 'User Login', 'formula-rate-shipping' );

												$user_placeholder = __( 'username / login', 'formula-rate-shipping' );

											} elseif ( 'user_email' === $rule_type ) {

												$user_title = __( 'User Email', 'formula-rate-shipping' );

												$user_placeholder = __( 'customer@example.com', 'formula-rate-shipping' );

											} elseif ( 'browser' === $rule_type ) {

												$user_title = __( 'Browser Name', 'formula-rate-shipping' );

												$user_placeholder = __( 'Chrome, Firefox, Safari, Edge, Opera, Brave, Vivaldi, etc. (custom browser names allowed)', 'formula-rate-shipping' );

											}

											?>

											<tr data-index="<?php echo $index; ?>" class="formula-location-row-main <?php echo $bg_class; ?><?php echo $enabled ? '' : ' formula-rule-disabled'; ?>">

												<td style="text-align: center; vertical-align: middle;">

													<span class="rule-priority-badge">#<?php echo ( $index + 1 ); ?></span>

													<input type="hidden" name="<?php echo esc_attr( $field_key ); ?>[<?php echo $index; ?>][enabled]" value="no" />

													<label class="formula-rule-switch" title="<?php esc_attr_e( 'Enable this rule', 'formula-rate-shipping' ); ?>">

														<input type="checkbox" name="<?php echo esc_attr( $field_key ); ?>[<?php echo $index; ?>][enabled]" value="yes" class="formula-rule-enabled-toggle" <?php checked( $enabled ); ?> />

														<span class="formula-rule-switch-slider"></span>

													</label>

												</td>

												<td>

													<input type="hidden" name="<?php echo esc_attr( $field_key ); ?>[<?php echo $index; ?>][rule_type]" class="formula-loc-rule-type-input" value="<?php echo esc_attr( $rule_type ); ?>" />

													<select class="formula-loc-type-select">

														<option value="location" <?php selected( $rule_type, 'location' ); ?>><?php esc_html_e( 'Location', 'formula-rate-shipping' ); ?></option>

														<option value="user_role" <?php selected( $rule_type, 'user_role' ); ?>><?php esc_html_e( 'User Role', 'formula-rate-shipping' ); ?></option>

														<option value="user_login" <?php selected( $rule_type, 'user_login' ); ?>><?php esc_html_e( 'User Login', 'formula-rate-shipping' ); ?></option>

														<option value="user_email" <?php selected( $rule_type, 'user_email' ); ?>><?php esc_html_e( 'User Email', 'formula-rate-shipping' ); ?></option>

														<option value="user_meta" <?php selected( $rule_type, 'user_meta' ); ?>><?php esc_html_e( 'User Meta', 'formula-rate-shipping' ); ?></option>

														<option value="device" <?php selected( $rule_type, 'device' ); ?>><?php esc_html_e( 'Device', 'formula-rate-shipping' ); ?></option>
<option value="os" <?php selected( $rule_type, 'os' ); ?>><?php esc_html_e( 'Operating System (OS)', 'formula-rate-shipping' ); ?></option>

														<option value="browser" <?php selected( $rule_type, 'browser' ); ?>><?php esc_html_e( 'Browser', 'formula-rate-shipping' ); ?></option>

													</select>

												</td>

												<td class="formula-location-target-cell">
													<div class="loc-geo-fields" style="display: <?php echo esc_attr( $geo_display ); ?>;">
														<div class="formula-mini-target-shell formula-mini-target-shell-location">
															<?php
															$country_clean = trim( (string) $country );
															$country_clean = preg_replace( '/^[\'"]|[\'"]$/', '', $country_clean );
															$country_clean = trim( strtoupper( $country_clean ) );
															$is_single_country = ( '' !== $country_clean && 'ALL' !== $country_clean && false === strpos( $country, ',' ) && false === strpos( $country, ';' ) );
															?>
															<div class="formula-location-inline-fields formula-mini-target-grid formula-mini-target-grid-location" data-has-state="<?php echo $is_single_country ? 'true' : 'false'; ?>">
																<div class="formula-loc-col formula-loc-col-country">
																	<label class="formula-loc-col-label"><?php esc_html_e( 'Country', 'formula-rate-shipping' ); ?></label>
																	<div class="formula-loc-country-wrap is-active">
																		<input type="text" name="<?php echo esc_attr( $field_key ); ?>[<?php echo $index; ?>][country]" class="formula-loc-country-input" value="<?php echo esc_attr( $country ); ?>" title="<?php esc_attr_e( 'Country', 'formula-rate-shipping' ); ?>" placeholder="US, CA, MX or ALL" />
																	</div>
																</div>
																<div class="formula-loc-col formula-loc-col-state" style="display: <?php echo $is_single_country ? 'block' : 'none'; ?>;">
																	<label class="formula-loc-col-label"><?php esc_html_e( 'State or Province', 'formula-rate-shipping' ); ?></label>
																	<div class="formula-loc-state-wrap is-active">
																		<input type="text" name="<?php echo esc_attr( $field_key ); ?>[<?php echo $index; ?>][state]" class="formula-loc-state-input" value="<?php echo esc_attr( $state ); ?>" title="<?php esc_attr_e( 'State or Province', 'formula-rate-shipping' ); ?>" placeholder="e.g. CA, ON, NY or ALL" <?php echo $is_single_country ? '' : 'disabled'; ?> />
																	</div>
																</div>
																<div class="formula-loc-col formula-loc-col-city">
																	<label class="formula-loc-col-label"><?php esc_html_e( 'City / ZIP / Pattern', 'formula-rate-shipping' ); ?></label>
																	<div class="formula-loc-city-wrap is-active">
																		<input type="text" name="<?php echo esc_attr( $field_key ); ?>[<?php echo $index; ?>][location]" class="formula-loc-location-input" value="<?php echo esc_attr( $location ); ?>" title="<?php esc_attr_e( 'City / ZIP / Pattern', 'formula-rate-shipping' ); ?>" placeholder="City, ZIP or pattern" />
																	</div>
																</div>
															</div>
														</div>
													</div>

													<div class="loc-user-fields" style="display: <?php echo esc_attr( $usr_display ); ?>;">
														<input type="text" name="<?php echo esc_attr( $field_key ); ?>[<?php echo $index; ?>][user]" class="formula-loc-user-input<?php echo in_array( $rule_type, array( 'browser', 'device', 'os' ), true ) ? ' formula-multi-suggest-input' : ''; ?>" value="<?php echo esc_attr( $user ); ?>" title="<?php echo esc_attr( $user_title ); ?>" placeholder="<?php echo esc_attr( $user_placeholder ); ?>" />
													</div>

													<div class="loc-user-meta-fields" style="display: <?php echo esc_attr( $meta_display ); ?>;">
														<div class="formula-location-inline-fields" style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; align-items: center;">
															<input type="text" name="<?php echo esc_attr( $field_key ); ?>[<?php echo $index; ?>][user_meta_key]" value="<?php echo esc_attr( $user_meta_key ); ?>" title="<?php esc_attr_e( 'User Meta Key', 'formula-rate-shipping' ); ?>" placeholder="membership_level" />
															<input type="text" name="<?php echo esc_attr( $field_key ); ?>[<?php echo $index; ?>][user_meta_value]" value="<?php echo esc_attr( $user_meta_value ); ?>" title="<?php esc_attr_e( 'User Meta Value', 'formula-rate-shipping' ); ?>" placeholder="gold" />
														</div>
													</div>
												</td>

											</tr>

											<tr data-index="<?php echo $index; ?>" class="formula-location-row-formulas <?php echo $bg_class; ?><?php echo $enabled ? '' : ' formula-rule-disabled'; ?>">

												<td colspan="3" style="padding: 6px 16px 16px 16px; border-bottom: 2px solid #e2e8f0;">

													<div style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 16px; align-items: end;">

														<div style="display: flex; flex-direction: column;">

															<label style="font-weight: 600; font-size: 12px; color: #334155; margin-bottom: 6px; display: block; text-align: left; width: 100%;"><?php esc_html_e( 'Shipping Formula', 'formula-rate-shipping' ); ?></label>

															<input type="text" name="<?php echo esc_attr( $field_key ); ?>[<?php echo $index; ?>][shipping_formula]" value="<?php echo esc_attr( $ship ); ?>" placeholder="e.g. 15.00 or [qty] * 8" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #94a3b8 !important; background: #ffffff !important; border-radius: 6px; height: 38px; font-size: 13px; color: #0f172a;" />

														</div>

														<div style="display: flex; flex-direction: column;">

															<label style="font-weight: 600; font-size: 12px; color: #334155; margin-bottom: 6px; display: block; text-align: left; width: 100%;"><?php esc_html_e( 'Handling Formula', 'formula-rate-shipping' ); ?></label>

															<input type="text" name="<?php echo esc_attr( $field_key ); ?>[<?php echo $index; ?>][handling_formula]" value="<?php echo esc_attr( $hand ); ?>" placeholder="e.g. 2.50" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #94a3b8 !important; background: #ffffff !important; border-radius: 6px; height: 38px; font-size: 13px; color: #0f172a;" />

														</div>

														<div style="display: flex; flex-direction: column; align-items: center; min-width: 150px;">

															<label style="font-weight: 600; font-size: 12px; color: #334155; margin-bottom: 6px; display: block; text-align: center; width: 100%;"><?php esc_html_e( 'Actions', 'formula-rate-shipping' ); ?></label>

															<div class="formula-rule-action-buttons">

																<button type="button" class="button formula-shipping-move-up-rule" title="<?php esc_attr_e( 'Move Up Priority', 'formula-rate-shipping' ); ?>">&#9650;</button>

																<button type="button" class="button formula-shipping-move-down-rule" title="<?php esc_attr_e( 'Move Down Priority', 'formula-rate-shipping' ); ?>">&#9660;</button>

																<button type="button" class="button formula-shipping-duplicate-rule" title="<?php esc_attr_e( 'Duplicate Rule', 'formula-rate-shipping' ); ?>">&#128464;</button>

																<button type="button" class="button formula-shipping-delete-rule" title="<?php esc_attr_e( 'Delete Rule', 'formula-rate-shipping' ); ?>">&#10005;</button>

															</div>

														</div>

													</div>

												</td>

											</tr>

											<?php

											$index++;

										}

										?>

									</tbody>

								</table>

								<div style="margin-top: 14px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">

									<button type="button" class="button button-primary formula-shipping-add-rule-btn-generic" data-target-table="formula_shipping_rules_table_usa_county_rules"><?php esc_html_e( '+ Add Location Rule', 'formula-rate-shipping' ); ?></button>

									<div id="formula_location_resolution_controls" style="display: flex; align-items: center; gap: 10px;">

										<label for="<?php echo esc_attr( $this->get_field_key( 'usa_location_rule_resolution' ) ); ?>" style="font-weight: 600; font-size: 12px; color: #334155; white-space: nowrap;"><?php esc_html_e( 'Resolution Logic:', 'formula-rate-shipping' ); ?></label>

										<select name="<?php echo esc_attr( $this->get_field_key( 'usa_location_rule_resolution' ) ); ?>" id="<?php echo esc_attr( $this->get_field_key( 'usa_location_rule_resolution' ) ); ?>" style="height: 34px; font-size: 12px;">

											<option value="first_match" <?php selected( $resolution, 'first_match' ); ?>><?php esc_html_e( 'Override / Replace Base (First Match)', 'formula-rate-shipping' ); ?></option>

											<option value="merge_difference" <?php selected( $resolution, 'merge_difference' ); ?>><?php esc_html_e( 'Folded Incremental (Base + Additional)', 'formula-rate-shipping' ); ?></option>

											<option value="sum" <?php selected( $resolution, 'sum' ); ?>><?php esc_html_e( 'Sum All Matches', 'formula-rate-shipping' ); ?></option>

											<option value="max" <?php selected( $resolution, 'max' ); ?>><?php esc_html_e( 'Highest Cost Only', 'formula-rate-shipping' ); ?></option>

										</select>

									</div>

								</div>

								<div id="formula_location_resolution_help_box" style="margin-top: 12px; background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 6px; padding: 10px 14px; font-size: 12px; color: #0369a1; display: flex; align-items: flex-start; gap: 8px;">

									<span class="dashicons dashicons-info" style="font-size: 16px; margin-top: 2px; color: #0284c7; flex-shrink: 0;"></span>

									<div id="formula_location_resolution_help_text" style="line-height: 1.5;"></div>

								</div>

							</div>

						</div>

					</div>

					<script type="text/javascript">

						(function($) {

							var tableId = 'formula_shipping_rules_table_usa_county_rules';

							var table = $('#' + tableId + ' tbody');

							var fieldKey = '<?php echo esc_js( $field_key ); ?>';



							function normalizeLocRuleType(ruleType) {

								if (!ruleType || ruleType === 'user') {

									return 'user_role';

								}

								return ruleType;

							}



							function getLocUserFieldConfig(ruleType) {

								ruleType = normalizeLocRuleType(ruleType);

								if (ruleType === 'user_login') {

									return {

										title: '<?php echo esc_js( __( 'User Login', 'formula-rate-shipping' ) ); ?>',

										placeholder: '<?php echo esc_js( __( 'username / login', 'formula-rate-shipping' ) ); ?>'

									};

								}

								if (ruleType === 'user_email') {

									return {

										title: '<?php echo esc_js( __( 'User Email', 'formula-rate-shipping' ) ); ?>',

										placeholder: '<?php echo esc_js( __( 'customer@example.com', 'formula-rate-shipping' ) ); ?>'

									};

								}

								if (ruleType === 'browser') {

									return {

										title: '<?php echo esc_js( __( 'Browser Name', 'formula-rate-shipping' ) ); ?>',

										placeholder: '<?php echo esc_js( __( 'Chrome, Firefox, Safari, Edge, Brave, Floorp, or any browser name', 'formula-rate-shipping' ) ); ?>'

									};

								}

								if (ruleType === 'device') {

									return {

										title: '<?php echo esc_js( __( 'Device Type', 'formula-rate-shipping' ) ); ?>',

										placeholder: '<?php echo esc_js( __( 'Mobile, Desktop, Tablet or ALL', 'formula-rate-shipping' ) ); ?>'

									};

								}

								if (ruleType === 'os') {

									return {

										title: '<?php echo esc_js( __( 'Operating System', 'formula-rate-shipping' ) ); ?>',

										placeholder: '<?php echo esc_js( __( 'Windows, Linux, MacOS, iOS, Android, or any OS name', 'formula-rate-shipping' ) ); ?>'

									};

								}

								return {

									title: '<?php echo esc_js( __( 'User Role', 'formula-rate-shipping' ) ); ?>',

									placeholder: '<?php echo esc_js( __( 'subscriber, customer', 'formula-rate-shipping' ) ); ?>'

								};

							}



							function buildLocUserTargetField(index, ruleType, value, deviceValue, osValue) {

								var config = getLocUserFieldConfig(ruleType);

								if (ruleType === 'device_os') {

									return '<div class="formula-mini-target-shell formula-mini-target-shell-environment">' +

										'<div class="formula-mini-target-tabs">' +

											'<span class="formula-mini-target-tab is-active"><?php echo esc_js( __( 'Device', 'formula-rate-shipping' ) ); ?></span>' +

											'<span class="formula-mini-target-tab is-active"><?php echo esc_js( __( 'OS', 'formula-rate-shipping' ) ); ?></span>' +

										'</div>' +

										'<div class="formula-mini-target-grid formula-mini-target-grid-environment">' +

											'<input type="text" name="' + fieldKey + '[' + index + '][device_target]" class="formula-loc-device-input formula-multi-suggest-input" value="' + $('<div>').text(deviceValue || '').html() + '" title="<?php echo esc_js( __( 'Device', 'formula-rate-shipping' ) ); ?>" placeholder="mobile, desktop, tablet or ALL" />' +

											'<input type="text" name="' + fieldKey + '[' + index + '][os_target]" class="formula-loc-os-input formula-multi-suggest-input" value="' + $('<div>').text(osValue || '').html() + '" title="<?php echo esc_js( __( 'Operating System', 'formula-rate-shipping' ) ); ?>" placeholder="Win10, Win11, Ventura, iOS 17, Android 14, Linux" />' +

										'</div>' +

									'</div>';

								}

								return '<input type="text" name="' + fieldKey + '[' + index + '][user]" class="formula-loc-user-input' + (ruleType === 'browser' ? ' formula-multi-suggest-input' : '') + '" value="' + $('<div>').text(value || '').html() + '" title="' + config.title + '" placeholder="' + config.placeholder + '" />';

							}



							function updateLocRuleTargetVisibility(mainRow) {

								var selectedType = normalizeLocRuleType(mainRow.find('.formula-loc-type-select').val());

								var currentValue = mainRow.find('.formula-loc-user-input').val() || '';

								var currentDeviceValue = mainRow.find('.formula-loc-device-input').val() || '';

								var currentOsValue = mainRow.find('.formula-loc-os-input').val() || '';

								if (currentDeviceValue) {

									mainRow.data('formula-device-target', currentDeviceValue);

								}

								if (currentOsValue) {

									mainRow.data('formula-os-target', currentOsValue);

								}

								if (selectedType === 'device_os') {

									currentDeviceValue = currentDeviceValue || mainRow.data('formula-device-target') || '';

									currentOsValue = currentOsValue || mainRow.data('formula-os-target') || '';

								}

								var rowIndex = mainRow.attr('data-index') || 0;

								mainRow.find('.formula-loc-rule-type-input').val(selectedType);

								mainRow.find('.loc-geo-fields').toggle(selectedType === 'location');

								mainRow.find('.loc-user-fields').toggle(selectedType !== 'location' && selectedType !== 'user_meta');

								mainRow.find('.loc-user-meta-fields').toggle(selectedType === 'user_meta');

								mainRow.find('.loc-user-fields').html(buildLocUserTargetField(rowIndex, selectedType, currentValue, currentDeviceValue, currentOsValue));

								if (selectedType !== 'device_os') {

									var userConfig = getLocUserFieldConfig(selectedType);

									mainRow.find('.formula-loc-user-input').attr('title', userConfig.title).attr('placeholder', userConfig.placeholder);

								}

								updateLocStateVisibility(mainRow);

								if (window.formulaEnsureChipEditors) {

									window.formulaEnsureChipEditors();

								}

							}



							function checkIsSingleCountryVal(val) {
								if (!val || typeof val !== 'string') return false;
								var raw = val.trim();
								var clean = raw.replace(/^['"]|['"]$/g, '').trim().toUpperCase();
								if (!clean || clean === 'ALL') return false;
								return (raw.indexOf(',') === -1 && raw.indexOf(';') === -1);
							}

							function updateLocStateVisibility(mainRow) {
								if (!mainRow || !mainRow.length) return;
								var countryInput = mainRow.find('.formula-loc-country-input');
								if (!countryInput.length) return;
								var countryVal = String(countryInput.val() || '').trim();
								var stateInput = mainRow.find('.formula-loc-state-input');
								var grid       = mainRow.find('.formula-mini-target-grid-location');
								var isSingleCountry = checkIsSingleCountryVal(countryVal);

								if (isSingleCountry) {
									stateInput.prop('disabled', false).attr('placeholder', 'e.g. CA, ON, NY or ALL');
									grid.attr('data-has-state', 'true');
								} else {
									stateInput.prop('disabled', true).attr('placeholder', 'To Set State or Province Only One Country Must Be Selected');
									grid.attr('data-has-state', 'false');
								}
							}



							$(document.body).off('change input', '.formula-loc-country-input').on('change input', '.formula-loc-country-input', function() {

								var mainRow = $(this).closest('tr.formula-location-row-main');

								updateLocStateVisibility(mainRow);

							});



							function updateLocationResolutionHelpText() {

								var val = $('#<?php echo esc_js( $this->get_field_key( "usa_location_rule_resolution" ) ); ?>').val();

								var helpHtml = '';

								if (val === 'first_match') {

									helpHtml = '<strong>Override / Replace Base:</strong> The first matching location or user rule replaces the running shipping and handling amounts.';

								} else if (val === 'merge_difference') {

									helpHtml = '<strong>Folded Incremental:</strong> Matching location or user rules add their evaluated amounts on top of the item-rule result.';

								} else if (val === 'sum') {

									helpHtml = '<strong>Sum All Matches:</strong> Every matching location or user rule is added to the running totals.';

								} else if (val === 'max') {

									helpHtml = '<strong>Highest Cost Only:</strong> Only the single highest-cost matching location or user rule is applied.';

								}

								$('#formula_location_resolution_help_text').html(helpHtml);

							}



							function updateLocationResolutionVisibility() {

								var hasRules = table.find('tr.formula-location-row-main').length > 0;

								$('#formula_location_resolution_controls, #formula_location_resolution_help_box').toggle(hasRules);

							}



							function syncLocationRuleEnabledState(mainRow) {

								var formulaRow = mainRow.next('tr.formula-location-row-formulas');

								var isEnabled = mainRow.find('.formula-rule-enabled-toggle').is(':checked');

								mainRow.toggleClass('formula-rule-disabled', !isEnabled);

								formulaRow.toggleClass('formula-rule-disabled', !isEnabled);

							}



							function reindexLocationRulesTable() {

								var mainRows = table.find('tr.formula-location-row-main');

								mainRows.each(function(idx) {

									var mainRow = $(this);

									var formulaRow = mainRow.next('tr.formula-location-row-formulas');

									var bgClass = (idx % 2 === 0) ? 'formula-rule-bg-white' : 'formula-rule-bg-gray';



									mainRow.attr('data-index', idx).removeClass('formula-rule-bg-white formula-rule-bg-gray').addClass(bgClass);

									mainRow.find('.rule-priority-badge').text('#' + (idx + 1));

									mainRow.find('input, select').each(function() {

										var name = $(this).attr('name');

										if (name) {

											$(this).attr('name', name.replace(/\[\d+\]/, '[' + idx + ']'));

										}

									});

									if (formulaRow.length) {

										formulaRow.attr('data-index', idx).removeClass('formula-rule-bg-white formula-rule-bg-gray').addClass(bgClass);

										formulaRow.find('input').each(function() {

											var name = $(this).attr('name');

											if (name) {

												$(this).attr('name', name.replace(/\[\d+\]/, '[' + idx + ']'));

											}

										});

									}

									syncLocationRuleEnabledState(mainRow);

								});

								updateLocationResolutionVisibility();

							}



							function buildLocMainRow(idx, ruleType, country, state, locPat, user, metaKey, metaValue, isEnabled, bgClass, deviceTarget, osTarget) {
	ruleType = normalizeLocRuleType(ruleType || 'location');
	isEnabled = (isEnabled !== false);
	country = country || '';
	state = state || '';
	var countryRaw = (country || '').trim();
	var countryClean = countryRaw.replace(/^['"]|['"]$/g, '').trim().toUpperCase();
	var isSingleCountry = (countryClean !== '' && countryClean !== 'ALL' && countryRaw.indexOf(',') === -1 && countryRaw.indexOf(';') === -1);

	var geoDisplay  = (ruleType === 'location') ? 'block' : 'none';
	var userDisplay = (ruleType !== 'location' && ruleType !== 'user_meta') ? 'block' : 'none';
	var metaDisplay = (ruleType === 'user_meta') ? 'block' : 'none';
	var userConfig = getLocUserFieldConfig(ruleType);

	return '<tr data-index="' + idx + '" class="formula-location-row-main ' + bgClass + (isEnabled ? '' : ' formula-rule-disabled') + '">' +
		'<td style="text-align:center;vertical-align:bottom;padding-bottom:12px;"><span class="rule-priority-badge">#' + (idx + 1) + '</span><input type="hidden" name="' + fieldKey + '[' + idx + '][enabled]" value="no" /><label class="formula-rule-switch" title="<?php echo esc_js( __( 'Enable this rule', 'formula-rate-shipping' ) ); ?>"><input type="checkbox" name="' + fieldKey + '[' + idx + '][enabled]" value="yes" class="formula-rule-enabled-toggle"' + (isEnabled ? ' checked' : '') + ' /><span class="formula-rule-switch-slider"></span></label></td>' +
		'<td style="vertical-align:bottom;padding-bottom:12px;">' +
			'<input type="hidden" name="' + fieldKey + '[' + idx + '][rule_type]" class="formula-loc-rule-type-input" value="' + ruleType + '" />' +
			'<select class="formula-loc-type-select" style="vertical-align:bottom;margin-bottom:0;">' +
				'<option value="location"' + (ruleType === 'location' ? ' selected' : '') + '><?php echo esc_js( __( 'Location', 'formula-rate-shipping' ) ); ?></option>' +
				'<option value="user_role"' + (ruleType === 'user_role' ? ' selected' : '') + '><?php echo esc_js( __( 'User Role', 'formula-rate-shipping' ) ); ?></option>' +
				'<option value="user_login"' + (ruleType === 'user_login' ? ' selected' : '') + '><?php echo esc_js( __( 'User Login', 'formula-rate-shipping' ) ); ?></option>' +
				'<option value="user_email"' + (ruleType === 'user_email' ? ' selected' : '') + '><?php echo esc_js( __( 'User Email', 'formula-rate-shipping' ) ); ?></option>' +
				'<option value="user_meta"' + (ruleType === 'user_meta' ? ' selected' : '') + '><?php echo esc_js( __( 'User Meta', 'formula-rate-shipping' ) ); ?></option>' +
				'<option value="device"' + (ruleType === 'device' ? ' selected' : '') + '><?php echo esc_js( __( 'Device', 'formula-rate-shipping' ) ); ?></option>' +
				'<option value="os"' + (ruleType === 'os' ? ' selected' : '') + '><?php echo esc_js( __( 'Operating System (OS)', 'formula-rate-shipping' ) ); ?></option>' +
				'<option value="browser"' + (ruleType === 'browser' ? ' selected' : '') + '><?php echo esc_js( __( 'Browser', 'formula-rate-shipping' ) ); ?></option>' +
			'</select>' +
		'</td>' +
		'<td class="formula-location-target-cell" style="vertical-align:bottom;padding-bottom:12px;">' +
			'<div class="loc-geo-fields" style="display:' + geoDisplay + ';">' +
				'<div class="formula-mini-target-shell formula-mini-target-shell-location">' +
					'<div class="formula-location-inline-fields formula-mini-target-grid formula-mini-target-grid-location" data-has-state="' + (isSingleCountry ? 'true' : 'false') + '">' +
						'<div class="formula-loc-col formula-loc-col-country">' +
							'<label class="formula-loc-col-label"><?php echo esc_js( __( 'Country', 'formula-rate-shipping' ) ); ?></label>' +
							'<div class="formula-loc-country-wrap is-active"><input type="text" name="' + fieldKey + '[' + idx + '][country]" class="formula-loc-country-input" value="' + $('<div>').text(country).html() + '" title="<?php echo esc_js( __( 'Country', 'formula-rate-shipping' ) ); ?>" placeholder="US, CA, MX or ALL" /></div>' +
						'</div>' +
						'<div class="formula-loc-col formula-loc-col-state" style="display:' + (isSingleCountry ? 'block' : 'none') + ';">' +
							'<label class="formula-loc-col-label"><?php echo esc_js( __( 'State or Province', 'formula-rate-shipping' ) ); ?></label>' +
							'<div class="formula-loc-state-wrap is-active"><input type="text" name="' + fieldKey + '[' + idx + '][state]" class="formula-loc-state-input" value="' + $('<div>').text(state).html() + '" title="<?php echo esc_js( __( 'State or Province', 'formula-rate-shipping' ) ); ?>" placeholder="e.g. CA, ON, NY or ALL"' + (isSingleCountry ? '' : ' disabled') + ' /></div>' +
						'</div>' +
						'<div class="formula-loc-col formula-loc-col-city">' +
							'<label class="formula-loc-col-label"><?php echo esc_js( __( 'City / ZIP / Pattern', 'formula-rate-shipping' ) ); ?></label>' +
							'<div class="formula-loc-city-wrap is-active"><input type="text" name="' + fieldKey + '[' + idx + '][location]" class="formula-loc-location-input" value="' + $('<div>').text(locPat).html() + '" title="<?php echo esc_js( __( 'City / ZIP / Pattern', 'formula-rate-shipping' ) ); ?>" placeholder="<?php echo esc_js( __( 'City, ZIP or pattern', 'formula-rate-shipping' ) ); ?>" /></div>' +
						'</div>' +
					'</div>' +
				'</div>' +
			'</div>' +
			'<div class="loc-user-fields" style="display:' + userDisplay + ';">' +
				buildLocUserTargetField(idx, ruleType, user, deviceTarget, osTarget) +
			'</div>' +
			'<div class="loc-user-meta-fields" style="display:' + metaDisplay + ';">' +
				'<div class="formula-location-inline-fields" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;align-items:center;">' +
					'<input type="text" name="' + fieldKey + '[' + idx + '][user_meta_key]" value="' + $('<div>').text(metaKey || '').html() + '" title="<?php echo esc_js( __( 'User Meta Key', 'formula-rate-shipping' ) ); ?>" placeholder="membership_level" />' +
					'<input type="text" name="' + fieldKey + '[' + idx + '][user_meta_value]" value="' + $('<div>').text(metaValue || '').html() + '" title="<?php echo esc_js( __( 'User Meta Value', 'formula-rate-shipping' ) ); ?>" placeholder="gold" />' +
				'</div>' +
			'</div>' +
		'</td>' +
	'</tr>';
}

function buildLocFormulaRow(idx, ship, hand, isEnabled, bgClass) {

								return '<tr data-index="' + idx + '" class="formula-location-row-formulas ' + bgClass + (isEnabled === false ? ' formula-rule-disabled' : '') + '">' +

									'<td colspan="3" style="padding:6px 16px 16px 16px;border-bottom:2px solid #e2e8f0;">' +

										'<div style="display:grid;grid-template-columns:1fr 1fr auto;gap:16px;align-items:end;">' +

											'<div style="display:flex;flex-direction:column;">' +

												'<label style="font-weight:600;font-size:12px;color:#334155;margin-bottom:6px;display:block;text-align:left;width:100%;"><?php echo esc_js( __( 'Shipping Formula', 'formula-rate-shipping' ) ); ?></label>' +

												'<input type="text" name="' + fieldKey + '[' + idx + '][shipping_formula]" value="' + $('<div>').text(ship).html() + '" placeholder="e.g. 15.00 or [qty] * 8" style="width:100%;box-sizing:border-box;padding:8px 12px;border:1px solid #94a3b8 !important;background:#ffffff !important;border-radius:6px;height:38px;font-size:13px;color:#0f172a;" />' +

											'</div>' +

											'<div style="display:flex;flex-direction:column;">' +

												'<label style="font-weight:600;font-size:12px;color:#334155;margin-bottom:6px;display:block;text-align:left;width:100%;"><?php echo esc_js( __( 'Handling Formula', 'formula-rate-shipping' ) ); ?></label>' +

												'<input type="text" name="' + fieldKey + '[' + idx + '][handling_formula]" value="' + $('<div>').text(hand).html() + '" placeholder="e.g. 2.50" style="width:100%;box-sizing:border-box;padding:8px 12px;border:1px solid #94a3b8 !important;background:#ffffff !important;border-radius:6px;height:38px;font-size:13px;color:#0f172a;" />' +

											'</div>' +

											'<div style="display:flex;flex-direction:column;align-items:center;min-width:150px;">' +

												'<label style="font-weight:600;font-size:12px;color:#334155;margin-bottom:6px;display:block;text-align:center;width:100%;"><?php echo esc_js( __( 'Actions', 'formula-rate-shipping' ) ); ?></label>' +

												'<div class="formula-rule-action-buttons">' +

													'<button type="button" class="button formula-shipping-move-up-rule" title="Move Up Priority">&#9650;</button> ' +

													'<button type="button" class="button formula-shipping-move-down-rule" title="Move Down Priority">&#9660;</button> ' +

													'<button type="button" class="button formula-shipping-duplicate-rule" title="Duplicate Rule">&#128464;</button> ' +

													'<button type="button" class="button formula-shipping-delete-rule" title="Delete Rule">&#10005;</button>' +

												'</div>' +

											'</div>' +

										'</div>' +

									'</td>' +

								'</tr>';

							}



							$(document).off('change', '.formula-loc-type-select').on('change', '.formula-loc-type-select', function() {

								var mainRow = $(this).closest('tr.formula-location-row-main');

								updateLocRuleTargetVisibility(mainRow);

							});



							$(document).off('change', '.formula-location-row-main .formula-rule-enabled-toggle').on('change', '.formula-location-row-main .formula-rule-enabled-toggle', function() {

								syncLocationRuleEnabledState($(this).closest('tr.formula-location-row-main'));

							});



							$(document).off('change', '#<?php echo esc_js( $this->get_field_key( "usa_location_rule_resolution" ) ); ?>').on('change', '#<?php echo esc_js( $this->get_field_key( "usa_location_rule_resolution" ) ); ?>', updateLocationResolutionHelpText);



							$(document).off('click', '.formula-shipping-add-rule-btn-generic[data-target-table="' + tableId + '"]').on('click', '.formula-shipping-add-rule-btn-generic[data-target-table="' + tableId + '"]', function(e) {

								e.preventDefault();

								var index = table.find('tr.formula-location-row-main').length;

								var bgClass = (index % 2 === 0) ? 'formula-rule-bg-white' : 'formula-rule-bg-gray';

								var newMainRow = $(buildLocMainRow(index, 'location', '', '', '', '', '', '', true, bgClass, '', ''));

								var newFormulaRow = $(buildLocFormulaRow(index, '', '', true, bgClass));

								table.append(newMainRow).append(newFormulaRow);

								updateLocStateVisibility(newMainRow);

								reindexLocationRulesTable();

								if (window.formulaEnsureChipEditors) {

									window.formulaEnsureChipEditors();

								}

							});



							$(document).off('click', '#' + tableId + ' .formula-shipping-move-up-rule').on('click', '#' + tableId + ' .formula-shipping-move-up-rule', function(e) {

								e.preventDefault();

								var formulaRow = $(this).closest('tr.formula-location-row-formulas');

								var mainRow = formulaRow.prev('tr.formula-location-row-main');

								var prevFormula = mainRow.prev('tr.formula-location-row-formulas');

								var prevMain = prevFormula.prev('tr.formula-location-row-main');

								if (prevMain.length > 0) {

									mainRow.insertBefore(prevMain);

									formulaRow.insertAfter(mainRow);

									reindexLocationRulesTable();

								}

							});



							$(document).off('click', '#' + tableId + ' .formula-shipping-move-down-rule').on('click', '#' + tableId + ' .formula-shipping-move-down-rule', function(e) {

								e.preventDefault();

								var formulaRow = $(this).closest('tr.formula-location-row-formulas');

								var mainRow = formulaRow.prev('tr.formula-location-row-main');

								var nextMain = formulaRow.next('tr.formula-location-row-main');

								if (nextMain.length > 0) {

									var nextFormula = nextMain.next('tr.formula-location-row-formulas');

									formulaRow.insertAfter(nextFormula);

									mainRow.insertBefore(formulaRow);

									reindexLocationRulesTable();

								}

							});



							$(document).off('click', '#' + tableId + ' .formula-shipping-duplicate-rule').on('click', '#' + tableId + ' .formula-shipping-duplicate-rule', function(e) {

								e.preventDefault();

								var formulaRow = $(this).closest('tr.formula-location-row-formulas');

								var mainRow    = formulaRow.prev('tr.formula-location-row-main');

								var ruleType   = mainRow.find('.formula-loc-rule-type-input').val() || 'location';

								var country    = mainRow.find('input[name*="[country]"]').val() || 'ALL';

								var state      = mainRow.find('input[name*="[state]"]').val() || 'ALL';

								var locPat     = mainRow.find('input[name*="[location]"]').val() || '';

								var user       = mainRow.find('input[name*="[user]"]').val() || '';

								var deviceTarget = mainRow.find('input[name*="[device_target]"]').val() || '';

								var osTarget   = mainRow.find('input[name*="[os_target]"]').val() || '';

								var metaKey    = mainRow.find('input[name*="[user_meta_key]"]').val() || '';

								var metaValue  = mainRow.find('input[name*="[user_meta_value]"]').val() || '';

								var enabled    = mainRow.find('.formula-rule-enabled-toggle').is(':checked');

								var shipF      = formulaRow.length ? formulaRow.find('input[name*="[shipping_formula]"]').val() || '' : '';

								var handF      = formulaRow.length ? formulaRow.find('input[name*="[handling_formula]"]').val() || '' : '';

								var dIdx       = 999;

								var newMain    = $(buildLocMainRow(dIdx, ruleType, country, state, locPat, user, metaKey, metaValue, enabled, 'formula-rule-bg-white', deviceTarget, osTarget));

								var newFormula = $(buildLocFormulaRow(dIdx, shipF, handF, enabled, 'formula-rule-bg-white'));

								var insertAfter = formulaRow.length ? formulaRow : mainRow;

								newMain.insertAfter(insertAfter);

								newFormula.insertAfter(newMain);

								reindexLocationRulesTable();

								if (window.formulaEnsureChipEditors) {

									window.formulaEnsureChipEditors();

								}

							});



							$(document).off('click', '#' + tableId + ' .formula-shipping-delete-rule').on('click', '#' + tableId + ' .formula-shipping-delete-rule', function(e) {

								e.preventDefault();

								var formulaRow = $(this).closest('tr.formula-location-row-formulas');

								var mainRow    = formulaRow.prev('tr.formula-location-row-main');

								mainRow.remove();

								formulaRow.remove();

								reindexLocationRulesTable();

							});



							updateLocationResolutionHelpText();

							updateLocationResolutionVisibility();

							reindexLocationRulesTable();

							table.find('tr.formula-location-row-main').each(function() {

								updateLocRuleTargetVisibility($(this));

							});

						})(jQuery);

					</script>

				</td>

			</tr>

			<?php

			return ob_get_clean();

		}



		/**

		 * Generate HTML for Card 3: Item-Specific Rules & Priority Order. (No redundant 'Default' option in dropdown)

		 */

		public function generate_item_rules_card_html( $key, $data ) {

			$field_key  = $this->get_field_key( 'usa_item_rules' );

			$resolution = $this->get_option( 'usa_rule_resolution', 'first_match' );

			$rules      = $this->get_option( 'usa_item_rules', array() );



			if ( ! is_array( $rules ) || empty( $rules ) ) {

				$rules = $this->usa_item_rules;

			}



			$categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );

			$tags       = get_terms( array( 'taxonomy' => 'product_tag', 'hide_empty' => false ) );



			$category_options = array();

			if ( ! is_wp_error( $categories ) && ! empty( $categories ) ) {

				foreach ( $categories as $cat ) {

					$category_options[ $cat->slug ] = $cat->name;

				}

			}



			$tag_options = array();

			if ( ! is_wp_error( $tags ) && ! empty( $tags ) ) {

				foreach ( $tags as $tag ) {

					$tag_options[ $tag->slug ] = $tag->name;

				}

			}



			foreach ( $rules as $r ) {

				$r_type   = isset( $r['type'] ) ? $r['type'] : 'category';

				$r_target = isset( $r['target'] ) ? $r['target'] : '';

				if ( ! empty( $r_target ) ) {

					if ( 'category' === $r_type && ! isset( $category_options[ $r_target ] ) ) {

						$category_options[ $r_target ] = ucfirst( str_replace( array( '-', '_' ), ' ', $r_target ) );

					} elseif ( 'tag' === $r_type && ! isset( $tag_options[ $r_target ] ) ) {

						$tag_options[ $r_target ] = ucfirst( str_replace( array( '-', '_' ), ' ', $r_target ) );

					}

				}

			}



			$enabled_item_rules_count = 0;

			$total_item_rules_count = 0;

			if ( is_array( $rules ) ) {

				foreach ( $rules as $r ) {

					if ( 'default' === ( isset( $r['type'] ) ? $r['type'] : '' ) ) {

						continue;

					}

					$total_item_rules_count++;

					if ( ! isset( $r['enabled'] ) || 'no' !== $r['enabled'] ) {

						$enabled_item_rules_count++;

					}

				}

			}



			ob_start();

			?>

			<tr valign="top">

				<td colspan="2" style="padding: 0; margin-bottom: 28px;">

					<div class="formula-section-card card-theme-item-rules">

						<div class="card-header">

							<h3><span class="dashicons dashicons-category"></span> <?php esc_html_e( '3. Item-Specific Category / Tag / Variation Rules', 'formula-rate-shipping' ); ?></h3>

							<span class="card-badge"><?php esc_html_e( 'Rule Matching', 'formula-rate-shipping' ); ?></span>

						</div>

						<div class="card-summary-bar" id="formula_card_summary_item">

							<div class="card-summary-text">

								<span>Rules: <?php echo esc_html( $enabled_item_rules_count ); ?> of <?php echo esc_html( $total_item_rules_count ); ?> enabled</span>

								<span>Resolution: <?php echo esc_html( $resolution === 'merge_difference' ? 'Folded Incremental' : ( $resolution === 'first_match' ? 'Priority Order' : ( $resolution === 'sum' ? 'Sum Matches' : 'Highest Cost' ) ) ); ?></span>

							</div>

							<span class="card-toggle-icon dashicons dashicons-arrow-up-alt2"></span>

						</div>

						<div class="card-body" style="padding: 24px 28px;">

							<p class="description" style="margin-top: 0; margin-bottom: 16px; font-size: 13px; color: #475569;"><?php echo esc_html__( 'Map formulas to specific categories, tags, or variations. Use the ▲ Up and ▼ Down buttons to reorder priority.', 'formula-rate-shipping' ); ?></p>

							

							<div class="formula-shipping-rules-container" data-key="usa_item_rules">

								<table class="widefat wc_input_table formula-shipping-rules-table" id="formula_shipping_rules_table_usa_item_rules">

									<thead>

										<tr>

											<th style="width: 65px; text-align: center;"><?php esc_html_e( 'Priority', 'formula-rate-shipping' ); ?></th>

											<th style="width: 245px;"><?php esc_html_e( 'Type', 'formula-rate-shipping' ); ?></th>

											<th><?php esc_html_e( 'Selection / Value *', 'formula-rate-shipping' ); ?></th>

											<th style="width: 130px; text-align: center;"><?php esc_html_e( 'Actions', 'formula-rate-shipping' ); ?></th>

										</tr>

									</thead>

									<tbody>

										<?php

										$index = 0;

										foreach ( $rules as $rule ) {

											$type   = isset( $rule['type'] ) ? $rule['type'] : 'category';

											if ( 'default' === $type ) {

												continue; // Omit legacy default rows in table since card 1 handles defaults

											}

											$target = isset( $rule['target'] ) ? $rule['target'] : '';

											$enabled = ! isset( $rule['enabled'] ) || 'no' !== $rule['enabled'];

											$ship   = isset( $rule['shipping_formula'] ) ? $rule['shipping_formula'] : '';

											$hand   = isset( $rule['handling_formula'] ) ? $rule['handling_formula'] : '';

											$bg_class = ( $index % 2 === 0 ) ? 'formula-rule-bg-white' : 'formula-rule-bg-gray';

											?>

											<tr data-index="<?php echo $index; ?>" class="formula-rule-row-main <?php echo $bg_class; ?><?php echo $enabled ? '' : ' formula-rule-disabled'; ?>">

												<td style="text-align: center; vertical-align: middle;">

													<span class="rule-priority-badge">#<?php echo ( $index + 1 ); ?></span>

													<input type="hidden" name="<?php echo esc_attr( $field_key ); ?>[<?php echo $index; ?>][enabled]" value="no" />

													<label class="formula-rule-switch" title="<?php esc_attr_e( 'Enable this rule', 'formula-rate-shipping' ); ?>">

														<input type="checkbox" name="<?php echo esc_attr( $field_key ); ?>[<?php echo $index; ?>][enabled]" value="yes" class="formula-rule-enabled-toggle" <?php checked( $enabled ); ?> />

														<span class="formula-rule-switch-slider"></span>

													</label>

												</td>

												<td>

													<select name="<?php echo esc_attr( $field_key ); ?>[<?php echo $index; ?>][type]" class="formula-shipping-type-select">

														<option value="category" <?php selected( $type, 'category' ); ?>><?php esc_html_e( 'Category', 'formula-rate-shipping' ); ?></option>

														<option value="tag" <?php selected( $type, 'tag' ); ?>><?php esc_html_e( 'Tag', 'formula-rate-shipping' ); ?></option>

														<option value="variation" <?php selected( $type, 'variation' ); ?>><?php esc_html_e( 'Variation SKU / Attribute', 'formula-rate-shipping' ); ?></option>

													</select>

												</td>

												<td class="formula-shipping-target-cell">

													<?php if ( 'category' === $type ) : ?>

														<select name="<?php echo esc_attr( $field_key ); ?>[<?php echo $index; ?>][target]">

															<?php foreach ( $category_options as $slug => $name ) : ?>

																<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $target, $slug ); ?>><?php echo esc_html( $name ); ?></option>

															<?php endforeach; ?>

														</select>

													<?php elseif ( 'tag' === $type ) : ?>

														<select name="<?php echo esc_attr( $field_key ); ?>[<?php echo $index; ?>][target]">

															<?php foreach ( $tag_options as $slug => $name ) : ?>

																<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $target, $slug ); ?>><?php echo esc_html( $name ); ?></option>

															<?php endforeach; ?>

														</select>

													<?php else : ?>

														<input type="text" name="<?php echo esc_attr( $field_key ); ?>[<?php echo $index; ?>][target]" value="<?php echo esc_attr( $target ); ?>" placeholder="e.g. Hardcover, BLUE-101, 201" />

													<?php endif; ?>

												</td>

												<td style="text-align: center; white-space: nowrap;">

													<div class="formula-rule-action-buttons">

														<button type="button" class="button formula-shipping-move-up-rule" title="<?php esc_attr_e( 'Move Up Priority', 'formula-rate-shipping' ); ?>">&#9650;</button>

														<button type="button" class="button formula-shipping-move-down-rule" title="<?php esc_attr_e( 'Move Down Priority', 'formula-rate-shipping' ); ?>">&#9660;</button>

														<button type="button" class="button formula-shipping-duplicate-rule" title="<?php esc_attr_e( 'Duplicate Rule', 'formula-rate-shipping' ); ?>">&#128464;</button>

														<button type="button" class="button formula-shipping-delete-rule" title="<?php esc_attr_e( 'Delete Rule', 'formula-rate-shipping' ); ?>">&#10005;</button>

													</div>

												</td>

											</tr>

											<tr data-index="<?php echo $index; ?>" class="formula-rule-row-formulas <?php echo $bg_class; ?><?php echo $enabled ? '' : ' formula-rule-disabled'; ?>">

												<td colspan="4" style="padding: 6px 16px 16px 16px; border-bottom: 2px solid #e2e8f0;">

													<div class="formula-rule-formulas-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; text-align: left;">

														<div class="formula-rule-formula-field" style="display: flex; flex-direction: column; text-align: left;">

															<label style="font-weight: 600; font-size: 12px; color: #334155; margin-bottom: 6px; text-align: left; display: block;"><?php esc_html_e( 'Shipping Formula', 'formula-rate-shipping' ); ?></label>

															<input type="text" name="<?php echo esc_attr( $field_key ); ?>[<?php echo $index; ?>][shipping_formula]" value="<?php echo esc_attr( $ship ); ?>" placeholder="e.g. [qty] * 5.00" />

														</div>

														<div class="formula-rule-formula-field" style="display: flex; flex-direction: column; text-align: left;">

															<label style="font-weight: 600; font-size: 12px; color: #334155; margin-bottom: 6px; text-align: left; display: block;"><?php esc_html_e( 'Handling Formula', 'formula-rate-shipping' ); ?></label>

															<input type="text" name="<?php echo esc_attr( $field_key ); ?>[<?php echo $index; ?>][handling_formula]" value="<?php echo esc_attr( $hand ); ?>" placeholder="e.g. 1.25" />

														</div>

													</div>

												</td>

											</tr>

											<?php

											$index++;

										}

										?>

									</tbody>

								</table>

								

								<div style="margin-top: 14px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">

									<button type="button" class="button button-primary formula-shipping-add-rule-btn-generic" data-target-table="formula_shipping_rules_table_usa_item_rules"><?php esc_html_e( '+ Add Rule', 'formula-rate-shipping' ); ?></button>

									

									<div style="display: flex; align-items: center; gap: 10px;">

										<label for="<?php echo esc_attr( $this->get_field_key( 'usa_rule_resolution' ) ); ?>" style="font-weight: 600; font-size: 12px; color: #334155; white-space: nowrap;"><?php esc_html_e( 'Item Resolution Logic:', 'formula-rate-shipping' ); ?></label>

										<select name="<?php echo esc_attr( $this->get_field_key( 'usa_rule_resolution' ) ); ?>" id="<?php echo esc_attr( $this->get_field_key( 'usa_rule_resolution' ) ); ?>" style="height: 34px; font-size: 12px;">

											<option value="first_match" <?php selected( $resolution, 'first_match' ); ?>><?php esc_html_e( 'Priority (Apply matched rules in top-to-bottom order)', 'formula-rate-shipping' ); ?></option>

											<option value="sum" <?php selected( $resolution, 'sum' ); ?>><?php esc_html_e( 'Sum All Matches (Add rules together)', 'formula-rate-shipping' ); ?></option>

											<option value="max" <?php selected( $resolution, 'max' ); ?>><?php esc_html_e( 'Highest Cost Only (Apply single highest rule)', 'formula-rate-shipping' ); ?></option>

											<option value="merge_difference" <?php selected( $resolution, 'merge_difference' ); ?>><?php esc_html_e( 'Folded Incremental (Primary Base + Additional Item Rates)', 'formula-rate-shipping' ); ?></option>

										</select>

									</div>

								</div>



								<!-- Dynamic Resolution Logic Inline Help Box -->

								<div id="formula_resolution_help_box" style="margin-top: 12px; background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 6px; padding: 10px 14px; font-size: 12px; color: #0369a1; display: flex; align-items: flex-start; gap: 8px;">

									<span class="dashicons dashicons-info" style="font-size: 16px; margin-top: 2px; color: #0284c7; flex-shrink: 0;"></span>

									<div id="formula_resolution_help_text" style="line-height: 1.5;"></div>

								</div>

							</div>

						</div>

					</div>

					<script type="text/javascript">

						(function($) {

							var tableId = 'formula_shipping_rules_table_usa_item_rules';

							var table = $('#' + tableId + ' tbody');

							var fieldKey = '<?php echo esc_js( $field_key ); ?>';



							function updateResolutionHelpText() {

								var val = $('#<?php echo esc_js( $this->get_field_key( "usa_rule_resolution" ) ); ?>').val();

								var helpHtml = '';

								if (val === 'first_match') {

									helpHtml = '<strong>Priority (Default):</strong> Rules are evaluated in top-to-bottom order. Once an item matches a rule, it is captured by that rule and excluded from subsequent lower-priority rules.';

								} else if (val === 'merge_difference') {

									helpHtml = '<strong>Folded Incremental (Recommended for mixed carts):</strong> The item set matching the rule with the highest base rate sets the package base shipping fee and maximum handling fee. Lighter items fold in using only their incremental additional-item rates.';

								} else if (val === 'sum') {

									helpHtml = '<strong>Sum All Matches:</strong> Calculates shipping and handling formulas for all matching rules and adds their totals together.';

								} else if (val === 'max') {

									helpHtml = '<strong>Highest Cost Only:</strong> Evaluates all matching rules for items in the cart, but applies only the single rule that yields the highest total cost.';

								}

								$('#formula_resolution_help_text').html(helpHtml);

							}

							$(document).off('change', '#<?php echo esc_js( $this->get_field_key( "usa_rule_resolution" ) ); ?>').on('change', '#<?php echo esc_js( $this->get_field_key( "usa_rule_resolution" ) ); ?>', updateResolutionHelpText);

							updateResolutionHelpText();



							function syncItemRuleEnabledState(mainRow) {

								var formulaRow = mainRow.next('tr.formula-rule-row-formulas');

								var isEnabled = mainRow.find('.formula-rule-enabled-toggle').is(':checked');

								mainRow.toggleClass('formula-rule-disabled', !isEnabled);

								formulaRow.toggleClass('formula-rule-disabled', !isEnabled);

							}



							function reindexItemRulesTable() {

								var mainRows = table.find('tr.formula-rule-row-main');

								mainRows.each(function(idx) {

									var mainRow = $(this);

									var formulaRow = mainRow.next('tr.formula-rule-row-formulas');

									var bgClass = (idx % 2 === 0) ? 'formula-rule-bg-white' : 'formula-rule-bg-gray';

									

									mainRow.attr('data-index', idx).removeClass('formula-rule-bg-white formula-rule-bg-gray').addClass(bgClass);

									mainRow.find('.rule-priority-badge').text('#' + (idx + 1));

									mainRow.find('select, input').each(function() {

										var name = $(this).attr('name');

										if (name) {

											var newName = name.replace(/\[\d+\]/, '[' + idx + ']');

											$(this).attr('name', newName);

										}

									});

									

									if (formulaRow.length) {

										formulaRow.attr('data-index', idx).removeClass('formula-rule-bg-white formula-rule-bg-gray').addClass(bgClass);

										formulaRow.find('select, input').each(function() {

											var name = $(this).attr('name');

											if (name) {

												var newName = name.replace(/\[\d+\]/, '[' + idx + ']');

												$(this).attr('name', newName);

											}

										});

									}

									syncItemRuleEnabledState(mainRow);

								});

							}



							var categoryOptions = <?php echo json_encode( $category_options ); ?>;

							var tagOptions = <?php echo json_encode( $tag_options ); ?>;



							function updateRowTargetField(row, selectedType, currentValue) {

								var targetCell = row.find('.formula-shipping-target-cell');

								targetCell.empty();



								var index = row.attr('data-index') || 0;

								var inputName = fieldKey + '[' + index + '][target]';



								if (selectedType === 'category') {

									var selectHtml = '<select name="' + inputName + '">';

									$.each(categoryOptions, function(slug, name) {

										var sel = (slug === currentValue) ? 'selected' : '';

										selectHtml += '<option value="' + slug + '" ' + sel + '>' + name + '</option>';

									});

									selectHtml += '</select>';

									targetCell.html(selectHtml);

								} else if (selectedType === 'tag') {

									var selectHtml = '<select name="' + inputName + '">';

									$.each(tagOptions, function(slug, name) {

										var sel = (slug === currentValue) ? 'selected' : '';

										selectHtml += '<option value="' + slug + '" ' + sel + '>' + name + '</option>';

									});

									selectHtml += '</select>';

									targetCell.html(selectHtml);

								} else {

									var placeholder = 'e.g. Hardcover, SKU-123';

									var safeVal = currentValue ? $('<div>').text(currentValue).html() : '';

									var inputHtml = '<input type="text" name="' + inputName + '" value="' + safeVal + '" placeholder="' + placeholder + '" />';

									targetCell.html(inputHtml);

								}

							}



							$(document).off('change', '.formula-shipping-type-select').on('change', '.formula-shipping-type-select', function() {

								var row = $(this).closest('tr.formula-rule-row-main');

								var selectedType = $(this).val();

								updateRowTargetField(row, selectedType, '');

							});



							$(document).off('change', '.formula-rule-row-main .formula-rule-enabled-toggle').on('change', '.formula-rule-row-main .formula-rule-enabled-toggle', function() {

								syncItemRuleEnabledState($(this).closest('tr.formula-rule-row-main'));

							});



							$(document).off('click', '.formula-shipping-add-rule-btn-generic[data-target-table="' + tableId + '"]').on('click', '.formula-shipping-add-rule-btn-generic[data-target-table="' + tableId + '"]', function(e) {

								e.preventDefault();

								var index = table.find('tr.formula-rule-row-main').length;

								var rowBg = (index % 2 === 0) ? '#ffffff' : '#f8fafc';

								var mainRowHtml = '<tr data-index="' + index + '" class="formula-rule-row-main">' +

									'<td style="text-align: center; vertical-align: middle;"><span class="rule-priority-badge">#' + (index + 1) + '</span><input type="hidden" name="' + fieldKey + '[' + index + '][enabled]" value="no" /><label class="formula-rule-switch" title="<?php echo esc_js( __( 'Enable this rule', 'formula-rate-shipping' ) ); ?>"><input type="checkbox" name="' + fieldKey + '[' + index + '][enabled]" value="yes" class="formula-rule-enabled-toggle" checked /><span class="formula-rule-switch-slider"></span></label></td>' +

									'<td>' +

										'<select name="' + fieldKey + '[' + index + '][type]" class="formula-shipping-type-select">' +

											'<option value="category"><?php echo esc_js( __( 'Category', 'formula-rate-shipping' ) ); ?></option>' +

											'<option value="tag"><?php echo esc_js( __( 'Tag', 'formula-rate-shipping' ) ); ?></option>' +

											'<option value="variation" selected><?php echo esc_js( __( 'Variation SKU / Attribute', 'formula-rate-shipping' ) ); ?></option>' +

										'</select>' +

									'</td>' +

									'<td class="formula-shipping-target-cell"></td>' +

									'<td style="text-align: center; white-space: nowrap;">' +

										'<div class="formula-rule-action-buttons">' +

											'<button type="button" class="button formula-shipping-move-up-rule" title="Move Up Priority">&#9650;</button> ' +

											'<button type="button" class="button formula-shipping-move-down-rule" title="Move Down Priority">&#9660;</button> ' +

											'<button type="button" class="button formula-shipping-duplicate-rule" title="Duplicate Rule">&#128464;</button> ' +

											'<button type="button" class="button formula-shipping-delete-rule" title="Delete Rule">&#10005;</button>' +

										'</div>' +

									'</td>' +

								'</tr>';

								var formulaRowHtml = '<tr data-index="' + index + '" class="formula-rule-row-formulas">' +

									'<td colspan="4" style="padding: 6px 16px 16px 16px; border-bottom: 2px solid #e2e8f0;">' +

										'<div class="formula-rule-formulas-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; text-align: left;">' +

											'<div class="formula-rule-formula-field" style="display: flex; flex-direction: column; text-align: left;">' +

												'<label style="font-weight: 600; font-size: 12px; color: #334155; margin-bottom: 6px; text-align: left; display: block;"><?php echo esc_js( __( 'Shipping Formula', 'formula-rate-shipping' ) ); ?></label>' +

												'<input type="text" name="' + fieldKey + '[' + index + '][shipping_formula]" placeholder="e.g. [qty] * 5.00" />' +

											'</div>' +

											'<div class="formula-rule-formula-field" style="display: flex; flex-direction: column; text-align: left;">' +

												'<label style="font-weight: 600; font-size: 12px; color: #334155; margin-bottom: 6px; text-align: left; display: block;"><?php echo esc_js( __( 'Handling Formula', 'formula-rate-shipping' ) ); ?></label>' +

												'<input type="text" name="' + fieldKey + '[' + index + '][handling_formula]" placeholder="e.g. 1.25" />' +

											'</div>' +

										'</div>' +

									'</td>' +

								'</tr>';

								table.append(mainRowHtml + formulaRowHtml);

								var newRow = table.find('tr.formula-rule-row-main').last();

								updateRowTargetField(newRow, 'variation', 'Hardcover');

								reindexItemRulesTable();

							});



							$(document).off('click', '#' + tableId + ' .formula-shipping-move-up-rule').on('click', '#' + tableId + ' .formula-shipping-move-up-rule', function(e) {

								e.preventDefault();

								var mainRow = $(this).closest('tr.formula-rule-row-main');

								var formulaRow = mainRow.next('tr.formula-rule-row-formulas');

								var prevFormula = mainRow.prev('tr.formula-rule-row-formulas');

								var prevMain = prevFormula.prev('tr.formula-rule-row-main');

								if (prevMain.length > 0) {

									mainRow.insertBefore(prevMain);

									formulaRow.insertAfter(mainRow);

									reindexItemRulesTable();

								}

							});



							$(document).off('click', '#' + tableId + ' .formula-shipping-move-down-rule').on('click', '#' + tableId + ' .formula-shipping-move-down-rule', function(e) {

								e.preventDefault();

								var mainRow = $(this).closest('tr.formula-rule-row-main');

								var formulaRow = mainRow.next('tr.formula-rule-row-formulas');

								var nextMain = formulaRow.next('tr.formula-rule-row-main');

								if (nextMain.length > 0) {

									var nextFormula = nextMain.next('tr.formula-rule-row-formulas');

									formulaRow.insertAfter(nextFormula);

									mainRow.insertBefore(formulaRow);

									reindexItemRulesTable();

								}

							});



							$(document).off('click', '#' + tableId + ' .formula-shipping-duplicate-rule').on('click', '#' + tableId + ' .formula-shipping-duplicate-rule', function(e) {

								e.preventDefault();

								var mainRow = $(this).closest('tr.formula-rule-row-main');

								var formulaRow = mainRow.next('tr.formula-rule-row-formulas');



								var type = mainRow.find('select.formula-shipping-type-select').val() || 'category';

								var target = mainRow.find('.formula-shipping-target-cell select, .formula-shipping-target-cell input').val() || '';

								var enabled = mainRow.find('.formula-rule-enabled-toggle').is(':checked');

								var shipF = formulaRow.length ? formulaRow.find('input[name*="[shipping_formula]"]').val() || '' : '';

								var handF = formulaRow.length ? formulaRow.find('input[name*="[handling_formula]"]').val() || '' : '';



								var dummyIndex = 999;

								var mainRowHtml = '<tr data-index="' + dummyIndex + '" class="formula-rule-row-main">' +

									'<td style="text-align: center; vertical-align: middle;"><span class="rule-priority-badge">#</span><input type="hidden" name="' + fieldKey + '[' + dummyIndex + '][enabled]" value="no" /><label class="formula-rule-switch" title="<?php echo esc_js( __( 'Enable this rule', 'formula-rate-shipping' ) ); ?>"><input type="checkbox" name="' + fieldKey + '[' + dummyIndex + '][enabled]" value="yes" class="formula-rule-enabled-toggle"' + (enabled ? ' checked' : '') + ' /><span class="formula-rule-switch-slider"></span></label></td>' +

									'<td>' +

										'<select name="' + fieldKey + '[' + dummyIndex + '][type]" class="formula-shipping-type-select">' +

											'<option value="category" ' + (type === 'category' ? 'selected' : '') + '><?php echo esc_js( __( 'Category', 'formula-rate-shipping' ) ); ?></option>' +

											'<option value="tag" ' + (type === 'tag' ? 'selected' : '') + '><?php echo esc_js( __( 'Tag', 'formula-rate-shipping' ) ); ?></option>' +

											'<option value="variation" ' + (type === 'variation' ? 'selected' : '') + '><?php echo esc_js( __( 'Variation SKU / Attribute', 'formula-rate-shipping' ) ); ?></option>' +

										'</select>' +

									'</td>' +

									'<td class="formula-shipping-target-cell"></td>' +

									'<td style="text-align: center; white-space: nowrap;">' +

										'<div class="formula-rule-action-buttons">' +

											'<button type="button" class="button formula-shipping-move-up-rule" title="Move Up Priority">&#9650;</button> ' +

											'<button type="button" class="button formula-shipping-move-down-rule" title="Move Down Priority">&#9660;</button> ' +

											'<button type="button" class="button formula-shipping-duplicate-rule" title="Duplicate Rule">&#128464;</button> ' +

											'<button type="button" class="button formula-shipping-delete-rule" title="Delete Rule">&#10005;</button>' +

										'</div>' +

									'</td>' +

								'</tr>';



								var formulaRowHtml = '<tr data-index="' + dummyIndex + '" class="formula-rule-row-formulas">' +

									'<td colspan="4" style="padding: 6px 16px 16px 16px; border-bottom: 2px solid #e2e8f0;">' +

										'<div class="formula-rule-formulas-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; text-align: left;">' +

											'<div class="formula-rule-formula-field" style="display: flex; flex-direction: column; text-align: left;">' +

												'<label style="font-weight: 600; font-size: 12px; color: #334155; margin-bottom: 6px; text-align: left; display: block;"><?php echo esc_js( __( 'Shipping Formula', 'formula-rate-shipping' ) ); ?></label>' +

												'<input type="text" name="' + fieldKey + '[' + dummyIndex + '][shipping_formula]" value="' + $('<div>').text(shipF).html() + '" placeholder="e.g. [qty] * 5.00" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #cbd5e1 !important; background: #ffffff !important; border-radius: 6px; height: 38px; font-size: 13px; color: #0f172a;" />' +

											'</div>' +

											'<div class="formula-rule-formula-field" style="display: flex; flex-direction: column; text-align: left;">' +

												'<label style="font-weight: 600; font-size: 12px; color: #334155; margin-bottom: 6px; text-align: left; display: block;"><?php echo esc_js( __( 'Handling Formula', 'formula-rate-shipping' ) ); ?></label>' +

												'<input type="text" name="' + fieldKey + '[' + dummyIndex + '][handling_formula]" value="' + $('<div>').text(handF).html() + '" placeholder="e.g. 1.25" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #cbd5e1 !important; background: #ffffff !important; border-radius: 6px; height: 38px; font-size: 13px; color: #0f172a;" />' +

											'</div>' +

										'</div>' +

									'</td>' +

								'</tr>';



								var newMainRow = $(mainRowHtml);

								var newFormulaRow = $(formulaRowHtml);



								if (formulaRow.length) {

									newFormulaRow.insertAfter(formulaRow);

									newMainRow.insertAfter(formulaRow);

								} else {

									newFormulaRow.insertAfter(mainRow);

									newMainRow.insertAfter(mainRow);

								}



								updateRowTargetField(newMainRow, type, target);

								reindexItemRulesTable();

							});



							$(document).off('click', '#' + tableId + ' .formula-shipping-delete-rule').on('click', '#' + tableId + ' .formula-shipping-delete-rule', function(e) {

								e.preventDefault();

								var mainRow = $(this).closest('tr.formula-rule-row-main');

								var formulaRow = mainRow.next('tr.formula-rule-row-formulas');

								mainRow.remove();

								formulaRow.remove();

								reindexItemRulesTable();

							});



							reindexItemRulesTable();

						})(jQuery);

					</script>

				</td>

			</tr>

			<?php

			return ob_get_clean();

		}



		/**

		 * Validate and sanitize item rules recursively searching $_POST for any item rule array.

		 */

		public function validate_item_rules_table_field( $key, $value ) {

			$post_data = $this->get_post_data();

			if ( empty( $post_data ) ) {

				$post_data = $_POST;

			}



			$field_key = $this->get_field_key( $key );

			$rules     = array();



			if ( isset( $post_data[ $field_key ] ) && is_array( $post_data[ $field_key ] ) ) {

				$rules = $post_data[ $field_key ];

			} elseif ( isset( $_POST[ $field_key ] ) && is_array( $_POST[ $field_key ] ) ) {

				$rules = $_POST[ $field_key ];

			} else {

				foreach ( $_POST as $p_k => $p_v ) {

					if ( is_array( $p_v ) && ( strpos( $p_k, 'usa_item_rules' ) !== false || strpos( $p_k, 'item_rules' ) !== false ) ) {

						$rules = $p_v;

						break;

					}

				}

			}



			$sanitized_rules = array();

			if ( is_array( $rules ) && ! empty( $rules ) ) {

				foreach ( $rules as $rule ) {

					$type   = isset( $rule['type'] ) ? sanitize_text_field( $rule['type'] ) : 'category';

					$target = isset( $rule['target'] ) ? sanitize_text_field( trim( $rule['target'] ) ) : '';

					$enabled = isset( $rule['enabled'] ) ? sanitize_text_field( $rule['enabled'] ) : 'yes';

					if ( 'default' === $type ) {

						continue;

					}

					if ( empty( $target ) ) {

						continue;

					}

					$sanitized_rules[] = array(

						'type'             => $type,

						'target'           => $target,

						'enabled'          => 'no' === $enabled ? 'no' : 'yes',

						'shipping_formula' => isset( $rule['shipping_formula'] ) ? sanitize_text_field( $rule['shipping_formula'] ) : '',

						'handling_formula' => isset( $rule['handling_formula'] ) ? sanitize_text_field( $rule['handling_formula'] ) : '',

					);

				}

				if ( ! empty( $sanitized_rules ) ) {

					return $sanitized_rules;

				}

			}



			if ( is_array( $value ) && ! empty( $value ) ) {

				return $value;

			}



			return $this->get_option( $key, array() );

		}



		/**

		 * Validate and sanitize county rules.

		 */

		public function validate_county_rules_table_field( $key, $value ) {

			$post_data = $this->get_post_data();

			if ( empty( $post_data ) ) {

				$post_data = $_POST;

			}



			$field_key = $this->get_field_key( $key );

			$rules    = array();



			if ( isset( $post_data[ $field_key ] ) && is_array( $post_data[ $field_key ] ) ) {

				$rules = $post_data[ $field_key ];

			} elseif ( isset( $_POST[ $field_key ] ) && is_array( $_POST[ $field_key ] ) ) {

				$rules = $_POST[ $field_key ];

			} else {

				foreach ( $_POST as $p_k => $p_v ) {

					if ( is_array( $p_v ) && ( strpos( $p_k, 'usa_county_rules' ) !== false || strpos( $p_k, 'county_rules' ) !== false ) ) {

						$rules = $p_v;

						break;

					}

				}

			}



			$sanitized_rules = array();

			if ( is_array( $rules ) && ! empty( $rules ) ) {

				foreach ( $rules as $rule ) {

					$country       = isset( $rule['country'] ) ? sanitize_text_field( trim( strtoupper( $rule['country'] ) ) ) : 'ALL';

					$state         = isset( $rule['state'] ) ? sanitize_text_field( trim( strtoupper( $rule['state'] ) ) ) : 'ALL';

					$location      = isset( $rule['location'] ) ? sanitize_text_field( trim( $rule['location'] ) ) : '';

					$rule_type_val = isset( $rule['rule_type'] ) ? sanitize_text_field( $rule['rule_type'] ) : 'location';

					$user_val      = isset( $rule['user'] ) ? sanitize_text_field( trim( $rule['user'] ) ) : '';

					$device_target = isset( $rule['device_target'] ) ? sanitize_text_field( trim( $rule['device_target'] ) ) : '';

					$os_target     = isset( $rule['os_target'] ) ? sanitize_text_field( trim( $rule['os_target'] ) ) : '';

					$user_meta_key = isset( $rule['user_meta_key'] ) ? sanitize_text_field( trim( $rule['user_meta_key'] ) ) : '';

					$user_meta_value = isset( $rule['user_meta_value'] ) ? sanitize_text_field( trim( $rule['user_meta_value'] ) ) : '';

					$enabled       = isset( $rule['enabled'] ) ? sanitize_text_field( $rule['enabled'] ) : 'yes';



					if ( 'user' === $rule_type_val ) {

						$rule_type_val = 'user_role';

					}



					if ( ! in_array( $rule_type_val, array( 'location', 'user_role', 'user_login', 'user_email', 'user_meta', 'device_os', 'browser' ), true ) ) {

						$rule_type_val = 'location';

					}



					if ( 'user_meta' === $rule_type_val && '' === $user_meta_key ) {

						continue;

					}



					if ( in_array( $rule_type_val, array( 'user_role', 'user_login', 'user_email' ), true ) && '' === $user_val ) {

						continue;

					}



					if ( 'device_os' === $rule_type_val && '' === $device_target && '' === $os_target && '' === $user_val ) {

						continue;

					}



					// Skip empty/meaningless rows (all defaults with no user target either)

					if ( empty( $country ) && empty( $state ) && empty( $location ) && 'location' === $rule_type_val ) {

						continue;

					}

					$sanitized_rules[] = array(

						'rule_type'        => $rule_type_val,

						'country'          => $country,

						'state'            => $state,

						'location'         => $location,

						'user'             => $user_val,

						'device_target'    => $device_target,

						'os_target'        => $os_target,

						'user_meta_key'    => $user_meta_key,

						'user_meta_value'  => $user_meta_value,

						'enabled'          => 'no' === $enabled ? 'no' : 'yes',

						'shipping_formula' => isset( $rule['shipping_formula'] ) ? sanitize_text_field( $rule['shipping_formula'] ) : '',

						'handling_formula' => isset( $rule['handling_formula'] ) ? sanitize_text_field( $rule['handling_formula'] ) : '',

					);

				}

				if ( ! empty( $sanitized_rules ) ) {

					return $sanitized_rules;

				}

			}



			if ( is_array( $value ) && ! empty( $value ) ) {

				return $value;

			}



			return $this->get_option( $key, array() );

		}



		/**

		 * Generate HTML for Card 4: Live Shipping Simulator (2 Columns: Col 1 = Address + Trace Log, Col 2 = Cart + Results).

		 */

		public function generate_live_preview_panel_html( $key, $data ) {

			$categories_raw = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );

			$tags_raw       = get_terms( array( 'taxonomy' => 'product_tag', 'hide_empty' => false ) );



			$categories = array();

			if ( ! is_wp_error( $categories_raw ) && ! empty( $categories_raw ) ) {

				foreach ( $categories_raw as $cat ) {

					$categories[] = array(

						'slug' => $cat->slug,

						'name' => $cat->name

					);

				}

			}



			$tags = array();

			if ( ! is_wp_error( $tags_raw ) && ! empty( $tags_raw ) ) {

				foreach ( $tags_raw as $tag ) {

					$tags[] = array(

						'slug' => $tag->slug,

						'name' => $tag->name

					);

				}

			}



			$us_states = array();

			if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) && WC()->countries ) {

				$us_states = WC()->countries->get_states( 'US' );

			}



			// Query ACTUAL published products from WooCommerce database

			$real_products = array();

			if ( function_exists( 'wc_get_products' ) ) {

				$wc_prods = wc_get_products( array(

					'status' => 'publish',

					'limit'  => 250,

					'return' => 'objects',

				) );

				foreach ( $wc_prods as $p ) {

					$p_cats = array();

					$c_terms = get_the_terms( $p->get_id(), 'product_cat' );

					if ( is_array( $c_terms ) && ! is_wp_error( $c_terms ) ) {

						foreach ( $c_terms as $ct ) {

							$p_cats[] = $ct->slug;

						}

					}

					$p_tags = array();

					$t_terms = get_the_terms( $p->get_id(), 'product_tag' );

					if ( is_array( $t_terms ) && ! is_wp_error( $t_terms ) ) {

						foreach ( $t_terms as $tt ) {

							$p_tags[] = $tt->slug;

						}

					}



					$real_products[] = array(

						'id'         => $p->get_id(),

						'name'       => $p->get_name(),

						'sku'        => $p->get_sku() ? $p->get_sku() : strval( $p->get_id() ),

						'price'      => floatval( $p->get_price() ),

						'category'   => ! empty( $p_cats ) ? $p_cats[0] : '',

						'tag'        => ! empty( $p_tags ) ? $p_tags[0] : '',

						'categories' => $p_cats,

						'tags'       => $p_tags,

					);

				}

			}



			if ( empty( $real_products ) ) {

				$real_products = array(

					array( 'id' => 1, 'name' => 'Standard Paperback Book', 'sku' => 'MINI-1', 'price' => 2.99, 'category' => 'paperback', 'tag' => 'standard', 'categories' => array( 'paperback' ), 'tags' => array( 'standard' ) ),

					array( 'id' => 2, 'name' => 'Non-Fiction Book', 'sku' => 'NF-101', 'price' => 15.00, 'category' => 'non-fiction', 'tag' => 'hardback', 'categories' => array( 'non-fiction' ), 'tags' => array( 'hardback' ) ),

					array( 'id' => 3, 'name' => 'Pivotal Press Guide', 'sku' => 'PP-1', 'price' => 12.50, 'category' => 'pivotal-press', 'tag' => 'paperback', 'categories' => array( 'pivotal-press' ), 'tags' => array( 'paperback' ) ),

					array( 'id' => 4, 'name' => 'Childrens Illustrated Book', 'sku' => 'CB-1', 'price' => 8.99, 'category' => 'childrens-books', 'tag' => 'hardback', 'categories' => array( 'childrens-books' ), 'tags' => array( 'hardback' ) ),

					array( 'id' => 5, 'name' => 'Hardcover Collector Edition', 'sku' => 'COP-HC1', 'price' => 18.99, 'category' => 'books', 'tag' => 'hardcover', 'categories' => array( 'books' ), 'tags' => array( 'hardcover' ) ),

				);

			}



			ob_start();

			?>

			<tr valign="top">

				<td colspan="2" style="padding: 0; margin-bottom: 28px;">

					<div class="formula-section-card card-theme-simulator">

						<div class="card-header">

							<h3><span class="dashicons dashicons-calculator"></span> <?php esc_html_e( '4. Test Cart', 'formula-rate-shipping' ); ?></h3>

							<span class="card-badge"><?php esc_html_e( 'Real-Time Tester', 'formula-rate-shipping' ); ?></span>

						</div>

						<div class="card-summary-bar" id="formula_card_summary_simulator">

							<div class="card-summary-text">

								<span>Real-Time Tester | <?php echo count( $real_products ); ?> sample products loaded</span>

							</div>

							<span class="card-toggle-icon dashicons dashicons-arrow-up-alt2"></span>

						</div>

						<div class="card-body" style="padding: 24px 28px;">

							<p class="description" style="margin-top: 0; margin-bottom: 20px; font-size: 13px; color: #475569;"><?php esc_html_e( 'Test your shipping rates and priority rules using actual catalog products filtered by categories or attributes.', 'formula-rate-shipping' ); ?></p>



							<!-- 1-COLUMN STACKED SIMULATOR LAYOUT -->

							<div class="formula-simulator-stacked">

								<!-- SECTION 1: Destination Address -->

								<div class="formula-preview-section" style="margin-bottom: 20px;">

									<h4>1. <?php esc_html_e( 'Destination Address', 'formula-rate-shipping' ); ?></h4>

										<div class="formula-preview-form-group" style="display: grid; grid-template-columns: 140px 150px 140px 1fr; gap: 12px; align-items: end;">

											<div>

												<label><?php esc_html_e( 'Country', 'formula-rate-shipping' ); ?></label>

												<input type="text" id="formula_sim_country" class="formula-sim-country-input formula-multi-suggest-input" value="US" placeholder="US, CA, MX" title="<?php esc_attr_e( 'Country', 'formula-rate-shipping' ); ?>" />

											</div>

											<div id="formula_sim_state_group">

												<label><?php esc_html_e( 'State or Province', 'formula-rate-shipping' ); ?></label>

												<input type="text" id="formula_sim_state" class="formula-sim-state-input formula-multi-suggest-input" value="CA" placeholder="CA, ON, NY" title="<?php esc_attr_e( 'State or Province', 'formula-rate-shipping' ); ?>" />

											</div>

											<div>

												<label><?php esc_html_e( 'ZIP / Postcode', 'formula-rate-shipping' ); ?></label>

												<input type="text" id="formula_sim_zip" value="90210" placeholder="90210" />

											</div>

											<div>

												<label><?php esc_html_e( 'City', 'formula-rate-shipping' ); ?></label>

												<input type="text" id="formula_sim_city" value="Los Angeles" placeholder="Los Angeles" />

											</div>

										</div>

										<div style="margin-top: 14px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 14px 16px;">

											<p style="margin: 0 0 10px 0; font-size: 12px; font-weight: 600; color: #334155;"><?php esc_html_e( 'Customer Context for User Rules', 'formula-rate-shipping' ); ?></p>

											<div class="formula-preview-form-group-row">

												<div class="formula-preview-form-group">

													<label><?php esc_html_e( 'User Role', 'formula-rate-shipping' ); ?></label>

													<input type="text" id="formula_sim_user_role" placeholder="subscriber" />

												</div>

												<div class="formula-preview-form-group">

													<label><?php esc_html_e( 'User Login', 'formula-rate-shipping' ); ?></label>

													<input type="text" id="formula_sim_user_login" placeholder="johndoe" />

												</div>

											</div>

											<div class="formula-preview-form-group-row">

												<div class="formula-preview-form-group">

													<label><?php esc_html_e( 'User Email', 'formula-rate-shipping' ); ?></label>

													<input type="text" id="formula_sim_user_email" placeholder="customer@example.com" />

												</div>

												<div class="formula-preview-form-group">

													<label><?php esc_html_e( 'User Meta Key', 'formula-rate-shipping' ); ?></label>

													<input type="text" id="formula_sim_user_meta_key" placeholder="membership_level" />

												</div>

											</div>

											<div class="formula-preview-form-group">

												<label><?php esc_html_e( 'User Meta Value', 'formula-rate-shipping' ); ?></label>

												<input type="text" id="formula_sim_user_meta_value" placeholder="gold" />

											</div>

										</div>

									</div>



									<!-- SECTION 2: Test Cart Items -->

									<div class="formula-preview-section" style="margin-bottom: 20px;">

										<div class="section-header-flex" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f1f5f9; padding-bottom: 10px; margin-bottom: 16px; flex-wrap: wrap; gap: 12px;">

											<h4 style="margin: 0; border: none; padding: 0;">2. <?php esc_html_e( 'Test Cart Items', 'formula-rate-shipping' ); ?></h4>

											<div class="action-buttons-wrapper" style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">

												<button type="button" class="button button-primary" id="formula_sim_add_item_btn" style="white-space: nowrap;"><?php esc_html_e( '+ Add Blank Item', 'formula-rate-shipping' ); ?></button>

												<button type="button" class="button" id="formula_sim_add_random_btn" style="background:#f1f5f9; color:#0f172a; border-color:#cbd5e1; white-space: nowrap;"><?php esc_html_e( '+ Add Random Product', 'formula-rate-shipping' ); ?></button>

												<button type="button" class="button" id="formula_sim_random_cart_btn" style="background:#e0f2fe; color:#0369a1; border-color:#bae6fd; white-space: nowrap;"><?php esc_html_e( 'Random Cart', 'formula-rate-shipping' ); ?></button>

												<button type="button" class="button" id="formula_sim_clear_btn" style="white-space: nowrap;"><?php esc_html_e( 'Clear', 'formula-rate-shipping' ); ?></button>

											</div>

										</div>



										<!-- Intuitive Product Selector Filter Bar (Clean Stacked Layout) -->

										<div style="margin-bottom: 14px; background: #f8fafc; padding: 14px 16px; border: 1px solid #cbd5e1; border-radius: 8px;">

											<div style="display: flex; gap: 10px; margin-bottom: 10px; flex-wrap: wrap;">

												<div style="flex: 1; min-width: 130px;">

													<label style="font-weight: 600; font-size: 11px; color: #475569; display: block; margin-bottom: 4px;"><?php esc_html_e( 'Filter Category:', 'formula-rate-shipping' ); ?></label>

													<select id="formula_sim_filter_cat" style="width:100%; height:34px; font-size:12px;">

														<option value=""><?php esc_html_e( '-- All Categories --', 'formula-rate-shipping' ); ?></option>

														<?php foreach ( $categories as $c ) : ?>

															<option value="<?php echo esc_attr( $c['slug'] ); ?>"><?php echo esc_html( $c['name'] ); ?></option>

														<?php endforeach; ?>

													</select>

												</div>

												<div style="flex: 1; min-width: 130px;">

													<label style="font-weight: 600; font-size: 11px; color: #475569; display: block; margin-bottom: 4px;"><?php esc_html_e( 'Filter Tag:', 'formula-rate-shipping' ); ?></label>

													<select id="formula_sim_filter_tag" style="width:100%; height:34px; font-size:12px;">

														<option value=""><?php esc_html_e( '-- All Tags --', 'formula-rate-shipping' ); ?></option>

														<?php foreach ( $tags as $t ) : ?>

															<option value="<?php echo esc_attr( $t['slug'] ); ?>"><?php echo esc_html( $t['name'] ); ?></option>

														<?php endforeach; ?>

													</select>

												</div>

											</div>



											<div style="margin-bottom: 10px;">

												<label style="font-weight: 600; font-size: 11px; color: #475569; display: block; margin-bottom: 4px;"><?php esc_html_e( 'Select Catalog Product:', 'formula-rate-shipping' ); ?></label>

												<select id="formula_sim_select_store_product" style="width: 100%; height: 38px; font-size: 12px; border-color: #0284c7;">

													<option value=""><?php esc_html_e( '-- Choose product from catalog --', 'formula-rate-shipping' ); ?></option>

												</select>

											</div>



											<div>

												<button type="button" class="button button-primary" id="formula_sim_add_selected_product_btn" style="width: 100%; height: 38px; font-size: 13px; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 6px;"><?php esc_html_e( '+ Add Selected Product to Test Cart', 'formula-rate-shipping' ); ?></button>

											</div>

										</div>



										<div class="formula-sim-cart-table-wrapper" style="margin-top: 14px; border: 1px solid #cbd5e1; border-radius: 8px; overflow: hidden;">

											<table class="widefat wc_input_table formula-sim-cart-table" id="formula_sim_cart_table" style="margin: 0; width: 100%;">

												<thead>

													<tr>

														<th><?php esc_html_e( 'Item / Product Name', 'formula-rate-shipping' ); ?></th>

														<th style="width: 70px; text-align: center;"><?php esc_html_e( 'Qty', 'formula-rate-shipping' ); ?></th>

														<th style="width: 90px; text-align: center;"><?php esc_html_e( 'Price ($)', 'formula-rate-shipping' ); ?></th>

														<th style="width: 80px; text-align: center;"><?php esc_html_e( 'Action', 'formula-rate-shipping' ); ?></th>

													</tr>

												</thead>

												<tbody>

													<!-- Dynamic stacked 2-row items added by JS -->

												</tbody>

											</table>

										</div>



										<div style="margin-top: 16px;">

											<button type="button" class="button button-primary button-large" id="formula_sim_calculate_btn" style="width: 100%; height: 44px; font-size: 15px; font-weight: 600; border-radius: 6px;"><?php esc_html_e( 'Calculate Shipping Preview', 'formula-rate-shipping' ); ?></button>

										</div>

									</div>



									<!-- SECTION 3: Shipping Cost Calculation Output -->

									<div class="formula-preview-section" style="margin-bottom: 20px;">

										<h4>3. <?php esc_html_e( 'Shipping Cost Calculation Output', 'formula-rate-shipping' ); ?></h4>

										

										<div class="formula-sim-results-grid">

											<div class="formula-sim-result-box">

												<span class="label"><?php esc_html_e( 'Shipping', 'formula-rate-shipping' ); ?></span>

												<span class="value" id="formula_sim_out_item_shipping">$0.00</span>

											</div>

											<div class="formula-sim-result-box">

												<span class="label"><?php esc_html_e( 'Handling', 'formula-rate-shipping' ); ?></span>

												<span class="value" id="formula_sim_out_item_handling">$0.00</span>

											</div>

											<div class="formula-sim-result-box highlight-total" style="grid-column: span 2;">

												<span class="label"><?php esc_html_e( 'Estimated Total Shipping Cost', 'formula-rate-shipping' ); ?></span>

												<span class="value" id="formula_sim_out_total">$0.00</span>

											</div>

										</div>



										<!-- User-Friendly Breakdown Box Below Shipping Calculator -->

										<div id="formula_sim_breakdown_card" style="margin-top: 20px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 18px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">

											<div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f1f5f9; padding-bottom: 12px; margin-bottom: 14px; flex-wrap: wrap; gap: 8px;">

												<h5 style="margin: 0; font-size: 14px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">

													<span class="dashicons dashicons-analytics" style="color: #0284c7;"></span>

													<?php esc_html_e( 'Applied Rules & Formula Breakdown', 'formula-rate-shipping' ); ?>

												</h5>

												<span id="formula_sim_breakdown_resolution_badge" style="background: #e0f2fe; color: #0369a1; font-weight: 600; font-size: 11px; padding: 4px 10px; border-radius: 12px; border: 1px solid #bae6fd;">

													<?php esc_html_e( 'Resolution: Priority', 'formula-rate-shipping' ); ?>

												</span>

											</div>

											<div id="formula_sim_breakdown_body" style="font-size: 13px; color: #334155; line-height: 1.6;">

												<p style="margin: 0; font-style: italic; color: #64748b; text-align: center; padding: 12px 0;">

													<?php esc_html_e( 'Click "Calculate Shipping Preview" above to see step-by-step how rules and formulas are applied to your cart cost.', 'formula-rate-shipping' ); ?>

												</p>

											</div>

										</div>

									</div>



									<!-- SECTION 4: Technical Data & Calculation Trace Log (at bottom) -->

									<div class="formula-preview-section">

										<h4>4. <?php esc_html_e( 'Technical Data & Calculation Trace Log', 'formula-rate-shipping' ); ?></h4>

										<pre id="formula_sim_trace" style="margin:0; padding:12px; background:#0f172a; color:#f8fafc; font-family:Consolas, Monaco, monospace; font-size:12px; border-radius:6px; min-height: 200px; max-height: 380px; overflow-y: auto; white-space: pre-wrap; line-height: 1.5;"></pre>

									</div>

								</div>

							</div>

						</div>

					</div>



					<script type="text/javascript">

						(function($) {

							var storeCategories = <?php echo json_encode( $categories ); ?>;

							var storeTags = <?php echo json_encode( $tags ); ?>;

							var realProductsPool = <?php echo json_encode( $real_products ); ?>;



							function populateFilteredProducts() {

								var selectedCat = $('#formula_sim_filter_cat').val() || '';

								var selectedTag = $('#formula_sim_filter_tag').val() || '';

								var selectEle = $('#formula_sim_select_store_product');



								selectEle.empty().append('<option value="">-- Choose product from catalog --</option>');



								var count = 0;

								realProductsPool.forEach(function(rp) {

									var productCategories = Array.isArray(rp.categories) ? rp.categories : (rp.category ? [rp.category] : []);

									var productTags = Array.isArray(rp.tags) ? rp.tags : (rp.tag ? [rp.tag] : []);

									var catMatch = !selectedCat || productCategories.indexOf(selectedCat) !== -1;

									var tagMatch = !selectedTag || productTags.indexOf(selectedTag) !== -1;



									if (catMatch && tagMatch) {

										count++;

										var primaryCategory = rp.category || (productCategories[0] || '');

										var primaryTag = rp.tag || (productTags[0] || '');

										var optHtml = '<option value="' + rp.id + '" data-name="' + rp.name.replace(/"/g, '&quot;') + '" data-price="' + rp.price + '" data-sku="' + rp.sku + '" data-category="' + primaryCategory + '" data-tag="' + primaryTag + '" data-categories="' + productCategories.join('|') + '" data-tags="' + productTags.join('|') + '">' +

											rp.name + ' ($' + rp.price.toFixed(2) + ')' +

											'</option>';

										selectEle.append(optHtml);

									}

								});

							}



							$(document).on('change', '#formula_sim_filter_cat, #formula_sim_filter_tag', function() {

								populateFilteredProducts();

							});



							populateFilteredProducts();



							$(document).on('change', '#formula_sim_country', function() {

								var val = $(this).val();

								if (val === 'US' || val === 'PR') {

									$('#formula_sim_state_group').show();

								} else {

									$('#formula_sim_state_group').hide();

								}

							});



							function addMockItemRow(name, qty, cost, category, tag, variation) {

								var nameVal = name || 'Custom Item';

								var qtyVal = qty || 1;

								var costVal = (cost !== undefined) ? cost : 10.00;

								var categoryVal = category || '';

								var tagVal = tag || '';

								var variationVal = variation || '';



								var catOptions = '<option value="">(None)</option>';

								if (Array.isArray(storeCategories)) {

									storeCategories.forEach(function(c) {

										var selected = (c.slug === categoryVal) ? 'selected' : '';

										catOptions += '<option value="' + c.slug + '" ' + selected + '>' + c.name + '</option>';

									});

								}



								var tagOptions = '<option value="">(None)</option>';

								if (Array.isArray(storeTags)) {

									storeTags.forEach(function(t) {

										var selected = (t.slug === tagVal) ? 'selected' : '';

										tagOptions += '<option value="' + t.slug + '" ' + selected + '>' + t.name + '</option>';

									});

								}



								var rowHtml = '<tr class="formula-sim-item-row-main" style="background:#ffffff;">' +

									'<td><input type="text" class="formula-sim-item-name" value="' + nameVal.replace(/"/g, '&quot;') + '" style="width:100%; height:34px; font-weight:600;" placeholder="Item Name" /></td>' +

									'<td style="text-align:center;"><input type="number" class="formula-sim-item-qty" value="' + qtyVal + '" min="1" style="width:100%; text-align:center; height:34px;" /></td>' +

									'<td style="text-align:center;"><input type="number" class="formula-sim-item-cost" value="' + costVal + '" min="0" step="0.01" style="width:100%; text-align:center; height:34px;" /></td>' +

									'<td style="text-align:center;"><button type="button" class="button formula-sim-delete-item-btn" style="color:#ef4444; border-color:#fca5a5; height:34px; line-height:32px; padding:0 8px;">✕ Delete</button></td>' +

								'</tr>' +

								'<tr class="formula-sim-item-row-sub" style="background:#f8fafc; border-bottom: 2px solid #cbd5e1;">' +

									'<td colspan="4" style="padding: 6px 12px 12px 12px;">' +

										'<div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">' +

											'<div style="flex:1; min-width:110px;">' +

												'<label style="font-weight:600; font-size:10px; color:#64748b; display:block; margin-bottom:2px;">Category</label>' +

												'<select class="formula-sim-item-category" style="width:100%; height:30px; font-size:11px;">' + catOptions + '</select>' +

											'</div>' +

											'<div style="flex:1; min-width:110px;">' +

												'<label style="font-weight:600; font-size:10px; color:#64748b; display:block; margin-bottom:2px;">Tag</label>' +

												'<select class="formula-sim-item-tag" style="width:100%; height:30px; font-size:11px;">' + tagOptions + '</select>' +

											'</div>' +

											'<div style="flex:1.2; min-width:130px;">' +

												'<label style="font-weight:600; font-size:10px; color:#64748b; display:block; margin-bottom:2px;">Variation / SKU / Attr</label>' +

												'<input type="text" class="formula-sim-item-variation" value="' + variationVal.replace(/"/g, '&quot;') + '" placeholder="e.g. Hardcover, SKU-1" style="width:100%; height:30px; font-size:11px;" />' +

											'</div>' +

										'</div>' +

									'</td>' +

								'</tr>';



								$('#formula_sim_cart_table tbody').append(rowHtml);

							}



							var defaultFallbackProducts = [

								{ id: 'fb1', name: 'Mini-Book Edition', price: 12.99, category: 'paperback', tag: 'featured', categories: ['paperback'], tags: ['featured'], sku: 'BK-MINI-01' },

								{ id: 'fb2', name: 'Childrens Storybook', price: 15.50, category: 'childrens-books', tag: 'featured', categories: ['childrens-books'], tags: ['featured'], sku: 'BK-CHILD-02' },

								{ id: 'fb3', name: 'Pivotal Press Hardcover', price: 24.99, category: 'pivotal-press', tag: 'hardcover', categories: ['pivotal-press'], tags: ['hardcover'], sku: 'BK-PIV-03' },

								{ id: 'fb4', name: 'Standard Paperback', price: 8.50, category: 'paperback', tag: 'sale', categories: ['paperback'], tags: ['sale'], sku: 'BK-STD-04' }

							];



							function getEffectiveProductPool() {

								return (realProductsPool && realProductsPool.length > 0) ? realProductsPool : defaultFallbackProducts;

							}



							$(document).off('click', '#formula_sim_add_selected_product_btn').on('click', '#formula_sim_add_selected_product_btn', function(e) {

								e.preventDefault();

								var selectedOpt = $('#formula_sim_select_store_product option:selected');

								var pId = selectedOpt.val();

								if (pId) {

									var name = selectedOpt.attr('data-name');

									var price = parseFloat(selectedOpt.attr('data-price')) || 0.0;

									var sku = selectedOpt.attr('data-sku');

									var cat = selectedOpt.attr('data-category');

									var tag = selectedOpt.attr('data-tag');

									var productCategories = (selectedOpt.attr('data-categories') || '').split('|').filter(Boolean);

									var productTags = (selectedOpt.attr('data-tags') || '').split('|').filter(Boolean);

									var selectedCat = $('#formula_sim_filter_cat').val() || '';

									var selectedTag = $('#formula_sim_filter_tag').val() || '';

									if (selectedCat && productCategories.indexOf(selectedCat) !== -1) {

										cat = selectedCat;

									}

									if (selectedTag && productTags.indexOf(selectedTag) !== -1) {

										tag = selectedTag;

									}

									addMockItemRow(name, 1, price, cat, tag, sku);

								} else {

									var pool = getEffectiveProductPool();

									var randP = pool[Math.floor(Math.random() * pool.length)];

									addMockItemRow(randP.name, 1, randP.price, randP.category, randP.tag, randP.sku);

								}

							});



							$(document).off('click', '#formula_sim_add_item_btn').on('click', '#formula_sim_add_item_btn', function(e) {

								e.preventDefault();

								addMockItemRow('Custom Test Item', 1, 10.00, '', '', '');

							});



							$(document).off('click', '#formula_sim_add_random_btn').on('click', '#formula_sim_add_random_btn', function(e) {

								e.preventDefault();

								var pool = getEffectiveProductPool();

								var randP = pool[Math.floor(Math.random() * pool.length)];

								var qty = Math.floor(Math.random() * 2) + 1;

								addMockItemRow(randP.name, qty, randP.price, randP.category, randP.tag, randP.sku);

							});



							$(document).off('click', '#formula_sim_random_cart_btn').on('click', '#formula_sim_random_cart_btn', function(e) {

								e.preventDefault();

								$('#formula_sim_cart_table tbody').empty();

								var pool = getEffectiveProductPool();

								var numItems = Math.floor(Math.random() * 3) + 2;

								for (var i = 0; i < numItems; i++) {

									var randP = pool[Math.floor(Math.random() * pool.length)];

									var qty = Math.floor(Math.random() * 2) + 1;

									addMockItemRow(randP.name, qty, randP.price, randP.category, randP.tag, randP.sku);

								}

							});



							$(document).off('click', '#formula_sim_clear_btn').on('click', '#formula_sim_clear_btn', function(e) {

								e.preventDefault();

								$('#formula_sim_cart_table tbody').empty();

							});



							$('#formula_sim_cart_table').off('click', '.formula-sim-delete-item-btn').on('click', '.formula-sim-delete-item-btn', function(e) {

								e.preventDefault();

								var mainRow = $(this).closest('tr.formula-sim-item-row-main');

								if (mainRow.length) {

									mainRow.next('tr.formula-sim-item-row-sub').remove();

									mainRow.remove();

								} else {

									$(this).closest('tr').remove();

								}

							});



							function itemMatches(item, targetType, targetVal) {

								if (targetType === 'default') return true;

								if (!targetType || !targetVal) return true;

								targetVal = targetVal.toLowerCase().trim();

								var name = (item.name || '').toLowerCase().trim();

								var cat = (item.category || '').toLowerCase().trim();

								var tag = (item.tag || '').toLowerCase().trim();

								var varVal = (item.variation || item.sku || '').toLowerCase().trim();



								if (name && name.indexOf(targetVal) !== -1) return true;

								if (cat && (cat === targetVal || cat.indexOf(targetVal) !== -1 || targetVal.indexOf(cat) !== -1)) return true;

								if (tag && (tag === targetVal || tag.indexOf(targetVal) !== -1 || targetVal.indexOf(tag) !== -1)) return true;

								if (varVal && (varVal === targetVal || varVal.indexOf(targetVal) !== -1 || targetVal.indexOf(varVal) !== -1)) return true;



								return false;

							}



							function getSimulatorUserContext() {

								return {

									role: ($('#formula_sim_user_role').val() || '').trim(),

									login: ($('#formula_sim_user_login').val() || '').trim(),

									email: ($('#formula_sim_user_email').val() || '').trim(),

									metaKey: ($('#formula_sim_user_meta_key').val() || '').trim(),

									metaValue: ($('#formula_sim_user_meta_value').val() || '').trim()

								};

							}



							function matchesSimulatorUser(targetVal, matchType, metaKey, userContext) {

								targetVal = (targetVal || '').trim().toLowerCase();

								matchType = (matchType || 'auto').toLowerCase();

								metaKey = (metaKey || '').trim().toLowerCase();

								var simRole = (userContext.role || '').trim().toLowerCase();

								var simLogin = (userContext.login || '').trim().toLowerCase();

								var simEmail = (userContext.email || '').trim().toLowerCase();

								var simMetaKey = (userContext.metaKey || '').trim().toLowerCase();

								var simMetaValue = (userContext.metaValue || '').trim().toLowerCase();



								if (matchType === 'user' || matchType === 'user_role' || matchType === 'role') {

									return simRole !== '' && simRole === targetVal;

								}

								if (matchType === 'user_login' || matchType === 'login') {

									return simLogin !== '' && simLogin === targetVal;

								}

								if (matchType === 'user_email' || matchType === 'email') {

									return simEmail !== '' && simEmail === targetVal;

								}

								if (matchType === 'user_meta' || matchType === 'meta') {

									if (simMetaKey === '' || simMetaKey !== metaKey) {

										return false;

									}

									return targetVal === '' ? simMetaValue !== '' : simMetaValue === targetVal;

								}

								return matchesSimulatorUser(targetVal, 'user_role', '', userContext)

									|| matchesSimulatorUser(targetVal, 'user_email', '', userContext)

									|| matchesSimulatorUser(targetVal, 'user_login', '', userContext);

							}



							function evaluateFormula(formula, items, logTrace) {

								formula = formula.trim();

								if (!formula) return 0.0;



								var simUserContext = getSimulatorUserContext();



								// 0. Resolve context-aware variables ([category="X"], [tag="X"], [variation="X"], [user="X"], [user_role], [shipping_country], [shipping_state], [shipping_zip], [shipping_city], [is_international])

								var storeBaseCountry = '<?php echo esc_js( function_exists( 'WC' ) && WC() && isset( WC()->countries ) ? WC()->countries->get_base_country() : 'US' ); ?>';

								var countryMap = <?php echo wp_json_encode( function_exists( 'WC' ) && WC() && isset( WC()->countries ) && is_array( WC()->countries->countries ) ? WC()->countries->countries : array() ); ?>;

								function normalizeCountryReference(value) {

									var raw = String(value || '').trim();

									if (!raw) return '';

									var upper = raw.toUpperCase();

									if (/^[A-Z]{2}$/.test(upper)) return upper;

									var lower = raw.toLowerCase();

									for (var code in countryMap) {

										if (!Object.prototype.hasOwnProperty.call(countryMap, code)) continue;

										if (String(countryMap[code] || '').trim().toLowerCase() === lower) {

											return String(code).toUpperCase();

										}

									}

									return upper;

								}

								formula = formula.replace(/\[category\s*=\s*['"]([^'"]+)['"]\]/gi, function(match, targetVal) {

									targetVal = targetVal.toLowerCase().trim();

									var hasMatch = items.some(function(item) { return itemMatches(item, 'category', targetVal); });

									if (logTrace) {

										logTrace.push('  Resolved [category="' + targetVal + '"] -> ' + (hasMatch ? '1 (Match)' : '0 (No Match)'));

									}

									return hasMatch ? '1' : '0';

								});



								formula = formula.replace(/\[tag\s*=\s*['"]([^'"]+)['"]\]/gi, function(match, targetVal) {

									targetVal = targetVal.toLowerCase().trim();

									var hasMatch = items.some(function(item) { return itemMatches(item, 'tag', targetVal); });

									if (logTrace) {

										logTrace.push('  Resolved [tag="' + targetVal + '"] -> ' + (hasMatch ? '1 (Match)' : '0 (No Match)'));

									}

									return hasMatch ? '1' : '0';

								});



								formula = formula.replace(/\[variation\s*=\s*['"]([^'"]+)['"]\]/gi, function(match, targetVal) {

									targetVal = targetVal.toLowerCase().trim();

									var hasMatch = items.some(function(item) { return itemMatches(item, 'variation', targetVal); });

									if (logTrace) {

										logTrace.push('  Resolved [variation="' + targetVal + '"] -> ' + (hasMatch ? '1 (Match)' : '0 (No Match)'));

									}

									return hasMatch ? '1' : '0';

								});



								formula = formula.replace(/\[user\s*=\s*['"]([^'"]+)['"]\]/gi, function(match, targetVal) {

									var matched = matchesSimulatorUser(targetVal, 'auto', '', simUserContext);

									if (logTrace) {

										logTrace.push('  Resolved [user="' + targetVal + '"] -> ' + (matched ? '1 (Match)' : '0 (No Match)'));

									}

									return matched ? '1' : '0';

								});



								formula = formula.replace(/\[user_role\]/gi, simUserContext.role || 'guest');



								var simCountry = normalizeCountryReference($('#formula_sim_country').val() || 'US');

								var simState   = $('#formula_sim_state').val() || '';

								var simZip     = $('#formula_sim_zip').val() || '';

								var simCity    = $('#formula_sim_city').val() || '';



								formula = formula.replace(/\[shipping_country\]/gi, simCountry);

								formula = formula.replace(/\[shipping_state\]/gi, simState);

								formula = formula.replace(/\[shipping_zip\]/gi, simZip);

								formula = formula.replace(/\[shipping_city\]/gi, simCity);

								formula = formula.replace(/\[is_international(?:\s*=\s*['"]([^'"]+)['"])?\]/gi, function(match, warehouseCountry) {

									var compareCountry = normalizeCountryReference(warehouseCountry || storeBaseCountry || 'US');

									return (simCountry && simCountry.toUpperCase() !== compareCountry) ? '1' : '0';

								});

								formula = formula.replace(/\[(?:notice|message|warning)\s*=\s*['"][^'"]+['"](?:\s+position\s*=\s*['"][^'"]+['"])?\]/gi, '0');
								formula = formula.replace(/\[(?:fee|discount|sale)[^\]]*\]/gi, '0');



								// Resolve [product="X"] - returns 1 if any item name, SKU, or product number contains X

								formula = formula.replace(/\[product\s*=\s*['"]([^'"]+)['"]\]/gi, function(match, targetVal) {

									targetVal = targetVal.toLowerCase().trim();

									var hasMatch = items.some(function(item) {

										var name = (item.name || '').toLowerCase().trim();

										var sku = (item.sku || item.variation || '').toLowerCase().trim();

										var productId = String(item.product_id || item.id || '').toLowerCase().trim();

										return (name && name.indexOf(targetVal) !== -1)

											|| (sku && sku.indexOf(targetVal) !== -1)

											|| (productId && productId === targetVal);

									});

									if (logTrace) {

										logTrace.push('  Resolved [product="' + targetVal + '"] -> ' + (hasMatch ? '1 (Match)' : '0 (No Match)'));

									}

									return hasMatch ? '1' : '0';

								});



								// Resolve IN operator: value IN (A, B, C) -> value == A OR value == B OR value == C

								var maxInIter = 50, inIter = 0;

								while (/([^\s(]+)\s+IN\s*\(([^)]+)\)/i.test(formula) && inIter < maxInIter) {

									inIter++;

									formula = formula.replace(/([^\s(]+)\s+IN\s*\(([^)]+)\)/i, function(m, leftVal, rightList) {

										var vals = rightList.split(',').map(function(v) { return v.trim(); });

										var orParts = vals.map(function(v) { return leftVal + ' == ' + v; });

										return '(' + orParts.join(' OR ') + ')';

									});

								}



								// Resolve BETWEEN operator: value BETWEEN low AND high -> value >= low AND value <= high

								var maxBetIter = 50, betIter = 0;

								while (/([^\s(]+)\s+BETWEEN\s+([^\s]+)\s+AND\s+([^\s)]+)/i.test(formula) && betIter < maxBetIter) {

									betIter++;

									formula = formula.replace(/([^\s(]+)\s+BETWEEN\s+([^\s]+)\s+AND\s+([^\s)]+)/i, function(m, val, low, high) {

										return '(' + val + ' >= ' + low + ' AND ' + val + ' <= ' + high + ')';

									});

								}



								// Resolve CONTAINS operator: value CONTAINS "text" -> 1 or 0

								var maxContIter = 50, contIter = 0;

								while (/([^\s(]+)\s+CONTAINS\s+['"]([^'"]+)['"]/i.test(formula) && contIter < maxContIter) {

									contIter++;

									formula = formula.replace(/([^\s(]+)\s+CONTAINS\s+['"]([^'"]+)['"]/i, function(m, haystack, needle) {

										var hayStr = String(evalSimpleExpr(haystack)).toLowerCase();

										var needStr = needle.toLowerCase();

										return hayStr.indexOf(needStr) !== -1 ? '1' : '0';

									});

								}



								// Resolve [rate] placeholders

								var rateRegex = /\[rate\s+([^\]]+)\]/gi;

								formula = formula.replace(rateRegex, function(match, attributesStr) {

									var percent = 0;

									var min_fee = null;

									var max_fee = null;

									var targetType = '';

									var targetVal = '';



									var attrMatches = attributesStr.matchAll(/(percent|min_fee|max_fee|category|tag|variation)\s*=\s*(?:['"]([^'"]+)['"]|([0-9.]+))/gi);

									for (var m of attrMatches) {

										var key = m[1].toLowerCase();

										var valStr = m[2] !== undefined ? m[2] : m[3];

										if (key === 'percent') percent = parseFloat(valStr);

										else if (key === 'min_fee') min_fee = parseFloat(valStr);

										else if (key === 'max_fee') max_fee = parseFloat(valStr);

										else if (['category', 'tag', 'variation'].includes(key)) {

											targetType = key;

											targetVal = valStr.toLowerCase().trim();

										}

									}



									var matchingCost = 0.0;

									items.forEach(function(item) {

										if (itemMatches(item, targetType, targetVal)) {

											matchingCost += parseFloat(item.cost) * parseInt(item.qty);

										}

									});



									var calculatedRate = matchingCost * (percent / 100);

									if (min_fee !== null && calculatedRate < min_fee) calculatedRate = min_fee;

									if (max_fee !== null && calculatedRate > max_fee) calculatedRate = max_fee;



									if (logTrace) {

										logTrace.push('  Resolved [rate] percent=' + percent + ' target=' + (targetType ? targetType + ':' + targetVal : 'all') + ' matching_cost=' + matchingCost.toFixed(2) + ' -> rate=' + calculatedRate.toFixed(2));

									}



									return calculatedRate.toFixed(4);

								});



								// Resolve [qty], [cost], [weight], [items], [volume], [length], [width], [height] placeholders

								var placeholderRegex = /\[(qty|cost|weight|items|volume|length|width|height)(?:\s+(category|tag|variation)\s*=\s*['"]([^'"]+)['"])?\]/gi;

								formula = formula.replace(placeholderRegex, function(match, type, targetType, targetVal) {

									type = type.toLowerCase();

									targetType = targetType ? targetType.toLowerCase() : '';

									targetVal = targetVal ? targetVal.toLowerCase().trim() : '';



									var calcQty = 0;

									var calcCost = 0.0;

									var calcWeight = 0.0;

									var calcItems = 0;

									var calcVolume = 0.0;

									var calcLength = 0.0;

									var calcWidth = 0.0;

									var calcHeight = 0.0;



									items.forEach(function(item) {

										if (itemMatches(item, targetType, targetVal)) {

											var q = parseInt(item.qty) || 1;

											calcQty += q;

											calcCost += parseFloat(item.cost) * q;

											calcItems += 1;

											var w = parseFloat(item.weight) || 0.0;

											calcWeight += w * q;



											var l = parseFloat(item.length) || 0.0;

											var wd = parseFloat(item.width) || 0.0;

											var ht = parseFloat(item.height) || 0.0;

											var vol = l * wd * ht;

											calcVolume += vol * q;



											if (l > calcLength) calcLength = l;

											if (wd > calcWidth) calcWidth = wd;

											if (ht > calcHeight) calcHeight = ht;

										}

									});



									if (logTrace) {

										logTrace.push('  Resolved [' + type + '] target=' + (targetType ? targetType + ':' + targetVal : 'all') + ' -> qty=' + calcQty + ', cost=' + calcCost.toFixed(2) + ', weight=' + calcWeight.toFixed(2) + ', volume=' + calcVolume.toFixed(2) + ', maxDim=' + calcLength + 'x' + calcWidth + 'x' + calcHeight);

									}



									if (type === 'qty') return calcQty;

									if (type === 'weight') return calcWeight.toFixed(4);

									if (type === 'items') return calcItems;

									if (type === 'volume') return calcVolume.toFixed(4);

									if (type === 'length') return calcLength.toFixed(4);

									if (type === 'width') return calcWidth.toFixed(4);

									if (type === 'height') return calcHeight.toFixed(4);

									return calcCost.toFixed(4);

								});



								// Resolve math helper functions (innermost first)

								var mathFunctionRegex = /(min|max|sum|avg|average|clamp|round|ceil|floor|abs)\s*\(([^()]*)\)/i;

								var mathFunctionIter = 0;

								while (mathFunctionRegex.test(formula) && mathFunctionIter < 50) {

									mathFunctionIter++;

									var mathMatch = formula.match(mathFunctionRegex);

									var functionName = mathMatch[1].toLowerCase();

									var functionArgs = splitIfArgs(mathMatch[2]).map(function(arg) { return evalSimpleExpr(arg); });

									var functionResult = 0;

									if (functionName === 'min') functionResult = Math.min.apply(Math, functionArgs);

									else if (functionName === 'max') functionResult = Math.max.apply(Math, functionArgs);

									else if (functionName === 'sum') functionResult = functionArgs.reduce(function(a, b) { return a + b; }, 0);

									else if (functionName === 'avg' || functionName === 'average') {

										functionResult = functionArgs.length > 0 ? functionArgs.reduce(function(a, b) { return a + b; }, 0) / functionArgs.length : 0;

									} else if (functionName === 'clamp') {

										var val = functionArgs[0] || 0;

										var minV = functionArgs[1] !== undefined ? functionArgs[1] : 0;

										var maxV = functionArgs[2] !== undefined ? functionArgs[2] : val;

										if (val < minV) val = minV;

										if (val > maxV) val = maxV;

										functionResult = val;

									}

									else if (functionName === 'ceil') functionResult = Math.ceil(functionArgs[0] || 0);

									else if (functionName === 'floor') functionResult = Math.floor(functionArgs[0] || 0);

									else if (functionName === 'abs') functionResult = Math.abs(functionArgs[0] || 0);

									else if (functionName === 'round') {

										var precision = functionArgs.length > 1 ? parseInt(functionArgs[1], 10) : 0;

										var factor = Math.pow(10, precision);

										functionResult = Math.round((functionArgs[0] || 0) * factor) / factor;

									}

									formula = formula.replace(mathMatch[0], functionResult.toFixed(4));

								}



								// Resolve if() function calls (innermost first for nesting)

								var maxIterations = 50;

								var iter = 0;

								var ifRegex = /if\s*\(([^()]*(?:\([^()]*\)[^()]*)*)\)/i;

								while (ifRegex.test(formula) && iter < maxIterations) {

									iter++;

									var ifMatch = formula.match(ifRegex);

									if (!ifMatch) break;

									var innerArgs = ifMatch[1];

									var parts = splitIfArgs(innerArgs);

									if (parts.length !== 3) {

										throw new Error("if() requires exactly 3 arguments: if(condition, true_val, false_val)");

									}

									var condExpr = parts[0].trim();

									var trueExpr = parts[1].trim();

									var falseExpr = parts[2].trim();

									var condVal = evalSimpleExpr(condExpr);

									var resultExpr = (condVal != 0) ? trueExpr : falseExpr;

									var resultVal = evalSimpleExpr(resultExpr);

									formula = formula.replace(ifMatch[0], resultVal.toFixed(4));

								}



								// Resolve ternary expressions: condition ? true_val : false_val

								var maxTernaryIter = 50;

								var ternaryIter = 0;

								var ternaryRegex = /([^?:]+)\s*\?\s*([^?:]+?)\s*:\s*([^?:]+)/;

								while (ternaryRegex.test(formula) && ternaryIter < maxTernaryIter) {

									ternaryIter++;

									var ternaryMatch = formula.match(ternaryRegex);

									if (!ternaryMatch) break;

									var condExpr = ternaryMatch[1].trim();

									var trueExpr = ternaryMatch[2].trim();

									var falseExpr = ternaryMatch[3].trim();

									var condVal = evalSimpleExpr(condExpr);

									var resultExpr = (condVal != 0) ? trueExpr : falseExpr;

									var resultVal = evalSimpleExpr(resultExpr);

									if (logTrace) {

										logTrace.push('  Evaluated Ternary: (' + condExpr + ' = ' + condVal + ') ? (' + trueExpr + ') : (' + falseExpr + ') -> ' + resultVal.toFixed(4));

									}

									formula = formula.replace(ternaryMatch[0], resultVal.toFixed(4));

								}



								// Evaluate the remaining arithmetic/comparison expression

								var cleanFormula = formula.replace(/\s+/g, '');

								if (!/^[0-9\+\-\*\/\%\^\(\)\.\>\<\=\!]+$/.test(cleanFormula)) {

									throw new Error("Invalid characters in formula expression: " + cleanFormula);

								}



								try {

									var val = evalArithmeticExpr(cleanFormula);

									return parseFloat(val) || 0.0;

								} catch(e) {

									throw new Error("Syntax error in formula evaluation: " + cleanFormula + " (" + e.message + ")");

								}

							}



							// Split if() arguments by commas at depth 0

							function splitIfArgs(argsStr) {

								var parts = [];

								var current = '';

								var depth = 0;

								for (var i = 0; i < argsStr.length; i++) {

									var ch = argsStr[i];

									if (ch === '(') { depth++; current += ch; }

									else if (ch === ')') { depth--; current += ch; }

									else if (ch === ',' && depth === 0) { parts.push(current); current = ''; }

									else { current += ch; }

								}

								if (current !== '') parts.push(current);

								return parts;

							}



							// Evaluate a simple expression (no placeholders, no if())

							function evalSimpleExpr(expr) {

								expr = expr.trim();

								if (expr === '') return 0.0;

								if (!isNaN(parseFloat(expr)) && isFinite(expr)) return parseFloat(expr);

								try {

									return evalArithmeticExpr(expr.replace(/\s+/g, ''));

								} catch(e) {

									return 0.0;

								}

							}



							// Shunting-yard arithmetic evaluator with comparison operators

							function evalArithmeticExpr(expr) {

								if (!expr) return 0.0;

								// Tokenize

								var tokenRegex = /[0-9]+(?:\.[0-9]+)?|>=|<=|==|!=|[><+\-\*\/\%\^\(\)]/g;

								var tokens = expr.match(tokenRegex) || [];

								if (tokens.length === 0) return 0.0;



								// Handle unary minus/plus

								var processed = [];

								var prev = null;

								var compOps = ['>=', '<=', '==', '!=', '>', '<'];

								for (var i = 0; i < tokens.length; i++) {

									var t = tokens[i];

									if (t === '-' || t === '+') {

										var isUnary = (prev === null || ['+', '-', '*', '/', '%', '^', '(', ...compOps].indexOf(prev) !== -1);

										if (isUnary) {

											if (t === '-') processed.push('u-');

											prev = t;

											continue;

										}

									}

									processed.push(t);

									prev = t;

								}



								// Shunting-yard

								var output = [];

								var opStack = [];

								var prec = { 'u-': 7, '^': 6, '*': 4, '/': 4, '%': 4, '+': 3, '-': 3, '>': 2, '<': 2, '>=': 2, '<=': 2, '==': 1, '!=': 1 };



								for (var i = 0; i < processed.length; i++) {

									var tok = processed[i];

									if (!isNaN(parseFloat(tok))) {

										output.push(parseFloat(tok));

									} else if (prec[tok] !== undefined) {

										while (opStack.length > 0 && opStack[opStack.length - 1] !== '(') {

											var top = opStack[opStack.length - 1];

											var rightAssociative = (tok === 'u-' || tok === '^');

											if (prec[top] !== undefined && ((!rightAssociative && prec[tok] <= prec[top]) || (rightAssociative && prec[tok] < prec[top]))) {

												output.push(opStack.pop());

											} else break;

										}

										opStack.push(tok);

									} else if (tok === '(') {

										opStack.push(tok);

									} else if (tok === ')') {

										var matched = false;

										while (opStack.length > 0) {

											var op = opStack.pop();

											if (op === '(') { matched = true; break; }

											output.push(op);

										}

										if (!matched) throw new Error("Mismatched parentheses");

									}

								}

								while (opStack.length > 0) {

									var op = opStack.pop();

									if (op === '(' || op === ')') throw new Error("Mismatched parentheses");

									output.push(op);

								}



								// Evaluate RPN

								var stack = [];

								for (var i = 0; i < output.length; i++) {

									var tok = output[i];

									if (typeof tok === 'number') {

										stack.push(tok);

									} else if (tok === 'u-') {

										if (stack.length < 1) throw new Error("Invalid syntax");

										stack.push(-stack.pop());

									} else {

										if (stack.length < 2) throw new Error("Invalid syntax");

										var b = stack.pop();

										var a = stack.pop();

										switch (tok) {

											case '+': stack.push(a + b); break;

											case '-': stack.push(a - b); break;

											case '*': stack.push(a * b); break;

											case '/': if (b === 0) throw new Error("Division by zero"); stack.push(a / b); break;

											case '%': if (b === 0) throw new Error("Modulo by zero"); stack.push(a % b); break;

											case '^': stack.push(Math.pow(a, b)); break;

											case '>': stack.push(a > b ? 1.0 : 0.0); break;

											case '<': stack.push(a < b ? 1.0 : 0.0); break;

											case '>=': stack.push(a >= b ? 1.0 : 0.0); break;

											case '<=': stack.push(a <= b ? 1.0 : 0.0); break;

											case '==': stack.push(a == b ? 1.0 : 0.0); break;

											case '!=': stack.push(a != b ? 1.0 : 0.0); break;

										}

									}

								}

								if (stack.length !== 1) throw new Error("Malformed expression");

								return stack[0];

							}



							function customerMatchesLocation(dest, ruleCountry, ruleState, ruleLocPattern, ruleType, ruleUser, ruleMetaKey, ruleMetaValue, userContext, ruleDevice, ruleOs) {

								function parseRuleTokens(value, allowSpaceDelimited) {

									var raw = String(value || '').trim();

									if (!raw) {

										return [];

									}

									if (raw.indexOf(',') !== -1) {

										return raw.split(',').map(function(part) { return part.trim(); }).filter(Boolean);

									}

									if (allowSpaceDelimited && /\s+/.test(raw)) {

										return raw.split(/\s+/).map(function(part) { return part.trim(); }).filter(Boolean);

									}

									return [raw];

								}



								function tokenListContainsAll(tokens) {

									return tokens.some(function(token) {

										return String(token || '').trim().toUpperCase() === 'ALL';

									});

								}



								function normalizeCountryReferenceForLocation(value) {

									var raw = String(value || '').trim();

									if (!raw) {

										return '';

									}

									var upper = raw.toUpperCase();

									if (/^[A-Z]{2}$/.test(upper)) {

										return upper;

									}

									var countries = window.formulaCountrySuggestions || [];

									var lower = raw.toLowerCase();

									for (var i = 0; i < countries.length; i++) {

										var country = countries[i] || {};

										if (String(country.value || '').toLowerCase() === lower) {

											return String(country.value || '').toUpperCase();

										}

										var label = String(country.label || '').toLowerCase();

										if (label === lower || label.indexOf(lower + ' (') === 0) {

											return String(country.value || '').toUpperCase();

										}

									}

									return upper;

								}



								function countryRuleMatches(custCountry, ruleValue) {

									var actualCountry = normalizeCountryReferenceForLocation(custCountry);

									var rawRule = String(ruleValue || '').trim();

									if (!rawRule) {

										return true;

									}

									if (rawRule.toUpperCase() === 'ALL') {

										return true;

									}

									if (normalizeCountryReferenceForLocation(rawRule) === actualCountry) {

										return true;

									}

									var allowSpaceDelimited = /^[A-Za-z]{2,3}(?:\s+[A-Za-z]{2,3})+$/.test(rawRule);

									var tokens = parseRuleTokens(rawRule, allowSpaceDelimited);

									if (tokenListContainsAll(tokens)) {

										return true;

									}

									return tokens.some(function(token) {

										return normalizeCountryReferenceForLocation(token) === actualCountry;

									});

								}



								function simpleTokenRuleMatches(actualValue, ruleValue, allowSpaceDelimited) {

									var rawRule = String(ruleValue || '').trim();

									if (!rawRule) {

										return true;

									}

									var tokens = parseRuleTokens(rawRule, allowSpaceDelimited);

									if (tokenListContainsAll(tokens)) {

										return true;

									}

									var lowerActual = String(actualValue || '').trim().toLowerCase();

									return tokens.some(function(token) {

										return lowerActual === String(token || '').trim().toLowerCase();

									});

								}



								function normalizeDeviceReference(value) {

									var lowerValue = String(value || '').trim().toLowerCase();

									var aliases = {

										phone: 'mobile',

										cell: 'mobile',

										smartphone: 'mobile',

										computer: 'desktop',

										laptop: 'desktop',

										pc: 'desktop',

										ipad: 'tablet'

									};

									return aliases[lowerValue] || lowerValue;

								}



								function normalizeOsReference(value) {

									var raw = String(value || '').trim();

									if (!raw) {

										return '';

									}

									var lowerValue = raw.toLowerCase().replace(/_/g, ' ').replace(/\s+/g, ' ');

									var windows = [

										[/^(?:windows\s*11|win\s*11|win11)$/, 'Windows 11'],

										[/^(?:windows\s*10|win\s*10|win10)$/, 'Windows 10'],

										[/^(?:windows\s*8\.1|win\s*8\.1|win8\.1)$/, 'Windows 8.1'],

										[/^(?:windows\s*8|win\s*8|win8)$/, 'Windows 8'],

										[/^(?:windows\s*7|win\s*7|win7)$/, 'Windows 7'],

										[/^(?:windows\s+vista|vista)$/, 'Windows Vista'],

										[/^(?:windows\s+xp|xp)$/, 'Windows XP'],

										[/^(?:windows|win)$/, 'Windows']

									];

									for (var wi = 0; wi < windows.length; wi++) {

										if (windows[wi][0].test(lowerValue)) {

											return windows[wi][1];

										}

									}

									var macs = [

										[/^(?:mac(?:\s*os)?|os\s*x|macos)\s+sequoia$|^sequoia$/, 'MacOS Sequoia'],

										[/^(?:mac(?:\s*os)?|os\s*x|macos)\s+sonoma$|^sonoma$/, 'MacOS Sonoma'],

										[/^(?:mac(?:\s*os)?|os\s*x|macos)\s+ventura$|^ventura$/, 'MacOS Ventura'],

										[/^(?:mac(?:\s*os)?|os\s*x|macos)\s+monterey$|^monterey$/, 'MacOS Monterey'],

										[/^(?:mac(?:\s*os)?|os\s*x|macos)\s+big sur$|^big sur$/, 'MacOS Big Sur'],

										[/^(?:mac(?:\s*os)?|os\s*x|macos)\s+catalina$|^catalina$/, 'MacOS Catalina'],

										[/^(?:mac(?:\s*os)?|os\s*x|macos)\s+high sierra$|^high sierra$/, 'MacOS High Sierra'],

										[/^(?:mac(?:\s*os)?|os\s*x|macos)$/, 'MacOS']

									];

									for (var mi = 0; mi < macs.length; mi++) {

										if (macs[mi][0].test(lowerValue)) {

											return macs[mi][1];

										}

									}

									if (/^ios\s+([0-9]+(?:\.[0-9]+)?)$/.test(lowerValue)) return 'iOS ' + lowerValue.match(/^ios\s+([0-9]+(?:\.[0-9]+)?)$/)[1];

									if (lowerValue === 'ios') return 'iOS';

									if (/^android\s+([0-9]+(?:\.[0-9]+)?)$/.test(lowerValue)) return 'Android ' + lowerValue.match(/^android\s+([0-9]+(?:\.[0-9]+)?)$/)[1];

									if (lowerValue === 'android') return 'Android';

									if (lowerValue === 'chrome os' || lowerValue === 'chromeos') return 'ChromeOS';

									if (lowerValue === 'linux') return 'Linux';

									return raw.replace(/\b\w/g, function(ch) { return ch.toUpperCase(); });

								}



								function environmentTargetMatches(clientValue, ruleValue, environmentType) {

									var tokens = parseRuleTokens(ruleValue, false);

									if (!tokens.length || tokenListContainsAll(tokens)) {

										return true;

									}

									if (environmentType === 'device') {

										var normalizedClientDevice = normalizeDeviceReference(clientValue);

										return tokens.some(function(token) {

											return normalizedClientDevice === normalizeDeviceReference(token);

										});

									}

									var normalizedClientOs = normalizeOsReference(clientValue).toLowerCase();

									return tokens.some(function(token) {

										var normalizedTargetOs = normalizeOsReference(token).toLowerCase();

										if (!normalizedTargetOs) return false;

										return normalizedClientOs === normalizedTargetOs || normalizedClientOs.indexOf(normalizedTargetOs + ' ') === 0 || normalizedTargetOs.indexOf(normalizedClientOs + ' ') === 0;

									});

								}



								var custCountry = (dest.country || '').toUpperCase().trim();

								if (!countryRuleMatches(custCountry, ruleCountry)) {

									return false;

								}



								var custState = (dest.state || '').toUpperCase().trim();

								if (!simpleTokenRuleMatches(custState, ruleState, true)) {

									return false;

								}



								ruleType = (ruleType || 'location').trim().toLowerCase();

								if (ruleType === 'user') {

									ruleType = 'user_role';

								}



								if (ruleType === 'device_os' || ruleType === 'device') {

									var clientOS = window.formulaClientContext ? window.formulaClientContext.os : 'Windows';

									var clientDevice = window.formulaClientContext ? window.formulaClientContext.device : 'desktop';

									var legacyValue = String(ruleUser || '').trim();

									if (!String(ruleDevice || '').trim() && !String(ruleOs || '').trim() && legacyValue) {

										var legacyTargets = parseRuleTokens(legacyValue, false);

										if (!tokenListContainsAll(legacyTargets) && legacyTargets.length) {

											var legacyMatched = legacyTargets.some(function(target) {

												return environmentTargetMatches(clientDevice, target, 'device') || environmentTargetMatches(clientOS, target, 'os');

											});

											if (!legacyMatched) return false;

										}

									} else {

										if (!environmentTargetMatches(clientDevice, ruleDevice, 'device')) return false;

										if (!environmentTargetMatches(clientOS, ruleOs, 'os')) return false;

									}

								} else if (ruleType === 'browser') {

									var clientBrowser = (window.formulaClientContext ? window.formulaClientContext.browser : 'Chrome').toLowerCase();

									if (!simpleTokenRuleMatches(clientBrowser, ruleUser, true)) return false;

								} else if (['user_role', 'user_login', 'user_email', 'user_meta'].indexOf(ruleType) !== -1) {

									var targetValue = (ruleType === 'user_meta') ? (ruleMetaValue || '') : (ruleUser || '');

									if (!matchesSimulatorUser(targetValue, ruleType, ruleMetaKey || '', userContext)) {

										return false;

									}

								} else if (ruleUser && ruleUser.toUpperCase() !== 'ALL') {

									if (!matchesSimulatorUser(ruleUser, 'auto', '', userContext)) {

										return false;

									}

								}



								ruleLocPattern = (ruleLocPattern || '').trim();

								if (!ruleLocPattern) return true;



								var custCity = (dest.city || '').toLowerCase().trim();

								var custPostcode = (dest.zip || '').toLowerCase().trim();

								var pattern = ruleLocPattern.toLowerCase();



								if (pattern.indexOf('*') !== -1) {

									var escapeRegex = function(s) { return s.replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&'); };

									var regexStr = '^' + escapeRegex(pattern).replace(/\\\*/g, '.*') + '$';

									var regex = new RegExp(regexStr, 'i');

									return regex.test(custPostcode) || regex.test(custCity);

								} else {

									return (custPostcode === pattern || custCity === pattern || custCity.indexOf(pattern) !== -1 || custPostcode.indexOf(pattern) !== -1);

								}

							}



							$(document).off('click', '#formula_sim_calculate_btn').on('click', '#formula_sim_calculate_btn', function(e) {

								e.preventDefault();



								var country = $('#formula_sim_country').val();

								var state = $('#formula_sim_state').val();

								var city = $('#formula_sim_city').val();

								var zip = $('#formula_sim_zip').val();

								var userContext = {

									role: ($('#formula_sim_user_role').val() || '').trim(),

									login: ($('#formula_sim_user_login').val() || '').trim(),

									email: ($('#formula_sim_user_email').val() || '').trim(),

									metaKey: ($('#formula_sim_user_meta_key').val() || '').trim(),

									metaValue: ($('#formula_sim_user_meta_value').val() || '').trim()

								};



								var traceLog = [];

								traceLog.push('=== STARTING SIMULATION ===');

								traceLog.push('Destination Address: Country=' + country + ', State=' + state + ', City=' + city + ', ZIP=' + zip);



								$('#formula_sim_cart_table').on('click', '.formula-sim-delete-item-btn', function(e) {

									e.preventDefault();

									var mainRow = $(this).closest('tr');

									mainRow.next('tr.formula-sim-item-row-sub').remove();

									mainRow.remove();

								});



								var items = [];

								$('#formula_sim_cart_table tbody tr.formula-sim-item-row-main').each(function() {

									var mainRow = $(this);

									var subRow = mainRow.next('tr.formula-sim-item-row-sub');

									items.push({

										name: mainRow.find('.formula-sim-item-name').val(),

										qty: parseInt(mainRow.find('.formula-sim-item-qty').val()) || 1,

										cost: parseFloat(mainRow.find('.formula-sim-item-cost').val()) || 0.0,

										category: subRow.find('.formula-sim-item-category').val() || '',

										tag: subRow.find('.formula-sim-item-tag').val() || '',

										variation: subRow.find('.formula-sim-item-variation').val() || '',

										sku: subRow.find('.formula-sim-item-variation').val() || ''

									});

								});



								traceLog.push('Cart Contents: ' + items.length + ' item types.');

								items.forEach(function(item) {

									traceLog.push('  - ' + item.name + ' x ' + item.qty + ' @ $' + item.cost.toFixed(2) + ' [Cat=' + (item.category || 'none') + ', Tag=' + (item.tag || 'none') + ', Var=' + (item.variation || 'none') + ']');

								});



								var shipping_cost = 0.0;

								var handling_cost = 0.0;



								var defaultShipF = $('[name*="usa_default_shipping_formula"]').val() || '4.50 + ([qty] - 1) * 2.50';

								var defaultHandF = $('[name*="usa_default_handling_formula"]').val() || '3.00';



									var resolutionLogic = $('[name*="usa_rule_resolution"]').val() || 'first_match';

									traceLog.push('USA Item Rule Resolution Logic: ' + resolutionLogic);

									

									var itemTableRows = $('#formula_shipping_rules_table_usa_item_rules tbody tr.formula-rule-row-main');

									traceLog.push('Evaluating ' + itemTableRows.length + ' item rules.');

									var itemShippingAdd = 0.0;

									var itemHandlingAdd = 0.0;

									var matchedItemIndices = {};

									var appliedRules = [];

									var appliedAdjustments = [];

									var matchedLocationOverride = null;



									for (var i = 0; i < itemTableRows.length; i++) {

										var row = $(itemTableRows[i]);

										var formulaRow = row.next('tr.formula-rule-row-formulas');

										var isEnabled = row.find('.formula-rule-enabled-toggle').is(':checked');

										if (!isEnabled) {

											traceLog.push('  Rule #' + (i + 1) + ' skipped: disabled');

											continue;

										}

										var rType = row.find('select[name*="[type]"]').val();

										var rTarget = row.find('select[name*="[target]"], input[name*="[target]"]').val() || '';

										var rShipF = formulaRow.length ? formulaRow.find('input[name*="[shipping_formula]"]').val() || '' : '';

										var rHandF = formulaRow.length ? formulaRow.find('input[name*="[handling_formula]"]').val() || '' : '';



										if (rType === 'default') continue;

										rTarget = rTarget.toLowerCase().trim();



										var matchingItems = [];

										items.forEach(function(item, idx) {

											if (matchedItemIndices[idx]) return;



											if (itemMatches(item, rType, rTarget)) {

												matchingItems.push(item);

												matchedItemIndices[idx] = true;

											}

										});



										if (matchingItems.length > 0) {

											traceLog.push('  Rule #' + (i+1) + ' MATCHED: Type=' + rType + ' Target="' + rTarget + '" (' + matchingItems.length + ' item(s))');

											var rShipCost = 0.0;

											var rHandCost = 0.0;



											if (rShipF) {

												try { rShipCost = evaluateFormula(rShipF, matchingItems, traceLog); } catch(err) {}

											}

											if (rHandF) {

												try { rHandCost = evaluateFormula(rHandF, matchingItems, traceLog); } catch(err) {}

											}



											appliedRules.push({

												shipping: rShipCost,

												handling: rHandCost,

												base_ship: rShipCost,

												incremental: (matchingItems.length > 1 ? rShipCost / matchingItems.length : rShipCost),

												qty: matchingItems.reduce(function(acc, i){ return acc + (i.qty || 1); }, 0),

												total: rShipCost + rHandCost,

												index: '#' + (i + 1),

												type: rType,

												target: rTarget,

												shipping_formula: rShipF,

												handling_formula: rHandF,

												items: matchingItems.map(function(mi) { return mi.name + ' (x' + mi.qty + ')'; })

											});

										}

									}



									var uncategorizedItems = [];

									items.forEach(function(item, idx) {

										if (!matchedItemIndices[idx]) uncategorizedItems.push(item);

									});



									if (uncategorizedItems.length > 0 && (defaultShipF || defaultHandF)) {

										traceLog.push('  Default Rule MATCHED for ' + uncategorizedItems.length + ' uncategorized item(s)');

										var rShipCost = 0.0;

										var rHandCost = 0.0;



										if (defaultShipF) {

											try { rShipCost = evaluateFormula(defaultShipF, uncategorizedItems, traceLog); } catch(err) {}

										}

										if (defaultHandF) {

											try { rHandCost = evaluateFormula(defaultHandF, uncategorizedItems, traceLog); } catch(err) {}

										}



										appliedRules.push({

											shipping: rShipCost,

											handling: rHandCost,

											base_ship: rShipCost,

											incremental: (uncategorizedItems.length > 1 ? rShipCost / uncategorizedItems.length : rShipCost),

											qty: uncategorizedItems.reduce(function(acc, i){ return acc + (i.qty || 1); }, 0),

											total: rShipCost + rHandCost,

											index: 'Default',

											type: 'default',

											target: 'uncategorized',

											shipping_formula: defaultShipF,

											handling_formula: defaultHandF,

											items: uncategorizedItems.map(function(mi) { return mi.name + ' (x' + mi.qty + ')'; })

										});

									}



									if (appliedRules.length > 0) {

										if (resolutionLogic === 'max') {

											var maxRule = null;

											var maxTotal = -1.0;

											appliedRules.forEach(function(ar) {

												if (ar.total > maxTotal) { maxTotal = ar.total; maxRule = ar; }

											});

											if (maxRule) {

												traceLog.push('Resolution (Max Cost): Selecting Rule ' + maxRule.index + ' ($' + maxTotal.toFixed(2) + ')');

												itemShippingAdd = maxRule.shipping;

												itemHandlingAdd = maxRule.handling;

											}

										} else if (resolutionLogic === 'merge_difference') {

											appliedRules.sort(function(a, b) { return b.base_ship - a.base_ship; });

											var primaryRule = appliedRules[0];

											itemShippingAdd = primaryRule.shipping;

											itemHandlingAdd = 0.0;

											appliedRules.forEach(function(ar) {

												if (ar.handling > itemHandlingAdd) itemHandlingAdd = ar.handling;

											});

											for (var s = 1; s < appliedRules.length; s++) {

												itemShippingAdd += appliedRules[s].shipping;

											}

											traceLog.push('Resolution (Folded Merge Difference): Primary Base Ship = $' + primaryRule.base_ship.toFixed(2) + ', Max Hand = $' + itemHandlingAdd.toFixed(2) + ' across ' + appliedRules.length + ' rules.');

										} else {

											appliedRules.forEach(function(ar) {

												itemShippingAdd += ar.shipping;

												itemHandlingAdd += ar.handling;

											});

										}

									}



									shipping_cost = itemShippingAdd;

									handling_cost = itemHandlingAdd;



									var locResolution = $('[name*="usa_location_rule_resolution"]').val() || 'first_match';

									var countyTableRows = $('#formula_shipping_rules_table_usa_county_rules tbody tr.formula-location-row-main');

									var appliedLocRules = [];



									for (var i = 0; i < countyTableRows.length; i++) {

										var row = $(countyTableRows[i]);

										var isEnabled = row.find('.formula-rule-enabled-toggle').is(':checked');

										if (!isEnabled) {

											traceLog.push('Location Rule #' + (i + 1) + ' skipped: disabled');

											continue;

										}

										var ruleType = row.find('.formula-loc-rule-type-input').val() || 'location';

										var ruleCountry = row.find('input[name*="[country]"]').val() || 'ALL';

										var ruleState = row.find('input[name*="[state]"]').val() || 'ALL';

										var ruleLoc = row.find('input[name*="[location]"]').val() || '';

										var ruleUser = row.find('input[name*="[user]"]').val() || '';

										var ruleDevice = row.find('input[name*="[device_target]"]').val() || '';

										var ruleOs = row.find('input[name*="[os_target]"]').val() || '';

										var ruleMetaKey = row.find('input[name*="[user_meta_key]"]').val() || '';

										var ruleMetaValue = row.find('input[name*="[user_meta_value]"]').val() || '';

										var formulaRow = row.next('tr.formula-location-row-formulas');

										var ruleShipF = formulaRow.length ? formulaRow.find('input[name*="[shipping_formula]"]').val() || '' : row.find('input[name*="[shipping_formula]"]').val() || '';

										var ruleHandF = formulaRow.length ? formulaRow.find('input[name*="[handling_formula]"]').val() || '' : row.find('input[name*="[handling_formula]"]').val() || '';



										var dest = { country: country, state: state, city: city, zip: zip };

										if (customerMatchesLocation(dest, ruleCountry, ruleState, ruleLoc, ruleType, ruleUser, ruleMetaKey, ruleMetaValue, userContext, ruleDevice, ruleOs)) {

											traceLog.push('Location Rule MATCHED (Rule #' + (i + 1) + '): Type=' + ruleType + ' Country=' + ruleCountry + ' State=' + ruleState + ' LocPattern="' + ruleLoc + '" User="' + ruleUser + '" Device="' + ruleDevice + '" OS="' + ruleOs + '" MetaKey="' + ruleMetaKey + '"');

											var lShipCost = 0.0;

											var lHandCost = 0.0;

											if (ruleShipF) lShipCost = evaluateFormula(ruleShipF, items, traceLog);

											if (ruleHandF) lHandCost = evaluateFormula(ruleHandF, items, traceLog);



											if (locResolution === 'first_match') {

												if (ruleShipF) shipping_cost = lShipCost;

												if (ruleHandF) handling_cost = lHandCost;

												matchedLocationOverride = {

													matched: true,

													country: ruleCountry,

													state: ruleState,

													location: ruleLoc,

													shipF: ruleShipF,

													handF: ruleHandF,

													shipCost: lShipCost,

													handCost: lHandCost

												};

												break;

											} else {

												appliedLocRules.push({ ship: lShipCost, hand: lHandCost, ruleIdx: (i + 1) });

											}

										}

									}



									if (locResolution !== 'first_match' && appliedLocRules.length > 0) {

										if (locResolution === 'max') {

											var maxTot = -1;

											appliedLocRules.forEach(function(ar) {

												var tot = ar.ship + ar.hand;

												if (tot > maxTot) {

													maxTot = tot;

													shipping_cost = ar.ship;

													handling_cost = ar.hand;

												}

											});

											traceLog.push('Location Resolution (Max Cost): Selected highest location rule rate ($' + maxTot.toFixed(2) + ')');

										} else if (locResolution === 'merge_difference' || locResolution === 'sum') {

											appliedLocRules.forEach(function(ar) {

												shipping_cost += ar.ship;

												handling_cost += ar.hand;

											});

											traceLog.push('Location Resolution (Folded/Sum): Combined location rates across ' + appliedLocRules.length + ' matching location rules.');

										}

									}



									var usaMinStr = $('[name*="usa_min_shipping"]').val();

									var usaMaxStr = $('[name*="usa_max_shipping"]').val();

									if (usaMinStr && !isNaN(parseFloat(usaMinStr))) {

										var usaMin = parseFloat(usaMinStr);

										if (shipping_cost < usaMin) {

											traceLog.push('  Applied Minimum Shipping Cap: $' + shipping_cost.toFixed(2) + ' -> $' + usaMin.toFixed(2));

											appliedAdjustments.push('Enforced Minimum Shipping Cap ($' + usaMin.toFixed(2) + ')');

											shipping_cost = usaMin;

										}

									}

									if (usaMaxStr && !isNaN(parseFloat(usaMaxStr))) {

										var usaMax = parseFloat(usaMaxStr);

										if (shipping_cost > usaMax) {

											traceLog.push('  Applied Maximum Shipping Cap: $' + shipping_cost.toFixed(2) + ' -> $' + usaMax.toFixed(2));

											appliedAdjustments.push('Enforced Maximum Shipping Cap ($' + usaMax.toFixed(2) + ')');

											shipping_cost = usaMax;

										}

									}



									// Rounding to 5 or 0 cents if enabled

									if ($('[name*="round_rates"]').is(':checked')) {

										if (shipping_cost > 0) {

											var oldS = shipping_cost;

											shipping_cost = Math.ceil(shipping_cost * 20) / 20;

											if (oldS !== shipping_cost) {

												traceLog.push('  Applied 5/0 Cents Rounding (Shipping): $' + oldS.toFixed(2) + ' -> $' + shipping_cost.toFixed(2));

												appliedAdjustments.push('Rounded Shipping Rate to 5/0 Cents ($' + oldS.toFixed(2) + ' ➔ $' + shipping_cost.toFixed(2) + ')');

											}

										}

										if (handling_cost > 0) {

											var oldH = handling_cost;

											handling_cost = Math.ceil(handling_cost * 20) / 20;

											if (oldH !== handling_cost) {

												traceLog.push('  Applied 5/0 Cents Rounding (Handling): $' + oldH.toFixed(2) + ' -> $' + handling_cost.toFixed(2));

												appliedAdjustments.push('Rounded Handling Fee to 5/0 Cents ($' + oldH.toFixed(2) + ' ➔ $' + handling_cost.toFixed(2) + ')');

											}

										}

									}



									var breakdownData = {

										resolutionLogic: resolutionLogic,

										appliedRules: appliedRules,

										locationOverride: matchedLocationOverride,

										adjustments: appliedAdjustments,

										destination: { country: country, state: state, city: city, zip: zip }

									};



									displayResults(shipping_cost, handling_cost, traceLog, 0, 0, itemShippingAdd, itemHandlingAdd, breakdownData);

							});



							function displayResults(finalShip, finalHand, log, baseS, baseH, itemS, itemH, breakdownData) {

								$('#formula_sim_out_item_shipping').text('$' + (itemS !== undefined ? itemS : 0).toFixed(2));

								$('#formula_sim_out_item_handling').text('$' + (itemH !== undefined ? itemH : 0).toFixed(2));



								var grandTotal = finalShip + finalHand;

								$('#formula_sim_out_total').text('$' + grandTotal.toFixed(2));



								log.push('=== CALCULATION COMPLETE ===');

								log.push('Total Shipping & Handling Cost: $' + grandTotal.toFixed(2));



								$('#formula_sim_trace').text(log.join('\n'));



								if (breakdownData) {

									var resLabel = 'Priority';

									if (breakdownData.resolutionLogic === 'merge_difference') resLabel = 'Folded Incremental';

									else if (breakdownData.resolutionLogic === 'sum') resLabel = 'Sum All Matches';

									else if (breakdownData.resolutionLogic === 'max') resLabel = 'Highest Cost Only';



									$('#formula_sim_breakdown_resolution_badge').text('Resolution: ' + resLabel);



									var bHtml = '';



									bHtml += '<div style="margin-bottom: 14px; background: #f8fafc; padding: 8px 12px; border-radius: 6px; font-size: 12px; border: 1px solid #e2e8f0; display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">' +

										'📍 <strong>Destination:</strong> ' + breakdownData.destination.country +

										(breakdownData.destination.state ? ' / State: ' + breakdownData.destination.state : '') +

										(breakdownData.destination.city ? ' / City: ' + breakdownData.destination.city : '') +

										(breakdownData.destination.zip ? ' / ZIP: ' + breakdownData.destination.zip : '') +

									'</div>';



									if (breakdownData.appliedRules && breakdownData.appliedRules.length > 0) {

										bHtml += '<div style="margin-bottom: 10px; font-weight: 600; font-size: 12px; color: #475569;">Matched Rules & Evaluation Steps (' + breakdownData.appliedRules.length + '):</div>';

										breakdownData.appliedRules.forEach(function(r, rIndex) {

											var isPrimary = (breakdownData.resolutionLogic === 'merge_difference' && rIndex === 0);

											var roleTag = '';

											if (breakdownData.resolutionLogic === 'merge_difference') {

												roleTag = isPrimary ? '<span style="background:#dcfce7; color:#15803d; font-size:10px; padding:2px 8px; border-radius:10px; font-weight:600;">⭐ Primary Base Rate</span>'

																	: '<span style="background:#fef3c7; color:#b45309; font-size:10px; padding:2px 8px; border-radius:10px; font-weight:600;">➕ Folded Incremental Rate</span>';

											}



											bHtml += '<div style="margin-bottom: 10px; border: 1px solid #e2e8f0; border-left: 4px solid #0284c7; border-radius: 6px; padding: 12px 14px; background: #ffffff;">' +

												'<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; flex-wrap: wrap; gap: 6px;">' +

													'<div style="font-weight: 700; color: #0f172a;">Rule ' + r.index + ': ' + (r.type ? r.type.toUpperCase() : '') + ' = "' + (r.target || 'Default') + '" ' + roleTag + '</div>' +

													'<div style="font-weight: 700; color: #0284c7;">Subtotal: $' + (r.shipping + r.handling).toFixed(2) + '</div>' +

												'</div>' +

												'<div style="font-size: 12px; color: #64748b; margin-bottom: 6px;">Matched item(s): ' + (r.items ? r.items.join(', ') : 'Cart items') + '</div>' +

												'<div style="font-family: Consolas, monospace; font-size: 11px; background: #f8fafc; padding: 6px 10px; border-radius: 4px; border: 1px solid #f1f5f9; display: flex; gap: 16px; flex-wrap: wrap;">' +

													'<div>🚚 <strong>Shipping Formula:</strong> ' + (r.shipping_formula || '0.00') + ' ➔ <span style="color:#0f172a; font-weight:600;">$' + r.shipping.toFixed(2) + '</span></div>' +

													'<div>📦 <strong>Handling Formula:</strong> ' + (r.handling_formula || '0.00') + ' ➔ <span style="color:#0f172a; font-weight:600;">$' + r.handling.toFixed(2) + '</span></div>' +

												'</div>' +

											'</div>';

										});

									} else {

										bHtml += '<div style="padding: 8px 12px; background: #fffbeb; border: 1px solid #fef3c7; border-radius: 6px; font-size: 12px; color: #b45309; margin-bottom: 12px;">' +

											'No item-specific rules matched. Standard/Default rate applies.' +

										'</div>';

									}



									if (breakdownData.locationOverride && breakdownData.locationOverride.matched) {

										var loc = breakdownData.locationOverride;

										bHtml += '<div style="margin-bottom: 10px; border: 1px solid #fde68a; border-left: 4px solid #f59e0b; border-radius: 6px; padding: 12px 14px; background: #fffbeb;">' +

											'<div style="font-weight: 700; color: #b45309; margin-bottom: 4px;">🗺Location Override Applied</div>' +

											'<div style="font-size: 12px; color: #92400e;">Target Pattern: ' + loc.country + ' / ' + loc.state + ' ' + (loc.location ? '(' + loc.location + ')' : '') + '</div>' +

											'<div style="font-family: Consolas, monospace; font-size: 11px; margin-top: 6px; background: #ffffff; padding: 6px 10px; border-radius: 4px; border: 1px solid #fef3c7; display: flex; gap: 16px; flex-wrap: wrap;">' +

												'<div>🚚 <strong>Ship Override:</strong> ' + (loc.shipF || 'n/a') + ' ➔ <span style="color:#b45309; font-weight:600;">$' + loc.shipCost.toFixed(2) + '</span></div>' +

												'<div>📦 <strong>Hand Override:</strong> ' + (loc.handF || 'n/a') + ' ➔ <span style="color:#b45309; font-weight:600;">$' + loc.handCost.toFixed(2) + '</span></div>' +

											'</div>' +

										'</div>';

									}



									if (breakdownData.adjustments && breakdownData.adjustments.length > 0) {

										bHtml += '<div style="margin-bottom: 10px; font-size: 12px; color: #475569;">';

										breakdownData.adjustments.forEach(function(adj) {

											bHtml += '<div style="background: #f1f5f9; padding: 4px 10px; border-radius: 4px; margin-bottom: 4px;">💡 ' + adj + '</div>';

										});

										bHtml += '</div>';

									}



									bHtml += '<div style="margin-top: 14px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">' +

										'<div style="font-size: 13px; color: #166534;">' +

											'Shipping: <strong>$' + finalShip.toFixed(2) + '</strong> | Handling: <strong>$' + finalHand.toFixed(2) + '</strong>' +

										'</div>' +

										'<div style="font-size: 15px; font-weight: 800; color: #15803d;">' +

											'Estimated Total Cost: $' + grandTotal.toFixed(2) +

										'</div>' +

									'</div>';





									var finalShippingExplainer = '';

									var finalHandlingExplainer = '';

									var appliedRules = breakdownData.appliedRules || [];

									if (appliedRules.length > 0) {

										if (breakdownData.resolutionLogic === 'merge_difference') {

											var primaryRule = appliedRules[0];

											var shippingParts = [];

											shippingParts.push('Rule ' + primaryRule.index + ' set the base shipping from ' + (primaryRule.shipping_formula || '0.00') + ' = $' + primaryRule.shipping.toFixed(2));

											appliedRules.slice(1).forEach(function(rule) {

												shippingParts.push('Rule ' + rule.index + ' folded in from ' + (rule.shipping_formula || '0.00') + ' = $' + rule.shipping.toFixed(2));

											});

											finalShippingExplainer = shippingParts.join(' + ') + ' = $' + itemS.toFixed(2);



											var highestHandlingRule = appliedRules[0];

											appliedRules.forEach(function(rule) {

												if (rule.handling > highestHandlingRule.handling) {

													highestHandlingRule = rule;

												}

											});

											finalHandlingExplainer = 'Handling uses the highest matched handling amount. Rule ' + highestHandlingRule.index + ' contributed ' + (highestHandlingRule.handling_formula || '0.00') + ' = $' + highestHandlingRule.handling.toFixed(2) + ', so handling = $' + itemH.toFixed(2);

										} else if (breakdownData.resolutionLogic === 'max') {

											var maxRule = appliedRules[0];

											appliedRules.forEach(function(rule) {

												if ((rule.shipping + rule.handling) > (maxRule.shipping + maxRule.handling)) {

													maxRule = rule;

												}

											});

											finalShippingExplainer = 'Rule ' + maxRule.index + ' had the highest subtotal, so shipping uses ' + (maxRule.shipping_formula || '0.00') + ' = $' + maxRule.shipping.toFixed(2);

											finalHandlingExplainer = 'That same highest-cost rule also sets handling from ' + (maxRule.handling_formula || '0.00') + ' = $' + maxRule.handling.toFixed(2);

										} else {

											finalShippingExplainer = appliedRules.map(function(rule) {

												return 'Rule ' + rule.index + ' [' + (rule.shipping_formula || '0.00') + '] = $' + rule.shipping.toFixed(2);

											}).join(' + ') + ' = $' + itemS.toFixed(2);

											finalHandlingExplainer = appliedRules.map(function(rule) {

												return 'Rule ' + rule.index + ' [' + (rule.handling_formula || '0.00') + '] = $' + rule.handling.toFixed(2);

											}).join(' + ') + ' = $' + itemH.toFixed(2);

										}



										bHtml += '<div style="margin-top: 14px; margin-bottom: 12px; border: 1px solid #bfdbfe; border-left: 4px solid #2563eb; background: #eff6ff; border-radius: 6px; padding: 12px 14px;">' +

											'<div style="font-weight: 700; color: #1d4ed8; margin-bottom: 8px;">Final Combination Step</div>' +

											'<div style="font-size: 12px; color: #1e3a8a; margin-bottom: 6px;"><strong>Shipping:</strong> ' + finalShippingExplainer + '</div>' +

											'<div style="font-size: 12px; color: #1e3a8a;">' +

												'<strong>Handling:</strong> ' + finalHandlingExplainer +

											'</div>' +

										'</div>';

									}

									$('#formula_sim_breakdown_body').html(bHtml);

								}

							}



							if (realProductsPool && realProductsPool.length > 0) {

								var p0 = realProductsPool[0];

								addMockItemRow(p0.name, 1, p0.price, p0.category, p0.tag, p0.sku);

							} else {

								addMockItemRow('Standard Paperback Book', 1, 2.99, 'paperback', '', 'MINI-1');

							}

						})(jQuery);

					</script>

				</td>

			</tr>

			<?php

			return ob_get_clean();

		}



		/**

		 * Generate HTML for Card 5: Import/Export Settings.

		 */

		public function generate_import_export_card_html( $key, $data ) {

			$nonce = wp_create_nonce( 'formula_shipping_import_export' );

			

			ob_start();

			?>

			<tr valign="top">

				<td colspan="2" style="padding: 0; margin-bottom: 28px;">

					<div class="formula-section-card card-theme-import-export">

						<div class="card-header">

							<h3><span class="dashicons dashicons-migrate"></span> <?php esc_html_e( '5. Import/Export Settings (.frshipping)', 'formula-rate-shipping' ); ?></h3>

							<span class="card-badge"><?php esc_html_e( 'Backup & Transfer', 'formula-rate-shipping' ); ?></span>

						</div>

						<div class="card-summary-bar" id="formula_card_summary_import_export">

							<div class="card-summary-text">

								<span>Backup & Transfer | Export or import settings (.frshipping)</span>

							</div>

							<span class="card-toggle-icon dashicons dashicons-arrow-up-alt2"></span>

						</div>

						<div class="card-body" style="padding: 24px 28px;">

							<p class="description" style="margin-top: 0; margin-bottom: 16px; font-size: 13px; color: #475569;"><?php esc_html_e( 'Export all Formula Rate Shipping settings across all zones into a .frshipping backup file, or restore settings from an existing backup file.', 'formula-rate-shipping' ); ?></p>

							

							<!-- Export Section -->

							<div class="formula-import-export-section" style="margin-bottom: 24px; padding: 18px 20px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px;">

								<h4 style="margin: 0 0 12px 0; font-size: 14px; color: #166534; font-weight: 700;"><?php esc_html_e( '📤 Export Settings', 'formula-rate-shipping' ); ?></h4>

								<p style="margin: 0 0 14px 0; font-size: 12px; color: #475569;"><?php esc_html_e( 'Download all Formula Rate Shipping instances from all shipping zones as a single .frshipping backup file.', 'formula-rate-shipping' ); ?></p>

								

								<div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">

									<input type="hidden" id="formula_export_format" value="json" />

									<button type="button" class="button button-primary" id="formula_export_btn" style="height: 36px; font-size: 13px; font-weight: 600;">

										<?php esc_html_e( 'Download .frshipping Backup File', 'formula-rate-shipping' ); ?>

									</button>

								</div>

							</div>

							

							<!-- Import Section -->

							<div class="formula-import-export-section" style="padding: 18px 20px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px;">

								<h4 style="margin: 0 0 12px 0; font-size: 14px; color: #1e40af; font-weight: 700;"><?php esc_html_e( '📥 Import Settings', 'formula-rate-shipping' ); ?></h4>

								<p style="margin: 0 0 14px 0; font-size: 12px; color: #475569;"><?php esc_html_e( 'Upload a previously exported .frshipping file to restore or transfer settings.', 'formula-rate-shipping' ); ?></p>

								

								<!-- Import Mode Selection -->

								<div style="margin-bottom: 14px;">

									<label style="font-weight: 600; font-size: 12px; color: #334155; display: block; margin-bottom: 6px;"><?php esc_html_e( 'Import Mode:', 'formula-rate-shipping' ); ?></label>

									<div style="display: flex; gap: 16px; flex-wrap: wrap;">

										<label style="font-weight: 400; font-size: 12px; color: #475569; cursor: pointer;">

											<input type="radio" name="formula_import_mode" value="replace" checked /> 

											<strong><?php esc_html_e( 'Replace', 'formula-rate-shipping' ); ?></strong> - <?php esc_html_e( 'Delete all existing and import new', 'formula-rate-shipping' ); ?>

										</label>

										<label style="font-weight: 400; font-size: 12px; color: #475569; cursor: pointer;">

											<input type="radio" name="formula_import_mode" value="append" /> 

											<strong><?php esc_html_e( 'Append', 'formula-rate-shipping' ); ?></strong> - <?php esc_html_e( 'Add as new instances', 'formula-rate-shipping' ); ?>

										</label>

										<label style="font-weight: 400; font-size: 12px; color: #475569; cursor: pointer;">

											<input type="radio" name="formula_import_mode" value="merge" /> 

											<strong><?php esc_html_e( 'Merge', 'formula-rate-shipping' ); ?></strong> - <?php esc_html_e( 'Update matching zones, add new ones', 'formula-rate-shipping' ); ?>

										</label>

									</div>

								</div>

								

								<!-- File Upload -->

								<div style="margin-bottom: 14px;">

									<input type="file" id="formula_import_file" accept=".frshipping,.json" style="font-size: 13px;" />

									<p class="description" style="margin-top: 6px; font-size: 11px; color: #64748b;"><?php esc_html_e( 'Accepted format: .frshipping (or .json backup file)', 'formula-rate-shipping' ); ?></p>

								</div>

								

								<!-- Import Preview Area -->

								<div id="formula_import_preview" style="display: none; margin-bottom: 14px; padding: 12px; background: #fff; border: 1px solid #e2e8f0; border-radius: 6px;">

									<h5 style="margin: 0 0 8px 0; font-size: 12px; color: #334155;"><?php esc_html_e( 'Preview:', 'formula-rate-shipping' ); ?></h5>

									<pre id="formula_import_preview_content" style="margin: 0; padding: 10px; background: #f8fafc; border-radius: 4px; font-size: 11px; max-height: 200px; overflow-y: auto;"></pre>

								</div>

								

								<button type="button" class="button button-primary" id="formula_import_btn" style="height: 36px; font-size: 13px; font-weight: 600;" disabled>

									<?php esc_html_e( 'Import Settings', 'formula-rate-shipping' ); ?>

								</button>

								<span id="formula_import_status" style="margin-left: 10px; font-size: 12px;"></span>

							</div>

							

							<!-- Warning Box -->

							<div style="margin-top: 18px; padding: 12px 16px; background: #fef3c7; border: 1px solid #fcd34d; border-radius: 6px;">

								<p style="margin: 0; font-size: 12px; color: #92400e;">

									<strong>⚠️ <?php esc_html_e( 'Warning:', 'formula-rate-shipping' ); ?></strong> 

									<?php esc_html_e( 'Importing with "Replace" mode will delete all existing Formula Rate Shipping settings. Always export a backup before importing.', 'formula-rate-shipping' ); ?>

								</p>

							</div>

						</div>

					</div>

					

					<script type="text/javascript">

					jQuery(document).ready(function($) {

						var importNonce = '<?php echo esc_js( $nonce ); ?>';

						var importData = null;

						

						// Export functionality

						$('#formula_export_btn').on('click', function(e) {

							e.preventDefault();

							

							$.ajax({

								url: ajaxurl,

								type: 'POST',

								data: {

									action: 'formula_shipping_export',

									nonce: importNonce,

									format: 'json'

								},

								success: function(response) {

									if (response.success) {

										var date = new Date().toISOString().slice(0, 10);

										var blob = new Blob([response.data.content], { type: 'application/json' });

										var filename = 'formula-shipping-export-' + date + '.frshipping';

										

										var url = URL.createObjectURL(blob);

										var a = document.createElement('a');

										a.href = url;

										a.download = filename;

										document.body.appendChild(a);

										a.click();

										document.body.removeChild(a);

										URL.revokeObjectURL(url);

									} else {

										alert('Export failed: ' + (response.data.message || 'Unknown error'));

									}

								},

								error: function() {

									alert('Export failed. Please try again.');

								}

							});

						});

						

						// File selection - show preview

						$('#formula_import_file').on('change', function(e) {

							var file = e.target.files[0];

							if (!file) {

								$('#formula_import_btn').prop('disabled', true);

								$('#formula_import_preview').hide();

								return;

							}

							

							var reader = new FileReader();

							reader.onload = function(e) {

								var content = e.target.result;

								

								try {

									importData = JSON.parse(content);

									var preview = JSON.stringify(importData, null, 2).substring(0, 2000);

									$('#formula_import_preview_content').text(preview + (preview.length >= 2000 ? '\n... (truncated)' : ''));

									$('#formula_import_preview').show();

									$('#formula_import_btn').prop('disabled', false);

									$('#formula_import_status').text('').removeClass('error success');

								} catch (err) {

									$('#formula_import_preview_content').text('Error parsing .frshipping backup file: ' + err.message);

									$('#formula_import_preview').show();

									$('#formula_import_btn').prop('disabled', true);

									$('#formula_import_status').text('Invalid .frshipping file format').addClass('error');

								}

							};

							reader.readAsText(file);

						});

						

						// Import functionality

						$('#formula_import_btn').on('click', function(e) {

							e.preventDefault();

							

							if (!importData) {

								alert('Please select a file first.');

								return;

							}

							

							var mode = $('input[name="formula_import_mode"]:checked').val();

							var confirmMsg = 'Are you sure you want to import settings?';

							if (mode === 'replace') {

								confirmMsg = 'WARNING: Replace mode will DELETE all existing Formula Rate Shipping settings. Continue?';

							}

							

							if (!confirm(confirmMsg)) {

								return;

							}

							

							$('#formula_import_status').text('Importing...').removeClass('error success');

							$('#formula_import_btn').prop('disabled', true);

							

							$.ajax({

								url: ajaxurl,

								type: 'POST',

								data: {

									action: 'formula_shipping_import',

									nonce: importNonce,

									mode: mode,

									data: typeof importData === 'object' ? JSON.stringify(importData) : importData,

									format: 'json'

								},

								success: function(response) {

									if (response.success) {

										$('#formula_import_status').text('✓ ' + response.data.message).addClass('success').css('color', '#16a34a');

										setTimeout(function() {

											location.reload();

										}, 1500);

									} else {

										$('#formula_import_status').text('✗ ' + response.data.message).addClass('error').css('color', '#dc2626');

										$('#formula_import_btn').prop('disabled', false);

									}

								},

								error: function() {

									$('#formula_import_status').text('Import failed. Please try again.').css('color', '#dc2626');

									$('#formula_import_btn').prop('disabled', false);

								}

							});

						});

					});

					</script>

				</td>

			</tr>

			<?php

			return ob_get_clean();

		}



		/**

		 * Generate HTML for Card 6: Full Formula Documentation.

		 */

		public function generate_documentation_card_html( $key, $data ) {

			ob_start();

			?>

			<tr valign="top">

				<td colspan="2" style="padding: 0; margin-bottom: 28px;">

					<div class="formula-section-card card-theme-documentation" id="formula-documentation-card">

						<div class="card-header">

							<h3><span class="dashicons dashicons-book-alt"></span> <?php esc_html_e( '6. Full Formula Documentation', 'formula-rate-shipping' ); ?></h3>

							<span class="card-badge"><?php esc_html_e( 'Complete Reference', 'formula-rate-shipping' ); ?></span>

						</div>

						<div class="card-summary-bar" id="formula_card_summary_documentation">

							<div class="card-summary-text">

								<span>Reference Guide | Placeholders, operators, and dynamic logic helper</span>

							</div>

							<span class="card-toggle-icon dashicons dashicons-arrow-up-alt2"></span>

						</div>

						<div class="card-body" style="padding: 24px 28px;">

							<div style="font-size: 13px; color: #334155; line-height: 1.7;">



								<h4 style="margin: 0 0 10px 0; font-size: 15px; color: #1e293b; font-weight: 700; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">📦 Cart, Item & Dimensional Placeholders</h4>

								<ul style="margin: 0 0 18px 0; padding-left: 20px;">

									<li><code>[qty]</code> – Total quantity of matching items in the cart.</li>

									<li><code>[cost]</code> – Total price of matching items in the cart.</li>

									<li><code>[weight]</code> – Total weight of matching items in the cart.</li>

									<li><code>[items]</code> – Number of unique line items in the cart.</li>

									<li><code>[volume]</code> – Total cubic volume (length × width × height) of matching items.</li>

									<li><code>[length]</code> <code>[width]</code> <code>[height]</code> – Maximum single dimension among items in the cart.</li>

									<li><code>[on_sale]</code> – Returns <code>1</code> if any item in the cart is on sale, <code>0</code> otherwise.</li>

								</ul>



								<h4 style="margin: 0 0 10px 0; font-size: 15px; color: #1e293b; font-weight: 700; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">🏷️ Filtered Placeholders (Category / Tag / Variation / Product)</h4>

								<p style="margin: 0 0 8px 0;">All base variables support category, tag, variation, and product name filters:</p>

								<ul style="margin: 0 0 18px 0; padding-left: 20px;">

									<li><code>[qty category="X"]</code> <code>[qty tag="X"]</code> <code>[qty variation="X"]</code></li>

									<li><code>[cost category="X"]</code> <code>[cost tag="X"]</code> <code>[cost variation="X"]</code></li>

									<li><code>[weight category="X"]</code> <code>[weight tag="X"]</code> <code>[weight variation="X"]</code></li>

									<li><code>[items category="X"]</code> <code>[items tag="X"]</code> <code>[items variation="X"]</code></li>

									<li><code>[product="My Book"]</code> – Returns <code>1</code> if any cart item's name, SKU, or product/variation ID matches "My Book", <code>0</code> otherwise.</li>

									<li><code>[on_sale category="books"]</code> <code>[on_sale tag="clearance"]</code> – Checks if matching category/tag items are on sale.</li>

								</ul>



								<h4 style="margin: 0 0 10px 0; font-size: 15px; color: #1e293b; font-weight: 700; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">👤 User Role & Identity Variables</h4>

								<p style="margin: 0 0 8px 0;"><code>[user="X"]</code> checks the currently logged-in user and returns <code>1</code> (true) or <code>0</code> (false). It matches in this order:</p>

								<ol style="margin: 0 0 8px 0; padding-left: 20px;">

									<li><strong>User Role</strong> – e.g. <code>subscriber</code>, <code>customer</code>, <code>administrator</code>, <code>editor</code>, <code>shop_manager</code></li>

									<li><strong>User Email</strong> – e.g. <code>admin@example.com</code></li>

									<li><strong>User Login</strong> – e.g. <code>johndoe</code></li>

								</ol>

								<p style="margin: 0 0 8px 0;"><code>[user_role]</code> returns the primary user role as text (or <code>guest</code> for logged-out users).</p>

								<p style="margin: 0 0 18px 0; background: #fef3c7; border: 1px solid #fcd34d; border-radius: 6px; padding: 8px 12px; font-size: 12px; color: #92400e;"><strong>Note:</strong> For logged-out (guest) users, <code>[user="X"]</code> always returns <code>0</code>. Use <code>[user_role]</code> to detect guests.</p>



								<h4 style="margin: 0 0 10px 0; font-size: 15px; color: #1e293b; font-weight: 700; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">📱 Device, OS & Browser Environment Matching</h4>

								<ul style="margin: 0 0 12px 0; padding-left: 20px;">

									<li><code>[os]</code> – Operating system (e.g. <code>Windows 11</code>, <code>Windows 10</code>, <code>MacOS Ventura</code>, <code>Linux Mint</code>, <code>Ubuntu</code>, <code>iOS 17</code>, <code>Android 14</code>).</li>

									<li><code>[browser]</code> – Browser name (e.g. <code>Chrome</code>, <code>Firefox</code>, <code>Safari</code>, <code>Edge</code>, <code>Opera</code>, <code>Brave</code>, <code>Floorp</code>, <code>Waterfox</code>, <code>Vivaldi</code>, <code>Arc</code>).</li>

									<li><code>[device]</code> – Device type (e.g. <code>mobile</code>, <code>desktop</code>, <code>tablet</code>).</li>

									<li><code>[is_mobile]</code> – Returns <code>1</code> for mobile/tablet devices, <code>0</code> for desktop.</li>

								</ul>

								<p style="margin: 0 0 8px 0; font-weight: 600; color: #0f172a;">🖥️ Automatic OS & Version Simplification Aliases:</p>
								<ul style="margin: 0 0 12px 0; padding-left: 20px; font-size: 12px; color: #475569;">
									<li><strong>Windows:</strong> <code>Win11</code>, <code>Win 11</code>, <code>Windows 11</code>, <code>Win10</code>, <code>Win 10</code>, <code>Windows 10</code>, <code>Win 7</code>, <code>WinXP</code>, <code>Vista</code></li>
									<li><strong>MacOS:</strong> <code>Ventura</code>, <code>Sonoma</code>, <code>Sequoia</code>, <code>Monterey</code>, <code>Big Sur</code>, <code>Catalina</code>, <code>OSX</code>, <code>MacOS</code></li>
									<li><strong>Linux Distros:</strong> <code>Linux Mint</code>, <code>Ubuntu</code>, <code>Fedora</code>, <code>Arch</code>, <code>Pop!_OS</code>, <code>Debian</code>, <code>Manjaro</code></li>
									<li><strong>Niche Browsers:</strong> <code>Floorp</code>, <code>Waterfox</code>, <code>LibreWolf</code>, <code>Arc</code>, <code>Brave</code>, <code>Vivaldi</code></li>
								</ul>

								<p style="margin: 0 0 18px 0; background: #e0f2fe; border: 1px solid #7dd3fc; border-radius: 6px; padding: 8px 12px; font-size: 12px; color: #0369a1;"><strong>OS Simplifier Tip:</strong> You can enter full versioned names like <code>Win 11</code> or <code>Ventura</code> or generic names like <code>Windows</code> or <code>Linux</code>. Wildcards (e.g. <code>Win*</code>, <code>MacOS*</code>) are also supported.</p>



								<h4 style="margin: 0 0 10px 0; font-size: 15px; color: #1e293b; font-weight: 700; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">🔍 Wildcard Matching (* and ?)</h4>

								<p style="margin: 0 0 8px 0;">Location and User rule target fields, as well as Product text filters (category, tag, variation, SKU, title), support shell-style wildcard patterns:</p>

								<ul style="margin: 0 0 8px 0; padding-left: 20px;">

									<li><code>*</code> – Matches <strong>any sequence</strong> of characters (including none). E.g. <code>US*</code> matches <code>US</code>, <code>USA</code>, <code>USVI</code>.</li>

									<li><code>?</code> – Matches <strong>exactly one</strong> character. E.g. <code>NY?</code> matches <code>NYS</code>, <code>NYC</code> but not <code>NY</code>.</li>

								</ul>

								<ul style="margin: 0 0 18px 0; padding-left: 20px;">

									<li><strong>Country / State / City-ZIP:</strong> <code>US*</code>, <code>CA</code>, <code>1?002</code></li>

									<li><strong>User Email:</strong> <code>*@company.com</code>, <code>admin*@*.org</code></li>

									<li><strong>User Login / Role:</strong> <code>john*</code>, <code>shop_*</code></li>

									<li><strong>Product Category / Tag:</strong> <code>Ship*</code>, <code>*digital*</code></li>

									<li><strong>SKU / Title / Variation:</strong> <code>PROD-*</code>, <code>*-large</code>, <code>Size: ?L</code></li>

									<li><strong>Meta Value:</strong> <code>gold*</code>, <code>tier_?</code></li>

								</ul>

								<p style="margin: 0 0 18px 0; background: #f0fdf4; border: 1px solid #86efac; border-radius: 6px; padding: 8px 12px; font-size: 12px; color: #14532d;"><strong>Tip:</strong> Wildcards are case-insensitive. When no wildcard characters are present the rule performs a standard exact match, so existing rules are unaffected.</p>



								<h4 style="margin: 0 0 10px 0; font-size: 15px; color: #1e293b; font-weight: 700; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">⏰ Time & Temporal Variables</h4>

								<ul style="margin: 0 0 18px 0; padding-left: 20px;">

									<li><code>[hour]</code> – Current hour of the day (0 to 23). E.g. <code>if([hour] >= 14, 15.00, 7.50)</code> for 2 PM cutoff.</li>

									<li><code>[day]</code> – Day of the week name (e.g. <code>Monday</code>, <code>Friday</code>, <code>Sunday</code>).</li>

									<li><code>[month]</code> – Month number (1 to 12).</li>

									<li><code>[is_weekend]</code> – Returns <code>1</code> on Saturday/Sunday, <code>0</code> on weekdays.</li>

								</ul>



								<h4 style="margin: 0 0 10px 0; font-size: 15px; color: #1e293b; font-weight: 700; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">🎄 Holidays, Date Ranges & Checkout Notices</h4>

								<ul style="margin: 0 0 18px 0; padding-left: 20px;">

									<li><code>[is_holiday]</code> – Returns <code>1</code> on any public holiday or peak shopping event (Black Friday, Christmas, New Year, July 4th, etc.), <code>0</code> otherwise.</li>

									<li><code>[is_holiday="Christmas"]</code> – Returns <code>1</code> if today is Christmas, <code>0</code> otherwise.</li>

									<li><code>[is_holiday country="CA"]</code> – Checks public holidays in a specific country (e.g. Canada).</li>

									<li><code>[holiday]</code> – Returns the name of the active holiday as text (e.g. <code>Christmas</code>, <code>Black Friday</code>).</li>

									<li><code>[is_date="07-15"]</code> – Returns <code>1</code> if today is July 15th (store anniversary), <code>0</code> otherwise.</li>

									<li><code>[is_date_range="11-25 to 11-30"]</code> – Returns <code>1</code> if today is within the specified date range.</li>

									<li><code>[notice="Custom Text"]</code> / <code>[message="Custom Text"]</code> – Displays a message beneath the applicable shipping rate in the classic cart and checkout.</li>

								</ul>



								<details class="formula-help-accordion" style="margin-bottom: 12px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; overflow: hidden;" open>
									<summary style="padding: 12px 16px; font-weight: 700; font-size: 14px; color: #0f172a; background: #f8fafc; cursor: pointer; user-select: none;">💬 Dynamic Rate Notices, Alerts & Positioning ([message="..."] / [notice="..."] / [warning="..."])</summary>
									<div style="padding: 16px; border-top: 1px solid #e2e8f0;">
										<p style="margin: 0 0 8px 0;">Attach custom styled notice badges to shipping rate titles or place them anywhere in WooCommerce using <code>position="..."</code>:</p>
										<ul style="margin: 0 0 12px 0; padding-left: 20px;">
											<li><code>if([os] == "Linux", 0.00 + [message="Thanks for supporting open source!"], 5.00)</code> – Displays a blue 💬 Chat badge under the rate.</li>
											<li><code>if([is_mobile], 2.99 + [notice="Mobile App Special Rate"], 5.99)</code> – Displays a green ℹ️ Info badge under the rate.</li>
											<li><code>if([is_international], 15.00 + [warning="Customs Delays Possible" position="top"], 5.00)</code> – Displays an amber ⚠️ Warning banner at the <strong>top of the checkout page</strong>.</li>
											<li><code>[message="Free Shipping Unlocked!" position="cart_table"]</code> – Displays below the product list on the left side of the cart page.</li>
											<li><code>[notice="All shipments insured" position="before_shipping"]</code> – Displays directly above the shipping options box.</li>
										</ul>
										<h5 style="margin: 12px 0 6px 0; font-size: 13px; color: #334155; font-weight: 700;">📍 Notice Position Options (<code>position="..."</code>)</h5>
										<table style="width: 100%; border-collapse: collapse; font-size: 12px; border: 1px solid #e2e8f0;">
											<tr style="background: #f8fafc; text-align: left;">
												<th style="padding: 6px 10px; border: 1px solid #e2e8f0;">Position Value</th>
												<th style="padding: 6px 10px; border: 1px solid #e2e8f0;">WooCommerce Location</th>
											</tr>
											<tr>
												<td style="padding: 6px 10px; border: 1px solid #e2e8f0;"><code>under_rate</code> <em>(Default)</em></td>
												<td style="padding: 6px 10px; border: 1px solid #e2e8f0;">Beneath the specific shipping rate option title</td>
											</tr>
											<tr>
												<td style="padding: 6px 10px; border: 1px solid #e2e8f0;"><code>cart_table</code></td>
												<td style="padding: 6px 10px; border: 1px solid #e2e8f0;">Below product items list on left column of cart page</td>
											</tr>
											<tr>
												<td style="padding: 6px 10px; border: 1px solid #e2e8f0;"><code>before_shipping</code></td>
												<td style="padding: 6px 10px; border: 1px solid #e2e8f0;">Directly above the shipping options box</td>
											</tr>
											<tr>
												<td style="padding: 6px 10px; border: 1px solid #e2e8f0;"><code>after_shipping</code></td>
												<td style="padding: 6px 10px; border: 1px solid #e2e8f0;">Directly below the shipping options box</td>
											</tr>
											<tr>
												<td style="padding: 6px 10px; border: 1px solid #e2e8f0;"><code>top</code> / <code>global</code></td>
												<td style="padding: 6px 10px; border: 1px solid #e2e8f0;">Global alert banner at very top of Cart & Checkout pages</td>
											</tr>
										</table>
									</div>
								</details>

								<details class="formula-help-accordion" style="margin-bottom: 12px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; overflow: hidden;">
									<summary style="padding: 12px 16px; font-weight: 700; font-size: 14px; color: #0f172a; background: #f8fafc; cursor: pointer; user-select: none;">💳 Dynamic Order Total Line Items ([fee], [discount], [sale]) vs [rate]</summary>
									<div style="padding: 16px; border-top: 1px solid #e2e8f0;">
										<p style="margin: 0 0 8px 0;">Inject standalone dynamic surcharges and credits directly into the WooCommerce Cart & Checkout Order Totals summary block:</p>
										<ul style="margin: 0; padding-left: 20px;">
											<li><code>[fee percent=5 label="Fuel Surcharge"]</code> – Adds a standalone 5% surcharge line item (<strong>Fuel Surcharge: +$3.50</strong>) with emerald green currency icon.</li>
											<li><code>[fee formula="[qty] * 2.50" label="Hazmat Packaging Fee" icon="warning"]</code> – Adds a standalone packaging surcharge line item with an amber warning icon.</li>
											<li><code>[discount amount=5.00 label="Subscriber Credit"]</code> – Adds a standalone credit line item (<strong>Subscriber Credit: -$5.00</strong>) with a purple discount tag.</li>
											<li><code>[sale percent=10 label="Flash Sale Promo"]</code> – Adds a standalone promo credit line item (<strong>Flash Sale Promo: -$4.20</strong>) with a red sale tag.</li>
											<li><code>[fee amount=10.00 label="Remote Handling" icon="none"]</code> – <code>icon="none"</code> mode renders clean text-only line items without SVG icons.</li>
											<li><code>[rate percent=10]</code> – Internal math placeholder: calculates 10% of subtotal and bundles it <strong>inside</strong> the shipping rate price itself without creating a separate line item.</li>
										</ul>
									</div>
								</details>

								<details class="formula-help-accordion" style="margin-bottom: 12px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; overflow: hidden;">
									<summary style="padding: 12px 16px; font-weight: 700; font-size: 14px; color: #0f172a; background: #f8fafc; cursor: pointer; user-select: none;">🌍 Location & Country Rules Syntax</summary>
									<div style="padding: 16px; border-top: 1px solid #e2e8f0;">
										<ul style="margin: 0; padding-left: 20px;">
											<li><strong>Country Inputs</strong>: Accepts 2-letter ISO codes (<code>US</code>, <code>CA</code>, <code>GB</code>, <code>FR</code>, <code>DE</code>, <code>MX</code>), 3-letter codes (<code>USA</code>, <code>CAN</code>), or full country names (<code>Canada</code>, <code>France</code>, <code>"United States"</code>, <code>'United Kingdom'</code>).</li>
											<li><strong>Multi-Word Quotes</strong>: Enclose two-or-more-word country names in single quotes (<code>'United Kingdom'</code>) or double quotes (<code>"United States"</code>).</li>
											<li><strong>State or Province Reveal</strong>: Entering any single country dynamically activates the <strong>State or Province</strong> box. Selecting multiple countries (e.g. <code>US, CA</code>) or <code>ALL</code> hides State or Province and expands fields full-width.</li>
										</ul>
									</div>
								</details>

								<details class="formula-help-accordion" style="margin-bottom: 12px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; overflow: hidden;">
									<summary style="padding: 12px 16px; font-weight: 700; font-size: 14px; color: #0f172a; background: #f8fafc; cursor: pointer; user-select: none;">💻 Environment, OS & Browser String Rules</summary>
									<div style="padding: 16px; border-top: 1px solid #e2e8f0;">
										<ul style="margin: 0; padding-left: 20px;">
											<li><strong>Browser Field</strong>: Accepts <strong>ANY browser name, string, or wildcard pattern</strong> (e.g. <code>Chrome</code>, <code>Firefox</code>, <code>Safari</code>, <code>Edge</code>, <code>Brave</code>, <code>Floorp</code>, <code>Vivaldi</code>, <code>Opera</code>, <code>Browser*</code>).</li>
											<li><strong>OS Field</strong>: Accepts system aliases like <code>Win10</code>, <code>Win11</code>, <code>Ventura</code>, <code>Sonoma</code>, <code>Monterey</code>, <code>iOS 17</code>, <code>Android 14</code>, <code>Linux</code>, <code>ChromeOS</code>.</li>
											<li><strong>Device Field</strong>: Accepts <code>mobile</code>, <code>desktop</code>, <code>tablet</code>, or <code>ALL</code>.</li>
										</ul>
									</div>
								</details>



								<h4 style="margin: 0 0 10px 0; font-size: 15px; color: #1e293b; font-weight: 700; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">🌍 Location Variables</h4>

								<ul style="margin: 0 0 18px 0; padding-left: 20px;">

									<li><code>[shipping_country]</code> – Destination country code (e.g. <code>US</code>, <code>CA</code>, <code>MX</code>).</li>

									<li><code>[shipping_state]</code> – Destination state/region code (e.g. <code>CA</code>, <code>TN</code>, <code>ON</code>).</li>

									<li><code>[shipping_zip]</code> – Destination ZIP/postal code (e.g. <code>90210</code>, <code>M5V 2T6</code>).</li>

									<li><code>[shipping_city]</code> – Destination city name (e.g. <code>Los Angeles</code>).</li>

									<li><code>[is_international]</code> – Returns <code>1</code> when the shipping country differs from the global warehouse origin country, otherwise <code>0</code>.</li>

									<li><code>[is_international="US"]</code> – Overrides the warehouse origin comparison country for that formula and returns <code>1</code> when the destination is outside <code>US</code>.</li>

								</ul>



								<h4 style="margin: 0 0 10px 0; font-size: 15px; color: #1e293b; font-weight: 700; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">💰 Percentage Rate Placeholder ([rate])</h4>

								<ul style="margin: 0 0 18px 0; padding-left: 20px;">

									<li><code>[rate percent=10]</code> – 10% of matching cart subtotal.</li>

									<li><code>[rate percent=10 min_fee=5]</code> – 10% with $5.00 minimum charge.</li>

									<li><code>[rate percent=10 max_fee=50]</code> – 10% with $50.00 maximum cap.</li>

									<li><code>[rate percent=10 category="books"]</code> – 10% of only the <code>books</code> category subtotal.</li>

									<li><code>[rate percent=10 tag="fragile"]</code> – 10% of only items with the <code>fragile</code> tag.</li>

									<li><code>[rate percent=10 variation="Hardcover"]</code> – 10% of only <code>Hardcover</code> variation items.</li>

								</ul>



								<h4 style="margin: 0 0 10px 0; font-size: 15px; color: #1e293b; font-weight: 700; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">🔀 Conditional (If/Then/Else) Logic</h4>

								<p style="margin: 0 0 8px 0;">Two styles are supported:</p>

								<ul style="margin: 0 0 8px 0; padding-left: 20px;">

									<li><code>if(condition, true_value, false_value)</code> – Excel-style function.</li>

									<li><code>condition ? true_value : false_value</code> – C-style ternary operator.</li>

								</ul>

								<p style="margin: 0 0 18px 0;">Both evaluate the condition; if truthy (non-zero), the true value is used, otherwise the false value. Nesting is supported.</p>



								<table class="widefat" style="margin: 0 0 18px 0; max-width: 600px;">

									<thead>

										<tr><th style="width: 100px;">Operator</th><th>Description</th><th>Example</th></tr>

									</thead>

									<tbody>

										<tr><td><code>AND</code></td><td>Both conditions must be true</td><td><code>[user="Subscriber"] AND [qty] > 3</code></td></tr>

										<tr><td><code>OR</code></td><td>At least one condition must be true</td><td><code>[category="books"] OR [tag="fragile"]</code></td></tr>

										<tr><td><code>NOT</code></td><td>Negates a condition</td><td><code>NOT [user="Subscriber"]</code></td></tr>

										<tr><td><code>!</code></td><td>Negates a condition (shorthand)</td><td><code>![user="Subscriber"]</code></td></tr>

										<tr><td><code>( )</code></td><td>Groups conditions for precedence</td><td><code>([category="books"] OR [tag="fragile"]) AND [qty] > 2</code></td></tr>

									</tbody>

								</table>



								<h4 style="margin: 0 0 10px 0; font-size: 15px; color: #1e293b; font-weight: 700; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">⚖️ Comparison Operators</h4>

								<table class="widefat" style="margin: 0 0 18px 0; max-width: 600px;">

									<thead>

										<tr><th style="width: 100px;">Operator</th><th>Description</th><th>Example</th></tr>

									</thead>

									<tbody>

										<tr><td><code>></code></td><td>Greater than</td><td><code>[qty] > 5</code></td></tr>

										<tr><td><code><</code></td><td>Less than</td><td><code>[weight] < 10</code></td></tr>

										<tr><td><code>>=</code></td><td>Greater than or equal</td><td><code>[qty] >= 6</code></td></tr>

										<tr><td><code><=</code></td><td>Less than or equal</td><td><code>[cost] <= 50</code></td></tr>

										<tr><td><code>==</code></td><td>Equal to</td><td><code>[shipping_state] == "CA"</code></td></tr>

										<tr><td><code>!=</code></td><td>Not equal to</td><td><code>[shipping_country] != "US"</code></td></tr>

										<tr><td><code>IN</code></td><td>Matches a value in a list</td><td><code>[shipping_state] IN ("CA", "OR", "WA")</code></td></tr>

										<tr><td><code>BETWEEN</code></td><td>Inclusive numeric range</td><td><code>[weight] BETWEEN 5 AND 50</code></td></tr>

										<tr><td><code>CONTAINS</code></td><td>Case-insensitive substring match</td><td><code>[shipping_city] CONTAINS "Los"</code></td></tr>

									</tbody>

								</table>



								<h4 style="margin: 0 0 10px 0; font-size: 15px; color: #1e293b; font-weight: 700; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">🧮 Math Operators</h4>

								<table class="widefat" style="margin: 0 0 18px 0; max-width: 600px;">

									<thead>

										<tr><th style="width: 100px;">Operator</th><th>Description</th><th>Example</th></tr>

									</thead>

									<tbody>

										<tr><td><code>+</code></td><td>Addition</td><td><code>[qty] + 2</code></td></tr>

										<tr><td><code>-</code></td><td>Subtraction</td><td><code>[qty] - 1</code></td></tr>

										<tr><td><code>*</code></td><td>Multiplication</td><td><code>[qty] * 2.50</code></td></tr>

										<tr><td><code>/</code></td><td>Division</td><td><code>[cost] / 2</code></td></tr>

										<tr><td><code>%</code></td><td>Modulo (remainder)</td><td><code>[qty] % 6</code></td></tr>

										<tr><td><code>^</code></td><td>Exponentiation (power)</td><td><code>[qty] ^ 2</code></td></tr>

									</tbody>

								</table>



								<h4 style="margin: 0 0 10px 0; font-size: 15px; color: #1e293b; font-weight: 700; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">Math Functions</h4>

								<table class="widefat" style="margin: 0 0 18px 0; max-width: 600px;">

									<thead>

										<tr><th style="width: 100px;">Function</th><th>Description</th><th>Example</th></tr>

									</thead>

									<tbody>

										<tr><td><code>clamp()</code></td><td>Restricts value between min and max floor/ceiling</td><td><code>clamp([weight] * 2.50, 5.00, 50.00)</code></td></tr>

										<tr><td><code>sum()</code></td><td>Sum of numbers</td><td><code>sum(10, 20, 30)</code> or <code>sum([cost], 5)</code></td></tr>

										<tr><td><code>avg()</code></td><td>Average of numbers</td><td><code>avg(10, 20, 30)</code></td></tr>

										<tr><td><code>min()</code></td><td>Smallest value</td><td><code>min(20, [qty] * 3)</code></td></tr>

										<tr><td><code>max()</code></td><td>Largest value</td><td><code>max(5, [weight] * 1.5)</code></td></tr>

										<tr><td><code>round()</code></td><td>Rounds to an optional precision</td><td><code>round([cost] * 0.1, 2)</code></td></tr>

										<tr><td><code>ceil()</code></td><td>Rounds up</td><td><code>ceil([weight]) * 2</code></td></tr>

										<tr><td><code>floor()</code></td><td>Rounds down</td><td><code>floor([qty] / 6) * 10</code></td></tr>

										<tr><td><code>abs()</code></td><td>Absolute value</td><td><code>abs([qty] - 10)</code></td></tr>

									</tbody>

								</table>



								<h4 style="margin: 0 0 10px 0; font-size: 15px; color: #1e293b; font-weight: 700; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">🧪 Formula Examples</h4>

								<ol style="margin: 0 0 18px 0; padding-left: 20px;">

									<li><strong>1.50 + ([qty] - 1) * 0.75</strong> – Tiered shipping: $1.50 first item + $0.75 each additional.</li>

									<li><strong>[category="books"] ? 4.50 + ([qty] - 1) * 3.00 : 5.00 + ([qty] - 1) * 3.50</strong> – Category-conditional shipping (ternary).</li>

									<li><strong>if([user="Subscriber"], 0.00, 5.00)</strong> – Free shipping for Subscribers, $5.00 otherwise.</li>

									<li><strong>if([user="Subscriber"] AND [variation="Hardcover"], 0.00, 7.50)</strong> – Free shipping for Subscriber hardcovers.</li>

									<li><strong>if(![user="Subscriber"], 10.00, 0.00)</strong> – $10.00 for non-subscribers only.</li>

									<li><strong>if([shipping_state] == "CA", 15.00, 10.00)</strong> – $15.00 for California, $10.00 otherwise.</li>

									<li><strong>[weight] * 1.50</strong> – $1.50 per pound.</li>

									<li><strong>if([qty] % 6 > 0, 15.00, 10.00)</strong> – $15.00 for partial box, $10.00 for full box.</li>

									<li><strong>if([weight] > 50, 25.00, 10.00)</strong> – $25.00 if over 50 lbs, else $10.00.</li>

									<li><strong>if([category="Books"] > 0, [qty] * 3.50, [qty] * 2.50)</strong> – Different rates for books vs non-books.</li>

									<li><strong>[user="Subscriber"] ? 0.00 : [qty] * 2.50</strong> – Free for subscribers, else per-item rate (ternary).</li>

									<li><strong>[rate percent=10 min_fee=5]</strong> – 10% of cart subtotal, never less than $5.00.</li>

									<li><strong>[qty category="paperback"] * 0.75</strong> – $0.75 per mini-book in the cart.</li>

									<li><strong>if([user_role] == "guest", 8.00, 5.00)</strong> – $8.00 for guests, $5.00 for registered users.</li>

								</ol>

							</div>

						</div>

					</div>

					<script type="text/javascript">

						jQuery(document).ready(function($) {

							$(document).off('click', '.formula-doc-back-top').on('click', '.formula-doc-back-top', function(e) {

								e.preventDefault();

								var modalScrollTarget = $(this).closest('.wc-backbone-modal-content');

								if (!modalScrollTarget.length) {

									modalScrollTarget = $(this).closest('.wc-backbone-modal');

								}

								if (modalScrollTarget.length) {

									modalScrollTarget.animate({ scrollTop: 0 }, 300);

								} else {

									$('html, body').animate({ scrollTop: 0 }, 300);

								}

							});

						});

					</script>

				</td>

			</tr>

			<?php

			return ob_get_clean();

		}



		/**

		 * Evaluates and combines item rules based on priority resolution logic.

		 */

		public static function evaluate_item_rules( $item_rules, $resolution_logic, $contents, $default_ship_formula = '', $default_hand_formula = '' ) {

			if ( ! is_array( $item_rules ) ) {

				$item_rules = json_decode( $item_rules, true );

			}

			if ( ! is_array( $item_rules ) ) {

				$item_rules = array();

			}



			$applied_rules      = array();

			$matched_item_keys  = array();



			foreach ( $item_rules as $rule ) {

				if ( isset( $rule['enabled'] ) && 'no' === $rule['enabled'] ) {

					continue;

				}

				$type   = isset( $rule['type'] ) ? $rule['type'] : 'category';

				$target = isset( $rule['target'] ) ? strtolower( trim( $rule['target'] ) ) : '';

				$ship_f = isset( $rule['shipping_formula'] ) ? trim( $rule['shipping_formula'] ) : '';

				$hand_f = isset( $rule['handling_formula'] ) ? trim( $rule['handling_formula'] ) : '';



				if ( 'default' === $type ) {

					continue;

				}

				if ( empty( $target ) ) {

					continue;

				}



				$matching_items = array();

				foreach ( $contents as $item_key => $item ) {

					if ( ! empty( $matched_item_keys[ $item_key ] ) ) {

						continue;

					}



					if ( Formula_Evaluator::item_matches_target( $item, $type, $target ) ) {

						$matching_items[ $item_key ]    = $item;

						$matched_item_keys[ $item_key ] = true;

					}

				}



				if ( ! empty( $matching_items ) ) {

					$ship_cost = 0.0;

					$hand_cost = 0.0;



					if ( ! empty( $ship_f ) ) {

						try {

							$ship_cost = Formula_Evaluator::evaluate( $ship_f, $matching_items );

						} catch ( Exception $e ) {}

					}

					if ( ! empty( $hand_f ) ) {

						try {

							$hand_cost = Formula_Evaluator::evaluate( $hand_f, $matching_items );

						} catch ( Exception $e ) {}

					}



					$rule_qty = 0;

					foreach ( $matching_items as $mi ) {

						$rule_qty += isset( $mi['quantity'] ) ? intval( $mi['quantity'] ) : ( isset( $mi['qty'] ) ? intval( $mi['qty'] ) : 1 );

					}

					$base_ship = $ship_cost;

					$incremental = 0.0;

					if ( ! empty( $ship_f ) ) {

						$single_items = array();

						$first_k = array_key_first( $matching_items );

						if ( null !== $first_k ) {

							$single_items[ $first_k ] = $matching_items[ $first_k ];

							$single_items[ $first_k ]['quantity'] = 1;

							$single_items[ $first_k ]['qty']      = 1;

							try {

								$base_ship = Formula_Evaluator::evaluate( $ship_f, $single_items );

							} catch ( Exception $e ) {}

						}

						if ( $rule_qty > 1 ) {

							$incremental = max( 0.0, ( $ship_cost - $base_ship ) / ( $rule_qty - 1 ) );

						} else if ( null !== $first_k ) {

							$two_items = array();

							$two_items[ $first_k ] = $matching_items[ $first_k ];

							$two_items[ $first_k ]['quantity'] = 2;

							$two_items[ $first_k ]['qty']      = 2;

							try {

								$two_ship = Formula_Evaluator::evaluate( $ship_f, $two_items );

								$incremental = max( 0.0, $two_ship - $base_ship );

							} catch ( Exception $e ) {}

						}

					}



					$applied_rules[] = array(

						'shipping'    => $ship_cost,

						'handling'    => $hand_cost,

						'base_ship'   => $base_ship,

						'incremental' => $incremental,

						'qty'         => $rule_qty,

						'total'       => $ship_cost + $hand_cost,

					);

				}

			}



			$default_table_ship = '';

			$default_table_hand = '';

			foreach ( $item_rules as $rule ) {

				if ( 'default' === ( isset( $rule['type'] ) ? $rule['type'] : '' ) ) {

					$default_table_ship = isset( $rule['shipping_formula'] ) ? trim( $rule['shipping_formula'] ) : '';

					$default_table_hand = isset( $rule['handling_formula'] ) ? trim( $rule['handling_formula'] ) : '';

					break;

				}

			}

			if ( empty( $default_table_ship ) && ! empty( $default_ship_formula ) ) {

				$default_table_ship = $default_ship_formula;

			}

			if ( empty( $default_table_hand ) && ! empty( $default_hand_formula ) ) {

				$default_table_hand = $default_hand_formula;

			}



			$uncategorized_items = array();

			foreach ( $contents as $item_key => $item ) {

				if ( empty( $matched_item_keys[ $item_key ] ) ) {

					$uncategorized_items[ $item_key ] = $item;

				}

			}



			if ( ! empty( $uncategorized_items ) && ( ! empty( $default_table_ship ) || ! empty( $default_table_hand ) ) ) {

				$ship_cost = 0.0;

				$hand_cost = 0.0;



				if ( ! empty( $default_table_ship ) ) {

					try {

						$ship_cost = Formula_Evaluator::evaluate( $default_table_ship, $uncategorized_items );

					} catch ( Exception $e ) {}

				}

				if ( ! empty( $default_table_hand ) ) {

					try {

						$hand_cost = Formula_Evaluator::evaluate( $default_table_hand, $uncategorized_items );

					} catch ( Exception $e ) {}

				}



				$rule_qty = 0;

				foreach ( $uncategorized_items as $mi ) {

					$rule_qty += isset( $mi['quantity'] ) ? intval( $mi['quantity'] ) : ( isset( $mi['qty'] ) ? intval( $mi['qty'] ) : 1 );

				}

				$base_ship = $ship_cost;

				$incremental = 0.0;

				if ( ! empty( $default_table_ship ) ) {

					$single_items = array();

					$first_k = array_key_first( $uncategorized_items );

					if ( null !== $first_k ) {

						$single_items[ $first_k ] = $uncategorized_items[ $first_k ];

						$single_items[ $first_k ]['quantity'] = 1;

						$single_items[ $first_k ]['qty']      = 1;

						try {

							$base_ship = Formula_Evaluator::evaluate( $default_table_ship, $single_items );

						} catch ( Exception $e ) {}

					}

					if ( $rule_qty > 1 ) {

						$incremental = max( 0.0, ( $ship_cost - $base_ship ) / ( $rule_qty - 1 ) );

					} else if ( null !== $first_k ) {

						$two_items = array();

						$two_items[ $first_k ] = $uncategorized_items[ $first_k ];

						$two_items[ $first_k ]['quantity'] = 2;

						$two_items[ $first_k ]['qty']      = 2;

						try {

							$two_ship = Formula_Evaluator::evaluate( $default_table_ship, $two_items );

							$incremental = max( 0.0, $two_ship - $base_ship );

						} catch ( Exception $e ) {}

					}

				}



				$applied_rules[] = array(

					'shipping'    => $ship_cost,

					'handling'    => $hand_cost,

					'base_ship'   => $base_ship,

					'incremental' => $incremental,

					'qty'         => $rule_qty,

					'total'       => $ship_cost + $hand_cost,

				);

			}



			$total_ship = 0.0;

			$total_hand = 0.0;



			if ( ! empty( $applied_rules ) ) {

				if ( 'max' === $resolution_logic ) {

					$max_rule = null;

					$max_total = -1.0;

					foreach ( $applied_rules as $ar ) {

						if ( $ar['total'] > $max_total ) {

							$max_total = $ar['total'];

							$max_rule = $ar;

						}

					}

					if ( $max_rule ) {

						$total_ship = $max_rule['shipping'];

						$total_hand = $max_rule['handling'];

					}

				} elseif ( 'merge_difference' === $resolution_logic ) {

					usort( $applied_rules, function( $a, $b ) {

						if ( $a['base_ship'] === $b['base_ship'] ) return 0;

						return ( $a['base_ship'] > $b['base_ship'] ) ? -1 : 1;

					});

					$primary_rule = $applied_rules[0];

					$total_ship   = $primary_rule['base_ship'] + ( ( $primary_rule['qty'] - 1 ) * $primary_rule['incremental'] );

					$total_hand   = 0.0;

					foreach ( $applied_rules as $ar ) {

						if ( $ar['handling'] > $total_hand ) {

							$total_hand = $ar['handling'];

						}

					}

					for ( $i = 1; $i < count( $applied_rules ); $i++ ) {

						$sec         = $applied_rules[ $i ];

						$total_ship += $sec['qty'] * $sec['incremental'];

					}

				} else {

					foreach ( $applied_rules as $ar ) {

						$total_ship += $ar['shipping'];

						$total_hand += $ar['handling'];

					}

				}

			}



			return array( 'shipping' => $total_ship, 'handling' => $total_hand );

		}



		/**

		 * Calculate shipping costs.

		 */

		public function calculate_shipping( $package = array() ) {

			$rate_id = $this->get_rate_id();

			$GLOBALS['formula_shipping_current_notices'] = array();

			if ( function_exists( 'WC' ) && WC() && isset( WC()->session ) && WC()->session ) {

				WC()->session->set( 'formula_handling_fee_' . $rate_id, null );

				WC()->session->set( 'formula_handling_label_' . $rate_id, null );

			}



			$customer_country = isset( $package['destination']['country'] ) ? strtoupper( trim( $package['destination']['country'] ) ) : '';

			$contents         = isset( $package['contents'] ) ? $package['contents'] : array();



			$shipping_cost = 0.0;

			$handling_cost = 0.0;

			$rate_added    = false;



			$item_cost     = self::evaluate_item_rules( $this->usa_item_rules, $this->usa_rule_resolution, $contents, $this->usa_default_shipping_formula, $this->usa_default_handling_formula );

			$shipping_cost = $item_cost['shipping'];

			$handling_cost = $item_cost['handling'];



			$county_rules   = $this->get_option( 'usa_county_rules', array() );

			$loc_resolution = $this->get_option( 'usa_location_rule_resolution', 'first_match' );

			if ( ! is_array( $county_rules ) ) {

				$county_rules = json_decode( $county_rules, true );

			}

			if ( is_array( $county_rules ) && ! empty( $county_rules ) ) {

				$applied_loc_rules = array();

				foreach ( $county_rules as $rule ) {

					if ( isset( $rule['enabled'] ) && 'no' === $rule['enabled'] ) {

						continue;

					}

					$rule_country    = isset( $rule['country'] ) ? $rule['country'] : 'ALL';

					$rule_state      = isset( $rule['state'] ) ? $rule['state'] : 'ALL';

					$rule_loc        = isset( $rule['location'] ) ? $rule['location'] : '';

					$rule_type       = isset( $rule['rule_type'] ) ? $rule['rule_type'] : ( ! empty( $rule['user'] ) ? 'user_role' : 'location' );

					$rule_user       = isset( $rule['user'] ) ? $rule['user'] : '';

					$rule_device     = isset( $rule['device_target'] ) ? $rule['device_target'] : '';

					$rule_os         = isset( $rule['os_target'] ) ? $rule['os_target'] : '';

					$rule_meta_key   = isset( $rule['user_meta_key'] ) ? $rule['user_meta_key'] : '';

					$rule_meta_value = isset( $rule['user_meta_value'] ) ? $rule['user_meta_value'] : '';



					if ( self::customer_matches_location( $package['destination'], $rule_country, $rule_state, $rule_loc, $rule_type, $rule_user, $rule_meta_key, $rule_meta_value, $rule_device, $rule_os ) ) {

						$l_ship = 0.0;

						$l_hand = 0.0;

						if ( ! empty( $rule['shipping_formula'] ) ) {

							try {

								$l_ship = Formula_Evaluator::evaluate( $rule['shipping_formula'], $contents );

							} catch ( Exception $e ) {}

						}

						if ( ! empty( $rule['handling_formula'] ) ) {

							try {

								$l_hand = Formula_Evaluator::evaluate( $rule['handling_formula'], $contents );

							} catch ( Exception $e ) {}

						}



						if ( 'first_match' === $loc_resolution ) {

							if ( ! empty( $rule['shipping_formula'] ) ) {

								$shipping_cost = $l_ship;

							}

							if ( ! empty( $rule['handling_formula'] ) ) {

								$handling_cost = $l_hand;

							}

							break;

						} else {

							$applied_loc_rules[] = array(

								'shipping' => $l_ship,

								'handling' => $l_hand,

							);

						}

					}

				}



				if ( 'first_match' !== $loc_resolution && ! empty( $applied_loc_rules ) ) {

					if ( 'max' === $loc_resolution ) {

						$max_tot = -1;

						$best_s  = $shipping_cost;

						$best_h  = $handling_cost;

						foreach ( $applied_loc_rules as $ar ) {

							$tot = $ar['shipping'] + $ar['handling'];

							if ( $tot > $max_tot ) {

								$max_tot = $tot;

								$best_s  = $ar['shipping'];

								$best_h  = $ar['handling'];

							}

						}

						$shipping_cost = $best_s;

						$handling_cost = $best_h;

					} elseif ( 'merge_difference' === $loc_resolution || 'sum' === $loc_resolution ) {

						foreach ( $applied_loc_rules as $ar ) {

							$shipping_cost += $ar['shipping'];

							$handling_cost += $ar['handling'];

						}

					}

				}

			}



			$min_shipping = trim( $this->usa_min_shipping );

			$max_shipping = trim( $this->usa_max_shipping );

			if ( '' !== $min_shipping ) {

				$shipping_cost = max( floatval( $min_shipping ), $shipping_cost );

			}

			if ( '' !== $max_shipping ) {

				$shipping_cost = min( floatval( $max_shipping ), $shipping_cost );

			}



			// Apply 5 or 0 cents rounding if enabled

			if ( 'yes' === $this->round_rates ) {

				if ( $shipping_cost > 0 ) {

					$shipping_cost = ceil( $shipping_cost * 20 ) / 20;

				}

				if ( $handling_cost > 0 ) {

					$handling_cost = ceil( $handling_cost * 20 ) / 20;

				}

			}



			$rate_added = true;



			if ( $rate_added ) {

				// Build notice metadata preserving message types (message/notice/warning)

				$notice_meta = array();

				if ( ! empty( $GLOBALS['formula_shipping_current_notices'] ) && is_array( $GLOBALS['formula_shipping_current_notices'] ) ) {

					foreach ( $GLOBALS['formula_shipping_current_notices'] as $notice_entry ) {

						if ( is_array( $notice_entry ) && ! empty( $notice_entry['message'] ) ) {

							$type    = in_array( $notice_entry['type'], array( 'message', 'notice', 'warning' ), true ) ? $notice_entry['type'] : 'message';

							$message = sanitize_text_field( $notice_entry['message'] );

							if ( '' !== $message ) {

								$notice_meta[] = array( 'type' => $type, 'message' => $message );

							}

						}

					}

				}

				// Deduplicate identical type+message pairs

				$seen = array();

				$deduped = array();

				foreach ( $notice_meta as $entry ) {

					$key = $entry['type'] . '|' . $entry['message'];

					if ( ! isset( $seen[ $key ] ) ) {

						$seen[ $key ] = true;

						$deduped[] = $entry;

					}

				}

				$rate_meta_data  = ! empty( $deduped ) ? array( 'formula_shipping_notice' => wp_json_encode( $deduped ) ) : array();

				if ( 'yes' === $this->handling_combine ) {

					$total_cost = $shipping_cost + $handling_cost;

					$rate = array(

						'id'        => $rate_id,

						'label'     => $this->title,

						'cost'      => $total_cost,

						'meta_data' => $rate_meta_data,

					);

					$this->add_rate( $rate );

				} else {

					$rate = array(

						'id'        => $rate_id,

						'label'     => $this->title,

						'cost'      => $shipping_cost,

						'meta_data' => $rate_meta_data,

					);

					$this->add_rate( $rate );



					if ( $handling_cost > 0 && function_exists( 'WC' ) && WC() && isset( WC()->session ) && WC()->session ) {

						WC()->session->set( 'formula_handling_fee_' . $rate_id, $handling_cost );

						WC()->session->set( 'formula_handling_label_' . $rate_id, $this->handling_title );

					}

				}

			unset( $GLOBALS['formula_shipping_current_notices'] );

			}

		}



		public static function customer_matches_location( $destination, $rule_country, $rule_state, $rule_location_pattern, $rule_type = 'location', $rule_user = '', $rule_meta_key = '', $rule_meta_value = '', $rule_device = '', $rule_os = '' ) {

			$cust_country = isset( $destination['country'] ) ? strtoupper( trim( $destination['country'] ) ) : '';

			if ( ! self::rule_value_matches_country_list( $cust_country, $rule_country ) ) {

				return false;

			}



			$cust_state = isset( $destination['state'] ) ? strtoupper( trim( $destination['state'] ) ) : '';

			if ( ! self::rule_value_matches_token_list( $cust_state, $rule_state, true ) ) {

				return false;

			}



			$rule_type = strtolower( trim( (string) $rule_type ) );

			if ( 'user' === $rule_type ) {

				$rule_type = 'user_role';

			}



			if ( 'device_os' === $rule_type || 'device' === $rule_type ) {

				$client = Formula_Evaluator::detect_client_context();

				$rule_device = trim( (string) $rule_device );

				$rule_os     = trim( (string) $rule_os );

				if ( '' === $rule_device && '' === $rule_os && '' !== trim( (string) $rule_user ) ) {

					$target_tokens = self::parse_rule_match_tokens( $rule_user, false );

					if ( ! self::token_list_contains_all( $target_tokens ) && ! empty( $target_tokens ) ) {

						$matched_target = false;

						foreach ( $target_tokens as $target ) {

							$target = trim( (string) $target );

							if ( Formula_Evaluator::environment_target_matches( $client['device'], $target, 'device' ) || Formula_Evaluator::environment_target_matches( $client['os'], $target, 'os' ) ) {

								$matched_target = true;

								break;

							}

						}

						if ( ! $matched_target ) {

							return false;

						}

					}

				} else {

					if ( ! Formula_Evaluator::environment_target_matches( $client['device'], $rule_device, 'device' ) ) {

						return false;

					}

					if ( ! Formula_Evaluator::environment_target_matches( $client['os'], $rule_os, 'os' ) ) {

						return false;

					}

				}

			} elseif ( 'browser' === $rule_type ) {

				$client = Formula_Evaluator::detect_client_context();

				if ( ! self::rule_value_matches_token_list( strtolower( $client['browser'] ), $rule_user, true ) ) {

					return false;

				}

			} elseif ( in_array( $rule_type, array( 'user_role', 'user_login', 'user_email', 'user_meta' ), true ) ) {

				$target_value = ( 'user_meta' === $rule_type ) ? $rule_meta_value : $rule_user;

				if ( ! Formula_Evaluator::evaluate_user_placeholder( $target_value, $rule_type, $rule_meta_key ) ) {

					return false;

				}

			} elseif ( ! empty( $rule_user ) && 'ALL' !== strtoupper( trim( $rule_user ) ) ) {

				if ( ! Formula_Evaluator::evaluate_user_placeholder( trim( $rule_user ), 'auto' ) ) {

					return false;

				}

			}

			

			$rule_location_pattern = trim( $rule_location_pattern );

			if ( empty( $rule_location_pattern ) ) {

				return true;

			}

			

			$cust_city     = isset( $destination['city'] ) ? strtolower( trim( $destination['city'] ) ) : '';

			$cust_postcode = isset( $destination['postcode'] ) ? strtolower( trim( $destination['postcode'] ) ) : '';

			

			$cust_county = '';

			if ( isset( $destination['county'] ) ) {

				$cust_county = strtolower( trim( $destination['county'] ) );

			} elseif ( function_exists( 'WC' ) && WC() && isset( WC()->customer ) && WC()->customer ) {

				$cust_county = strtolower( trim( WC()->customer->get_meta( 'shipping_county' ) ) );

				if ( empty( $cust_county ) ) {

					$cust_county = strtolower( trim( WC()->customer->get_meta( 'billing_county' ) ) );

				}

			}

			

			$pattern = strtolower( $rule_location_pattern );

			

			if ( strpos( $pattern, '*' ) !== false ) {

				$regex = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/i';

				if ( preg_match( $regex, $cust_postcode ) || preg_match( $regex, $cust_city ) || ( ! empty( $cust_county ) && preg_match( $regex, $cust_county ) ) ) {

					return true;

				}

			} else {

				if ( $cust_postcode === $pattern || $cust_city === $pattern || ( ! empty( $cust_county ) && $cust_county === $pattern ) ) {

					return true;

				}

				if ( strpos( $cust_city, $pattern ) !== false || strpos( $cust_postcode, $pattern ) !== false || ( ! empty( $cust_county ) && strpos( $cust_county, $pattern ) !== false ) ) {

					return true;

				}

			}

			

			return false;

		}



		private static function parse_rule_match_tokens( $value, $allow_space_delimited = false ) {
			if ( ! is_string( $value ) ) {
				return array();
			}

			$value = trim( $value );
			if ( '' === $value ) {
				return array();
			}

			if ( false !== strpos( $value, ',' ) || false !== strpos( $value, ';' ) ) {
				preg_match_all( '/(?:"[^"]*"|\'[^\']*\'|[^,;])+/', $value, $matches );
				$tokens = isset( $matches[0] ) ? $matches[0] : array( $value );
			} elseif ( $allow_space_delimited && preg_match( '/\s+/', $value ) && ! preg_match( '/^[\'"].*[\'"]$/', $value ) ) {
				$tokens = preg_split( '/\s+/', $value );
			} else {
				$tokens = array( $value );
			}

			$clean_tokens = array();
			foreach ( $tokens as $token ) {
				$token = trim( (string) $token );
				$token = preg_replace( '/^[\'"]|[\'"]$/', '', $token );
				$token = trim( $token );
				if ( '' !== $token ) {
					$clean_tokens[] = $token;
				}
			}

			return $clean_tokens;
		}



		private static function token_list_contains_all( $tokens ) {

			foreach ( $tokens as $token ) {

				if ( 'ALL' === strtoupper( trim( (string) $token ) ) ) {

					return true;

				}

			}



			return false;

		}



		private static function rule_value_matches_country_list( $actual_country, $rule_value ) {

			$actual_country = Formula_Evaluator::normalize_country_reference( $actual_country );

			if ( '' === $actual_country ) {

				return false;

			}



			$rule_value = is_string( $rule_value ) ? trim( $rule_value ) : '';

			if ( '' === $rule_value ) {

				return true;

			}



			if ( 'ALL' === strtoupper( $rule_value ) ) {

				return true;

			}



			if ( Formula_Evaluator::normalize_country_reference( $rule_value ) === $actual_country ) {

				return true;

			}



			$allow_space_delimited = (bool) preg_match( '/^[A-Za-z]{2,3}(?:\s+[A-Za-z]{2,3})+$/', $rule_value );

			$tokens = self::parse_rule_match_tokens( $rule_value, $allow_space_delimited );

			if ( self::token_list_contains_all( $tokens ) ) {

				return true;

			}



			foreach ( $tokens as $token ) {

				if ( Formula_Evaluator::normalize_country_reference( $token ) === $actual_country ) {

					return true;

				}

			}



			return false;

		}



		private static function rule_value_matches_token_list( $actual_value, $rule_value, $allow_space_delimited = false ) {

			$actual_value = strtolower( trim( (string) $actual_value ) );

			$rule_value   = is_string( $rule_value ) ? trim( $rule_value ) : '';

			if ( '' === $rule_value ) {

				return true;

			}



			$tokens = self::parse_rule_match_tokens( $rule_value, $allow_space_delimited );

			if ( self::token_list_contains_all( $tokens ) ) {

				return true;

			}



			foreach ( $tokens as $token ) {

				if ( $actual_value === strtolower( trim( (string) $token ) ) ) {

					return true;

				}

			}



			return false;

		}

	}

}



// ==========================================

// 3. WP/WOOCOMMERCE HOOKS

// ==========================================



if ( ! function_exists( 'formula_rate_shipping_register_method' ) ) {

	function formula_rate_shipping_register_method( $methods ) {

		if ( ! class_exists( 'WC_Shipping_Formula' ) ) {

			formula_rate_shipping_init();

		}

		if ( class_exists( 'WC_Shipping_Formula' ) ) {

			$methods['formula_rate_shipping'] = 'WC_Shipping_Formula';

		}

		return $methods;

	}

	add_filter( 'woocommerce_shipping_methods', 'formula_rate_shipping_register_method' );

}



if ( ! function_exists( 'formula_rate_shipping_auto_inject_rates' ) ) {

	function formula_rate_shipping_auto_inject_rates( $rates, $package ) {

		if ( ! class_exists( 'WC_Shipping_Formula' ) ) {

			formula_rate_shipping_init();

		}

		if ( ! class_exists( 'WC_Shipping_Formula' ) ) {

			return $rates;

		}



		// Check if already present in package rates

		foreach ( $rates as $rate_id => $rate ) {

			if ( strpos( $rate_id, 'formula_rate_shipping' ) !== false ) {

				return $rates;

			}

		}



		$method = new WC_Shipping_Formula();

		if ( 'no' === $method->get_option( 'auto_inject', 'yes' ) ) {

			return $rates;

		}



		$method->calculate_shipping( $package );

		if ( ! empty( $method->rates ) ) {

			foreach ( $method->rates as $rate ) {

				$rates[ $rate->id ] = $rate;

			}

		}



		return $rates;

	}

	add_filter( 'woocommerce_package_rates', 'formula_rate_shipping_auto_inject_rates', 10, 2 );

}



if ( ! function_exists( 'formula_rate_shipping_render_rate_notice' ) ) {

	function formula_rate_shipping_render_rate_notice( $rate ) {

		if ( ! is_object( $rate ) || ! method_exists( $rate, 'get_meta' ) ) {

			return;

		}

		$notice = $rate->get_meta( 'formula_shipping_notice', true );

		if ( '' === trim( (string) $notice ) ) {

			return;

		}

		$entries = json_decode( $notice, true );

		if ( ! is_array( $entries ) ) {

			// Legacy plain-text notice

			echo '<div class="formula-rate-notice-badge formula-notice-message" style="display:inline-flex;align-items:center;gap:6px;margin:6px 0 2px 24px;padding:4px 10px;border-radius:6px;font-size:12px;font-weight:500;line-height:1.4;background:#f0f9ff;border:1px solid #bae6fd;color:#0369a1;"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg><span>' . esc_html( $notice ) . '</span></div>';

			return;

		}

		foreach ( $entries as $entry ) {

			if ( ! is_array( $entry ) || empty( $entry['message'] ) ) {

				continue;

			}

			$pos = isset( $entry['position'] ) ? strtolower( trim( $entry['position'] ) ) : 'under_rate';

			if ( 'top' === $pos || 'global' === $pos ) {

				if ( function_exists( 'wc_add_notice' ) ) {

					wc_add_notice( $entry['message'], 'notice' );

				}

				continue;

			}

			if ( 'under_rate' !== $pos && '' !== $pos ) {

				if ( ! isset( $GLOBALS['formula_shipping_positioned_notices'] ) ) {

					$GLOBALS['formula_shipping_positioned_notices'] = array();

				}

				$GLOBALS['formula_shipping_positioned_notices'][] = $entry;

				continue;

			}

			$type    = isset( $entry['type'] ) ? $entry['type'] : 'message';

			$message = esc_html( $entry['message'] );

			$bg_color     = '#f0f9ff';

			$border_color = '#bae6fd';

			$text_color   = '#0369a1';

			$svg_icon     = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';

			if ( 'notice' === $type ) {

				$bg_color     = '#f0fdf4';

				$border_color = '#a7f3d0';

				$text_color   = '#15803d';

				$svg_icon     = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>';

			} elseif ( 'warning' === $type ) {

				$bg_color     = '#fffbeb';

				$border_color = '#fde68a';

				$text_color   = '#b45309';

				$svg_icon     = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';

			}

			echo '<div class="formula-rate-notice-badge formula-notice-' . esc_attr( $type ) . '" style="display:inline-flex;align-items:center;gap:6px;margin:6px 0 2px 24px;padding:4px 10px;border-radius:6px;font-size:12px;font-weight:500;line-height:1.4;background:' . $bg_color . ';border:1px solid ' . $border_color . ';color:' . $text_color . ';">' . $svg_icon . '<span>' . $message . '</span></div>';
		}
	}
	add_action( 'woocommerce_after_shipping_rate', 'formula_rate_shipping_render_rate_notice', 10, 1 );
}

if ( ! function_exists( 'formula_rate_shipping_render_positioned_notices' ) ) {
	function formula_rate_shipping_render_positioned_notices( $target_pos ) {
		if ( empty( $GLOBALS['formula_shipping_positioned_notices'] ) || ! is_array( $GLOBALS['formula_shipping_positioned_notices'] ) ) {
			return;
		}

		foreach ( $GLOBALS['formula_shipping_positioned_notices'] as $entry ) {
			$pos = isset( $entry['position'] ) ? strtolower( trim( $entry['position'] ) ) : 'under_rate';
			if ( $pos !== $target_pos ) {
				continue;
			}

			$type    = isset( $entry['type'] ) ? $entry['type'] : 'message';
			$message = esc_html( $entry['message'] );

			$bg_color     = '#f0f9ff';
			$border_color = '#bae6fd';
			$text_color   = '#0369a1';
			$svg_icon     = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';

			if ( 'notice' === $type ) {
				$bg_color     = '#f0fdf4';
				$border_color = '#a7f3d0';
				$text_color   = '#15803d';
				$svg_icon     = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>';
			} elseif ( 'warning' === $type ) {
				$bg_color     = '#fffbeb';
				$border_color = '#fde68a';
				$text_color   = '#b45309';
				$svg_icon     = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
			}

			echo '<div class="formula-rate-notice-badge formula-notice-' . esc_attr( $type ) . '" style="display:inline-flex;align-items:center;gap:6px;margin:8px 0;padding:6px 12px;border-radius:6px;font-size:13px;font-weight:500;line-height:1.4;background:' . $bg_color . ';border:1px solid ' . $border_color . ';color:' . $text_color . ';">' . $svg_icon . '<span>' . $message . '</span></div>';
		}
	}

	add_action( 'woocommerce_cart_contents', function() { formula_rate_shipping_render_positioned_notices( 'cart_table' ); }, 20 );
	add_action( 'woocommerce_cart_coupon', function() { formula_rate_shipping_render_positioned_notices( 'cart_table' ); }, 20 );
	add_action( 'woocommerce_before_shipping_calculator', function() { formula_rate_shipping_render_positioned_notices( 'before_shipping' ); }, 10 );
	add_action( 'woocommerce_cart_totals_before_shipping', function() { formula_rate_shipping_render_positioned_notices( 'before_shipping' ); }, 10 );
	add_action( 'woocommerce_after_shipping_calculator', function() { formula_rate_shipping_render_positioned_notices( 'after_shipping' ); }, 10 );
	add_action( 'woocommerce_cart_totals_after_shipping', function() { formula_rate_shipping_render_positioned_notices( 'after_shipping' ); }, 10 );
}



if ( ! function_exists( 'formula_rate_shipping_inject_frontend_css' ) ) {

	function formula_rate_shipping_inject_frontend_css() {

		?>

		<style>

			.formula-shipping-call-office-notice {

				background-color: #fffaf0;

				border-left: 4px solid #e67e22;

				padding: 15px 20px;

				margin: 20px 0;

				border-radius: 4px;

				color: #2c3e50;

				font-size: 15px;

				line-height: 1.6;

				box-shadow: 0 2px 5px rgba(0,0,0,0.05);

				font-family: inherit;

			}

		
/* REMOVE DOUBLE NESTED CHIP BORDERS COMPLETELY */
.formula-chip-field {
	border: none !important;
	box-shadow: none !important;
	outline: none !important;
	background: transparent !important;
	border-radius: 0 !important;
	padding: 0 !important;
	margin: 0 !important;
}

.formula-chip-field .formula-chip-tag {
	border-radius: 4px !important;
	font-size: 11px !important;
}

/* VERTICAL ALIGNMENT OF DROPDOWN AND INPUT CONTAINERS */
.formula-shipping-rules-table td,
.formula-shipping-rules-table td > div {
	vertical-align: middle !important;
}

.formula-mini-target-shell {
	background: #f8fafc;
	border: 1px solid #cbd5e1;
	border-radius: 8px;
	padding: 8px 12px;
	display: flex;
	flex-direction: column;
	gap: 6px;
	box-sizing: border-box;
	vertical-align: middle !important;
}

/* SINGLE VISIBLE INPUT CONTAINER */
.formula-mini-target-grid-location {
	width: 100% !important;
	box-sizing: border-box !important;
}

.formula-loc-country-wrap,
.formula-loc-state-wrap,
.formula-loc-city-wrap {
	width: 100% !important;
	box-sizing: border-box !important;
}

/* State and city hidden by default – JS shows the active one */
.formula-loc-state-wrap,
.formula-loc-city-wrap {
	display: none;
}

.formula-mini-target-grid-location input[type="text"]:not(.formula-chip-editor) {
	width: 100% !important;
	box-sizing: border-box !important;
	border: 1px solid #94a3b8 !important;
	border-radius: 6px !important;
	padding: 6px 10px !important;
	font-size: 12px !important;
	background: #ffffff;
}

/* TAB PILLS */
.formula-mini-target-tabs {
	display: flex;
	align-items: center;
	gap: 6px;
	margin-bottom: 4px;
}

.formula-mini-target-tab {
	display: inline-flex;
	align-items: center;
	padding: 3px 10px;
	border-radius: 12px;
	font-size: 11px;
	font-weight: 600;
	color: #64748b;
	background: #e2e8f0;
	cursor: pointer;
	user-select: none;
	transition: all 0.2s ease;
}

.formula-mini-target-tab:hover {
	background: #cbd5e1;
	color: #1e293b;
}

.formula-mini-target-tab.is-active {
	background: #0284c7 !important;
	color: #ffffff !important;
	font-weight: 700 !important;
}

.formula-mini-target-tab.has-data {
	background: #16a34a !important;
	color: #ffffff !important;
	font-weight: 700 !important;
	box-shadow: 0 1px 3px rgba(22, 163, 74, 0.3) !important;
}

.formula-mini-target-tab.has-data::after {
	content: " ●";
	font-size: 9px;
	margin-left: 3px;
	color: #bbf7d0;
}

/* DISABLED STATE STYLING */
.formula-mini-target-tab.formula-state-disabled {
	opacity: 0.6 !important;
	cursor: not-allowed !important;
}

input.formula-state-disabled,
input:disabled {
	background-color: #f1f5f9 !important;
	color: #94a3b8 !important;
	border-color: #cbd5e1 !important;
	cursor: not-allowed !important;
}

/* INPUT WITH DATA (SOFT MINT) */
input.formula-field-has-data,
select.formula-field-has-data {
	background-color: #f0fdf4 !important;
	border-color: #86efac !important;
	color: #14532d !important;
}

/* WIDE SELECT DROPDOWNS FOR FULL TEXT VISIBILITY */
select.formula-shipping-type-select,
select.formula-rule-type-select,
select.formula-loc-type-select,
select[name*="[type]"] {
	min-width: 235px !important;
	max-width: 280px !important;
	width: 100% !important;
	font-size: 12px !important;
	padding-right: 28px !important;
	box-sizing: border-box !important;
}

/* TEST CART CATEGORY & VARIATION BOX SIZES */
.formula-sim-cart-table select.formula-sim-cat-select {
	min-width: 260px !important;
	flex: 1 1 auto !important;
}

.formula-sim-cart-table input.formula-sim-var-input {
	max-width: 110px !important;
	flex: 0 0 110px !important;
}


/* STRICT SINGLE VISIBLE INPUT CONTROL FOR LOCATION TARGET WRAPS */
.formula-mini-target-tabs {
	display: none !important;
}

.formula-loc-country-wrap,
.formula-loc-state-wrap,
.formula-loc-city-wrap {
	width: 100% !important;
	box-sizing: border-box !important;
	display: block !important;
}

.formula-mini-target-grid-location {
	width: 100% !important;
	box-sizing: border-box !important;
}

.formula-mini-target-grid-location input[type="text"]:not(.formula-chip-editor) {
	width: 100% !important;
	box-sizing: border-box !important;
	border: 1px solid #94a3b8 !important;
	border-radius: 6px !important;
	padding: 6px 10px !important;
	font-size: 12px !important;
	background: #ffffff;
}

/* TAB PILLS STYLING */
.formula-mini-target-tabs {
	display: flex;
	align-items: center;
	gap: 6px;
	margin-bottom: 6px;
}

.formula-mini-target-tab {
	display: inline-flex;
	align-items: center;
	padding: 3px 10px;
	border-radius: 12px;
	font-size: 11px;
	font-weight: 600;
	color: #64748b;
	background: #e2e8f0;
	cursor: pointer;
	user-select: none;
	transition: all 0.2s ease;
}

.formula-mini-target-tab:hover {
	background: #cbd5e1;
	color: #1e293b;
}

.formula-mini-target-tab.is-active {
	background: #0284c7 !important;
	color: #ffffff !important;
	font-weight: 700 !important;
}

.formula-mini-target-tab.has-data {
	background: #16a34a !important;
	color: #ffffff !important;
	font-weight: 700 !important;
	box-shadow: 0 1px 3px rgba(22, 163, 74, 0.3) !important;
}

.formula-mini-target-tab.has-data::after {
	content: " ●";
	font-size: 9px;
	margin-left: 3px;
	color: #bbf7d0;
}

.formula-mini-target-tab.formula-state-disabled {
	opacity: 0.5 !important;
	cursor: not-allowed !important;
}

/* STRIP INNER DOUBLE ROUNDED BORDERS */
.formula-chip-field {
	border: none !important;
	box-shadow: none !important;
	outline: none !important;
	background: transparent !important;
	border-radius: 0 !important;
	padding: 0 !important;
	margin: 0 !important;
}

/* WIDE DROPDOWNS */
select.formula-loc-type-select,
select.formula-rule-type-select {
	min-width: 215px !important;
	max-width: 260px !important;
	width: auto !important;
	font-size: 12px !important;
}

.formula-sim-cart-table select.formula-sim-cat-select {
	min-width: 260px !important;
}

.formula-sim-cart-table input.formula-sim-var-input {
	max-width: 110px !important;
}

</style>

		<?php

	}

	add_action( 'wp_head', 'formula_rate_shipping_inject_frontend_css' );

}



if ( ! function_exists( 'formula_rate_shipping_inject_admin_css' ) ) {

	function formula_rate_shipping_inject_admin_css() {

	?>

	<style>

		/* MODAL DIALOG IN WOOCOMMERCE ZONE SETTINGS - widened for the denser rule editor. */

		.wc-backbone-modal-main,

		#wc-backbone-modal-dialog .wc-backbone-modal-main,

		.wc-backbone-modal-content,

		#wc-backbone-modal-dialog .wc-backbone-modal-content {

			width: 950px !important;

			max-width: calc(100vw - 24px) !important;

			min-width: 0 !important;

			border-radius: 12px !important;

			box-shadow: 0 10px 30px rgba(0,0,0,0.2) !important;

		}

		.wc-backbone-modal-content,

		#wc-backbone-modal-dialog .wc-backbone-modal-content {

			box-sizing: border-box !important;

			border-radius: 12px !important;

		}

		.wc-backbone-modal .wc-backbone-modal-main article {

			padding: 30px 40px !important;

			box-sizing: border-box !important;

		}



		/* Card containers and Modal Layout */

		.wc-backbone-modal .formula-section-card,

		.woocommerce .formula-section-card,

		.formula-section-card {

			background: #ffffff !important;

			border: 1px solid #cbd5e1 !important;

			border-radius: 10px !important;

			margin-bottom: 32px !important;

			box-shadow: 0 4px 15px rgba(0, 0, 0, 0.06) !important;

			overflow: hidden !important;

			display: block !important;

		}

		.wc-backbone-modal .formula-section-card .card-header,

		.woocommerce .formula-section-card .card-header,

		.formula-section-card .card-header {

			padding: 16px 24px !important;

			display: flex !important;

			align-items: center !important;

			justify-content: space-between !important;

			color: #ffffff !important;

			border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important;

		}

		.wc-backbone-modal .formula-section-card .card-header h3,

		.woocommerce .formula-section-card .card-header h3,

		.formula-section-card .card-header h3 {

			margin: 0 !important;

			color: #ffffff !important;

			font-size: 16px !important;

			font-weight: 700 !important;

			display: flex !important;

			align-items: center !important;

			gap: 10px !important;

		}

		.card-theme-general .card-header { background: linear-gradient(135deg, #1e293b 0%, #334155 100%) !important; }

		.card-theme-location .card-header { background: linear-gradient(135deg, #047857 0%, #065f46 100%) !important; }

		.card-theme-county .card-header { background: linear-gradient(135deg, #0f766e 0%, #115e59 100%) !important; }

		.card-theme-item-rules .card-header { background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%) !important; }

		.card-theme-simulator .card-header { background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%) !important; }

		.card-theme-import-export .card-header { background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%) !important; }

		.card-theme-documentation .card-header { background: linear-gradient(135deg, #0369a1 0%, #075985 100%) !important; }



		/* Say Thanks button and Card Badges styling */

		.formula-section-card .card-header .card-badge {

			font-size: 11px !important;

			font-weight: 600 !important;

			padding: 4px 10px !important;

			border-radius: 999px !important;

			background: rgba(255, 255, 255, 0.15) !important;

			color: #ffffff !important;

			text-transform: uppercase !important;

			letter-spacing: 0.5px !important;

			border: 1px solid rgba(255, 255, 255, 0.25) !important;

			display: inline-block !important;

			line-height: 1.4 !important;

		}

		.formula-section-card .card-header a.card-badge {

			background: #ffffff !important;

			color: #b91c1c !important; /* High contrast red */

			border: 1px solid #fee2e2 !important;

			box-shadow: 0 2px 4px rgba(0, 0, 0, 0.08) !important;

			transition: all 0.2s ease !important;

			text-transform: none !important;

			letter-spacing: normal !important;

			display: inline-flex !important;

			align-items: center !important;

			gap: 6px !important;

			text-decoration: none !important;

		}

		.formula-section-card .card-header a.card-badge:hover {

			background: #fee2e2 !important;

			color: #991b1b !important;

			border-color: #fca5a5 !important;

			transform: translateY(-1px) !important;

			box-shadow: 0 4px 8px rgba(0, 0, 0, 0.12) !important;

		}



		/* Collapsible Summary Bar Styles */

		.card-summary-bar {

			background-color: #f8fafc !important;

			border-bottom: 1px solid #e2e8f0 !important;

			padding: 8px 24px !important;

			display: flex !important;

			align-items: center !important;

			justify-content: space-between !important;

			cursor: pointer !important;

			user-select: none !important;

			transition: background-color 0.2s ease !important;

		}

		.card-summary-bar:hover {

			background-color: #f1f5f9 !important;

		}

		.card-summary-text {

			font-size: 12px !important;

			font-weight: 500 !important;

			color: #475569 !important;

			display: flex !important;

			align-items: center !important;

			gap: 12px !important;

			flex-wrap: wrap !important;

		}

		.card-summary-text span.summary-pill {

			background: #e2e8f0 !important;

			color: #334155 !important;

			padding: 2px 8px !important;

			border-radius: 4px !important;

			font-size: 11px !important;

			font-weight: 600 !important;

		}

		.card-summary-text span.summary-pill.pill-active {

			background: #dcfce7 !important;

			color: #15803d !important;

		}

		.card-toggle-icon {

			font-size: 20px !important;

			color: #64748b !important;

			transition: transform 0.3s ease !important;

			display: inline-block !important;

			width: 20px !important;

			height: 20px !important;

			line-height: 20px !important;

			text-align: center !important;

		}

		.formula-section-card.is-collapsed .card-toggle-icon {

			transform: rotate(-180deg) !important;

		}



		/* Autocomplete Suggestion Box Styling */

		.formula-autocomplete-wrapper {

			position: relative !important;

			display: block !important;

			width: 100% !important;

		}

		.formula-autocomplete-suggestions {

			position: absolute !important;

			top: 100% !important;

			left: 0 !important;

			right: 0 !important;

			z-index: 999999 !important;

			background: #ffffff !important;

			border: 1px solid #94a3b8 !important;

			border-radius: 6px !important;

			box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15) !important;

			max-height: 200px !important;

			overflow-y: auto !important;

			margin-top: 4px !important;

			padding: 4px 0 !important;

			list-style: none !important;

		}

		.formula-autocomplete-item {

			padding: 8px 12px !important;

			font-size: 12px !important;

			color: #1e293b !important;

			cursor: pointer !important;

			transition: background-color 0.15s ease !important;

			display: flex !important;

			align-items: center !important;

			justify-content: space-between !important;

		}

		.formula-autocomplete-item:hover,

		.formula-autocomplete-item.is-selected {

			background-color: #f1f5f9 !important;

			color: #0284c7 !important;

			font-weight: 600 !important;

		}

		.formula-autocomplete-item .item-badge {

			font-size: 10px !important;

			background: #e2e8f0 !important;

			color: #475569 !important;

			padding: 2px 6px !important;

			border-radius: 4px !important;

			font-weight: 500 !important;

		}

		.formula-chip-source-input {

			display: none !important;

		}

		.formula-chip-field {

			display: flex !important;

			align-items: center !important;

			gap: 6px !important;

			flex-wrap: nowrap !important;

			width: 100% !important;

			height: 38px !important;

			min-height: 38px !important;

			max-height: 38px !important;

			padding: 2px 6px !important;

			border: 1px solid #94a3b8 !important;

			border-radius: 6px !important;

			background: #ffffff !important;

			box-sizing: border-box !important;

			overflow-x: auto !important;

			overflow-y: hidden !important;

		}

		.formula-mini-target-shell {

			display: flex !important;

			flex-direction: column !important;

			gap: 6px !important;

			width: 100% !important;

		}

		.formula-mini-target-tabs {

			display: flex !important;

			gap: 6px !important;

			flex-wrap: wrap !important;

		}

		.formula-mini-target-tab {

			display: inline-flex !important;

			align-items: center !important;

			padding: 2px 8px !important;

			border-radius: 999px !important;

			border: 1px solid #cbd5e1 !important;

			background: #f8fafc !important;

			color: #64748b !important;

			font-size: 11px !important;

			font-weight: 600 !important;

			line-height: 1.4 !important;

		}

		.formula-mini-target-tab.is-active {

			border-color: #7dd3fc !important;

			background: #e0f2fe !important;

			color: #075985 !important;

		}

		tr.formula-location-row-main td {
			vertical-align: top !important;
			padding-top: 10px !important;
			padding-bottom: 12px !important;
		}

		tr.formula-location-row-main select.formula-loc-type-select {
			margin-top: 17px !important;
			vertical-align: top !important;
			width: 100% !important;
		}

		select.formula-shipping-type-select,
		select.formula-rule-type-select,
		select.formula-loc-type-select,
		select[name*="[type]"] {
			min-width: 235px !important;
			max-width: 280px !important;
			width: 100% !important;
			font-size: 12px !important;
			padding-right: 28px !important;
			box-sizing: border-box !important;
		}

		td.formula-location-target-cell,
		.loc-geo-fields,
		.formula-mini-target-shell-location,
		.formula-location-inline-fields {
			width: 100% !important;
			max-width: 100% !important;
			box-sizing: border-box !important;
			display: block !important;
			flex: 1 1 100% !important;
		}

		.formula-mini-target-grid {
			display: grid !important;
			gap: 12px !important;
			align-items: end !important;
			width: 100% !important;
			max-width: 100% !important;
			box-sizing: border-box !important;
		}

		.formula-loc-col {
			display: flex;
			flex-direction: column;
			align-items: flex-start;
			text-align: left;
			min-width: 0;
			width: 100%;
			max-width: 100%;
		}

		.formula-loc-col-city,
		.formula-loc-city-wrap,
		input.formula-loc-location-input {
			width: 100% !important;
			max-width: 100% !important;
			box-sizing: border-box !important;
			flex: 1 1 100% !important;
		}

		.formula-loc-col-state {
			display: none !important;
		}

		.formula-mini-target-grid-location[data-has-state="true"] .formula-loc-col-state {
			display: flex !important;
		}

		.formula-loc-col-label {
			display: block !important;
			text-align: left !important;
			float: none !important;
			margin: 0 0 4px 0 !important;
			font-size: 11px !important;
			font-weight: 600 !important;
			color: #475569 !important;
			line-height: 1.2 !important;
			white-space: nowrap !important;
			width: 100% !important;
		}

		.formula-mini-target-grid-location {
			display: grid !important;
			grid-template-columns: 140px 1fr !important;
			width: 100% !important;
			max-width: 100% !important;
			box-sizing: border-box !important;
		}

		.formula-mini-target-grid-location[data-has-state="true"] {
			display: grid !important;
			grid-template-columns: 140px 150px 1fr !important;
			width: 100% !important;
			max-width: 100% !important;
			box-sizing: border-box !important;
		}

		.formula-mini-target-grid-environment {

			grid-template-columns: minmax(150px, 0.8fr) minmax(220px, 1.2fr) !important;

		}

		.formula-chip-field:focus-within {

			border-color: #0284c7 !important;

			box-shadow: 0 0 0 1px rgba(2, 132, 199, 0.16) !important;

		}

		.formula-chip-field.formula-has-error {

			border-color: #dc2626 !important;

			box-shadow: 0 0 0 1px rgba(220, 38, 38, 0.12) !important;

		}

		.formula-chip-list {

			display: flex !important;

			flex-wrap: nowrap !important;

			gap: 6px !important;

			align-items: center !important;

			flex: 0 1 auto !important;

			min-width: 0 !important;

		}

		.formula-chip-token {

			display: inline-flex !important;

			align-items: center !important;

			gap: 6px !important;

			padding: 3px 8px !important;

			border-radius: 999px !important;

			background: #e0f2fe !important;

			border: 1px solid #bae6fd !important;

			color: #075985 !important;

			font-size: 12px !important;

			font-weight: 600 !important;

			line-height: 1.2 !important;

			white-space: nowrap !important;

			flex-shrink: 0 !important;

		}

		.formula-chip-remove {

			appearance: none !important;

			border: 0 !important;

			background: transparent !important;

			color: #0369a1 !important;

			cursor: pointer !important;

			font-size: 14px !important;

			line-height: 1 !important;

			padding: 0 !important;

		}

		.formula-chip-remove:hover {

			color: #b91c1c !important;

		}

		.formula-chip-editor-wrap {

			flex: 1 1 50px !important;

			min-width: 30px !important;

		}

		.formula-chip-editor,
		.formula-chip-field input,
		.formula-chip-field input[type="text"],
		.formula-chip-field input.formula-chip-editor,
		.formula-chip-editor-wrap input,
		.formula-chip-editor-wrap input[type="text"],
		.formula-shipping-rules-table .formula-chip-field input,
		.formula-shipping-rules-table .formula-chip-field input[type="text"],
		.formula-shipping-rules-table input.formula-chip-editor,
		.formula-mini-target-shell .formula-chip-field input,
		.formula-mini-target-shell .formula-chip-field input[type="text"],
		.formula-mini-target-grid-location .formula-chip-field input,
		.formula-mini-target-grid-location .formula-chip-field input[type="text"],
		table.formula-shipping-rules-table td .formula-chip-field input[type="text"],
		table.wc_input_table td .formula-chip-field input[type="text"] {
			width: 100% !important;
			height: 26px !important;
			min-height: 26px !important;
			max-height: 26px !important;
			padding: 0 4px !important;
			border: 0 !important;
			border-style: none !important;
			box-shadow: none !important;
			outline: none !important;
			background: transparent !important;
			line-height: 24px !important;
			margin: 0 !important;
		}

		.formula-chip-editor:focus,
		.formula-chip-field input:focus,
		.formula-chip-field input[type="text"]:focus,
		.formula-chip-field input.formula-chip-editor:focus,
		.formula-chip-editor-wrap input:focus,
		.formula-chip-editor-wrap input[type="text"]:focus,
		.formula-shipping-rules-table .formula-chip-field input:focus,
		.formula-shipping-rules-table .formula-chip-field input[type="text"]:focus,
		.formula-shipping-rules-table input.formula-chip-editor:focus,
		.formula-mini-target-shell .formula-chip-field input:focus,
		.formula-mini-target-shell .formula-chip-field input[type="text"]:focus,
		.formula-mini-target-grid-location .formula-chip-field input:focus,
		.formula-mini-target-grid-location .formula-chip-field input[type="text"]:focus,
		table.formula-shipping-rules-table td .formula-chip-field input[type="text"]:focus,
		table.wc_input_table td .formula-chip-field input[type="text"]:focus {
			border: 0 !important;
			border-style: none !important;
			box-shadow: none !important;
			outline: none !important;
			background: transparent !important;
		}

		.formula-formula-error {

			display: none;

			margin-top: 6px;

			font-size: 12px;

			line-height: 1.45;

			color: #b91c1c;

		}

		.formula-formula-error.is-visible {

			display: block;

		}

		.formula-formula-error.is-pending {

			display: block;

			color: #64748b;

		}

		.formula-input-invalid {

			border-color: #dc2626 !important;

			box-shadow: 0 0 0 1px rgba(220, 38, 38, 0.12) !important;

		}



		/* Two Column Grid */

		.formula-two-col-grid {

			display: grid;

			grid-template-columns: 1fr 1fr;

			gap: 24px;

			margin-bottom: 20px;

		}

		.formula-field-group {

			display: flex;

			flex-direction: column;

		}

		.formula-field-group label {

			font-weight: 600;

			font-size: 13px;

			color: #1e293b;

			margin-bottom: 8px;

		}

		.formula-field-group input[type="text"],

		.formula-field-group select {

			height: 40px !important;

			padding: 8px 14px !important;

			border: 1px solid #cbd5e1 !important;

			border-radius: 6px !important;

			font-size: 13px !important;

			width: 100% !important;

			box-sizing: border-box !important;

		}

		.formula-field-group .formula-holiday-country-select {

			display: block !important;

			margin: 0 !important;

			padding: 0 36px 0 14px !important;

			line-height: 38px !important;

		}



		/* Priority Badges & Action Buttons */

		.rule-priority-badge {

			background: #eff6ff;

			color: #1d4ed8;

			font-weight: 700;

			font-size: 12px;

			padding: 4px 10px;

			border-radius: 12px;

			border: 1px solid #bfdbfe;

			display: inline-block;

			margin-bottom: 8px;

		}

		.formula-rule-switch {

			position: relative;

			display: inline-block;

			width: 34px;

			height: 20px;

		}

		.formula-rule-switch input {

			opacity: 0;

			width: 0;

			height: 0;

			position: absolute;

		}

		.formula-rule-switch-slider {

			position: absolute;

			cursor: pointer;

			top: 0;

			left: 0;

			right: 0;

			bottom: 0;

			background-color: #cbd5e1;

			transition: .2s ease;

			border-radius: 999px;

		}

		.formula-rule-switch-slider:before {

			position: absolute;

			content: '';

			height: 14px;

			width: 14px;

			left: 3px;

			bottom: 3px;

			background-color: #ffffff;

			transition: .2s ease;

			border-radius: 50%;

			box-shadow: 0 1px 2px rgba(15, 23, 42, 0.2);

		}

		.formula-rule-switch input:checked + .formula-rule-switch-slider {

			background-color: #16a34a;

		}

		.formula-rule-switch input:checked + .formula-rule-switch-slider:before {

			transform: translateX(14px);

		}

		.formula-rule-disabled td {

			opacity: 0.58;

		}

		.formula-rule-disabled .rule-priority-badge {

			background: #f8fafc;

			color: #64748b;

			border-color: #cbd5e1;

		}

		.formula-rule-action-buttons {

			display: inline-flex;

			gap: 6px;

		}

		.formula-shipping-move-up-rule,

		.formula-shipping-move-down-rule {

			height: 32px !important;

			width: 32px !important;

			padding: 0 !important;

			line-height: 30px !important;

			font-size: 13px !important;

			border-color: #cbd5e1 !important;

			color: #475569 !important;

			background: #ffffff !important;

			border-radius: 6px !important;

			cursor: pointer;

		}

		.formula-shipping-move-up-rule:hover,

		.formula-shipping-move-down-rule:hover {

			background: #f1f5f9 !important;

			color: #0f172a !important;

			border-color: #94a3b8 !important;

		}

		.formula-shipping-delete-rule {

			height: 32px !important;

			width: 32px !important;

			padding: 0 !important;

			line-height: 30px !important;

			border-color: #ef4444 !important;

			color: #ef4444 !important;

			background: #ffffff !important;

			border-radius: 6px !important;

			cursor: pointer;

		}

		.formula-shipping-delete-rule:hover {

			background: #ef4444 !important;

			color: #ffffff !important;

		}

		.formula-shipping-duplicate-rule {

			height: 32px !important;

			width: 32px !important;

			padding: 0 !important;

			line-height: 30px !important;

			border-color: #0284c7 !important;

			color: #0284c7 !important;

			background: #ffffff !important;

			border-radius: 6px !important;

			cursor: pointer;

		}

		.formula-shipping-duplicate-rule:hover {

			background: #0284c7 !important;

			color: #ffffff !important;

		}



		/* Item and Location Rules Table Banding Styles */

		.formula-rule-row-main.formula-rule-bg-white td,

		.formula-rule-row-formulas.formula-rule-bg-white td,

		.formula-location-row-main.formula-rule-bg-white td,

		.formula-location-row-formulas.formula-rule-bg-white td {

			background-color: #ffffff !important;

		}

		.formula-rule-row-main.formula-rule-bg-gray td,

		.formula-rule-row-formulas.formula-rule-bg-gray td,

		.formula-location-row-main.formula-rule-bg-gray td,

		.formula-location-row-formulas.formula-rule-bg-gray td {

			background-color: #f8fafc !important;

		}



		/* Test Cart Table Columns & Selects Layout */

		.formula-sim-cart-table th,

		.formula-sim-cart-table td {

			vertical-align: middle !important;

			padding: 8px 10px !important;

		}

		.formula-sim-cart-table select.formula-sim-item-category,

		.formula-sim-cart-table select.formula-sim-item-tag {

			min-width: 160px !important;

			max-width: 220px !important;

			font-size: 12px !important;

		}

		.formula-sim-cart-table input.formula-sim-item-variation {

			min-width: 90px !important;

			font-size: 12px !important;

			border: 1px solid #94a3b8 !important;

			border-radius: 6px !important;

			background: #ffffff !important;

		}

		.formula-sim-cart-table .formula-sim-item-row-sub label {

			text-align: left !important;

		}



		.formula-shipping-help-box {

			background-color: #ffffff;

			border: 1px solid #e2e8f0;

			border-left: 4px solid #0284c7;

			padding: 16px;

			margin-top: 12px;

			border-radius: 6px;

			box-shadow: 0 1px 3px rgba(0,0,0,0.03);

		}



		.formula-preview-columns {

			display: flex;

			gap: 24px;

			flex-wrap: wrap;

		}

		.formula-preview-col {

			flex: 1;

			min-width: 320px;

		}

		.formula-preview-section {

			background: #ffffff;

			border: 1px solid #e2e8f0;

			border-radius: 8px;

			padding: 20px;

			margin-bottom: 20px;

		}

		.formula-preview-section h4 {

			margin: 0 0 14px 0;

			font-size: 15px;

			color: #1e293b;

			font-weight: 700;

			border-bottom: 1px solid #f1f5f9;

			padding-bottom: 10px;

		}

		.section-header-flex {

			display: flex;

			justify-content: space-between;

			align-items: center;

			border-bottom: 1px solid #f1f5f9;

			padding-bottom: 10px;

			margin-bottom: 16px;

			flex-wrap: wrap;

			gap: 12px;

		}

		.action-buttons-wrapper {

			display: flex;

			gap: 8px;

			flex-wrap: wrap;

		}

		.action-buttons-wrapper button {

			padding: 6px 12px !important;

			height: 34px !important;

			line-height: 20px !important;

			font-size: 12px !important;

			font-weight: 600 !important;

		}

		.formula-preview-form-group {

			margin-bottom: 14px;

		}

		.formula-preview-form-group label {

			display: block;

			font-weight: 600;

			font-size: 12px;

			color: #475569;

			margin-bottom: 6px;

		}

		.formula-preview-form-group select,

		.formula-preview-form-group input[type="text"] {

			width: 100%;

			height: 36px;

			padding: 6px 10px;

			border: 1px solid #cbd5e1;

			border-radius: 6px;

			font-size: 13px;

			box-sizing: border-box;

		}

		.formula-preview-form-group-row {

			display: flex;

			gap: 14px;

		}

		.formula-preview-form-group-row .formula-preview-form-group {

			flex: 1;

			margin-bottom: 0;

		}



		.formula-sim-cart-table {

			width: 100%;

			border-collapse: collapse;

			font-size: 13px;

		}

		.formula-sim-cart-table th {

			background: #f8fafc !important;

			font-weight: 600 !important;

			color: #475569 !important;

			padding: 10px 10px !important;

			border-bottom: 2px solid #e2e8f0 !important;

		}

		.formula-sim-cart-table td {

			padding: 10px 8px !important;

			vertical-align: middle !important;

			border-bottom: 1px solid #f1f5f9 !important;

		}

		.formula-sim-cart-table input[type="text"],

		.formula-sim-cart-table input[type="number"],

		.formula-sim-cart-table select {

			width: 100%;

			height: 34px;

			padding: 4px 8px;

			border: 1px solid #cbd5e1;

			border-radius: 6px;

			font-size: 12px;

			box-sizing: border-box;

		}



		.formula-sim-results-grid {

			display: grid;

			grid-template-columns: 1fr 1fr;

			gap: 14px;

			margin-bottom: 15px;

		}

		.formula-sim-result-box {

			background: #f8fafc;

			border: 1px solid #e2e8f0;

			border-radius: 8px;

			padding: 14px;

			display: flex;

			flex-direction: column;

			align-items: center;

			justify-content: center;

			text-align: center;

		}

		.formula-sim-result-box .label {

			font-size: 12px;

			color: #64748b;

			margin-bottom: 4px;

			font-weight: 500;

		}

		.formula-sim-result-box .value {

			font-size: 16px;

			font-weight: 700;

			color: #334155;

		}

		.formula-sim-result-box.highlight-total {

			background: #f0fdf4;

			border-color: #bbf7d0;

		}

		.formula-sim-result-box.highlight-total .label {

			color: #166534;

		}

		.formula-sim-result-box.highlight-total .value {

			font-size: 22px;

			color: #15803d;

		}



		/* Rule Formulas Grid (2 columns side-by-side) */

		.formula-rule-formulas-grid {

			display: grid;

			grid-template-columns: 1fr 1fr;

			gap: 16px;

		}

		.formula-rule-formula-field {

			display: flex;

			flex-direction: column;

		}

		.formula-rule-formula-field label {

			font-weight: 600;

			font-size: 12px;

			color: #475569;

			margin-bottom: 6px;

		}

		.formula-rule-formula-field input[type="text"] {

			width: 100%;

			box-sizing: border-box;

			padding: 8px 12px;

			border: 1px solid #cbd5e1;

			border-radius: 6px;

			height: 36px;

			font-size: 13px;

			color: #334155;

		}



		.formula-shipping-rules-container {

			max-width: 100%;

			box-sizing: border-box;

		}

		.formula-shipping-rules-table {

			width: 100%;

			border-collapse: collapse;

			background: #ffffff;

			border: 1px solid #cbd5e1;

			border-radius: 8px;

			overflow: hidden;

		}

		.formula-shipping-rules-table th {

			background-color: #f8fafc;

			font-weight: 600;

			color: #1e293b;

			text-align: left;

			padding: 12px 14px;

			border-bottom: 2px solid #e2e8f0;

			font-size: 13px;

		}

		.formula-shipping-rules-table td {

			padding: 10px 14px !important;

			border-bottom: 1px solid #e2e8f0 !important;

			vertical-align: middle !important;

		}

		.formula-shipping-rules-table select,

		.formula-shipping-rules-table input[type="text"]:not(.formula-chip-editor) {

			width: 100% !important;

			box-sizing: border-box !important;

			padding: 0 12px !important;

			border: 1px solid #94a3b8 !important; /* Higher contrast border */

			border-radius: 6px !important;

			height: 36px !important;

			line-height: 34px !important;

			font-size: 13px !important;

			color: #334155 !important;

			margin: 0 !important;

			vertical-align: middle !important;

		}

		/* ENFORCE NO INNER BORDER ON CHIP EDITORS IN RULES TABLE */
		.formula-shipping-rules-table .formula-chip-field input,
		.formula-shipping-rules-table .formula-chip-field input[type="text"],
		.formula-shipping-rules-table input.formula-chip-editor {
			border: 0 !important;
			border-style: none !important;
			box-shadow: none !important;
			outline: none !important;
			background: transparent !important;
		}

	</style>

	<script type="text/javascript">

		jQuery(document).ready(function($) {

			var simulatorInitAttempts = 0;



			function initializeFormulaSimulator() {

				var simulatorScript = $('.wc-backbone-modal-content script').filter(function() {

					return $(this).text().indexOf('formula_sim_add_item_btn') !== -1;

				}).first();



				if (simulatorScript.length && !simulatorScript.data('formula-simulator-executed')) {

					simulatorScript.data('formula-simulator-executed', true);

					$.globalEval(simulatorScript.text());

					simulatorInitAttempts = 0;

				} else if (!simulatorScript.length && simulatorInitAttempts < 20 && $('.wc-backbone-modal-content').length) {

					simulatorInitAttempts++;

					setTimeout(initializeFormulaSimulator, 100);

				}

			}



			function toggleSettingsVisibility() {
				var handlingCombine = $('[name*="handling_combine"]').is(':checked');
				var handlingTitleGroup = $('#formula_handling_title_group');
				var handlingFormulaGroup = $('#formula_default_handling_formula').closest('.formula-field-group');
				var row1Grid = $('#formula_row1_grid');
				var defaultGrid = $('#formula_default_formulas_grid');

				if (handlingCombine) {
					handlingTitleGroup.hide();
					handlingFormulaGroup.hide();
					if (row1Grid.length) row1Grid.css('grid-template-columns', '1fr');
					if (defaultGrid.length) defaultGrid.css('grid-template-columns', '1fr');
				} else {
					handlingTitleGroup.show();
					handlingFormulaGroup.show();
					if (row1Grid.length) row1Grid.css('grid-template-columns', '1fr 1fr');
					if (defaultGrid.length) defaultGrid.css('grid-template-columns', '1fr 1fr');
				}

				var holidayProvider = $('#formula_holiday_provider').val();
				var customIcalWrap = $('#formula_custom_ical_wrap');
				if (holidayProvider === 'custom_ical') {
					customIcalWrap.slideDown(200);
				} else {
					customIcalWrap.hide();
				}
			}

			$(document.body).on('change', '#formula_holiday_provider', toggleSettingsVisibility);



			
			function updateLocStateVisibility(mainRow) {
				if (!mainRow || !mainRow.length) return;

				var countryInput = mainRow.find('.formula-loc-country-input');
				if (!countryInput.length) return;

				var countryVal = String(countryInput.val() || '').trim();
				var stateInput = mainRow.find('.formula-loc-state-input');
				var grid       = mainRow.find('.formula-mini-target-grid-location');
				var isSingleCountry = checkIsSingleCountryVal(countryVal);

				if (isSingleCountry) {
					stateInput.prop('disabled', false).attr('placeholder', 'e.g. CA, ON, NY or ALL');
					grid.attr('data-has-state', 'true');
				} else {
					stateInput.prop('disabled', true).attr('placeholder', 'To Set State or Province Only One Country Must Be Selected');
					grid.attr('data-has-state', 'false');
				}
			}

			$(document.body).on('input change blur keyup', '.formula-loc-country-input, .formula-chip-editor', function() {
				var input = $(this);
				var mainRow = input.closest('tr.formula-location-row-main');
				if (!mainRow.length && input.hasClass('formula-chip-editor')) {
					var sourceInput = getFormulaChipSourceInput(input);
					mainRow = sourceInput.closest('tr.formula-location-row-main');
				}
				if (mainRow.length) {
					updateLocStateVisibility(mainRow);
				}
			});

			$(function() {
				$('tr.formula-location-row-main').each(function() {
					updateLocStateVisibility($(this));
				});
			});

			$(document).on('formula:row:added', function() {
				$('tr.formula-location-row-main').each(function() {
					updateLocStateVisibility($(this));
				});
			});

			function updateDynamicSummaries() {

				// 1. General Settings Card

				var autoInjectChecked = $('[name*="auto_inject"]').is(':checked');

				var autoInjectText = autoInjectChecked ? 'Auto-Inject: Enabled' : 'Auto-Inject: Disabled';



				var roundRatesChecked = $('[name*="round_rates"]').is(':checked');

				var roundRatesText = roundRatesChecked ? 'Rounding: Enabled' : 'Rounding: Disabled';



				var minShipping = $('[name*="usa_min_shipping"]').val() || '';

				var maxShipping = $('[name*="usa_max_shipping"]').val() || '';

				var minMaxText = 'Min/Max Charges: ';

				if (minShipping || maxShipping) {

					minMaxText += (minShipping ? '$' + minShipping : '$0') + ' / ' + (maxShipping ? '$' + maxShipping : 'None');

				} else {

					minMaxText += 'None';

				}



				var generalSummaryText = '<span>' + autoInjectText + '</span><span>' + roundRatesText + '</span><span>' + minMaxText + '</span>';

				$('#formula_card_summary_general .card-summary-text').html(generalSummaryText);



				// 2. Location Rules Card

				var locRows = $('#formula_shipping_rules_table_usa_county_rules tbody tr.formula-location-row-main');

				var totalLocRules = locRows.length;

				var enabledLocIndices = [];

				locRows.each(function(index) {

					var isEnabled = $(this).find('.formula-rule-enabled-toggle').is(':checked');

					if (isEnabled) {

						enabledLocIndices.push(index + 1);

					}

				});



				var locEnabledText = '';

				if (totalLocRules === 0) {

					locEnabledText = 'No rules configured';

				} else if (enabledLocIndices.length === 0) {

					locEnabledText = 'Rules: 0 of ' + totalLocRules + ' enabled';

				} else if (enabledLocIndices.length === totalLocRules) {

					locEnabledText = 'Rules: ' + (totalLocRules === 1 ? '#1' : '1-' + totalLocRules) + ' enabled';

				} else {

					locEnabledText = 'Rules: #' + enabledLocIndices.join(',') + ' enabled';

				}



				var locResolution = $('#formula_location_resolution_controls select').val() || 'first_match';

				var locResolutionLabel = 'Logic: ';

				if (locResolution === 'merge_difference') {

					locResolutionLabel += 'Folded Incremental';

				} else if (locResolution === 'first_match') {

					locResolutionLabel += 'First Match Override';

				} else if (locResolution === 'sum') {

					locResolutionLabel += 'Sum Matches';

				} else if (locResolution === 'max') {

					locResolutionLabel += 'Highest Cost';

				}



				var locationSummaryText = '<span>' + locEnabledText + '</span><span>' + locResolutionLabel + '</span>';

				$('#formula_card_summary_location .card-summary-text').html(locationSummaryText);



				// 3. Item Rules Card

				var itemRows = $('#formula_shipping_rules_table_usa_item_rules tbody tr.formula-rule-row-main');

				var totalItemRules = itemRows.length;

				var enabledItemIndices = [];

				itemRows.each(function(index) {

					var isEnabled = $(this).find('.formula-rule-enabled-toggle').is(':checked');

					if (isEnabled) {

						enabledItemIndices.push(index + 1);

					}

				});



				var itemEnabledText = '';

				if (totalItemRules === 0) {

					itemEnabledText = 'No item rules configured';

				} else if (enabledItemIndices.length === 0) {

					itemEnabledText = 'Rules: 0 of ' + totalItemRules + ' enabled';

				} else if (enabledItemIndices.length === totalItemRules) {

					itemEnabledText = 'Rules: ' + (totalItemRules === 1 ? '#1' : '1-' + totalItemRules) + ' enabled';

				} else {

					itemEnabledText = 'Rules: #' + enabledItemIndices.join(',') + ' enabled';

				}



				var itemResolution = $('[id*="usa_rule_resolution"]').val() || 'first_match';

				var itemResolutionLabel = 'Logic: ';

				if (itemResolution === 'merge_difference') {

					itemResolutionLabel += 'Folded Incremental';

				} else if (itemResolution === 'first_match') {

					itemResolutionLabel += 'Priority Order';

				} else if (itemResolution === 'sum') {

					itemResolutionLabel += 'Sum Matches';

				} else if (itemResolution === 'max') {

					itemResolutionLabel += 'Highest Cost';

				}



				var itemSummaryText = '<span>' + itemEnabledText + '</span><span>' + itemResolutionLabel + '</span>';

				$('#formula_card_summary_item .card-summary-text').html(itemSummaryText);

			}



			$(document.body).on('change', '[name*="handling_combine"]', function() {

				toggleSettingsVisibility();

			});



			$(document.body).on('wc_backbone_modal_loaded', function() {

				setTimeout(toggleSettingsVisibility, 100);

				setTimeout(ensureFormulaChipEditors, 100);

				setTimeout(initializeFormulaSimulator, 100);

				setTimeout(updateDynamicSummaries, 100);

				$(document).off('click', '.formula-help-toggle-btn').on('click', '.formula-help-toggle-btn', function(e) {

					e.preventDefault();

					$(this).siblings('.formula-shipping-help-box').slideToggle(200);

				});

			});



			$(document).ajaxComplete(function(event, xhr, settings) {

				var requestData = settings && settings.data ? String(settings.data) : '';

				if (/action=formula_shipping_(validate_formula|search_targets)/.test(requestData)) {

					return;

				}

				setTimeout(toggleSettingsVisibility, 100);

				setTimeout(ensureFormulaChipEditors, 100);

				setTimeout(initializeFormulaSimulator, 100);

				setTimeout(updateDynamicSummaries, 100);

			});



			$(document).off('click', '.card-summary-bar').on('click', '.card-summary-bar', function(e) {

				if ($(e.target).closest('a, button').length) {

					return;

				}

				var card = $(this).closest('.formula-section-card');

				var body = card.find('.card-body');

				body.slideToggle(200, function() {

					if (body.is(':visible')) {

						card.removeClass('is-collapsed');

					} else {

						card.addClass('is-collapsed');

					}

				});

			});



			$(document.body).on('change input keyup click', '.formula-section-card input, .formula-section-card select, .formula-section-card button', function() {

				setTimeout(updateDynamicSummaries, 50);

			});



			if (window.MutationObserver) {

				new MutationObserver(function() {

					if ($('.wc-backbone-modal-content').length) {

						ensureFormulaChipEditors();

						initializeFormulaSimulator();

					}

				}).observe(document.body, { childList: true, subtree: true });

			}



			function parseFormulaTokenList(rawValue) {

				return String(rawValue || '').split(',').map(function(part) {

					return String(part || '').trim();

				}).filter(Boolean);

			}



			function getFormulaChipSourceInput(elem) {

				var input = $(elem);

				if (input.hasClass('formula-chip-editor')) {

					return input.closest('.formula-chip-field').prev('input.formula-chip-source-input');

				}

				if (input.hasClass('formula-chip-source-input')) {

					return input;

				}

				return $();

			}



			function updateFormulaChipField(sourceInput) {

				sourceInput = $(sourceInput);

				var chipField = sourceInput.next('.formula-chip-field');

				if (!chipField.length) {

					return;

				}

				var chipList = chipField.find('.formula-chip-list');

				var editor = chipField.find('.formula-chip-editor');

				var tokens = parseFormulaTokenList(sourceInput.val());

				chipList.empty();

				$.each(tokens, function(_, token) {

					var chip = $('<span class="formula-chip-token"></span>');

					chip.append($('<span></span>').text(token));

					chip.append($('<button type="button" class="formula-chip-remove" aria-label="Remove token">&times;</button>').attr('data-token', token));

					chipList.append(chip);

				});

				editor.attr('placeholder', tokens.length ? '' : (sourceInput.attr('placeholder') || 'Type to add...'));

			}



			function ensureFormulaChipEditor(sourceInput) {

				sourceInput = $(sourceInput);

				if (!sourceInput.length || !sourceInput.hasClass('formula-multi-suggest-input')) {

					return;

				}

				if (sourceInput.hasClass('formula-chip-source-input')) {

					return;

				}



				sourceInput.addClass('formula-chip-source-input');

				var editorValue = sourceInput.hasClass('formula-loc-country-input') ? '' : '';

				var chipField = $(

					'<div class="formula-chip-field">' +

						'<div class="formula-chip-list"></div>' +

						'<div class="formula-autocomplete-wrapper formula-chip-editor-wrap">' +

							'<input type="text" class="formula-chip-editor" value="' + editorValue + '" autocomplete="off" />' +

						'</div>' +

					'</div>'

				);

				sourceInput.after(chipField);

				chipField.find('.formula-chip-editor').attr('title', sourceInput.attr('title') || '');

				chipField.attr('title', sourceInput.attr('title') || '');

				updateFormulaChipField(sourceInput);

			}



			function ensureFormulaChipEditors() {

				$('.formula-multi-suggest-input:not(.formula-chip-source-input)').each(function() {

					ensureFormulaChipEditor($(this));

				});

			}



			function setFormulaChipTokens(sourceInput, tokens) {

				sourceInput = $(sourceInput);

				var deduped = [];

				$.each(tokens, function(_, token) {

					var cleanToken = String(token || '').trim();

					if (!cleanToken) {

						return;

					}

					var lowerToken = cleanToken.toLowerCase();

					var alreadyAdded = deduped.some(function(existing) {

						return existing.toLowerCase() === lowerToken;

					});

					if (!alreadyAdded) {

						deduped.push(cleanToken);

					}

				});

				sourceInput.val(deduped.join(', ')).trigger('change');

				updateFormulaChipField(sourceInput);

			}



			function commitFormulaChipEditorValue(editor) {

				editor = $(editor);

				if (!editor.hasClass('formula-chip-editor')) {

					return;

				}

				var rawValue = String(editor.val() || '').trim().replace(/,$/, '');

				if (!rawValue) {

					return;

				}

				var sourceInput = getFormulaChipSourceInput(editor);

				if (!sourceInput.length) {

					return;

				}

				var existingTokens = parseFormulaTokenList(sourceInput.val());

				existingTokens.push(rawValue);

				setFormulaChipTokens(sourceInput, existingTokens);

				editor.val('');

			}



			function getFormulaValidationFields() {

				return $('[name*="usa_default_shipping_formula"], [name*="usa_default_handling_formula"], input[name*="[shipping_formula]"], input[name*="[handling_formula]"]');

			}



			function ensureFormulaErrorBox(input) {

				input = $(input);

				var errorBox = input.siblings('.formula-formula-error');

				if (!errorBox.length) {

					errorBox = $('<div class="formula-formula-error" aria-live="polite"></div>');

					input.after(errorBox);

				}

				return errorBox;

			}



			function locateFormulaIssueIndex(formula, errorMessage) {

				var parenStack = [];

				var bracketStack = [];

				var quoteChar = '';

				var quoteStart = -1;

				for (var i = 0; i < formula.length; i++) {

					var ch = formula.charAt(i);

					if (quoteChar) {

						if (ch === quoteChar && formula.charAt(i - 1) !== '\\') {

							quoteChar = '';

						}

						continue;

					}

					if (ch === '"' || ch === '\'') {

						quoteChar = ch;

						quoteStart = i;

						continue;

					}

					if (ch === '(') {

						parenStack.push(i);

					} else if (ch === ')') {

						if (!parenStack.length) {

							return i;

						}

						parenStack.pop();

					} else if (ch === '[') {

						bracketStack.push(i);

					} else if (ch === ']') {

						if (!bracketStack.length) {

							return i;

						}

						bracketStack.pop();

					}

				}

				if (quoteChar && quoteStart >= 0) {

					return quoteStart;

				}

				if (/extra opening parenthesis/i.test(errorMessage || '') && parenStack.length) {

					return parenStack[parenStack.length - 1];

				}

				if (/extra closing parenthesis/i.test(errorMessage || '')) {

					return formula.lastIndexOf(')');

				}

				if (bracketStack.length) {

					return bracketStack[bracketStack.length - 1];

				}

				var invalidMatch = formula.match(/[^\w\s\[\]\(\)\+\-\*\/\%\^\.<>=!,'"\?:]/);

				if (invalidMatch && invalidMatch.index !== undefined) {

					return invalidMatch.index;

				}

				return -1;

			}



			function formatFormulaErrorMessage(formula, errorMessage) {

				var issueIndex = locateFormulaIssueIndex(formula, errorMessage);

				if (issueIndex < 0) {

					return errorMessage;

				}

				var snippetStart = Math.max(0, issueIndex - 12);

				var snippetEnd = Math.min(formula.length, issueIndex + 13);

				var snippet = formula.slice(snippetStart, snippetEnd);

				return errorMessage + ' Near character ' + (issueIndex + 1) + ': "' + snippet + '"';

			}



			function setFormulaValidationState(input, state, message) {

				input = $(input);

				var errorBox = ensureFormulaErrorBox(input);

				input.removeClass('formula-input-invalid');

				errorBox.removeClass('is-visible is-pending').text('');

				var chipField = input.hasClass('formula-chip-source-input') ? input.next('.formula-chip-field') : $();

				chipField.removeClass('formula-has-error');

				if (state === 'pending') {

					errorBox.addClass('is-pending').text(message || 'Validating formula...');

					return;

				}

				if (state === 'error') {

					input.addClass('formula-input-invalid');

					errorBox.addClass('is-visible').text(message || 'Invalid formula.');

					chipField.addClass('formula-has-error');

				}

			}



			function validateFormulaInput(input) {

				input = $(input);

				var formula = String(input.val() || '').trim();

				if (!formula) {

					setFormulaValidationState(input, 'clear');

					return;

				}

				setFormulaValidationState(input, 'pending', 'Validating formula...');

				var requestId = (input.data('formula-validate-request') || 0) + 1;

				input.data('formula-validate-request', requestId);

				$.ajax({

					url: window.ajaxurl || '/wp-admin/admin-ajax.php',

					type: 'POST',

					data: {

						action: 'formula_shipping_validate_formula',

						nonce: window.formulaImportNonce || '',

						formula: formula

					}

				}).done(function(response) {

					if (input.data('formula-validate-request') !== requestId) {

						return;

					}

					if (response && response.success && response.data && response.data.valid) {

						setFormulaValidationState(input, 'clear');

					} else {

						var responseMessage = response && response.data && response.data.message ? response.data.message : 'Invalid formula.';

						setFormulaValidationState(input, 'error', formatFormulaErrorMessage(formula, responseMessage));

					}

				}).fail(function() {

					if (input.data('formula-validate-request') !== requestId) {

						return;

					}

					setFormulaValidationState(input, 'error', 'Unable to validate the formula right now.');

				});

			}



			function queueFormulaValidation(input) {

				input = $(input);

				var existingTimer = input.data('formula-validate-timer');

				if (existingTimer) {

					clearTimeout(existingTimer);

				}

				var timer = setTimeout(function() {

					validateFormulaInput(input);

				}, 250);

				input.data('formula-validate-timer', timer);

			}



			window.formulaEnsureChipEditors = ensureFormulaChipEditors;



			// ==========================================

			// SECTION 2 AUTOCOMPLETE & SUGGESTIONS LOGIC

			// ==========================================

			var searchTimer = null;



			function closeFormulaSuggestions() {

				$('.formula-autocomplete-suggestions').remove();

			}



			function getFormulaSuggestionSearchTerm(inputElem) {

				if (inputElem.hasClass('formula-chip-editor')) {

					return String(inputElem.val() || '').trim();

				}

				var rawValue = inputElem.val() || '';

				if (!inputElem.hasClass('formula-multi-suggest-input') && !inputElem.hasClass('formula-chip-source-input')) {

					return rawValue.trim();

				}

				var parts = rawValue.split(',');

				return String(parts[parts.length - 1] || '').trim();

			}



			function insertFormulaSuggestionValue(inputElem, value) {

				if (inputElem.hasClass('formula-chip-editor')) {

					var sourceInput = getFormulaChipSourceInput(inputElem);

					if (!sourceInput.length) {

						return;

					}

					var tokens = parseFormulaTokenList(sourceInput.val());

					tokens.push(String(value || '').trim());

					setFormulaChipTokens(sourceInput, tokens);

					inputElem.val('');

					return;

				}

				if (!inputElem.hasClass('formula-multi-suggest-input') && !inputElem.hasClass('formula-chip-source-input')) {

					inputElem.val(value).trigger('change');

					return;

				}



				var parts = (inputElem.val() || '').split(',');

				var normalizedValue = String(value || '').trim();

				if (!parts.length) {

					parts = [normalizedValue];

				} else {

					parts[parts.length - 1] = normalizedValue;

				}



				var deduped = [];

				$.each(parts, function(_, part) {

					var cleanPart = String(part || '').trim();

					if (!cleanPart) {

						return;

					}

					var lowerPart = cleanPart.toLowerCase();

					var alreadyAdded = deduped.some(function(existing) {

						return existing.toLowerCase() === lowerPart;

					});

					if (!alreadyAdded) {

						deduped.push(cleanPart);

					}

				});



				inputElem.val(deduped.join(', ')).trigger('change');

			}



			function getSingleCountryCodeForRow(mainRow) {

				var countryInput = mainRow.find('.formula-loc-country-input');

				if (!countryInput.length) {

					return '';

				}

				var parts = (countryInput.val() || '').split(',').map(function(part) {

					return String(part || '').trim();

				}).filter(Boolean);

				if (parts.length !== 1) {

					return '';

				}

				var target = parts[0].toLowerCase();

				var countries = window.formulaCountrySuggestions || [];

				for (var i = 0; i < countries.length; i++) {

					var country = countries[i] || {};

					if (String(country.value || '').toLowerCase() === target) {

						return String(country.value || '').toUpperCase();

					}

					var label = String(country.label || '').toLowerCase();

					if (label === target || label.indexOf(target + ' (') === 0) {

						return String(country.value || '').toUpperCase();

					}

				}

				return target === 'all' ? 'ALL' : '';

			}



			function renderFormulaSuggestions(inputElem, items, badgeTitle) {

				closeFormulaSuggestions();

				if (!items || !items.length) {

					return;

				}



				var wrapper = inputElem.parent();

				if (!wrapper.hasClass('formula-autocomplete-wrapper')) {

					inputElem.wrap('<div class="formula-autocomplete-wrapper"></div>');

					wrapper = inputElem.parent();

				}



				var listHtml = '<ul class="formula-autocomplete-suggestions">';

				$.each(items, function(i, item) {

					var val = item.value || item;

					var label = item.label || item;

					listHtml += '<li class="formula-autocomplete-item" data-value="' + $('<div>').text(val).html() + '">' +

						'<span>' + $('<div>').text(label).html() + '</span>' +

						(badgeTitle ? '<span class="item-badge">' + badgeTitle + '</span>' : '') +

						'</li>';

				});

				listHtml += '</ul>';



				var suggestionElem = $(listHtml);

				wrapper.append(suggestionElem);



				suggestionElem.find('.formula-autocomplete-item').first().addClass('is-selected');

			}



			$(document.body).on('input focus', '.formula-loc-user-input, .formula-loc-device-input, .formula-loc-os-input, input[name*="[user_meta_key]"], .formula-loc-country-input, .formula-loc-state-input, .formula-sim-country-input, .formula-sim-state-input, .formula-chip-editor', function() {

				var input = $(this);

				var sourceInput = input.hasClass('formula-chip-editor') ? getFormulaChipSourceInput(input) : input;

				var mainRow = sourceInput.closest('tr.formula-location-row-main');

				if (!mainRow.length && !input.hasClass('formula-sim-country-input') && !input.hasClass('formula-sim-state-input')) {

					return;

				}



				var isCountry = sourceInput.hasClass('formula-loc-country-input') || input.hasClass('formula-sim-country-input');

				var isState = sourceInput.hasClass('formula-loc-state-input') || input.hasClass('formula-sim-state-input');

				var isMetaKey = sourceInput.attr('name') && sourceInput.attr('name').indexOf('[user_meta_key]') !== -1;

				var targetType = isMetaKey ? 'user_meta_key' : (mainRow.length ? (mainRow.find('.formula-loc-rule-type-input').val() || mainRow.find('.formula-loc-type-select').val() || 'user_role') : '');

				if (sourceInput.hasClass('formula-loc-device-input')) {

					targetType = 'device_target';

				} else if (sourceInput.hasClass('formula-loc-os-input')) {

					targetType = 'os_target';

				}

				var val = getFormulaSuggestionSearchTerm(input).toLowerCase();



				clearTimeout(searchTimer);



				if (isCountry) {

					var countries = window.formulaCountrySuggestions || [];

					// Prioritize exact and prefix matches over substring matches to avoid "US" matching "Australia (AU)".

					var filteredCountries = countries.filter(function(country) {

						if (!val) return true;

						var valueLower = country.value.toLowerCase();

						var labelLower = country.label.toLowerCase();

						return valueLower === val

							|| labelLower === val

							|| labelLower.indexOf(val + ' (') === 0

							|| valueLower.indexOf(val) === 0

							|| labelLower.indexOf(val) === 0

							|| labelLower.indexOf(val) !== -1;

					}).sort(function(a, b) {

						var aVal = a.value.toLowerCase();

						var bVal = b.value.toLowerCase();

						var aLabel = a.label.toLowerCase();

						var bLabel = b.label.toLowerCase();

						var aScore = (aVal === val ? 0 : (aVal.indexOf(val) === 0 ? 1 : (aLabel.indexOf(val) === 0 ? 2 : 3)));

						var bScore = (bVal === val ? 0 : (bVal.indexOf(val) === 0 ? 1 : (bLabel.indexOf(val) === 0 ? 2 : 3)));

						return aScore - bScore;

					});

						renderFormulaSuggestions(input, filteredCountries.slice(0, 15), 'Country');

				} else if (isState) {

					var countryCode = mainRow.length ? getSingleCountryCodeForRow(mainRow) : (normalizeCountryReference($('#formula_sim_country').val() || 'US'));

					var stateSuggestionsMap = window.formulaCountryStateSuggestions || {};

					var availableStates = countryCode && stateSuggestionsMap[countryCode] ? stateSuggestionsMap[countryCode] : [{ value: 'ALL', label: 'All States / Provinces (ALL)' }];

					var filteredStates = availableStates.filter(function(stateSuggestion) {

						return !val || stateSuggestion.value.toLowerCase().indexOf(val) !== -1 || stateSuggestion.label.toLowerCase().indexOf(val) !== -1;

					});

					renderFormulaSuggestions(input, filteredStates.slice(0, 20), countryCode && stateSuggestionsMap[countryCode] ? 'State' : 'State selector only available if one country is selected.');

				} else if (targetType === 'user_role') {

					var roles = window.formulaWpRoles || [

						{ value: 'administrator', label: 'Administrator (administrator)' },

						{ value: 'subscriber', label: 'Subscriber (subscriber)' },

						{ value: 'customer', label: 'Customer (customer)' },

						{ value: 'shop_manager', label: 'Shop Manager (shop_manager)' }

					];

					var filtered = roles.filter(function(r) {

						return !val || r.value.toLowerCase().indexOf(val) !== -1 || r.label.toLowerCase().indexOf(val) !== -1;

					});

					renderFormulaSuggestions(input, filtered.slice(0, 15), 'Role');

				} else if (targetType === 'browser' || targetType === 'device_target' || targetType === 'os_target') {

					var envSuggestions = targetType === 'browser' ? (window.formulaBrowserSuggestions || []) : (targetType === 'device_target' ? (window.formulaDeviceSuggestions || []) : (window.formulaOsSuggestions || []));

					var filteredEnvSuggestions = envSuggestions.filter(function(envSuggestion) {

						return !val || envSuggestion.value.toLowerCase().indexOf(val) !== -1 || envSuggestion.label.toLowerCase().indexOf(val) !== -1;

					});

					renderFormulaSuggestions(input, filteredEnvSuggestions.slice(0, 15), targetType === 'browser' ? 'Browser' : (targetType === 'device_target' ? 'Device' : 'OS'));

				} else {

					closeFormulaSuggestions();

				}

			});



			// Search targets (user login/email/meta key) only on blur or Enter key

			function doSearchTargets(input, targetType) {

				var val = getFormulaSuggestionSearchTerm(input).toLowerCase();

				if (val.length < 1) {

					closeFormulaSuggestions();

					return;

				}

				var ajaxUrl = window.ajaxurl || '/wp-admin/admin-ajax.php';

				$.ajax({

					url: ajaxUrl,

					type: 'POST',

					data: {

						action: 'formula_shipping_search_targets',

						nonce: window.formulaImportNonce || '',

						type: targetType,

						term: val

					},

					success: function(response) {

						if (response && response.success && response.data && response.data.results) {

							var badge = (targetType === 'user_meta_key') ? 'Meta Key' : (targetType === 'user_login' ? 'Username' : 'Email');

							renderFormulaSuggestions(input, response.data.results, badge);

						} else {

							closeFormulaSuggestions();

						}

					}

				});

			}

			$(document.body).on('blur', 'input[name*="[user]"], input[name*="[user_meta_key]"]', function() {

				var input = $(this);

				var sourceInput = input.hasClass('formula-chip-editor') ? getFormulaChipSourceInput(input) : input;

				var mainRow = sourceInput.closest('tr.formula-location-row-main');

				if (!mainRow.length) return;

				var targetType = (sourceInput.attr('name') && sourceInput.attr('name').indexOf('[user_meta_key]') !== -1) ? 'user_meta_key' : (mainRow.find('.formula-loc-rule-type-input').val() || 'user_role');

				if (targetType === 'user_login' || targetType === 'user_email' || targetType === 'user_meta_key') {

					setTimeout(function() { doSearchTargets(input, targetType); }, 150);

				}

			});

			$(document.body).on('keydown', 'input[name*="[user]"], input[name*="[user_meta_key]"], .formula-chip-editor', function(e) {

				if (e.which === 13) {

					var input = $(this);

					var sourceInput = input.hasClass('formula-chip-editor') ? getFormulaChipSourceInput(input) : input;

					var mainRow = sourceInput.closest('tr.formula-location-row-main');

					if (!mainRow.length) return;

					var targetType = (sourceInput.attr('name') && sourceInput.attr('name').indexOf('[user_meta_key]') !== -1) ? 'user_meta_key' : (mainRow.find('.formula-loc-rule-type-input').val() || 'user_role');

					if (targetType === 'user_login' || targetType === 'user_email' || targetType === 'user_meta_key') {

						e.preventDefault();

						doSearchTargets(input, targetType);

					}

				}

			});



			$(document.body).on('click', '.formula-autocomplete-item', function(e) {

				e.preventDefault();

				e.stopPropagation();

				var val = $(this).attr('data-value');

				var wrapper = $(this).closest('.formula-autocomplete-wrapper');

				var input = wrapper.find('input').first();

				insertFormulaSuggestionValue(input, val);

				closeFormulaSuggestions();

			});



			$(document.body).on('keydown', '.formula-loc-user-input, .formula-loc-device-input, .formula-loc-os-input, input[name*="[user_meta_key]"], .formula-loc-country-input, .formula-loc-state-input, .formula-chip-editor', function(e) {

				var suggestions = $(this).parent().find('.formula-autocomplete-suggestions');

				if (!suggestions.length) {

					if ($(this).hasClass('formula-chip-editor')) {

						if ((e.which === 13 || e.which === 188) && $(this).val().trim()) {

							e.preventDefault();

							commitFormulaChipEditorValue($(this));

						} else if (e.which === 8 && !$(this).val().trim()) {

							var sourceInput = getFormulaChipSourceInput($(this));

							var tokens = parseFormulaTokenList(sourceInput.val());

							if (tokens.length) {

								e.preventDefault();

								tokens.pop();

								setFormulaChipTokens(sourceInput, tokens);

							}

						}

					}

					return;

				}



				var items = suggestions.find('.formula-autocomplete-item');

				var selected = items.filter('.is-selected');



				if (e.which === 40) { // Down Arrow

					e.preventDefault();

					if (!selected.length || selected.is(':last-child')) {

						items.removeClass('is-selected').first().addClass('is-selected');

					} else {

						selected.removeClass('is-selected').next().addClass('is-selected');

					}

				} else if (e.which === 38) { // Up Arrow

					e.preventDefault();

					if (!selected.length || selected.is(':first-child')) {

						items.removeClass('is-selected').last().addClass('is-selected');

					} else {

						selected.removeClass('is-selected').prev().addClass('is-selected');

					}

				} else if (e.which === 13 || e.which === 9) { // Enter or Tab

					if (selected.length) {

						e.preventDefault();

						selected.trigger('click');

					}

				} else if (e.which === 27) { // Esc

					closeFormulaSuggestions();

				}

			});



			$(document).on('click', function(e) {

				if (!$(e.target).closest('.formula-autocomplete-wrapper').length) {

					closeFormulaSuggestions();

				}

			});



			$(document.body).on('blur', '.formula-chip-editor', function() {

				var editor = $(this);

				setTimeout(function() {

					commitFormulaChipEditorValue(editor);

				}, 100);

			});



			$(document.body).on('click', '.formula-chip-remove', function(e) {

				e.preventDefault();

				e.stopPropagation();

				var chipField = $(this).closest('.formula-chip-field');

				var sourceInput = chipField.prev('input.formula-chip-source-input');

				var removeToken = String($(this).attr('data-token') || '').toLowerCase();

				var tokens = parseFormulaTokenList(sourceInput.val()).filter(function(token) {

					return String(token || '').toLowerCase() !== removeToken;

				});

				setFormulaChipTokens(sourceInput, tokens);

				chipField.find('.formula-chip-editor').trigger('focus');

			});



			$(document.body).on('change', 'input.formula-chip-source-input', function() {

				updateFormulaChipField($(this));

			});



			$(document.body).on('blur', '[name*="usa_default_shipping_formula"], [name*="usa_default_handling_formula"], input[name*="[shipping_formula]"], input[name*="[handling_formula]"]', function() {

				validateFormulaInput($(this));

			});



			$(document.body).on('keydown', '[name*="usa_default_shipping_formula"], [name*="usa_default_handling_formula"], input[name*="[shipping_formula]"], input[name*="[handling_formula]"]', function(e) {

				if (e.which === 13) {

					e.preventDefault();

					validateFormulaInput($(this));

				}

			});



			ensureFormulaChipEditors();

			toggleSettingsVisibility();

			updateDynamicSummaries();

		});

	</script>

	<?php

	}

	add_action( 'admin_head', 'formula_rate_shipping_inject_admin_css' );

}



if ( ! function_exists( 'formula_rate_shipping_calculate_handling_fees' ) ) {

	function formula_rate_shipping_calculate_handling_fees( $cart ) {

		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {

			return;

		}

		if ( ! function_exists( 'WC' ) || ! WC() || ! isset( WC()->session ) || ! WC()->session ) {

			return;

		}



		$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );

		if ( ! is_array( $chosen_methods ) ) {

			return;

		}



		foreach ( $chosen_methods as $method_id ) {

			if ( strpos( $method_id, 'formula_rate_shipping' ) === 0 ) {

				$handling_cost  = WC()->session->get( 'formula_handling_fee_' . $method_id );

				$handling_label = WC()->session->get( 'formula_handling_label_' . $method_id );



				if ( $handling_cost && $handling_cost > 0 ) {

					$cart->add_fee( $handling_label, $handling_cost, false );

				}

			}

		}

	}

	add_action( 'woocommerce_cart_calculate_fees', 'formula_rate_shipping_calculate_handling_fees' );

}



// ==========================================

// 4. IMPORT/EXPORT AJAX HANDLERS

// ==========================================



/**

 * AJAX handler for exporting Formula Rate Shipping settings.

 */

if ( ! function_exists( 'formula_shipping_export_handler' ) ) {

	function formula_shipping_export_handler() {

		check_ajax_referer( 'formula_shipping_import_export', 'nonce' );

		

		if ( ! current_user_can( 'manage_woocommerce' ) ) {

			wp_send_json_error( array( 'message' => 'Permission denied.' ) );

		}

		

		// Get all shipping zones

		$zones = array();

		if ( class_exists( 'WC_Shipping_Zones' ) ) {

			$wc_zones = \WC_Shipping_Zones::get_zones();

			foreach ( $wc_zones as $zone ) {

				$zone_obj = new \WC_Shipping_Zone( $zone['id'] );

				$methods = $zone_obj->get_shipping_methods( true );

				foreach ( $methods as $method ) {

					if ( 'formula_rate_shipping' === $method->id ) {

						$zones[] = array(

							'zone_id'   => $zone['id'],

							'zone_name' => $zone['zone_name'],

							'settings'  => array(

								'title'                    => $method->get_option( 'title', 'Shipping and Handling' ),

								'auto_inject'              => $method->get_option( 'auto_inject', 'yes' ),

								'handling_combine'         => $method->get_option( 'handling_combine', 'yes' ),

								'handling_title'           => $method->get_option( 'handling_title', 'Handling Fee' ),

								'round_rates'              => $method->get_option( 'round_rates', 'no' ),

								'holiday_country'          => $method->get_option( 'holiday_country', 'store' ),

								'applicable_countries'     => $method->get_option( 'applicable_countries', $method->get_option( 'usa_countries', 'US, PR' ) ),

								'min_shipping'             => $method->get_option( 'min_shipping', $method->get_option( 'usa_min_shipping', '' ) ),

								'max_shipping'             => $method->get_option( 'max_shipping', $method->get_option( 'usa_max_shipping', '' ) ),

								'default_shipping_formula' => $method->get_option( 'default_shipping_formula', $method->get_option( 'usa_default_shipping_formula', '4.50 + ([qty] - 1) * 2.50' ) ),

								'default_handling_formula' => $method->get_option( 'default_handling_formula', $method->get_option( 'usa_default_handling_formula', '3.00' ) ),

								'location_rule_resolution' => $method->get_option( 'location_rule_resolution', $method->get_option( 'usa_location_rule_resolution', 'first_match' ) ),

								'location_rules'           => $method->get_option( 'location_rules', $method->get_option( 'usa_county_rules', array() ) ),

								'rule_resolution'          => $method->get_option( 'rule_resolution', $method->get_option( 'usa_rule_resolution', 'first_match' ) ),

								'item_rules'               => $method->get_option( 'item_rules', $method->get_option( 'usa_item_rules', array() ) ),

							),

						);

					}

				}

			}

		}

		

		$export_data = array(

			'plugin'    => 'formula-rate-shipping',

			'version'   => '0.64.0',

			'exported'  => current_time( 'Y-m-d H:i:s' ),

			'instances' => $zones,

		);



		$content = json_encode( $export_data, JSON_PRETTY_PRINT );



		wp_send_json_success( array( 'content' => $content ) );

	}

	add_action( 'wp_ajax_formula_shipping_export', 'formula_shipping_export_handler' );

}



/**

 * AJAX handler for importing Formula Rate Shipping settings.

 */

if ( ! function_exists( 'formula_shipping_import_handler' ) ) {

	function formula_shipping_import_handler() {

		check_ajax_referer( 'formula_shipping_import_export', 'nonce' );

		

		if ( ! current_user_can( 'manage_woocommerce' ) ) {

			wp_send_json_error( array( 'message' => 'Permission denied.' ) );

		}

		

		$mode = isset( $_POST['mode'] ) ? sanitize_text_field( $_POST['mode'] ) : 'replace';

		$raw_data = isset( $_POST['data'] ) ? $_POST['data'] : '';



		$import_data = json_decode( $raw_data, true );

		

		if ( ! $import_data || ! isset( $import_data['instances'] ) ) {

			wp_send_json_error( array( 'message' => 'Invalid import data format.' ) );

		}

		

		$instances_updated = 0;

		$instances_created = 0;

		

		// Get existing zones for merge mode

		$existing_zones = array();

		if ( class_exists( 'WC_Shipping_Zones' ) ) {

			$wc_zones = \WC_Shipping_Zones::get_zones();

			foreach ( $wc_zones as $zone ) {

				$existing_zones[ $zone['id'] ] = $zone;

			}

		}

		

		foreach ( $import_data['instances'] as $instance ) {

			$zone_id = isset( $instance['zone_id'] ) ? intval( $instance['zone_id'] ) : 0;

			$settings = isset( $instance['settings'] ) ? $instance['settings'] : array();

			

			if ( 'replace' === $mode || 'append' === $mode ) {

				// For replace/append, we need to find or create the zone

				$zone_obj = null;

				

				if ( $zone_id > 0 && isset( $existing_zones[ $zone_id ] ) ) {

					$zone_obj = new \WC_Shipping_Zone( $zone_id );

				}

				

				if ( ! $zone_obj && 'append' === $mode ) {

					// Create new zone

					$zone_obj = new \WC_Shipping_Zone();

					$zone_obj->set_zone_name( isset( $instance['zone_name'] ) ? $instance['zone_name'] : 'Imported Zone' );

					$zone_obj->save();

					$instances_created++;

				} elseif ( ! $zone_obj ) {

					continue;

				}

				

				// Find or add the shipping method

				$methods = $zone_obj->get_shipping_methods( true );

				$method_instance = null;

				foreach ( $methods as $method ) {

					if ( 'formula_rate_shipping' === $method->id ) {

						$method_instance = $method;

						break;

					}

				}

				

				if ( ! $method_instance ) {

					// Add the method to the zone

					$method_id = $zone_obj->add_shipping_method( 'formula_rate_shipping' );

					if ( $method_id ) {

						$method_instance = new \WC_Shipping_Method( $method_id );

					}

				}

				

				if ( $method_instance ) {

					// Update settings

					$option_key = $method_instance->get_option_key();

					$existing_settings = get_option( $option_key, array() );

					

					$new_settings = array_merge( $existing_settings, array(

						'title'                    => isset( $settings['title'] ) ? sanitize_text_field( $settings['title'] ) : 'Shipping and Handling',

						'auto_inject'              => isset( $settings['auto_inject'] ) ? sanitize_text_field( $settings['auto_inject'] ) : 'yes',

						'handling_combine'         => isset( $settings['handling_combine'] ) ? sanitize_text_field( $settings['handling_combine'] ) : 'yes',

						'handling_title'           => isset( $settings['handling_title'] ) ? sanitize_text_field( $settings['handling_title'] ) : 'Handling Fee',

						'round_rates'              => isset( $settings['round_rates'] ) ? sanitize_text_field( $settings['round_rates'] ) : 'no',

						'holiday_country'          => isset( $settings['holiday_country'] ) ? sanitize_text_field( $settings['holiday_country'] ) : 'store',

						'applicable_countries'     => isset( $settings['applicable_countries'] ) ? sanitize_text_field( $settings['applicable_countries'] ) : 'US, PR',

						'min_shipping'             => isset( $settings['min_shipping'] ) ? sanitize_text_field( $settings['min_shipping'] ) : '',

						'max_shipping'             => isset( $settings['max_shipping'] ) ? sanitize_text_field( $settings['max_shipping'] ) : '',

						'default_shipping_formula' => isset( $settings['default_shipping_formula'] ) ? sanitize_text_field( $settings['default_shipping_formula'] ) : '4.50 + ([qty] - 1) * 2.50',

						'default_handling_formula' => isset( $settings['default_handling_formula'] ) ? sanitize_text_field( $settings['default_handling_formula'] ) : '3.00',

						'location_rule_resolution' => isset( $settings['location_rule_resolution'] ) ? sanitize_text_field( $settings['location_rule_resolution'] ) : ( isset( $settings['usa_location_rule_resolution'] ) ? sanitize_text_field( $settings['usa_location_rule_resolution'] ) : 'first_match' ),

						'location_rules'           => isset( $settings['location_rules'] ) ? $settings['location_rules'] : ( isset( $settings['usa_county_rules'] ) ? $settings['usa_county_rules'] : array() ),

						'rule_resolution'          => isset( $settings['rule_resolution'] ) ? sanitize_text_field( $settings['rule_resolution'] ) : ( isset( $settings['usa_rule_resolution'] ) ? sanitize_text_field( $settings['usa_rule_resolution'] ) : 'first_match' ),

						'item_rules'               => isset( $settings['item_rules'] ) ? $settings['item_rules'] : ( isset( $settings['usa_item_rules'] ) ? $settings['usa_item_rules'] : array() ),

					) );

					

					update_option( $option_key, $new_settings );

					$instances_updated++;

				}

			} elseif ( 'merge' === $mode ) {

				// Merge mode: update existing zones by ID, add new ones

				if ( $zone_id > 0 && isset( $existing_zones[ $zone_id ] ) ) {

					$zone_obj = new \WC_Shipping_Zone( $zone_id );

					$methods = $zone_obj->get_shipping_methods( true );

					

					foreach ( $methods as $method ) {

						if ( 'formula_rate_shipping' === $method->id ) {

							$option_key = $method->get_option_key();

							$existing_settings = get_option( $option_key, array() );

							

							$new_settings = array_merge( $existing_settings, array(

								'title'                    => isset( $settings['title'] ) ? sanitize_text_field( $settings['title'] ) : $existing_settings['title'],

								'auto_inject'              => isset( $settings['auto_inject'] ) ? sanitize_text_field( $settings['auto_inject'] ) : ( isset( $existing_settings['auto_inject'] ) ? $existing_settings['auto_inject'] : 'yes' ),

								'handling_combine'         => isset( $settings['handling_combine'] ) ? sanitize_text_field( $settings['handling_combine'] ) : $existing_settings['handling_combine'],

								'handling_title'           => isset( $settings['handling_title'] ) ? sanitize_text_field( $settings['handling_title'] ) : $existing_settings['handling_title'],

								'round_rates'              => isset( $settings['round_rates'] ) ? sanitize_text_field( $settings['round_rates'] ) : ( isset( $existing_settings['round_rates'] ) ? $existing_settings['round_rates'] : 'no' ),

								'holiday_country'          => isset( $settings['holiday_country'] ) ? sanitize_text_field( $settings['holiday_country'] ) : ( isset( $existing_settings['holiday_country'] ) ? $existing_settings['holiday_country'] : 'store' ),

								'applicable_countries'     => isset( $settings['applicable_countries'] ) ? sanitize_text_field( $settings['applicable_countries'] ) : $existing_settings['applicable_countries'],

								'min_shipping'             => isset( $settings['min_shipping'] ) ? sanitize_text_field( $settings['min_shipping'] ) : $existing_settings['min_shipping'],

								'max_shipping'             => isset( $settings['max_shipping'] ) ? sanitize_text_field( $settings['max_shipping'] ) : $existing_settings['max_shipping'],

								'default_shipping_formula' => isset( $settings['default_shipping_formula'] ) ? sanitize_text_field( $settings['default_shipping_formula'] ) : $existing_settings['default_shipping_formula'],

								'default_handling_formula' => isset( $settings['default_handling_formula'] ) ? sanitize_text_field( $settings['default_handling_formula'] ) : $existing_settings['default_handling_formula'],

								'location_rule_resolution' => isset( $settings['location_rule_resolution'] ) ? sanitize_text_field( $settings['location_rule_resolution'] ) : ( isset( $existing_settings['location_rule_resolution'] ) ? $existing_settings['location_rule_resolution'] : 'first_match' ),

								'location_rules'           => isset( $settings['location_rules'] ) ? $settings['location_rules'] : ( isset( $existing_settings['location_rules'] ) ? $existing_settings['location_rules'] : array() ),

								'rule_resolution'          => isset( $settings['rule_resolution'] ) ? sanitize_text_field( $settings['rule_resolution'] ) : $existing_settings['rule_resolution'],

								'item_rules'               => isset( $settings['item_rules'] ) ? $settings['item_rules'] : $existing_settings['item_rules'],

							) );

							

							update_option( $option_key, $new_settings );

							$instances_updated++;

							break;

						}

					}

				} else {

					// Create new zone

					$zone_obj = new \WC_Shipping_Zone();

					$zone_obj->set_zone_name( isset( $instance['zone_name'] ) ? $instance['zone_name'] : 'Imported Zone' );

					$zone_obj->save();

					

					$method_id = $zone_obj->add_shipping_method( 'formula_rate_shipping' );

					if ( $method_id ) {

						$method = new \WC_Shipping_Method( $method_id );

						$option_key = $method->get_option_key();

						

						$new_settings = array(

							'title'                    => isset( $settings['title'] ) ? sanitize_text_field( $settings['title'] ) : 'Shipping and Handling',

							'auto_inject'              => isset( $settings['auto_inject'] ) ? sanitize_text_field( $settings['auto_inject'] ) : 'yes',

							'handling_combine'         => isset( $settings['handling_combine'] ) ? sanitize_text_field( $settings['handling_combine'] ) : 'yes',

							'handling_title'           => isset( $settings['handling_title'] ) ? sanitize_text_field( $settings['handling_title'] ) : 'Handling Fee',

							'round_rates'              => isset( $settings['round_rates'] ) ? sanitize_text_field( $settings['round_rates'] ) : 'no',

							'holiday_country'          => isset( $settings['holiday_country'] ) ? sanitize_text_field( $settings['holiday_country'] ) : 'store',

							'applicable_countries'     => isset( $settings['applicable_countries'] ) ? sanitize_text_field( $settings['applicable_countries'] ) : 'US, PR',

							'min_shipping'             => isset( $settings['min_shipping'] ) ? sanitize_text_field( $settings['min_shipping'] ) : '',

							'max_shipping'             => isset( $settings['max_shipping'] ) ? sanitize_text_field( $settings['max_shipping'] ) : '',

							'default_shipping_formula' => isset( $settings['default_shipping_formula'] ) ? sanitize_text_field( $settings['default_shipping_formula'] ) : '4.50 + ([qty] - 1) * 2.50',

							'default_handling_formula' => isset( $settings['default_handling_formula'] ) ? sanitize_text_field( $settings['default_handling_formula'] ) : '3.00',

							'location_rule_resolution' => isset( $settings['location_rule_resolution'] ) ? sanitize_text_field( $settings['location_rule_resolution'] ) : 'first_match',

							'location_rules'           => isset( $settings['location_rules'] ) ? $settings['location_rules'] : array(),

							'rule_resolution'          => isset( $settings['rule_resolution'] ) ? sanitize_text_field( $settings['rule_resolution'] ) : 'first_match',

							'item_rules'               => isset( $settings['item_rules'] ) ? $settings['item_rules'] : array(),

						);

						

						update_option( $option_key, $new_settings );

						$instances_created++;

					}

				}

			}

		}

		

		$message = sprintf( 'Import complete. %d instance(s) updated, %d instance(s) created.', $instances_updated, $instances_created );

		wp_send_json_success( array( 'message' => $message ) );

	}

	add_action( 'wp_ajax_formula_shipping_import', 'formula_shipping_import_handler' );

}



if ( ! function_exists( 'formula_rate_shipping_custom_no_shipping_message' ) ) {

	function formula_rate_shipping_custom_no_shipping_message( $default_message ) {

		if ( ! function_exists( 'WC' ) || ! WC() || ! isset( WC()->customer ) || ! WC()->customer ) {

			return $default_message;

		}



		$customer_country = strtoupper( trim( WC()->customer->get_shipping_country() ) );

		if ( empty( $customer_country ) ) {

			return $default_message;

		}



		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {

			return $default_message;

		}



		$package = array(

			'destination' => array(

				'country'  => $customer_country,

				'state'    => WC()->customer->get_shipping_state(),

				'postcode' => WC()->customer->get_shipping_postcode(),

				'city'     => WC()->customer->get_shipping_city(),

			),

		);

		$matching_zone = WC_Shipping_Zones::get_zone_matching_package( $package );



		if ( $matching_zone ) {

			$methods = $matching_zone->get_shipping_methods( true );

			foreach ( $methods as $method ) {

				if ( 'formula_rate_shipping' === $method->id ) {

					$usa_countries_raw = $method->get_option( 'applicable_countries', $method->get_option( 'usa_countries', '' ) );

					$usa_countries = array_filter( array_map( 'trim', explode( ',', strtoupper( $usa_countries_raw ) ) ) );



					if ( empty( $usa_countries ) || in_array( $customer_country, $usa_countries, true ) ) {

						return $default_message;

					}

				}

			}

		}



		return $default_message;

	}

	add_filter( 'woocommerce_no_shipping_available_html', 'formula_rate_shipping_custom_no_shipping_message' );

	add_filter( 'woocommerce_cart_no_shipping_available_html', 'formula_rate_shipping_custom_no_shipping_message' );

}



/**

 * AJAX handler for autocompleting Section 2 user logins, emails, and user meta keys.

 */

if ( ! function_exists( 'formula_shipping_search_targets_handler' ) ) {

	function formula_shipping_search_targets_handler() {

		check_ajax_referer( 'formula_shipping_import_export', 'nonce' );



		if ( ! current_user_can( 'manage_woocommerce' ) ) {

			wp_send_json_error( array( 'message' => 'Permission denied.' ) );

		}



		$target_type = isset( $_POST['type'] ) ? sanitize_text_field( $_POST['type'] ) : '';

		$term        = isset( $_POST['term'] ) ? sanitize_text_field( $_POST['term'] ) : '';



		if ( empty( $term ) || strlen( $term ) < 1 ) {

			wp_send_json_success( array( 'results' => array() ) );

		}



		$results = array();



		if ( 'user_login' === $target_type || 'user_email' === $target_type ) {

			$user_query = new \WP_User_Query( array(

				'search'         => '*' . esc_attr( $term ) . '*',

				'search_columns' => array( 'user_login', 'user_email', 'user_nicename' ),

				'number'         => 15,

				'fields'         => array( 'ID', 'user_login', 'user_email' ),

			) );



			$users = $user_query->get_results();

			if ( ! empty( $users ) ) {

				foreach ( $users as $u ) {

					if ( 'user_login' === $target_type ) {

						$results[] = array(

							'value' => $u->user_login,

							'label' => $u->user_login . ' (' . $u->user_email . ')',

						);

					} else {

						$results[] = array(

							'value' => $u->user_email,

							'label' => $u->user_email . ' (' . $u->user_login . ')',

						);

					}

				}

			}

		} elseif ( 'user_meta_key' === $target_type ) {

			global $wpdb;

			$like = '%' . $wpdb->esc_like( $term ) . '%';

			$meta_keys = $wpdb->get_col( $wpdb->prepare(

				"SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s LIMIT 15",

				$like

			) );



			if ( ! empty( $meta_keys ) ) {

				foreach ( $meta_keys as $mk ) {

					$results[] = array(

						'value' => $mk,

						'label' => $mk,

					);

				}

			}

		}



		wp_send_json_success( array( 'results' => $results ) );

	}

	add_action( 'wp_ajax_formula_shipping_search_targets', 'formula_shipping_search_targets_handler' );

}



/**

 * AJAX handler for validating formulas in the admin editor.

 */

if ( ! function_exists( 'formula_shipping_validate_formula_handler' ) ) {

	function formula_shipping_validate_formula_handler() {

		check_ajax_referer( 'formula_shipping_import_export', 'nonce' );



		if ( ! current_user_can( 'manage_woocommerce' ) ) {

			wp_send_json_error( array( 'message' => 'Permission denied.' ) );

		}



		$formula = isset( $_POST['formula'] ) ? trim( wp_unslash( $_POST['formula'] ) ) : '';

		if ( '' === $formula ) {

			wp_send_json_success( array( 'valid' => true ) );

		}



		$sample_contents = array(

			array(

				'qty'        => 3,

				'cost'       => 12.50,

				'weight'     => 2.25,

				'items'      => 2,

				'category'   => 'books',

				'tag'        => 'featured',

				'variation'  => 'Hardcover',

				'sku'        => 'BOOK-HC-001',

				'name'       => 'Sample Hardcover Book',

				'line_total' => 37.50,

			),

			array(

				'qty'        => 2,

				'cost'       => 8.25,

				'weight'     => 1.10,

				'items'      => 1,

				'category'   => 'paperback',

				'tag'        => 'sale',

				'variation'  => 'Standard',

				'sku'        => 'BOOK-PB-002',

				'name'       => 'Sample Paperback Book',

				'line_total' => 16.50,

			),

		);



		try {

			Formula_Evaluator::evaluate( $formula, $sample_contents );

			wp_send_json_success( array( 'valid' => true ) );

		} catch ( Exception $e ) {

			wp_send_json_success(

				array(

					'valid'   => false,

					'message' => $e->getMessage(),

				)

			);

		}

	}

	add_action( 'wp_ajax_formula_shipping_validate_formula', 'formula_shipping_validate_formula_handler' );

}

