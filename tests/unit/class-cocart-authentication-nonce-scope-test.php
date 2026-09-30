<?php
/**
 * Test CoCart Authentication REST Cookie Nonce Scoping
 *
 * Regression tests for a High severity vulnerability (reported by Naoki
 * Kawahigashi) present from 4.9.0: CoCart_Authentication::
 * check_authentication_error() was hooked on WordPress core's
 * `rest_authentication_errors` filter and answered `true` for every REST
 * request. Because core's own `rest_cookie_check_errors()` (which enforces
 * the REST nonce/CSRF check for cookie-authenticated requests) is hooked on
 * the same filter at a later priority and starts with
 * `if ( ! empty( $result ) ) { return $result; }`, CoCart's early `true`
 * caused core to skip the nonce check entirely — letting a logged-in
 * administrator's session cookie alone create a new administrator account
 * via `/wp/v2/users`, with no nonce, deliverable as a plain link.
 *
 * check_authentication_error() only answers "no error" for a request
 * CoCart::authenticate() itself has authenticated — tracked by
 * `$authenticated_by_cocart`, set only when a real user is established via
 * Basic Auth or a third-party `cocart_authenticate` hook (e.g. JWT). The
 * request URL is never consulted, since it is attacker-controlled: a
 * route-pattern check could otherwise be satisfied by appending an
 * unrelated query value that merely contains the pattern (e.g.
 * `?x=/wp-json/cocart/v2/cart`), or by routing through the core batch
 * endpoint (`/wp-json/batch/v1`), which CoCart deliberately recognizes for
 * session-sharing purposes regardless of what it batches together.
 *
 * @package CoCart\Tests\Unit
 */

/**
 * Test CoCart Authentication REST Cookie Nonce Scoping Class.
 *
 * @since 4.9.7 Introduced.
 * @package CoCart\Tests\Unit
 */
