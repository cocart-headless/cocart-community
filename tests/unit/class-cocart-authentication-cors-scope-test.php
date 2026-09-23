<?php
/**
 * Test CoCart Authentication CORS Origin Scoping
 *
 * Follow-up investigation from the REST auth error scoping fix: does
 * CoCart_Authentication::cors_headers() actually restrict credentialed
 * cross-origin access to the origin a store owner configures via
 * `cocart_settings['cors']['allowed_origin']`, or does it reflect any
 * cross-origin request regardless of that setting?
 *
 * `cors_headers()` sends `Access-Control-Allow-Origin` and
 * `Access-Control-Allow-Credentials: true` whenever
 * `! is_allowed_http_origin( $origin )` is true. `is_allowed_http_origin()`
 * only ever returns truthy for the site's own admin/home URL host — so for
 * any genuinely cross-origin request (the entire point of a headless
 * frontend), that condition is true regardless of what the request's
 * Origin header actually is. The `allowed_origin` setting is only used to
 * normalize a matching origin string; nothing in the code path rejects an
 * origin that does not match it.
 *
 * @package CoCart\Tests\Unit
 */

/**
 * Test CoCart Authentication CORS Origin Scoping Class.
 *
 * @package CoCart\Tests\Unit
 */
class Test_CoCart_Authentication_Cors_Scope extends CoCart_Unit_Test_Case {

	/**
	 * Original superglobal/option state, restored in tearDown().
	 *
	 * @var array
	 */
	private $original_state = array();

	/**
	 * Snapshot state before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_state = array(
			'HTTP_ORIGIN'     => $_SERVER['HTTP_ORIGIN'] ?? null,
			'cocart_settings' => get_option( 'cocart_settings', array() ),
		);
	}

	/**
	 * Restore state after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		if ( null === $this->original_state['HTTP_ORIGIN'] ) {
			unset( $_SERVER['HTTP_ORIGIN'] );
		} else {
			$_SERVER['HTTP_ORIGIN'] = $this->original_state['HTTP_ORIGIN'];
		}

		update_option( 'cocart_settings', $this->original_state['cocart_settings'] );

		parent::tearDown();
	}

	/**
	 * Locate the single CoCart_Authentication instance the plugin registered
	 * on its own filters at load time (there is exactly one for the life of
	 * the process).
	 *
	 * @return CoCart_Authentication|null
	 */
	private function get_authentication_instance() {
		global $wp_filter;

		if ( empty( $wp_filter['rest_authentication_errors'] ) ) {
			return null;
		}

		foreach ( $wp_filter['rest_authentication_errors']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];

				if ( is_array( $function ) && $function[0] instanceof CoCart_Authentication ) {
					return $function[0];
				}
			}
		}

		return null;
	}

	/**
	 * Find a sent header's value by name from a spy server's recorded list.
	 *
	 * @param array  $sent_headers Array of [key, value] pairs.
	 * @param string $name         Header name to find.
	 *
	 * @return string|null
	 */
	private function find_header( array $sent_headers, string $name ) {
		foreach ( $sent_headers as $header ) {
			if ( $header[0] === $name ) {
				return $header[1];
			}
		}

		return null;
	}

	/**
	 * With CORS enabled and a specific allowed origin configured, a request
	 * from a *different* origin should not be reflected back with
	 * credentials enabled — otherwise the "Allowed Origin" setting does not
	 * actually restrict anything, and any website can make a credentialed
	 * cross-origin request to CoCart's API.
	 *
	 * @return void
	 */
	public function test_unconfigured_origin_is_not_granted_credentialed_cors_access() {
		update_option(
			'cocart_settings',
			array(
				'cors' => array(
					'enable_cors'    => 'yes',
					'allowed_origin' => 'https://storefront.example',
				),
			)
		);

		$_SERVER['HTTP_ORIGIN'] = 'https://evil.example';

		$auth   = $this->get_authentication_instance();
		$server = new CoCart_Test_Spy_REST_Server();
		$request = new WP_REST_Request( 'GET', '/cocart/v2/cart' );

		$this->assertNotNull( $auth, 'Could not locate the shared CoCart_Authentication instance.' );

		$auth->cors_headers( false, null, $request, $server );

		$allow_origin      = $this->find_header( $server->sent_headers, 'Access-Control-Allow-Origin' );
		$allow_credentials = $this->find_header( $server->sent_headers, 'Access-Control-Allow-Credentials' );

		$this->assertNotSame(
			'https://evil.example',
			$allow_origin,
			'An origin other than the one configured in cocart_settings[cors][allowed_origin] must not be reflected back in Access-Control-Allow-Origin.'
		);
		$this->assertNotSame(
			'true',
			$allow_credentials,
			'An origin other than the configured allowed origin must not be granted Access-Control-Allow-Credentials.'
		);
	}

	/**
	 * The configured allowed origin itself must still work — this is a
	 * regression guard so a fix for the test above can't just deny
	 * everything.
	 *
	 * @return void
	 */
	public function test_configured_origin_is_granted_credentialed_cors_access() {
		update_option(
			'cocart_settings',
			array(
				'cors' => array(
					'enable_cors'    => 'yes',
					'allowed_origin' => 'https://storefront.example',
				),
			)
		);

		$_SERVER['HTTP_ORIGIN'] = 'https://storefront.example';

		$auth    = $this->get_authentication_instance();
		$server  = new CoCart_Test_Spy_REST_Server();
		$request = new WP_REST_Request( 'GET', '/cocart/v2/cart' );

		$auth->cors_headers( false, null, $request, $server );

		$allow_origin      = $this->find_header( $server->sent_headers, 'Access-Control-Allow-Origin' );
		$allow_credentials = $this->find_header( $server->sent_headers, 'Access-Control-Allow-Credentials' );

		$this->assertSame( 'https://storefront.example', $allow_origin, 'The configured allowed origin must still be granted CORS access.' );
		$this->assertSame( 'true', $allow_credentials, 'The configured allowed origin must still be granted credentialed access.' );
	}

	/**
	 * When CORS is enabled but no specific origin has been configured, a
	 * cross-origin request should still be able to read the response (this
	 * plugin's REST API is open by default, matching WordPress core's own
	 * baseline) — but must never be granted credentials, since there is no
	 * origin on file to restrict that to.
	 *
	 * @return void
	 */
	public function test_unconfigured_allowed_origin_setting_allows_reads_but_never_credentials() {
		update_option(
			'cocart_settings',
			array(
				'cors' => array(
					'enable_cors'    => 'yes',
					'allowed_origin' => '',
				),
			)
		);

		$_SERVER['HTTP_ORIGIN'] = 'https://any-origin.example';

		$auth    = $this->get_authentication_instance();
		$server  = new CoCart_Test_Spy_REST_Server();
		$request = new WP_REST_Request( 'GET', '/cocart/v2/cart' );

		$auth->cors_headers( false, null, $request, $server );

		$allow_origin      = $this->find_header( $server->sent_headers, 'Access-Control-Allow-Origin' );
		$allow_credentials = $this->find_header( $server->sent_headers, 'Access-Control-Allow-Credentials' );

		$this->assertSame( 'https://any-origin.example', $allow_origin, 'With no allowed origin configured, cross-origin reads should still work.' );
		$this->assertSame( 'false', $allow_credentials, 'With no allowed origin configured, credentials must never be granted to any origin.' );
	}
}
