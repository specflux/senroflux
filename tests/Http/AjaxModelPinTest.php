<?php
/**
 * Ajax::handleStart() tests for the optional model_provider/model_id
 * fields: a half-specified pair is refused as a bad request before start()
 * ever runs, and an omitted pair starts an automatic run.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Http;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Http\Ajax;
use Specflux\SenroFlux\Plugin;
use SenroFluxJsonResponse;
use wpdb;

final class AjaxModelPinTest extends TestCase {

	protected function setUp(): void {
		Plugin::reset();
		Plugin::set_dependency_probe( true );
		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$GLOBALS['senroflux_test_user_caps']       = array( 'read' => true );
		$GLOBALS['senroflux_test_filters']         = array();
		$GLOBALS['wpdb']                           = new wpdb();
		unset( $_POST );

		add_filter(
			'senroflux_http_consumers',
			static fn (): array => array(
				'specflux-mac' => array( 'allow' => array( 'senroflux/read-content' ) ),
			)
		);
	}

	protected function tearDown(): void {
		remove_all_filters( 'senroflux_http_consumers' );
		unset( $_POST, $GLOBALS['wpdb'] );
		Plugin::reset();
	}

	private function post( array $fields ): SenroFluxJsonResponse {
		$_POST = array_merge(
			array(
				'consumer' => 'specflux-mac',
				'goal'     => 'Write a post',
			),
			$fields
		);

		try {
			( new Ajax() )->handleStart();
		} catch ( SenroFluxJsonResponse $json ) {
			return $json;
		}

		$this->fail( 'handleStart() did not send a JSON response.' );
	}

	public function test_a_provider_with_no_model_id_is_refused(): void {
		$json = $this->post( array( 'model_provider' => 'openai' ) );

		$this->assertFalse( $json->success );
		$this->assertSame( 400, $json->status );
		$this->assertSame( 'senroflux_bad_request', $json->code() );
	}

	public function test_a_model_id_with_no_provider_is_refused(): void {
		$json = $this->post( array( 'model_id' => 'gpt-4o' ) );

		$this->assertFalse( $json->success );
		$this->assertSame( 400, $json->status );
		$this->assertSame( 'senroflux_bad_request', $json->code() );
	}

	public function test_neither_field_starts_an_automatic_run(): void {
		$json = $this->post( array() );

		$this->assertTrue( $json->success );
		$this->assertNull( $json->data['run']['model'] );
	}
}
