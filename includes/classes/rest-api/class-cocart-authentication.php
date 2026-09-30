<?php
/**
 * Handles REST API authentication.
 *
 * @author  Sébastien Dumont
 * @package CoCart\Classes
 * @since   2.6.0 Introduced.
 * @version 4.9.7
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'CoCart_Authentication' ) ) {

	/**
	 * Handles REST API authentication for CoCart.
	 *
	 * Basic and secure authentication is supported.
	 *
	 * Note: When you authenticate CoCart as the customer you are logging
	 * them in with their account.
	 *
	 * If you are using WooCommerce API consumer key and secret,
	 * a secure connection is required.
	 *
	 * @since 2.6.0 Introduced.
	 */
	class CoCart_Authentication {

		/**
		 * Authentication error.
		 *
		 * @access protected
		 *
		 * @since 3.0.0 Introduced.
		 *
		 * @var WP_Error
		 */
		protected $error = null;

		/**
		 * Logged in user data.
		 *
		 * @access protected
		 *
		 * @since 3.0.0 Introduced.
		 *
		 * @var WP_User
		 */
		protected $user = null;

		/**
		 * Current auth method.
		 *
		 * @access protected
		 *
		 * @since 3.0.0 Introduced.
		 *
		 * @var string
		 */
		protected $auth_method = '';

		/**
		 * Whether CoCart itself authenticated the current request (via Basic
		 * Auth or a third party hooking `cocart_authenticate`, e.g. JWT).
		 *
		 * This is the trust boundary `check_authentication_error()` relies on.
		 * It must never be based on what the request URL looks like — the URL
		 * is attacker-controlled, so a route-pattern check can be satisfied by
		 * appending an unrelated query value that merely contains the pattern.
		 *
		 * @access protected
		 *
		 * @since 4.9.7 Introduced.
		 *
		 * @var bool
		 */
		protected $authenticated_by_cocart = false;

		/**
		 * Basic authentication pattern.
		 *
		 * @access private
		 *
		 * @since 4.6.0 Introduced.
		 *
		 * @var string
		 */
		private const BASIC_AUTH_PATTERN = '/^Basic ([a-zA-Z0-9+\/=]+)$/';

		/**
		 * Constructor.
		 *
		 * @access public
		 *
		 * @since 2.6.0 Introduced.
		 *
		 * @ignore Function ignored when parsed into Code Reference.
		 */
		public function __construct() {
			// Authenticate user.
			add_filter( 'determine_current_user', array( $this, 'authenticate' ) );
			add_filter( 'rest_authentication_errors', array( $this, 'authentication_fallback' ) );

			// Check authentication errors.
			add_filter( 'rest_authentication_errors', array( $this, 'check_authentication_error' ), 15 );

			// Check API permissions.
			add_filter( 'rest_pre_dispatch', array( $this, 'check_api_permissions' ), 10, 3 );

			// Remove the default CORS headers. We will add our own.
			remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );

			// Sets CORS server headers.
			add_filter( 'rest_pre_serve_request', array( $this, 'cors_headers' ), 0, 4 );
			add_filter( 'rest_allowed_cors_headers', array( $this, 'add_cors_headers' ) );
			add_filter( 'rest_exposed_cors_headers', array( $this, 'add_cors_headers' ) );
		} // END init()

		/**
		 * Add allowed and exposed headers to CORS.
		 *
		 * @access public
		 *
		 * @since 4.6.2 Introduced.
		 *
		 * @param array $headers CORS headers array.
		 *
		 * @return array
		 */
		public function add_cors_headers( $headers ) {
			$headers[] = 'CoCart-API-Cart-Key';
			$headers[] = 'CoCart-API-Cart-Expiring';
			$headers[] = 'CoCart-API-Cart-Expiration';

			return $headers;
		} // END add_cors_headers()

		/**
		 * Triggers saved cart after login and updates user activity.
		 *
		 * @access public
		 *
		 * @since 2.9.1 Introduced.
		 *
		 * @since 4.2.0 Deprecated, thinking it was not needed anymore due to changes to support WooCommerce better for performance.
		 * @since 4.3.7 Reinstated again.
		 * @since 4.3.14 Don't update user to load saved cart when requesting to delete.
		 *
		 * @deprecated 4.6.2 No longer supported in WooCommerce as v10
		 *
		 * @param WP_Error|null|bool $error Error from another authentication handler, null if we should handle it, or another value if not.
		 *
		 * @return WP_Error|null|bool
		 */
		public function cocart_user_logged_in( $error ) {
			cocart_deprecated_function( 'CoCart_Authentication::cocart_user_logged_in', '4.6.2' );

			// Pass through errors from other authentication error checks used before this one.
			if ( ! empty( $error ) ) {
				return $error;
			}

			global $current_user;

			if ( $current_user instanceof WP_User && $current_user->exists() ) {
				wc_update_user_last_active( $current_user->ID );
				update_user_meta( $current_user->ID, '_woocommerce_load_saved_cart_after_login', 1 );
			}

			return $error;
		} // END cocart_user_logged_in()

		/**
		 * Returns true if we are making a REST API request for CoCart.
		 *
		 * @access public
		 *
		 * @static
		 *
		 * @since 2.1.0 Introduced.
		 *
		 * @deprecated 4.2.0 Moved function to main plugin class.
		 *
		 * @return bool
		 */
		public static function is_rest_api_request() {
			cocart_deprecated_function( 'CoCart_Authentication::is_rest_api_request', '4.2.0', 'CoCart::is_rest_api_request' );

			return CoCart::is_rest_api_request();
		} // END is_rest_api_request()

		/**
		 * Get the authorization header.
		 *
		 * Returns the value from the authorization header.
		 *
		 * On certain systems and configurations, the Authorization header will be
		 * stripped out by the server or PHP. Typically this is then used to
		 * generate `PHP_AUTH_USER`/`PHP_AUTH_PASS` but not passed on. We use
		 * `getallheaders` here to try and grab it out instead.
		 *
		 * @access protected
		 *
		 * @static
		 *
		 * @since 4.1.0 Introduced.
		 * @since 4.2.0 Changed access from protected to public.
		 *
		 * @return string $auth_header Authorization header value.
		 */
		public static function get_auth_header() {
			$auth_header = ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) ) : '';

			if ( function_exists( 'getallheaders' ) ) {
				$headers = getallheaders();
				// Check for the authorization header case-insensitively.
				foreach ( $headers as $key => $value ) {
					if ( 'authorization' === strtolower( $key ) ) {
						$auth_header = $value;
					}
				}
			}

			// Double check for different auth header string if empty (server dependent).
			if ( empty( $auth_header ) ) {
				$auth_header = isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) : '';
			}

			// Allow the saved "Authorization Header" setting to override the server variable read.
			$auth_settings   = get_option( 'cocart_settings', array() )['auth'] ?? array();
			$auth_header_var = $auth_settings['auth_header'] ?? '';

			if ( empty( $auth_header ) && ! empty( $auth_header_var ) && ! empty( $_SERVER[ $auth_header_var ] ) ) {
				$auth_header = sanitize_text_field( wp_unslash( $_SERVER[ $auth_header_var ] ) );
			}

			/**
			 * Filter allows you to change the authorization header.
			 *
			 * @since 4.1.0 Introduced.
			 *
			 * @param string $auth_header Authorization header.
			 */
			return apply_filters( 'cocart_auth_header', $auth_header );
		} // END get_auth_header()

		/**
		 * Check if the authorization header contains Basic authentication.
		 *
		 * @access private
		 *
		 * @since 4.6.0 Introduced.
		 *
		 * @param string $auth Authorization header value.
		 *
		 * @return bool
		 */
		private function is_basic_auth( string $auth ): bool {
			return ! empty( $auth ) && preg_match( self::BASIC_AUTH_PATTERN, $auth );
		} // END is_basic_auth()

		/**
		 * Extract Basic login from authorization header.
		 *
		 * @access private
		 *
		 * @since 4.6.0 Introduced.
		 *
		 * @param string $auth Authorization header value.
		 *
		 * @return string|null Login if found, null otherwise.
		 */
		private static function extract_basic_auth( string $auth ): ?string {
			if ( preg_match( self::BASIC_AUTH_PATTERN, $auth, $matches ) ) {
				return $matches[1];
			}

			return null;
		} // END extract_basic_auth()

		/**
		 * Authenticate user.
		 *
		 * @access public
		 *
		 * @since 2.6.0 Introduced.
		 *
		 * @param int|false $user_id User ID if one has been determined, false otherwise.
		 *
		 * @return int|false
		 */
		public function authenticate( $user_id ) {
			// Do not authenticate twice and check if is a request to our endpoint.
			if ( ! empty( $user_id ) || ! CoCart::is_rest_api_request() ) {
				return $user_id;
			}

			if ( is_ssl() || $this->is_wp_environment_local() ) {
				$user_id = $this->perform_basic_authentication();
			}

			/**
			 * Filters the user ID returned and allows for third party to
			 * include another authentication method.
			 *
			 * @since 2.6.0 Introduced.
			 * @since 3.8.1 Passed the authentication class as parameter.
			 *
			 * @param int    $user_id              The user ID returned if authentication was successful.
			 * @param bool   $is_secure            Determines if the site is secure.
			 * @param object $authentication_class The Authentication class.
			 */
			$user_id = apply_filters( 'cocart_authenticate', $user_id, is_ssl(), $this );

			// Record that CoCart itself vouches for this request — the only
			// signal check_authentication_error() is allowed to trust.
			if ( ! empty( $user_id ) ) {
				$this->authenticated_by_cocart = true;
			}

			return $user_id;
		} // END authenticate()

		/**
		 * Authenticate the user if authentication wasn't performed during the
		 * determine_current_user action.
		 *
		 * Necessary in cases where wp_get_current_user() is called before CoCart is loaded.
		 *
		 * @access public
		 *
		 * @since 3.0.0 Introduced.
		 *
		 * @param WP_Error|null|bool $error Error from another authentication handler, null if we should handle it, or another value if not.
		 *
		 * @return WP_Error|null|bool
		 */
		public function authentication_fallback( $error ) {
			if ( ! empty( $error ) ) {
				// Another error has already declared a failure.
				return $error;
			}

			if ( empty( $this->error ) && empty( $this->auth_method ) && empty( $this->user ) && 0 === get_current_user_id() ) {
				// Authentication hasn't occurred during `determine_current_user`, so check auth.
				$user_id = $this->authenticate( false );

				if ( ! empty( $user_id ) ) {
					wp_set_current_user( $user_id );
					return true;
				}
			}

			return $error;
		} // END authentication_fallback()

		/**
		 * Check for authentication error.
		 *
		 * CoCart does not require authentication.
		 *
		 * @access public
		 *
		 * @since 3.0.0 Introduced.
		 * @since 4.9.7 Scoped to requests CoCart itself has authenticated, tracked by
		 *              `$authenticated_by_cocart` (set by `authenticate()` only when it
		 *              establishes a user via Basic Auth or a third-party
		 *              `cocart_authenticate` hook, e.g. JWT). The request URL must never
		 *              be used to decide this — it is attacker-controlled, so a
		 *              route-pattern check can be satisfied by appending a query value
		 *              that merely contains the pattern, or by routing through the core
		 *              batch endpoint.
		 *
		 * @param WP_Error|mixed $error Error from another authentication handler, null if we should handle it, or another value if not.
		 *
		 * @return WP_Error|null|bool
		 */
		public function check_authentication_error( $error ) {
			// Pass through errors from other authentication methods used before this one.
			if ( ! empty( $error ) ) {
				return $error;
			}

			// If CoCart itself found invalid credentials for this request, surface that.
			if ( is_wp_error( $this->get_error() ) ) {
				return $this->get_error();
			}

			// Only answer "no error" for requests CoCart itself authenticated.
			// Anything else — including a request whose URL merely resembles a
			// CoCart route — must fall through untouched so WordPress core's own
			// `rest_cookie_check_errors()` (a later priority on this same filter)
			// still runs its nonce check instead of being short-circuited.
			if ( ! $this->authenticated_by_cocart ) {
				return $error;
			}

			return true;
		} // END check_authentication_error()

		/**
		 * Set authentication error.
		 *
		 * @access public
		 *
		 * @since 3.0.0 Introduced.
		 *
		 * @param WP_Error $error Authentication error data.
		 */
		public function set_error( $error ) {
			// Reset user.
			$this->user = null;

			$this->error = $error;
		} // END set_error()

		/**
		 * Get authentication error.
		 *
		 * @access protected
		 *
		 * @return WP_Error|null.
		 */
		protected function get_error() {
			return $this->error;
		} // END get_error()

		/**
		 * Set authentication method.
		 *
		 * @access public
		 *
		 * @since 3.8.1 Introduced.
		 *
		 * @param string $auth_method Authentication method.
		 */
		public function set_method( $auth_method ) {
			$this->auth_method = $auth_method;
		} // END set_method()

		/**
		 * Basic Authentication.
		 *
		 * SSL-encrypted requests are not subject to sniffing or man-in-the-middle
		 * attacks, so the request can be authenticated by simply looking up the user
		 * associated with the given username and password provided that it is valid.
		 *
		 * @link https://developer.wordpress.org/reference/functions/wp_authenticate/
		 *
		 * @access private
		 *
		 * @since 3.0.0 Introduced.
		 *
		 * @uses CoCart_Authentication()::get_auth_header()
		 * @uses CoCart_Authentication()::get_username()
		 * @uses get_user_by()
		 * @uses wp_check_password()
		 *
		 * @return int|bool
		 */
		private function perform_basic_authentication() {
			$this->auth_method = 'basic_auth';
			$username          = '';
			$password          = '';

			$auth_header = self::get_auth_header();

			// Look up authorization header and check it's a valid.
			if ( ! empty( $auth_header ) && $this->is_basic_auth( $auth_header ) ) {
				$auth_str = self::extract_basic_auth( $auth_header );

				$exploded = explode( ':', base64_decode( $auth_str ), 2 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

				// If valid return username and password.
				if ( 2 === \count( $exploded ) ) {
					list( $username, $password ) = $exploded;

					$username = self::get_username( $username );
				}
			} elseif ( ! empty( $_SERVER['PHP_AUTH_USER'] ) && ! empty( $_SERVER['PHP_AUTH_PW'] ) ) {
				// Check that we're trying to authenticate via simple headers.
				$username = trim( sanitize_user( wp_unslash( $_SERVER['PHP_AUTH_USER'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$username = self::get_username( $username );
				$password = trim( sanitize_text_field( wp_unslash( $_SERVER['PHP_AUTH_PW'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			} elseif ( ! empty( $_REQUEST['username'] ) && ! empty( $_REQUEST['password'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				// Fallback to check if the username and password was passed via URL.
				$username = trim( sanitize_user( wp_unslash( $_REQUEST['username'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$username = self::get_username( $username );
				$password = trim( sanitize_text_field( wp_unslash( $_REQUEST['password'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			} else {
				// Attempt to read JSON from raw input if request is posting and the content type is "application/json".
				$content_type   = isset( $_SERVER['CONTENT_TYPE'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ) ) ) : '';
				$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';

				// Check X-HTTP-Method-Override header if it exists and is not empty.
				$request_method = ! empty( $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ) ) ) : $request_method;

				if ( 'POST' === $request_method && false !== stripos( $content_type, 'application/json' ) ) {
					$raw_input = file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

					if ( ! empty( $raw_input ) ) {
						$body_params = json_decode( $raw_input, true );

						if ( is_array( $body_params ) ) {
							if ( ! empty( $body_params['username'] ) && ! empty( $body_params['password'] ) ) {
								$username = trim( sanitize_user( $body_params['username'] ) );
								$username = self::get_username( $username );
								$password = trim( sanitize_text_field( $body_params['password'] ) );
							}
						}
					}
				}
			}

			// If no username or password identified then authentication is not required.
			if ( empty( $username ) && empty( $password ) ) {
				return false;
			} elseif ( empty( $username ) || empty( $password ) ) {
				\CoCart_Logger::log( esc_html__( 'Authentication attempted but did not find valid login details.', 'cart-rest-api-for-woocommerce' ), 'error' );
				// If either username or password is missing then return error.
				$this->set_error( new WP_Error( 'cocart_authentication_error', __( 'Authentication invalid!', 'cart-rest-api-for-woocommerce' ), array( 'status' => 401 ) ) );
				return false;
			}

			$user = get_user_by( 'login', $username );

			if ( empty( $user ) ) {
				\CoCart_Logger::log( esc_html__( 'User was not found when authenticating.', 'cart-rest-api-for-woocommerce' ), 'error' );
				$this->set_error( new WP_Error( 'cocart_authentication_error', __( 'Authentication is invalid. Please check your login details are correct and try again.', 'cart-rest-api-for-woocommerce' ), array( 'status' => 401 ) ) );
				return false;
			}

			if ( ! wp_check_password( $password, $user->data->user_pass, $user->ID ) ) {
				$this->set_error( new WP_Error( 'cocart_authentication_error', __( 'The password you entered is incorrect.', 'cart-rest-api-for-woocommerce' ), array( 'status' => 401 ) ) );
				return false;
			}

			$this->user = $user;

			return $this->user->ID;
		} // END perform_basic_authentication()

		/**
		 * Checks the WordPress environment to see if we are running CoCart locally.
		 *
		 * @link https://developer.wordpress.org/reference/functions/wp_get_environment_type/
		 *
		 * @access protected
		 *
		 * @since   3.0.0 Introduced.
		 * @version 3.0.15
		 *
		 * @uses wp_get_environment_type() function introduced in WP 5.5
		 *
		 * @return bool
		 */
		protected function is_wp_environment_local() {
			if ( function_exists( 'wp_get_environment_type' ) ) {
				if ( 'local' === wp_get_environment_type() || 'development' === wp_get_environment_type() ) {
					return true;
				}
			}

			return false;
		} // END is_wp_environment_local()

		/**
		 * Is the request a preflight request? Checks the request method.
		 *
		 * @access protected
		 *
		 * @since 4.0.0 Introduced.
		 *
		 * @return boolean
		 */
		protected function is_preflight() {
			return isset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'], $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'], $_SERVER['HTTP_ORIGIN'] ) && 'OPTIONS' === $_SERVER['REQUEST_METHOD'];
		} // END is_preflight()

		/**
		 * Sends headers.
		 *
		 * Returns allowed headers and exposes headers that can be used.
		 *
		 * @access public
		 *
		 * @since 4.2.0 Introduced.
		 *
		 * @deprecated 4.6.2 No longer require. Uses `rest_allowed_cors_headers` and `rest_exposed_cors_headers` filters instead.
		 *
		 * @uses is_user_logged_in()
		 *
		 * @param bool             $served  Whether the request has already been served. Default false.
		 * @param WP_HTTP_Response $result  Result to send to the client. Usually a WP_REST_Response.
		 * @param WP_REST_Request  $request The request object.
		 * @param WP_REST_Server   $server  Server instance.
		 *
		 * @return bool
		 */
		public function send_headers( $served, $result, $request, $server ) {
			cocart_deprecated_function( 'CoCart_Authentication::send_headers', '4.6.2' );

			if ( strpos( $request->get_route(), 'cocart/' ) !== false ) {
				$server->send_header( 'Access-Control-Allow-Headers', implode( ', ', self::ALLOW_HEADERS ) );
				$server->send_header( 'Access-Control-Expose-Headers', implode( ', ', self::EXPOSE_HEADERS ) );

				/**
				 * Send nocache headers on authenticated requests.
				 *
				 * @param bool $rest_send_nocache_headers Whether to send no-cache headers.
				 *
				 * @since 4.2.0 Introduced.
				 *
				 * @deprecated 4.3.11 No longer used. See `cocart_send_cache_control_patterns` filter instead to control which routes are not cached.
				 */
				cocart_do_deprecated_filter( 'cocart_send_nocache_headers', '4.3.11', null, __( 'This filter is no longer used.', 'cart-rest-api-for-woocommerce' ), array( is_user_logged_in() ) );
			}

			// Exit early during preflight requests. This is so someone cannot access API data by sending an OPTIONS request
			// with preflight headers and a _GET property to override the method.
			if ( $this->is_preflight() ) {
				exit;
			}

			return $served;
		} // END send_headers()

		/**
		 * Set Cross Origin headers.
		 *
		 * For security reasons, browsers restrict cross-origin HTTP requests initiated from scripts.
		 * This overrides that by providing access should the request be for CoCart.
		 *
		 * These checks prevent access to the API from non-allowed origins. By default, the WordPress REST API allows
		 * access from any origin. Because some CoCart API routes return PII, we need to add our own CORS headers.
		 *
		 * Allowed origins can be changed using the WordPress `allowed_http_origins` or `allowed_http_origin` filters if
		 * access needs to be granted to other domains.
		 *
		 * @link https://developer.wordpress.org/reference/functions/get_http_origin/
		 * @link https://developer.wordpress.org/reference/functions/get_allowed_http_origins/
		 *
		 * @access public
		 *
		 * @since 2.2.0 Introduced.
		 * @since 3.3.0 Added new custom headers without the prefix `X-`
		 * @since 4.6.2 Removed the need to check if request was a CoCart API request again.
		 *
		 * @uses get_http_origin()
		 * @uses is_allowed_http_origin()
		 *
		 * @param bool             $served  Whether the request has already been served. Default false.
		 * @param WP_HTTP_Response $result  Result to send to the client. Usually a WP_REST_Response.
		 * @param WP_REST_Request  $request The request object.
		 * @param WP_REST_Server   $server  Server instance.
		 *
		 * @return bool
		 */
		public function cors_headers( $served, $result, $request, $server ) {
			$cocart_settings = get_option( 'cocart_settings', array() );
			$cors_default    = empty( $cocart_settings['cors']['enable_cors'] ) || 'yes' !== $cocart_settings['cors']['enable_cors'];

			/**
			 * Modifies if the "Cross Origin Headers" are allowed.
			 *
			 * Set as false to enable support.
			 *
			 * @since 2.2.0 Introduced.
			 */
			if ( apply_filters( 'cocart_disable_all_cors', $cors_default ) ) {
				return $served;
			}

			$origin = get_http_origin();

			// Requests from file:// and data: URLs send "Origin: null".
			if ( 'null' !== $origin ) {
				$origin = esc_url_raw( $origin );
			}

			// Allow the saved "Allowed Origin" setting to be treated as the allowed origin.
			$allowed_origin = $cocart_settings['cors']['allowed_origin'] ?? '';

			if ( ! empty( $allowed_origin ) && $origin === $allowed_origin ) {
				$origin = $allowed_origin;
			}

			/**
			 * Filter allows you to change the allowed HTTP origin result.
			 *
			 * @since 2.5.1 Introduced.
			 *
			 * @param string $origin Origin URL if allowed, empty string if not.
			 */
			$origin = apply_filters( 'cocart_allow_origin', $origin );

			$server->send_header( 'Access-Control-Allow-Methods', 'OPTIONS, GET, POST, PUT, PATCH, DELETE' );
			$server->send_header( 'Vary', 'Origin', false );
			$server->send_header( 'Access-Control-Max-Age', '600' ); // Cache the result of preflight requests (600 is the upper limit for Chromium).
			$server->send_header( 'X-Robots-Tag', 'noindex' );
			$server->send_header( 'X-Content-Type-Options', 'nosniff' );

			// Allow preflight requests and any allowed origins. Preflight requests
			// are allowed because we'll be unable to validate customer header at that point.
			if ( $this->is_preflight() || ! is_allowed_http_origin( $origin ) ) {
				$server->send_header( 'Access-Control-Allow-Origin', $origin );
				$server->send_header( 'Access-Control-Allow-Credentials', ( ! empty( $origin ) && '*' !== $origin ) ? 'true' : 'false' );
			}

			// Exit early during preflight requests. This is so someone cannot access API data by sending an OPTIONS request
			// with preflight headers and a _GET property to override the method.
			if ( $this->is_preflight() ) {
				exit;
			}

			return $served;
		} // END cors_headers()

		/**
		 * Check for permission to access API.
		 *
		 * @throws CoCart_Data_Exception Exception if invalid data is detected.
		 *
		 * @access public
		 *
		 * @since   3.0.0 Introduced.
		 * @version 3.1.0
		 *
		 * @param mixed           $result  Response to replace the requested version with.
		 * @param WP_REST_Server  $server  Server instance.
		 * @param WP_REST_Request $request The request object.
		 *
		 * @return mixed
		 */
		public function check_api_permissions( $result, $server, $request ) {
			$method = strtolower( $request->get_method() );
			$path   = $request->get_route();
			$prefix = 'cocart/';

			/**
			 * Filters API permissions check.
			 *
			 * Should you choose to restrict any of CoCart's API routes for any method,
			 * you can set the requested API and method to enforce authentication by
			 * not allowing it permission to be used by the public.
			 *
			 * Methods you can use to filter the permissions check.
			 *
			 * - `get`
			 * - `post`
			 * - `put`
			 * - `patch`
			 * - `delete`
			 * - `options`
			 *
			 * @since 3.0.0 Introduced.
			 */
			$api_not_allowed = apply_filters( 'cocart_api_permission_check_' . $method, array() );

			try {
				// If no user is logged in then just return.
				if ( ! is_user_logged_in() ) {
					switch ( $method ) {
						case 'get':
							foreach ( $api_not_allowed as $route ) {
								if ( preg_match( '!^/' . $prefix . $route . '(?:$|/)!', $path ) ) {
									throw new CoCart_Data_Exception(
										'cocart_rest_permission_error',
										sprintf(
											/* translators: 1: permission method, 2: api route */
											__( 'Permission to %1$s %2$s is only permitted if the user is authenticated.', 'cart-rest-api-for-woocommerce' ),
											'READ',
											$path
										),
										401
									);
								}
							}
							break;
						case 'post':
						case 'put':
						case 'patch':
						case 'delete':
							foreach ( $api_not_allowed as $route ) {
								if ( preg_match( '!^/' . $prefix . $route . '(?:$|/)!', $path ) ) {
									throw new CoCart_Data_Exception(
										'cocart_rest_permission_error',
										sprintf(
											/* translators: 1: permission method, 2: api route */
											__( 'Permission to %1$s %2$s is only permitted if the user is authenticated.', 'cart-rest-api-for-woocommerce' ),
											'WRITE',
											$path
										),
										401
									);
								}
							}
							break;
						case 'options':
							foreach ( $api_not_allowed as $route ) {
								if ( preg_match( '!^/' . $prefix . $route . '(?:$|/)!', $path ) ) {
									throw new CoCart_Data_Exception(
										'cocart_rest_permission_error',
										sprintf(
											/* translators: 1: permission method, 2: api route */
											__( 'Permission to %1$s %2$s is only permitted if the user is authenticated.', 'cart-rest-api-for-woocommerce' ),
											'OPTIONS',
											$path
										),
										401
									);
								}
							}
							break;
						default:
							throw new CoCart_Data_Exception(
								'cocart_rest_permission_error',
								sprintf(
									/* translators: %s: api route */
									__( 'Unknown request method for %s.', 'cart-rest-api-for-woocommerce' ),
									$path
								),
								401
							);
					}
				}

				// Return previous result if nothing has changed.
				return $result;
			} catch ( CoCart_Data_Exception $e ) {
				return new \WP_Error( $e->getErrorCode(), $e->getMessage(), array( 'status' => $e->getCode() ), $e->getAdditionalData() );
			}
		} // END check_api_permissions()

		/**
		 * Finds a user based on a matching billing phone number.
		 *
		 * @access public
		 *
		 * @static
		 *
		 * @since 4.1.0 Introduced.
		 * @since 4.2.0 Changed access from protected to public.
		 *
		 * @param numeric $phone The billing phone number to check.
		 *
		 * @return string The username returned if found.
		 */
		public static function get_user_by_phone( $phone ) {
			$matching_users = get_users(
				array(
					'meta_key'     => 'billing_phone', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'   => $phone, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_compare' => '=',
				)
			);

			$username = ! empty( $matching_users ) && is_array( $matching_users ) ? $matching_users[0]->user_login : '';

			return $username;
		} // END get_user_by_phone()

		/**
		 * Checks if the login provided is valid as a phone number or email address and returns the username.
		 *
		 * @access public
		 *
		 * @static
		 *
		 * @since 4.1.0 Introduced.
		 * @since 4.2.0 Changed access from protected to public.
		 *
		 * @param string $username Either a phone number, email address or username.
		 *
		 * @return string $username Username returned if valid.
		 */
		public static function get_username( $username ) {
			// Check if the username provided is a billing phone number and return the username if true.
			if ( WC_Validation::is_phone( $username ) ) {
				$username = self::get_user_by_phone( $username ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}

			// Check if the username provided was an email address and return the username if true.
			if ( is_email( $username ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$user = get_user_by( 'email', $username ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

				if ( $user ) {
					$username = $user->user_login; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				}
			}

			return $username;
		} // END get_username()

		/**
		 * Get current user IP Address.
		 *
		 * First checks the REMOTE_ADDR server variable as the most reliable source.
		 * When proxy support is enabled, checks if the remote address is a trusted proxy.
		 *
		 * For trusted proxies, the following headers may be used:
		 *
		 * - X-Forwarded-For (XFF): De-facto standard header for identifying original client IP.
		 *   Format: X-Forwarded-For: client1, proxy1, proxy2 (we use the first IP only)
		 *   Documentation: https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/X-Forwarded-For
		 *
		 * - X-Real-IP and Client-IP: Custom implementations for obtaining user IP through proxies/load balancers.
		 *
		 * - Forwarded: Standardized header for proxy server information (load balancers, CDNs)
		 *   Documentation: https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Forwarded
		 *   Full RFC: https://datatracker.ietf.org/doc/html/rfc7239
		 *
		 * @access public
		 *
		 * @static
		 *
		 * @since 4.2.0 Introduced.
		 * @since 4.8.0 Added support for trusted proxies and additional headers.
		 *
		 * @param boolean $proxy_support Enables/disables proxy support.
		 *
		 * @return string
		 */
		public static function get_ip_address( bool $proxy_support = false ) { // phpcs:ignore PHPCompatibility.FunctionDeclarations.NewParamTypeDeclarations.boolFound
			$ip = '';

			// Determine remote address.
			$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

			/**
			 * Trusted proxies list.
			 *
			 * Filter should return an array of IPs or CIDR ranges.
			 * Defaults to localhost addresses to avoid trusting arbitrary headers.
			 *
			 * @since 4.8.0 Introduced.
			 *
			 * @param array $trusted_proxies Array of trusted proxy IPs/CIDR.
			 */
			$trusted_proxies = array_merge( self::get_trusted_proxies(), apply_filters( 'cocart_trusted_proxies', array() ) );

			// If not forced and remote is not a trusted proxy, do not trust proxy headers.
			$use_proxy_headers = $proxy_support || self::is_trusted_proxy( $remote_addr, $trusted_proxies );

			// If we won't use proxy headers, just use REMOTE_ADDR.
			if ( ! $use_proxy_headers ) {
				if ( ! empty( $remote_addr ) ) {
					CoCart_Logger::log( 'Using REMOTE_ADDR (not a trusted proxy)', 'info' );
					return self::validate_ip( $remote_addr );
				}
				// Fall back to default later if REMOTE_ADDR empty.
			} else {
				CoCart_Logger::log( 'Connection from trusted proxy; parsing proxy headers', 'info' );
				// We'll parse headers below to find the client IP.
			}

			/**
			 * Additional IP headers for common proxy setups.
			 *
			 * @since 4.8.0 Introduced.
			 */
			$additional_headers = apply_filters(
				'cocart_ip_headers',
				array(
					// Highest priority edge/CDN headers first.
					'HTTP_CF_CONNECTING_IP',   // Cloudflare.
					'HTTP_TRUE_CLIENT_IP',     // Akamai / some CDNs.
					// Common proxy headers.
					'HTTP_X_REAL_IP',          // Nginx / proxy.
					'HTTP_X_CLUSTER_CLIENT_IP',
					// Less-trustworthy client-provided fallbacks.
					'HTTP_X_CLIENT_IP',
					'HTTP_CLIENT_IP',
					// Leave generic Forwarded header handling to the dedicated section below.
				)
			);

			// Only parse the additional headers if remote is trusted or proxy_support forced.
			if ( $use_proxy_headers ) {
				// Ensure X-Forwarded-For is considered (may be supplied here via filter).
				if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) && ! in_array( 'HTTP_X_FORWARDED_FOR', $additional_headers, true ) ) {
					array_unshift( $additional_headers, 'HTTP_X_FORWARDED_FOR' );
				}

				foreach ( $additional_headers as $header ) {
					if ( empty( $_SERVER[ $header ] ) ) {
						continue;
					}

					CoCart_Logger::log( $header . ' is detected', 'info' );

					$value = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );

					// Try to extract first valid ip from the header value (handles comma lists, quotes, brackets, ports).
					$candidate_ip = self::parse_ip_from_header_value( $value );
					if ( $candidate_ip ) {
						$ip = $candidate_ip;
						break;
					}
				}
			}

			if ( ! empty( $_SERVER['HTTP_FORWARDED'] ) ) {
				CoCart_Logger::log( 'HTTP_FORWARDED is detected', 'info' );

				// Extract the 'for' part(s) from Forwarded header and validate candidates.
				$forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_FORWARDED'] ) );

				// Find all for=... occurrences (may be multiple, comma-separated).
				if ( preg_match_all( '/for=([^;,]+)/i', $forwarded, $matches ) && ! empty( $matches[1] ) ) {
					foreach ( $matches[1] as $for_val ) {
						$candidate = self::parse_ip_from_header_value( $for_val );
						if ( $candidate ) {
							$ip = $candidate;
							break;
						}
					}
				}
			}

			// Validate the IP.
			if ( ! empty( $ip ) ) {
				return self::validate_ip( $ip );
			}

			// If we didn't extract an IP from headers, fall back to REMOTE_ADDR if present.
			if ( ! empty( $remote_addr ) ) {
				CoCart_Logger::log( 'Falling back to REMOTE_ADDR', 'info' );
				return self::validate_ip( $remote_addr );
			}

			CoCart_Logger::log( 'Falling back to default IP address', 'info' );

			// Return empty string if no IP found.
			return '';
		} // END get_ip_address()

		/**
		 * Uses filter_var() to validate and return ipv4 and ipv6 addresses.
		 *
		 * Will return 0.0.0.0 if the ip is not valid. This is done to group and still rate limit invalid ips.
		 *
		 * @access public
		 *
		 * @static
		 *
		 * @since 4.2.0 Introduced.
		 *
		 * @param string $ip ipv4 or ipv6 ip string.
		 *
		 * @return string
		 */
		public static function validate_ip( $ip ) {
			$ip = filter_var(
				$ip,
				FILTER_VALIDATE_IP,
				array( FILTER_FLAG_NO_RES_RANGE, FILTER_FLAG_IPV6 )
			);

			return $ip ? $ip : '0.0.0.0';
		} // END validate_ip()

		/**
		 * Parse a raw header value (comma lists, quoted/bracketed values, ports) and return the first valid IP.
		 *
		 * @access private
		 *
		 * @static
		 *
		 * @since 4.8.0 Introduced.
		 *
		 * @param string $value Raw header value.
		 *
		 * @return string|null Valid IP string or null.
		 */
		private static function parse_ip_from_header_value( string $value ) {
			$value = trim( $value, " \t\n\r\0\x0B\"'" );

			// Split comma-separated lists, take candidates in order.
			$candidates = ( false !== strpos( $value, ',' ) ) ? array_map( 'trim', explode( ',', $value ) ) : array( $value );

			foreach ( $candidates as $candidate ) {
				$candidate = trim( $candidate );
				// Remove surrounding brackets (IPv6 may be bracketed).
				$candidate = trim( $candidate, '[] ' );
				// Strip possible port suffix like :1234 (if present and not a valid IP).
				if ( ! filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					$without_port = preg_replace( '/:\d+$/', '', $candidate );
					if ( filter_var( $without_port, FILTER_VALIDATE_IP ) ) {
						$candidate = $without_port;
					}
				}

				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					return $candidate;
				}
			}

			return null;
		} // END parse_ip_from_header_value()

		/**
		 * Check whether a given IP belongs to a trusted proxy list.
		 *
		 * Supports single IPs and CIDR ranges.
		 *
		 * @access private
		 *
		 * @static
		 *
		 * @since 4.8.0 Introduced.
		 *
		 * @param string $ip IP address to check.
		 * @param array  $trusted_proxies Array of trusted IPs or CIDR ranges.
		 *
		 * @return bool True if the IP is in the trusted proxies list, false otherwise.
		 */
		private static function is_trusted_proxy( $ip, $trusted_proxies ) {
			if ( empty( $ip ) || empty( $trusted_proxies ) ) {
				return false;
			}

			foreach ( $trusted_proxies as $range ) {
				$range = trim( $range );
				if ( $range === $ip ) {
					return true;
				}
				if ( false !== strpos( $range, '/' ) && self::ip_in_cidr( $ip, $range ) ) {
					return true;
				}
			}

			return false;
		} // END is_trusted_proxy()

		/**
		 * Test if an IP is in a CIDR range (supports IPv4 and IPv6).
		 *
		 * @access private
		 *
		 * @static
		 *
		 * @since 4.8.0 Introduced.
		 *
		 * @param string $ip IP address.
		 * @param string $cidr CIDR range like "192.0.2.0/24" or "2001:db8::/32".
		 *
		 * @return bool True if the IP is in the CIDR range, false otherwise.
		 */
		private static function ip_in_cidr( $ip, $cidr ) {
			if ( ! $ip || ! $cidr ) {
				return false;
			}

			list( $subnet, $mask ) = explode( '/', $cidr );
			$mask                  = (int) $mask;

			$ip_bin     = inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$subnet_bin = inet_pton( $subnet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $ip_bin || false === $subnet_bin ) {
				return false;
			}

			// Ensure same address family.
			if ( strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
				return false;
			}

			$bytes     = (int) floor( $mask / 8 );
			$remainder = $mask % 8;

			if ( $bytes && substr( $ip_bin, 0, $bytes ) !== substr( $subnet_bin, 0, $bytes ) ) {
				return false;
			}

			if ( $remainder ) {
				$byte_ip   = ord( $ip_bin[ $bytes ] );
				$byte_sub  = ord( $subnet_bin[ $bytes ] );
				$mask_byte = ( ( 0xFF00 >> $remainder ) & 0xFF );
				if ( ( $byte_ip & $mask_byte ) !== ( $byte_sub & $mask_byte ) ) {
					return false;
				}
			}

			return true;
		} // END ip_in_cidr()

		/**
		 * Get trusted proxies.
		 *
		 * @access private
		 *
		 * @static
		 *
		 * @since 4.8.0 Introduced.
		 *
		 * @return array Array of trusted proxy IPs.
		 */
		private static function get_trusted_proxies() {
			return array(
				'127.0.0.1', // IPv4 localhost.
				'::1',       // IPv6 localhost.
			);
		} // END get_trusted_proxies()
	} // END class.
} // END if class exists.

return new CoCart_Authentication();
