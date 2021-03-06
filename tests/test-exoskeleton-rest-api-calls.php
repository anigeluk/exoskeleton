<?php
/**
 * Class ExoskeletonTest
 *
 * @package Exoskeleton
 */

/**
 * Sample test case.
 */
class ExoskeletonRestApiCallsTest extends WP_UnitTestCase {


	/**
	 * Pre test setup
	 */
	function setUp() {
		parent::setUp();

		add_action( 'rest_api_init', function () {
			register_rest_route( 'exoskeleton/v1', '/exoskeleton/(?P<id>\d+)', array(
				'methods' => 'GET',
				'callback' => [ $this, 'custom_route_callback' ],
			) );
		} );
	}


	/**
	 * Post test cleanup
	 */
	function tearDown() {
		parent::tearDown();
		// Clear up any added rules
		$instance = Exoskeleton::get_instance();
		$instance->rules = [];
	}

	/**
	 * Test key generation
	 *
	 * @dataProvider limitTestingProvider
	 * @param array $rule exoskeleton rule array.
	 * @param array $test extra test data.
	 */
	public function test_key_generation( $rule, $test ) {
		exoskeleton_add_rule( $rule );
		$exoskeleton = Exoskeleton::get_instance();
		$this->assertEquals( $test['key'], key( $exoskeleton->rules ) );
	}

	/**
	 * Simple method to provide a callback for custom routes during testing
	 *
	 * @param mixed $data data passed into the request for this route.
	 * @return mixed returns the data that was input to provide a valid output.
	 */
	public function custom_route_callback( $data ) {
		return new WP_REST_Response( $data, 200 );
	}


	/**
	 * Check limits are honoured if a route is registered with exoskeleton limits.
	 */
	function test_pre_registered_custom_route_rules() {
		global $wp_rest_server;
		add_action( 'rest_api_init', function () {
			register_rest_route( 'exoskeleton/v1', '/test_pre_registered_custom_route_rules/(?P<id>\d+)', array(
				'methods' => 'GET',
				'callback' => [ $this, 'custom_route_callback' ],
				'exoskeleton' => [
				  'window' => 10,
				  'method' => 'GET',
				  'limit' => 5,
				  'lockout' => 20,
				],
			), true );
		} );
		do_action( 'rest_api_init', $wp_rest_server );

		for ( $request = 1; $request <= 6; ++$request ) {
			if ( $request < 5 ) {
				$response = rest_do_request( new WP_REST_Request( 'GET', '/exoskeleton/v1/test_pre_registered_custom_route_rules/1' ) );
				$this->assertEquals( 200, $response->status );
			} else {
				$this->expectException( Exception::class );
				$this->expectExceptionMessage( 'locked' );
				$response = rest_do_request( new WP_REST_Request( 'GET', '/exoskeleton/v1/test_pre_registered_custom_route_rules/1' ) );
			}
		}
	}


	/**
	 * Check that requests do get properly limited
	 *
	 * @dataProvider limitTestingProvider
	 * @param array $rule exoskeleton rule array.
	 * @param array $test extra test data.
	 */
	public function test_limits_are_applied( $rule, $test ) {
		if ( empty( $test['method'] ) ) {
			$test['method'] = $rule['method'];
		}
		$this->assertTrue( exoskeleton_add_rule( $rule ) );
		for ( $request = 1; $request <= $rule['limit'] + 1; ++$request ) {
			if ( $request < $rule['limit'] ) {
				$response = rest_do_request( new WP_REST_Request( $test['method'], $rule['route'] ) );
				$this->assertEquals( 200, $response->status );
			} else {
				$this->expectException( Exception::class );
				$this->expectExceptionMessage( 'locked' );
				$response = rest_do_request( new WP_REST_Request( $test['method'], $rule['route'] ) );
			}
		}
	}


	/**
	 * Make sure that requests falling outside the window do not get limited.
	 *
	 * @dataProvider limitTestingProvider
	 * @param array $rule exoskeleton rule array.
	 * @param array $test extra test data.
	 */
	public function test_limit_window_expiry( $rule, $test ) {
		if ( empty( $test['method'] ) ) {
			$test['method'] = $rule['method'];
		}
		$this->assertTrue( exoskeleton_add_rule( $rule ) );
		for ( $request = 1; $request <= $rule['limit'] + 1; ++$request ) {
			if ( $request < $rule['limit'] ) {
				$response = rest_do_request( new WP_REST_Request( $test['method'], $rule['route'] ) );
				$this->assertEquals( 200, $response->status );
			} else {
				sleep( $rule['window'] + 1 );
				$response = rest_do_request( new WP_REST_Request( $test['method'], $rule['route'] ) );
				$this->assertEquals( 200, $response->status );
			}
		}
	}