class Test_CoCart_Authentication_Nonce_Scope extends CoCart_REST_Test_Case {

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
			'REQUEST_URI'         => $_SERVER['REQUEST_URI'] ?? null,
			'REQUEST_METHOD'      => $_SERVER['REQUEST_METHOD'] ?? null,
			'HTTP_X_WP_NONCE'     => $_SERVER['HTTP_X_WP_NONCE'] ?? null,
			'HTTPS'               => $_SERVER['HTTPS'] ?? null,
			'PHP_AUTH_USER'       => $_SERVER['PHP_AUTH_USER'] ?? null,
			'PHP_AUTH_PW'         => $_SERVER['PHP_AUTH_PW'] ?? null,
			'GET'                 => $_GET,
			'REQUEST'             => $_REQUEST,
			'wp_rest_auth_cookie' => $GLOBALS['wp_rest_auth_cookie'] ?? null,
		);
	}

	/**
	 * Restore superglobal/global state after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		foreach ( array( 'REQUEST_URI', 'REQUEST_METHOD', 'HTTP_X_WP_NONCE', 'HTTPS', 'PHP_AUTH_USER', 'PHP_AUTH_PW' ) as $key ) {
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

		remove_filter( 'rest_url_prefix', array( $this, 'custom_rest_url_prefix' ) );

		// The plugin creates exactly one CoCart_Authentication instance for
		// the life of the process, so state set on it (by real
		// authentication attempts, or forced directly in these tests) would
		// otherwise leak between test methods.
		$this->reset_authentication_state();

		wp_set_current_user( 0 );

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
	 * Force a protected property on the shared CoCart_Authentication
	 * instance. Used both to reset state between tests and to simulate the
	 * outcome of a successful CoCart authentication without needing to
	 * construct real Basic Auth headers for every test.
	 *
	 * @param string $property Property name.
	 * @param mixed  $value    Value to set.
	 *
	 * @return void
	 */
	private function set_authentication_property( $property, $value ) {
		$instance = $this->get_authentication_instance();

		if ( ! $instance || ! property_exists( $instance, $property ) ) {
			return;
		}

		$ref = new ReflectionProperty( CoCart_Authentication::class, $property );
		$ref->setAccessible( true );
		$ref->setValue( $instance, $value );
	}

	/**
	 * Reset every property check_authentication_error()/authenticate() can
	 * mutate, so a real authentication attempt in one test can't leak into
	 * the next.
	 *
	 * @return void
	 */
	private function reset_authentication_state() {
		$this->set_authentication_property( 'authenticated_by_cocart', false );
		$this->set_authentication_property( 'error', null );
		$this->set_authentication_property( 'user', null );
		$this->set_authentication_property( 'auth_method', '' );
	}

	/**
	 * Simulate a logged-in administrator whose only credential is a valid
	 * WordPress auth cookie (i.e. `$wp_rest_auth_cookie` is true, as core's
	 * `rest_cookie_collect_status()` sets it after `auth_cookie_valid`
	 * fires), with no REST nonce supplied anywhere on the request, and no
	 * CoCart credentials of any kind.
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
	 * Replicates the auth-then-dispatch sequence from
	 * WP_REST_Server::serve_request() without its header-sending/output
	 * side effects, so a test can assert on the resulting WP_REST_Response
	 * exactly as a real HTTP client would receive it — status code included.
	 *
	 * @param WP_REST_Request $request Request to authenticate and dispatch.
	 *
	 * @return WP_REST_Response
	 */
	private function dispatch_with_full_authentication( WP_REST_Request $request ) {
		$auth_result = apply_filters( 'rest_authentication_errors', null );

		if ( is_wp_error( $auth_result ) ) {
			return rest_ensure_response( $auth_result );
		}

		return rest_ensure_response( $this->server->dispatch( $request ) );
	}

	/**
	 * rest_url_prefix filter callback used by the custom-prefix tests below.
	 *
	 * @return string
	 */
	public function custom_rest_url_prefix() {
		return 'api';
	}

	// -------------------------------------------------------------------------
	// The two exploit lanes: a direct request, and a plain link using a
	// method override. Neither ever supplies CoCart credentials, so
	// $authenticated_by_cocart stays false and the request is treated as
	// anonymous.
	// -------------------------------------------------------------------------

	/**
	 * Lane 1 — direct request.
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
	 * End-to-end lane 1 — the actual exploit request, routed all the way
	 * through to core's users endpoint, must be rejected and must not
	 * create an account.
	 *
	 * @return void
	 */
	public function test_direct_post_to_users_endpoint_does_not_create_admin_without_nonce() {
		$this->simulate_cookie_authenticated_admin_with_no_nonce();

		$_SERVER['REQUEST_URI']    = '/wp-json/wp/v2/users';
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$request = new WP_REST_Request( 'POST', '/wp/v2/users' );
		$request->set_param( 'username', 'nonce_bypass_e2e_1' );
		$request->set_param( 'email', 'nonce-bypass-e2e-1@example.invalid' );
		$request->set_param( 'password', 'Sup3r-Secret-Password!1' );
		$request->set_param( 'roles', array( 'administrator' ) );

		$response = $this->dispatch_with_full_authentication( $request );

		$this->assertSame( 401, $response->get_status(), 'A direct request carrying only a session cookie and no nonce must be rejected by the users endpoint.' );
		$this->assertFalse( username_exists( 'nonce_bypass_e2e_1' ), 'No account should have been created.' );
	}

	/**
	 * End-to-end lane 2 — the plain-link/method-override exploit, routed all
	 * the way through to core's users endpoint, must also be rejected.
	 *
	 * @return void
	 */
	public function test_get_link_with_method_override_does_not_create_admin_without_nonce() {
		$this->simulate_cookie_authenticated_admin_with_no_nonce();

		$_SERVER['REQUEST_URI']    = '/wp-json/wp/v2/users?_method=POST&username=nonce_bypass_e2e_2&email=nonce-bypass-e2e-2%40example.invalid&password=hunter2&roles%5B%5D=administrator';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET['_method']           = 'POST';

		$request = new WP_REST_Request( 'GET', '/wp/v2/users' );
		$request->set_method( 'POST' );
		$request->set_param( 'username', 'nonce_bypass_e2e_2' );
		$request->set_param( 'email', 'nonce-bypass-e2e-2@example.invalid' );
		$request->set_param( 'password', 'Sup3r-Secret-Password!2' );
		$request->set_param( 'roles', array( 'administrator' ) );

		$response = $this->dispatch_with_full_authentication( $request );

		$this->assertSame( 401, $response->get_status(), 'A GET request using a method override must be rejected by the users endpoint.' );
		$this->assertFalse( username_exists( 'nonce_bypass_e2e_2' ), 'No account should have been created.' );
	}

	// -------------------------------------------------------------------------
