<?php
/**
 * Test CoCart Session Handler Route Recognition Scope
 *
 * Follow-up investigation from the REST auth error scoping fix:
 * CoCart::is_rest_api_request() is used well beyond authentication — the
 * session handler uses it to decide whether to treat the current request as
 * a decoupled CoCart cart session (identified by a client-supplied
 * `cart_key`) or a normal cookie-based WooCommerce session. Since that
 * signal is based on `$_SERVER['REQUEST_URI']` / `$_GET['rest_route']`, it
 * is attacker-controlled in exactly the same way it was for authentication —
 * a query value that merely contains CoCart's route pattern can fool it on
 * any page.
 *
 * This suite establishes what actually happens when that signal is fooled
 * on an ordinary (non-CoCart) request: does it let one customer load
 * another customer's cart? The cart-key ownership check added in 4.9.5
 * (CoCart_Session_Handler::is_requesting_unauthorized_cart()) runs
 * independently of route recognition, so it should hold regardless.
 *
 * @package CoCart\Tests\Unit
 */

/**
 * Test CoCart Session Handler Route Recognition Scope Class.
 *
 * @package CoCart\Tests\Unit
 */
class Test_CoCart_Session_Handler_Route_Scope extends CoCart_Unit_Test_Case {

	/**
	 * Original superglobal state, restored in tearDown().
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
			'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null,
			'cart_key'    => $_REQUEST['cart_key'] ?? null,
		);
	}

	/**
	 * Restore state after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		if ( null === $this->original_state['REQUEST_URI'] ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->original_state['REQUEST_URI'];
		}

		if ( null === $this->original_state['cart_key'] ) {
			unset( $_REQUEST['cart_key'] );
		} else {
			$_REQUEST['cart_key'] = $this->original_state['cart_key'];
		}

		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Sanity check: confirm the smuggled URL really does fool
	 * CoCart::is_rest_api_request() on a route that has nothing to do with
	 * CoCart, exactly as it did for the authentication check before that
	 * was fixed. If this ever stops being true, the test below stops
	 * proving anything.
	 *
	 * @return void
	 */
	public function test_smuggled_url_still_fools_is_rest_api_request() {
		$_SERVER['REQUEST_URI'] = '/some/other/page?x=/wp-json/cocart/v2/cart';

		$this->assertTrue(
			CoCart::is_rest_api_request(),
			'This test suite assumes is_rest_api_request() can still be fooled by a smuggled query value — if not, the scenario below no longer applies.'
		);
	}

	/**
	 * Even when route recognition is fooled into treating an ordinary page
	 * load as a CoCart request, the session handler must not let one
	 * customer's request resolve to another customer's cart by supplying
	 * their numeric user ID as `cart_key`.
	 *
	 * @return void
	 */
	public function test_cart_key_ownership_check_holds_even_when_route_recognition_is_spoofed() {
		$_SERVER['REQUEST_URI'] = '/some/other/page?x=/wp-json/cocart/v2/cart';

		$victim_id   = $this->factory->user->create( array( 'role' => 'customer' ) );
		$attacker_id = $this->factory->user->create( array( 'role' => 'customer' ) );

		wp_set_current_user( $attacker_id );
		$_REQUEST['cart_key'] = (string) $victim_id;

		$this->assertTrue( CoCart::is_rest_api_request(), 'Sanity check failed — see test_smuggled_url_still_fools_is_rest_api_request().' );

		$session_handler = new CoCart_Session_Handler();
		$session_handler->init();

		$this->assertNotEquals(
			(string) $victim_id,
			$session_handler->get_customer_id(),
			"Even when is_rest_api_request() is fooled into returning true, the cart-key ownership check must still prevent one customer from loading another's cart."
		);
	}

	/**
	 * Regression guard: a customer requesting their own cart_key (or none
	 * at all) must still resolve to their own cart, even under the spoofed
	 * signal — the fix for the scenario above must not break the normal
	 * case.
	 *
	 * @return void
	 */
	public function test_customer_still_resolves_their_own_cart_when_route_recognition_is_spoofed() {
		$_SERVER['REQUEST_URI'] = '/some/other/page?x=/wp-json/cocart/v2/cart';

		$customer_id = $this->factory->user->create( array( 'role' => 'customer' ) );
		wp_set_current_user( $customer_id );

		$session_handler = new CoCart_Session_Handler();
		$session_handler->init();

		$this->assertEquals(
			(string) $customer_id,
			$session_handler->get_customer_id(),
			"A customer's own session must still resolve correctly regardless of how route recognition classified the request."
		);
	}
}