	/**
	 * Limits should only be applied if the correct method is requested
	 *
	 * @dataProvider limitTestingProvider
	 * @param array $rule exoskeleton rule array.
	 * @param array $test extra test data.
	 */
	public function test_different_methods_not_limited( $rule, $test ) {
		// Change the test or rule method for testing.
		if ( empty( $test['method'] ) ) {
			$test['method'] = 'HEAD';
		} else {
			$rule['method'] = 'POST';
		}
		$this->assertTrue( exoskeleton_add_rule( $rule ) );
		for ( $request = 1; $request <= $rule['limit'] + 1; ++$request ) {
			$response = rest_do_request( new WP_REST_Request( $test['method'], $rule['route'] ) );
			$this->assertEquals( 200, $response->status );
		}
	}

	/**
	 * Limits should apply across methods if 'any' is the rule method
	 *
	 * @dataProvider limitTestingProvider
	 * @param array $rule exoskeleton rule array.
	 * @param array $test extra test data.
	 */
	public function test_limits_apply_across_methods_for_any( $rule, $test ) {
		// make sure the created rule applies to any method
		$rule['method'] = 'any';
		if ( empty( $test['method'] ) ) {
			$test['method'] = 'HEAD';
		}
		$this->assertTrue( exoskeleton_add_rule( $rule ) );
		for ( $request = 1; $request <= $rule['limit'] + 1; ++$request ) {
			if ( $request < $rule['limit'] ) {
				// Initially test up to limit with one method
				$response = rest_do_request( new WP_REST_Request( $test['method'], $rule['route'] ) );
				$this->assertEquals( 200, $response->status );
			} else {
				// Now call a different method and ensure it is limited
				$test['method'] = 'post';
				$this->expectException( Exception::class );
				$this->expectExceptionMessage( 'locked' );
				$response = rest_do_request( new WP_REST_Request( $test['method'], $rule['route'] ) );
			}
		}
	}


	/**
	 * Limits should apply across methods if multiple methods are specified
	 *
	 * @dataProvider limitTestingProvider
	 * @param array $rule exoskeleton rule array.
	 * @param array $test extra test data.
	 */
	public function test_limits_apply_across_methods_for_multi_method_rules( $rule, $test ) {
		// make sure the created rule applies to any method
		$rule['method'] = 'HEAD,GET';
		$rule['treat_head_as_get'] = false;
		$test['method'] = 'GET';
		$this->assertTrue( exoskeleton_add_rule( $rule ) );
		for ( $request = 1; $request <= $rule['limit'] + 1; ++$request ) {
			if ( $request < ( $rule['limit'] - 1 ) ) {
				// Initially test with one method
				$response = rest_do_request( new WP_REST_Request( $test['method'], $rule['route'] ) );
				$this->assertEquals( 200, $response->status );
			} elseif ( ( $rule['limit'] - 1 ) === $request ) {
				// Test second method allowed
				$test['method'] = 'HEAD';
				$response = rest_do_request( new WP_REST_Request( $test['method'], $rule['route'] ) );
				$this->assertEquals( 200, $response->status );
			} else {
				// Now call over the limit and ensure it is limited
				$this->expectException( Exception::class );
				$this->expectExceptionMessage( 'locked' );
				$response = rest_do_request( new WP_REST_Request( $test['method'], $rule['route'] ) );
			}
		}
	}

	/**
	 * Data provider
	 *
	 * @return array exoskeleton rule information and test data.
	 */
	public function limitTestingProvider() {
		return [
			[
				'rule' => [
					'route' => '/wp/v2/posts',
					'window' => 1,
					'limit'	=> 3,
					'lockout' => 5,
					'method' => 'GET',
					'treat_head_like_get' => false,
				],
				'test' => [
					'key' => '01dd28291a6b5b95802281831ec3d6f5_GET',
				],
			],
			[
				'rule' => [
					'route' => '/wp/v2/categories',
					'window' => 2,
					'limit'	=> 6,
					'lockout' => 5,
					'method' => 'GET',
					'treat_head_like_get' => false,
				],
				'test' => [
					'key' => 'fd568c1eb104fbad04765b9f2f0100ed_GET',
				],
			],
			[
				'rule' => [
					'route' => '/wp/v2/categories',
					'window' => 2,
					'limit'	=> 10,
					'lockout' => 5,
					'method' => 'GET',
					'treat_head_like_get' => true,
				],
				'test' => [
					'method' => 'HEAD',
					'key' => '6ae5a5054b0a306061f10b9c1b193183_GET',
				],
			],
			[
				'rule' => [
					'route' => '/exoskeleton/v1/exoskeleton/10',
					'window' => 1,
					'limit'	=> 3,
					'lockout' => 5,
					'method' => 'GET',
					'treat_head_like_get' => false,
				],
				'test' => [
					'key' => 'b09a74d523deb0962e21cafaadc63679_GET',
				],
			],
		];
	}

}