// Regression tests for the two bypass techniques Naoki reported — both	// exploit reading attacker-controlled request data to fake CoCart route recognition.
	// -------------------------------------------------------------------------

	/**
	 * A foreign route can no longer borrow CoCart's route recognition by
	 * embedding the route pattern in an unrelated query value — the URL is
	 * never consulted at all by the corrected check.
	 *
	 * @return void
	 */
	public function test_uri_smuggling_of_cocart_route_pattern_does_not_bypass_nonce_check() {
		$this->simulate_cookie_authenticated_admin_with_no_nonce();

		$_SERVER['REQUEST_URI']    = '/wp-json/wp/v2/users?_method=POST&x=/wp-json/cocart/v2/cart&username=nonce_bypass_smuggle&email=nonce-bypass-smuggle%40example.invalid&password=hunter2&roles%5B%5D=administrator';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET['_method']           = 'POST';
		$_GET['x']                 = '/wp-json/cocart/v2/cart';

		$result = apply_filters( 'rest_authentication_errors', null );

		$this->assertTrue( $result, 'Core cookie check should still run and report success (as anonymous).' );
		$this->assertSame(
			0,
			get_current_user_id(),
			'A query value that merely contains the CoCart route pattern must not be mistaken for a genuine CoCart request.'
		);

		$request = new WP_REST_Request( 'GET', '/wp/v2/users' );
		$request->set_method( 'POST' );
		$request->set_param( 'username', 'nonce_bypass_smuggle' );
		$request->set_param( 'email', 'nonce-bypass-smuggle@example.invalid' );
		$request->set_param( 'password', 'Sup3r-Secret-Password!3' );
		$request->set_param( 'roles', array( 'administrator' ) );

		$response = $this->dispatch_with_full_authentication( $request );

		$this->assertSame( 401, $response->get_status(), 'The smuggled route pattern must not let this request through to create an account.' );
		$this->assertFalse( username_exists( 'nonce_bypass_smuggle' ), 'No account should have been created.' );
	}

	/**
	 * The core batch endpoint is deliberately matched by
	 * `CoCart::is_rest_api_request()` so CoCart's session context applies to
	 * batched sub-requests. A batch request must not be treated as
	 * CoCart-authenticated merely because of that, regardless of what it
	 * batches together, and independently of the batch endpoint's own
	 * per-route `allow_batch` gating.
	 *
	 * @return void
	 */
	public function test_batch_endpoint_request_does_not_bypass_nonce_check() {
		$this->simulate_cookie_authenticated_admin_with_no_nonce();

		$_SERVER['REQUEST_URI']    = '/wp-json/batch/v1';
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$result = apply_filters( 'rest_authentication_errors', null );

		$this->assertTrue( $result, 'Core cookie check should still run and report success (as anonymous).' );
		$this->assertSame(
			0,
			get_current_user_id(),
			'A request to the batch endpoint must not be treated as authenticated by CoCart merely because CoCart recognizes the route for session-sharing purposes.'
		);
	}

	/**
	 * End-to-end batch variant — the same exploit delivered as a
	 * `/wp-json/batch/v1` request batching a `/wp/v2/users` create call,
	 * routed all the way through core's real batch dispatcher
	 * (`WP_REST_Server::serve_batch_request_v1()`, which core registers
	 * `/wp/v2/users` with `allow_batch => ['v1' => true]`). The outer batch
	 * request is authenticated once; each batched sub-request is then
	 * dispatched directly against whatever the current user already is —
	 * so if the outer request were wrongly treated as CoCart-authenticated,
	 * every sub-request would inherit that. Must be rejected and must not
	 * create an account.
	 *
	 * @return void
	 */
	public function test_batch_endpoint_does_not_create_admin_without_nonce() {
		$this->simulate_cookie_authenticated_admin_with_no_nonce();

		$_SERVER['REQUEST_URI']    = '/wp-json/batch/v1';
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$request = new WP_REST_Request( 'POST', '/batch/v1' );
		$request->set_body_params(
			array(
				'validation' => 'normal',
				'requests'   => array(
					array(
						'method' => 'POST',
						'path'   => '/wp/v2/users',
						'body'   => array(
							'username' => 'nonce_bypass_batch',
							'email'    => 'nonce-bypass-batch@example.invalid',
							'password' => 'Sup3r-Secret-Password!4',
							'roles'    => array( 'administrator' ),
						),
					),
				),
			)
		);

		$response = $this->dispatch_with_full_authentication( $request );
		$data     = $response->get_data();

		$this->assertFalse( username_exists( 'nonce_bypass_batch' ), 'No account should have been created via the batched sub-request.' );

		if ( isset( $data['responses'][0]['status'] ) ) {
			$this->assertSame( 401, $data['responses'][0]['status'], 'The batched sub-request must be rejected as unauthenticated, not used to create an account.' );
		} else {
			// The outer batch call itself was rejected outright (e.g. anonymous
			// users may be disallowed from the batch endpoint entirely) —
			// also an acceptable outcome, since either way no account is created.
			$this->assertSame( 401, $response->get_status(), 'If the batch request is not routed to sub-requests, the outer call must itself be rejected as unauthenticated.' );
		}
	}

	// -------------------------------------------------------------------------
	// The corrected model: CoCart's own routes get no special treatment
	// unless CoCart itself actually authenticated the request.
	// -------------------------------------------------------------------------

	/**
	 * CoCart does not support cookie/nonce authentication for its API — only
	 * JWT and Basic Auth. A request that merely targets a CoCart route, with
	 * no CoCart credentials of its own, must be treated exactly like any
	 * other unauthenticated REST route.
	 *
	 * @return void
	 */
	public function test_cocart_route_without_cocart_authentication_is_also_treated_as_anonymous() {
		$this->simulate_cookie_authenticated_admin_with_no_nonce();

		$_SERVER['REQUEST_URI']    = '/wp-json/cocart/v2/cart';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$result = apply_filters( 'rest_authentication_errors', null );

		$this->assertTrue( $result, 'Core cookie check should still run and report success (as anonymous).' );
		$this->assertSame(
			0,
			get_current_user_id(),
			'A CoCart route with no CoCart credentials must not be exempted from the nonce check just because of its URL.'
		);
	}

	/**
	 * The positive case: once CoCart has genuinely authenticated a request
	 * (Basic Auth, or a third party via `cocart_authenticate` such as JWT),
	 * the nonce requirement is correctly bypassed, and core must not be
	 * allowed to reset the current user CoCart just established.
	 *
	 * @return void
	 */
	public function test_cocart_route_with_cocart_authentication_bypasses_nonce_requirement() {
		$admin_id = $this->simulate_cookie_authenticated_admin_with_no_nonce();

		// Simulate CoCart itself having authenticated this request.
		$this->set_authentication_property( 'authenticated_by_cocart', true );

		$_SERVER['REQUEST_URI']    = '/wp-json/cocart/v2/cart';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$result = apply_filters( 'rest_authentication_errors', null );

		$this->assertTrue( $result, 'CoCart must succeed authentication once it has genuinely authenticated the request.' );
		$this->assertSame(
			$admin_id,
			get_current_user_id(),
			'Once CoCart has genuinely authenticated a request, core must not be allowed to reset the current user.'
		);
	}

	// -------------------------------------------------------------------------
	// rest_url_prefix customization — the historical context this bug was
	// introduced alongside. check_authentication_error() never reads the
	// prefix, so foreign-route protection is trivially prefix-independent;
	// authenticate() itself still reads it (to decide whether to attempt
	// authentication at all), so that path is verified directly with a
	// real Basic Auth request.
	// -------------------------------------------------------------------------

	/**
	 * Foreign-route protection must hold under a customized REST URL prefix.
	 *
	 * @return void
	 */
	public function test_foreign_route_protection_holds_with_custom_rest_url_prefix() {
		add_filter( 'rest_url_prefix', array( $this, 'custom_rest_url_prefix' ) );

		$this->simulate_cookie_authenticated_admin_with_no_nonce();

		$_SERVER['REQUEST_URI']    = '/api/wp/v2/users';
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$result = apply_filters( 'rest_authentication_errors', null );

		$this->assertTrue( $result, 'Core cookie check should still run and report success (as anonymous).' );
		$this->assertSame(
			0,
			get_current_user_id(),
			'Foreign-route protection must hold under a customized rest_url_prefix, exactly as it does for the default "wp-json" prefix.'
		);
	}

	/**
	 * A customized rest_url_prefix must not prevent authenticate() from
	 * recognizing a genuine CoCart request and completing real Basic Auth —
	 * and once it does, check_authentication_error() must bypass the nonce
	 * requirement exactly as it would under the default prefix.
	 *
	 * @return void
	 */
	public function test_legitimate_basic_auth_under_custom_rest_url_prefix_bypasses_nonce_check() {
		add_filter( 'rest_url_prefix', array( $this, 'custom_rest_url_prefix' ) );

		$password = 'Correct-Horse-Battery-Staple-1!';
		$user_id  = $this->factory->user->create( array( 'role' => 'customer' ) );
		wp_set_password( $password, $user_id );
		$user = get_user_by( 'id', $user_id );

		$_SERVER['REQUEST_URI']    = '/api/cocart/v2/cart';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['HTTPS']          = 'on'; // perform_basic_authentication() only runs over is_ssl() or a local environment.
		$_SERVER['PHP_AUTH_USER']  = $user->user_login;
		$_SERVER['PHP_AUTH_PW']    = $password;

		// Invokes the real authenticate() on the plugin's shared instance,
		// exactly as WordPress does via determine_current_user.
		$authenticated_user_id = apply_filters( 'determine_current_user', false );

		$this->assertSame(
			$user_id,
			$authenticated_user_id,
			'A customized rest_url_prefix must not prevent authenticate() from recognizing a genuine CoCart request and completing Basic Auth.'
		);

		wp_set_current_user( $authenticated_user_id );

		$result = apply_filters( 'rest_authentication_errors', null );

		$this->assertTrue( $result, 'A genuinely CoCart-authenticated request must not require a nonce.' );
		$this->assertSame( $user_id, get_current_user_id(), 'The Basic Auth-authenticated user must remain the current user.' );
	}
}
