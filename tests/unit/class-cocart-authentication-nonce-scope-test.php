<?php
/**
 * Test CoCart Authentication REST Cookie Nonce Scoping
 *
 * Regression tests for a High severity vulnerability (reported by Naoki
 * Kawahigashi) present from 4.9.0 through 4.9.6: CoCart_Authentication::
 * check_authentication_error() was hooked on WordPress core's
 * `rest_authentication_errors` filter with no route check, and answered
 * `true` for every REST request. Because core's own `rest_cookie_check_errors()`
 * (which enforces the REST nonce/CSRF check for cookie-authenticated requests)
 * is hooked on the same filter at a later priority and starts with
 * `if ( ! empty( $result ) ) { return $result; }`, CoCart's early `true`
 * caused core to skip the nonce check entirely — for every REST route on the
 * site, not only CoCart's own. That let a logged-in administrator's session
 * cookie alone create a new administrator account via `/wp/v2/users`, with
 * no nonce, deliverable as a plain link.
 *
 * @package CoCart\Tests\Unit
 */

/**
 * Test CoCart Authentication REST Cookie Nonce Scoping Class.
 *
 * @since 4.9.7 Introduced.
 * @package CoCart\Tests\Unit
 */
class Test_CoCart_Authentication_Nonce_Scope extends CoCart_Unit_Test_Case {

	/**
	 * Original superglobal/global state, restored in tearDown().
	 *
	 * @var array
	 */
	private $original_state = array();

	/**
	 * Snapshot request-related state before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_state = array(
			'REQUEST_URI'      => $_SERVER['REQUEST_URI'] ?? null,
			'REQUEST_METHOD'   => $_SERVER['REQUEST_METHOD'] ?? null,
			'HTTP_X_WP_NONCE'  => $_SERVER['HTTP_X_WP_NONCE'] ?? null,
			'GET'              => $_GET,
			'REQUEST'          => $_REQUEST,
			'wp_rest_auth_cookie' => $GLOBALS['wp_rest_auth_cookie'] ?? null,
		);
	}

	/**
	 * Restore superglobal/global state after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		foreach ( array( 'REQUEST_URI', 'REQUEST_METHOD', 'HTTP_X_WP_NONCE' ) as $key ) {
			if ( null === $this->original_state[ $key ] ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $this->original_state[ $key ];
			}
		}

		$_GET     = $this->original_state['GET'];
		$_REQUEST = $this->original_state['REQUEST'];

		if ( null === $this->original_state['wp_rest_auth_cookie'] ) {
			unset( $GLOBALS['wp_rest_auth_cookie'] );
		} else {
			$GLOBALS['wp_rest_auth_cookie'] = $this->original_state['wp_rest_auth_cookie'];
		}

		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Simulate a logged-in administrator whose only credential is a valid
	 * WordPress auth cookie (i.e. `$wp_rest_auth_cookie` is true, as core's
	 * `rest_cookie_collect_status()` sets it after `auth_cookie_valid`
	 * fires), with no REST nonce supplied anywhere on the request.
	 *
	 * @return int Administrator user ID.
	 */
	private function simulate_cookie_authenticated_admin_with_no_nonce() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $admin_id );

		// Mirrors what core's rest_cookie_collect_status() sets on a real
		// auth_cookie_valid event — this request is using cookie auth.
		$GLOBALS['wp_rest_auth_cookie'] = true;

		unset( $_SERVER['HTTP_X_WP_NONCE'], $_REQUEST['_wpnonce'], $_GET['_wpnonce'] );

		return $admin_id;
	}

	/**
	 * Lane 1 — direct request.
	 *
	 * A direct POST to a foreign route (`/wp/v2/users`, not one of CoCart's)
	 * using only the administrator's session cookie and no nonce must still
	 * be treated by WordPress core as an anonymous request. Core's
	 * `rest_cookie_check_errors()` enforces this by resetting the current
	 * user to 0 when cookie auth is in play and no nonce was supplied.
	 *
	 * Before the fix, CoCart's `check_authentication_error()` answered `true`
	 * for this route too, which short-circuited core's check before it could
	 * reset the current user — leaving the administrator authenticated for a
	 * state-changing request that carried no CSRF protection at all.
	 *
	 * @return void
	 */
	public function test_direct_post_to_foreign_route_is_still_treated_as_anonymous() {
		$this->simulate_cookie_authenticated_admin_with_no_nonce();

		$_SERVER['REQUEST_URI']    = '/wp-json/wp/v2/users';
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$result = apply_filters( 'rest_authentication_errors', null );

		$this->assertTrue( $result, 'Core cookie check should still run and report success (as anonymous).' );
		$this->assertSame(
			0,
			get_current_user_id(),
			'Without a nonce, a foreign REST route must be treated as unauthenticated even though a valid session cookie is present.'
		);
	}

	/**
	 * Lane 2 — plain link with a method override.
	 *
	 * The same attack delivered as a GET request carrying `?_method=POST`
	 * (and the malicious body as query params), the form an attacker would
	 * put in a plain link since WordPress REST supports overriding the HTTP
	 * method via `_method`/`X-HTTP-Method-Override`. The authentication
	 * error filter runs before method-override handling and before routing,
	 * so it must not distinguish or make an exception based on the HTTP verb
	 * or the presence of an override — protection must key off the route
	 * only, exactly as it does in lane 1.
	 *
	 * @return void
	 */
	public function test_get_link_with_method_override_to_foreign_route_is_still_treated_as_anonymous() {
		$this->simulate_cookie_authenticated_admin_with_no_nonce();

		$_SERVER['REQUEST_URI']    = '/wp-json/wp/v2/users?_method=POST&username=newadmin&email=newadmin%40example.invalid&password=hunter2&roles%5B%5D=administrator';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET['_method']           = 'POST';

		$result = apply_filters( 'rest_authentication_errors', null );

		$this->assertTrue( $result, 'Core cookie check should still run and report success (as anonymous).' );
		$this->assertSame(
			0,
			get_current_user_id(),
			'A GET request using a method override to a foreign REST route must not retain the administrator identity without a nonce.'
		);
	}

	/**
	 * Regression guard: CoCart's own routes must continue to work without a
	 * nonce. CoCart authenticates via JWT/Basic Auth, not core's cookie
	 * nonce scheme, so the scoping fix must only exclude foreign routes —
	 * not disable CoCart's existing behaviour on its own routes.
	 *
	 * @return void
	 */
	public function test_cocart_route_is_unaffected_by_the_scoping_fix() {
		$admin_id = $this->simulate_cookie_authenticated_admin_with_no_nonce();

		$_SERVER['REQUEST_URI']    = '/wp-json/cocart/v2/cart';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$result = apply_filters( 'rest_authentication_errors', null );

		$this->assertTrue( $result, 'CoCart routes must keep succeeding authentication without requiring a nonce.' );
		$this->assertSame(
			$admin_id,
			get_current_user_id(),
			'CoCart routes must not be forced to deauthenticate the current user the way foreign routes now correctly are.'
		);
	}
}
