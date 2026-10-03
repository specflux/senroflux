<?php
/**
 * ToolExecutor tests: the permission-first seam (stage 5).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Tools;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Tools\BuiltinGate;
use Specflux\SenroFlux\Tools\ToolExecutor;
use WP_Error;
use wpdb;
use SenroFlux_Test_Fake_Ability;

final class ToolExecutorTest extends TestCase {

	private ToolExecutor $executor;

	protected function setUp(): void {
		$this->executor = new ToolExecutor();
	}

	public function test_unknown_tool_is_reported_and_never_executed(): void {
		$executed                            = false;
		$GLOBALS['senroflux_test_abilities'] = array();

		$outcome = $this->executor->call( 'agsafe-smoke/ghost', null );

		$this->assertSame( 'unknown_tool', $outcome->kind );
		$this->assertFalse( $executed );
	}

	public function test_approval_required_is_detected_from_the_gate_error_data(): void {
		$GLOBALS['senroflux_test_abilities'] = array(
			'agsafe-smoke/blocked' => new SenroFlux_Test_Fake_Ability(
				'agsafe-smoke/blocked',
				permission_result: new WP_Error(
					'approval_required',
					'requires human approval',
					array(
						'status'      => 202,
						'verb'        => 'agsafe-smoke/blocked',
						'tier'        => 2,
						'approval_id' => 'apr_123',
					)
				)
			),
		);

		$outcome = $this->executor->call( 'agsafe-smoke/blocked', array( 'target' => 'x' ) );

		$this->assertSame( 'approval_required', $outcome->kind );
		$this->assertSame( 'apr_123', $outcome->approvalId );
		$this->assertSame( 'agsafe-smoke/blocked', $outcome->verb );
		$this->assertSame( '2', $outcome->tier );
	}

	public function test_denied_path_never_calls_execute(): void {
		$executed                            = false;
		$GLOBALS['senroflux_test_abilities'] = array(
			'agsafe-smoke/denied' => new SenroFlux_Test_Fake_Ability(
				'agsafe-smoke/denied',
				permission_result: new WP_Error( 'not_in_pack', 'verb is not in the pack allow-list' ),
				execute_result: function () use ( &$executed ) {
					$executed = true;

					return array( 'ok' => true );
				},
			),
		);

		$outcome = $this->executor->call( 'agsafe-smoke/denied', null );

		$this->assertFalse( $executed, 'a denial must never reach execute()' );
		$this->assertSame( 'denied', $outcome->kind );
		$this->assertSame( 'not_in_pack', $outcome->errorCode );
		$this->assertStringContainsString( 'allow-list', (string) $outcome->errorMessage );
	}

	public function test_a_bare_false_permission_still_gives_the_model_a_reason(): void {
		$GLOBALS['senroflux_test_abilities'] = array(
			'agsafe-smoke/denied' => new SenroFlux_Test_Fake_Ability(
				'agsafe-smoke/denied',
				permission_result: false,
				execute_result: array( 'ok' => true )
			),
		);

		$outcome = $this->executor->call( 'agsafe-smoke/denied', null );

		$this->assertSame( 'denied', $outcome->kind );
		$this->assertSame( 'not_allowed', $outcome->errorCode );
		$this->assertNotSame( '', (string) $outcome->errorMessage );
	}

	/**
	 * Live evidence 2026-09-29-cards1/scenario-1-1 step 115: the model resent
	 * {@see ToolExecutor::HISTORY_PLACEHOLDER_FORMAT}'s exact text as its
	 * `sections` argument, having read it in the history as a value rather
	 * than a note about the history. Caught here, before the ability ever
	 * runs, with a message that says what actually happened.
	 */
	public function test_a_resent_history_placeholder_is_refused_before_execute(): void {
		$executed                            = false;
		$GLOBALS['senroflux_test_abilities'] = array(
			'agsafe-smoke/write' => new SenroFlux_Test_Fake_Ability(
				'agsafe-smoke/write',
				execute_result: function () use ( &$executed ) {
					$executed = true;

					return array( 'ok' => true );
				},
			),
		);

		$outcome = $this->executor->call(
			'agsafe-smoke/write',
			array(
				'sections' => sprintf( ToolExecutor::HISTORY_PLACEHOLDER_FORMAT, 3626 ),
			)
		);

		$this->assertFalse( $executed, 'a placeholder resent as content must never reach execute()' );
		$this->assertSame( 'denied', $outcome->kind );
		$this->assertSame( 'history_placeholder', $outcome->errorCode );
		$this->assertStringContainsString( 'note left behind by history compaction', (string) $outcome->errorMessage );
	}

	/**
	 * The same placeholder text nested inside an array argument (a `sections`
	 * item, not the top-level value) is caught too — the resent history
	 * placeholder always replaces a whole argument or a whole array item,
	 * never a substring, but it can land at any depth.
	 */
	public function test_a_nested_history_placeholder_is_refused_too(): void {
		$GLOBALS['senroflux_test_abilities'] = array(
			'agsafe-smoke/write' => new SenroFlux_Test_Fake_Ability( 'agsafe-smoke/write' ),
		);

		$outcome = $this->executor->call(
			'agsafe-smoke/write',
			array(
				'sections' => array(
					array( 'layout' => 'text' ),
					sprintf( ToolExecutor::HISTORY_PLACEHOLDER_FORMAT, 500 ),
				),
			)
		);

		$this->assertSame( 'denied', $outcome->kind );
		$this->assertSame( 'history_placeholder', $outcome->errorCode );
	}

	public function test_success_wraps_scalar_output_as_text(): void {
		$GLOBALS['senroflux_test_abilities'] = array(
			'tool/text' => new SenroFlux_Test_Fake_Ability( 'tool/text', execute_result: 'just a string' ),
		);

		$outcome = $this->executor->call( 'tool/text', null );

		$this->assertSame( 'result', $outcome->kind );
		$this->assertSame( array( 'text' => 'just a string' ), $outcome->output );
	}

	public function test_execution_errors_surface_as_the_error_kind(): void {
		$GLOBALS['senroflux_test_abilities'] = array(
			'tool/boom' => new SenroFlux_Test_Fake_Ability(
				'tool/boom',
				execute_result: new WP_Error( 'kaboom', 'Execution failed hard' )
			),
		);

		$outcome = $this->executor->call( 'tool/boom', null );

		$this->assertSame( 'error', $outcome->kind );
		$this->assertSame( 'Execution failed hard', $outcome->errorMessage );
	}

	public function test_results_over_the_byte_cap_are_truncated_with_a_marker(): void {
		add_filter(
			'senroflux_tool_result_max_bytes',
			static fn (): int => 64,
			10,
			1
		);

		try {
			$GLOBALS['senroflux_test_abilities'] = array(
				'tool/huge' => new SenroFlux_Test_Fake_Ability(
					'tool/huge',
					execute_result: array( 'blob' => str_repeat( 'x', 5000 ) )
				),
			);

			$outcome = $this->executor->call( 'tool/huge', null );

			$this->assertSame( 'result', $outcome->kind );
			$this->assertTrue( $outcome->output['truncated'] ?? false );
			$this->assertLessThanOrEqual( 64 + 40, strlen( (string) wp_json_encode( $outcome->output ) ), 'prefix + marker stays close to the cap' );
		} finally {
			remove_all_filters( 'senroflux_tool_result_max_bytes' );
		}
	}

	// ------------------------------------------------------------------
	// 0.3 S3: the built-in gate parks BEFORE check_permissions
	// ------------------------------------------------------------------

	public function test_an_active_unapproved_built_in_gate_parks_before_check_permissions(): void {
		$permission_checked = false;
		$executed           = false;

		$GLOBALS['senroflux_test_abilities'] = array(
			'agsafe-smoke/write' => new SenroFlux_Test_Fake_Ability(
				'agsafe-smoke/write',
				permission_result: static function () use ( &$permission_checked ) {
					$permission_checked = true;

					return true;
				},
				execute_result: static function () use ( &$executed ) {
					$executed = true;

					return array( 'ok' => true );
				}
			),
		);

		$outcome = $this->executor->call(
			'agsafe-smoke/write',
			array(),
			new BuiltinGate( active: true, tier: 1, verb: 'agsafe-smoke/write', approvalId: 'builtin:1:call_x', approved: false )
		);

		$this->assertSame( 'approval_required', $outcome->kind );
		$this->assertSame( 'builtin:1:call_x', $outcome->approvalId );
		$this->assertSame( 'agsafe-smoke/write', $outcome->verb );
		$this->assertSame( '1', $outcome->tier );
		$this->assertFalse( $permission_checked, 'the built-in gate must park BEFORE check_permissions runs' );
		$this->assertFalse( $executed );
	}

	public function test_an_unmapped_verb_gate_is_active_by_the_caller_s_own_fail_closed_tier(): void {
		// VerbTier::tierFor() already fails an unmapped verb closed to tier 2;
		// the Runner hands ToolExecutor that resolved tier, so a gate built
		// from it is active exactly like any other tier-2 call.
		$gate = new BuiltinGate( active: true, tier: 2, verb: 'agsafe-smoke/unmapped', approvalId: 'builtin:1:call_y', approved: false );

		$GLOBALS['senroflux_test_abilities'] = array(
			'agsafe-smoke/unmapped' => new SenroFlux_Test_Fake_Ability( 'agsafe-smoke/unmapped' ),
		);

		$outcome = $this->executor->call( 'agsafe-smoke/unmapped', array(), $gate );

		$this->assertSame( 'approval_required', $outcome->kind );
		$this->assertSame( '2', $outcome->tier );
	}

	public function test_a_tier_zero_gate_is_inert_and_the_call_runs_normally(): void {
		$GLOBALS['senroflux_test_abilities'] = array(
			'agsafe-smoke/read' => new SenroFlux_Test_Fake_Ability( 'agsafe-smoke/read', execute_result: array( 'ok' => true ) ),
		);

		$outcome = $this->executor->call(
			'agsafe-smoke/read',
			array(),
			new BuiltinGate( active: false, tier: 0, verb: 'agsafe-smoke/read', approvalId: '', approved: false )
		);

		$this->assertSame( 'result', $outcome->kind );
	}

	public function test_an_approved_gate_skips_the_park_and_runs_the_ability(): void {
		$GLOBALS['senroflux_test_abilities'] = array(
			'agsafe-smoke/write' => new SenroFlux_Test_Fake_Ability( 'agsafe-smoke/write', execute_result: array( 'ok' => true ) ),
		);

		$outcome = $this->executor->call(
			'agsafe-smoke/write',
			array(),
			new BuiltinGate( active: true, tier: 1, verb: 'agsafe-smoke/write', approvalId: 'builtin:1:call_x', approved: true )
		);

		$this->assertSame( 'result', $outcome->kind, 'the approved re-run must never park itself' );
	}

	public function test_a_null_gate_never_registers_a_permission_filter_and_behaves_like_as_mode(): void {
		$GLOBALS['senroflux_test_abilities'] = array(
			'agsafe-smoke/write' => new SenroFlux_Test_Fake_Ability( 'agsafe-smoke/write', execute_result: array( 'ok' => true ) ),
		);

		$outcome = $this->executor->call( 'agsafe-smoke/write', array(), null );

		$this->assertSame( 'result', $outcome->kind );
	}

	/**
	 * Live batches 2026-09-29-final/final3 scenario 4: the model sent page
	 * sections as JSON strings ("input[sections][0] is not of type object"),
	 * and each refusal cost a full page-sized turn.
	 */
	public function test_json_encoded_strings_are_decoded_where_the_schema_expects_objects_or_arrays(): void {
		$received = null;
		$GLOBALS['senroflux_test_abilities']['senroflux/publish-post'] = new SenroFlux_Test_Fake_Ability(
			'senroflux/publish-post',
			execute_result: function ( $input ) use ( &$received ) {
				$received = $input;

				return array( 'ok' => true );
			},
			input_schema: array(
				'type'       => 'object',
				'properties' => array(
					'title'    => array( 'type' => 'string' ),
					'sections' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'paragraphs' => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
			)
		);

		$this->executor->call(
			'senroflux/publish-post',
			array(
				'title'    => '{"not":"decoded, the schema says string"}',
				'sections' => array( '{"layout":"text","paragraphs":"[\"One.\",\"Two.\"]"}' ),
			)
		);

		$this->assertSame( '{"not":"decoded, the schema says string"}', $received['title'] );
		$this->assertSame(
			array(
				array(
					'layout'     => 'text',
					'paragraphs' => array( 'One.', 'Two.' ),
				),
			),
			$received['sections']
		);
	}

	/**
	 * Register a fake ability that records what reached execute() and, like
	 * the real Abilities API, refuses a value the string schema still rejects.
	 *
	 * @param array<string,mixed> $schema Input schema.
	 * @return \ArrayObject<string,mixed> Holder: 'received' input, 'executed' bool.
	 */
	private function registerRecordingAbility( array $schema ): \ArrayObject {
		$seen = new \ArrayObject(
			array(
				'received' => null,
				'executed' => false,
			)
		);

		$GLOBALS['senroflux_test_abilities']['woocommerce/product-create'] = new SenroFlux_Test_Fake_Ability(
			'woocommerce/product-create',
			execute_result: function ( $input ) use ( $seen ) {
				$seen['received'] = $input;
				$seen['executed'] = true;
				$price            = $input['regular_price'] ?? '';

				if ( is_string( $price ) && 1 !== preg_match( '/^\d+(\.\d+)?$/', $price ) ) {
					return new WP_Error( 'ability_invalid_input', 'input[regular_price] does not match pattern.' );
				}

				return array( 'ok' => true );
			},
			input_schema: $schema
		);

		return $seen;
	}

	/**
	 * Live journey J9: the model sent regular_price 18 (an integer) nine times
	 * for a string+pattern property and the run ended with nothing created.
	 */
	public function test_an_integer_sent_for_a_string_property_reaches_the_ability_as_a_string(): void {
		$seen = $this->registerRecordingAbility(
			array(
				'type'       => 'object',
				'properties' => array(
					'regular_price' => array(
						'type'    => 'string',
						'pattern' => '^\d+(\.\d+)?$',
					),
				),
			)
		);

		$outcome = $this->executor->call( 'woocommerce/product-create', array( 'regular_price' => 18 ) );

		$this->assertSame( '18', $seen['received']['regular_price'] );
		$this->assertSame( 'result', $outcome->kind );
	}

	public function test_a_float_sent_for_a_string_property_becomes_a_plain_decimal_string(): void {
		$seen = $this->registerRecordingAbility(
			array(
				'type'       => 'object',
				'properties' => array(
					'regular_price' => array( 'type' => 'string' ),
					'sale_price'    => array( 'type' => array( 'string', 'null' ) ),
					'big'           => array( 'type' => 'string' ),
				),
			)
		);

		$this->executor->call(
			'woocommerce/product-create',
			array(
				'regular_price' => 18.5,
				'sale_price'    => 18.0,
				'big'           => 1.0E+20,
			)
		);

		$this->assertSame( '18.5', $seen['received']['regular_price'] );
		$this->assertSame( '18', $seen['received']['sale_price'] );
		$this->assertSame( '100000000000000000000', $seen['received']['big'] );
	}

	public function test_numbers_are_untouched_where_the_schema_allows_numbers(): void {
		$seen = $this->registerRecordingAbility(
			array(
				'type'       => 'object',
				'properties' => array(
					'a' => array( 'type' => 'number' ),
					'b' => array( 'type' => 'integer' ),
					'c' => array( 'type' => array( 'string', 'number' ) ),
					'd' => array( 'type' => array( 'string', 'integer' ) ),
					'e' => array(),
				),
			)
		);

		$this->executor->call(
			'woocommerce/product-create',
			array(
				'a' => 1.5,
				'b' => 3,
				'c' => 4,
				'd' => 5,
				'e' => 6,
			)
		);

		$this->assertSame(
			array(
				'a' => 1.5,
				'b' => 3,
				'c' => 4,
				'd' => 5,
				'e' => 6,
			),
			$seen['received']
		);
	}

	public function test_booleans_nulls_and_arrays_are_left_alone_for_string_properties(): void {
		$seen = $this->registerRecordingAbility(
			array(
				'type'       => 'object',
				'properties' => array(
					'flag' => array( 'type' => 'string' ),
					'nope' => array( 'type' => 'string' ),
					'list' => array( 'type' => 'string' ),
				),
			)
		);

		$this->executor->call(
			'woocommerce/product-create',
			array(
				'flag' => true,
				'nope' => null,
				'list' => array( 1 ),
			)
		);

		$this->assertSame(
			array(
				'flag' => true,
				'nope' => null,
				'list' => array( 1 ),
			),
			$seen['received']
		);
	}

	public function test_numbers_are_converted_inside_nested_objects_and_array_items(): void {
		$seen = $this->registerRecordingAbility(
			array(
				'type'       => 'object',
				'properties' => array(
					'dimensions' => array(
						'type'       => 'object',
						'properties' => array( 'length' => array( 'type' => 'string' ) ),
					),
					'variations' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'price' => array( 'type' => 'string' ),
								'stock' => array( 'type' => 'integer' ),
							),
						),
					),
					'skus'       => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			)
		);

		$this->executor->call(
			'woocommerce/product-create',
			array(
				'dimensions' => array( 'length' => 12.25 ),
				'variations' => array(
					array(
						'price' => 9,
						'stock' => 4,
					),
					array(
						'price' => 7.5,
						'stock' => 1,
					),
				),
				'skus'       => array( 101, 'abc' ),
			)
		);

		$this->assertSame( '12.25', $seen['received']['dimensions']['length'] );
		$this->assertSame(
			array(
				'price' => '9',
				'stock' => 4,
			),
			$seen['received']['variations'][0]
		);
		$this->assertSame(
			array(
				'price' => '7.5',
				'stock' => 1,
			),
			$seen['received']['variations'][1]
		);
		$this->assertSame( array( '101', 'abc' ), $seen['received']['skus'] );
	}

	public function test_a_converted_value_that_still_fails_the_pattern_is_refused_by_the_ability(): void {
		$seen = $this->registerRecordingAbility(
			array(
				'type'       => 'object',
				'properties' => array(
					'regular_price' => array(
						'type'    => 'string',
						'pattern' => '^\d+(\.\d+)?$',
					),
				),
			)
		);

		$outcome = $this->executor->call( 'woocommerce/product-create', array( 'regular_price' => -5 ) );

		$this->assertSame( '-5', $seen['received']['regular_price'] );
		$this->assertSame( 'error', $outcome->kind );
	}

	/** @return array<string,mixed> */
	private function listSchema( string $item_type = 'string', string|array $type = 'array' ): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'tags' => array(
					'type'  => $type,
					'items' => array( 'type' => $item_type ),
				),
			),
		);
	}

	/** Live J7: create-post got `"tags": {"item": [...]}` and `"categories": {"item": "Sustainability"}`. */
	public function test_a_list_wrapped_in_item_or_items_is_unwrapped_for_an_array_schema(): void {
		$seen = $this->registerRecordingAbility( $this->listSchema() );

		$this->executor->call( 'woocommerce/product-create', array( 'tags' => array( 'item' => array( 'A', 'B' ) ) ) );
		$this->assertSame( array( 'A', 'B' ), $seen['received']['tags'] );

		$this->executor->call( 'woocommerce/product-create', array( 'tags' => array( 'items' => array( 'A' ) ) ) );
		$this->assertSame( array( 'A' ), $seen['received']['tags'] );

		$this->executor->call( 'woocommerce/product-create', array( 'tags' => array( 'item' => 'Sustainability' ) ) );
		$this->assertSame( array( 'Sustainability' ), $seen['received']['tags'] );

		$this->executor->call( 'woocommerce/product-create', array( 'tags' => '{"item":["A","B"]}' ) );
		$this->assertSame( array( 'A', 'B' ), $seen['received']['tags'] );
	}

	public function test_a_bare_string_becomes_a_one_name_list_for_an_array_of_strings(): void {
		$seen = $this->registerRecordingAbility( $this->listSchema() );

		$this->executor->call( 'woocommerce/product-create', array( 'tags' => 'Kitchen' ) );
		$this->assertSame( array( 'Kitchen' ), $seen['received']['tags'] );

		$seen = $this->registerRecordingAbility( $this->listSchema( 'integer' ) );
		$this->executor->call( 'woocommerce/product-create', array( 'tags' => 'Kitchen' ) );
		$this->assertSame( 'Kitchen', $seen['received']['tags'], 'an array of integers is not a list of names' );
	}

	public function test_other_wrappers_are_left_alone_for_an_array_schema(): void {
		$seen = $this->registerRecordingAbility( $this->listSchema() );

		foreach ( array(
			array(
				'item'  => array( 'A' ),
				'extra' => 1,
			),
			array( 'name' => array( 'A' ) ),
			array( 'item' => array( 'k' => 'A' ) ),
		) as $odd ) {
			$this->executor->call( 'woocommerce/product-create', array( 'tags' => $odd ) );
			$this->assertSame( $odd, $seen['received']['tags'] );
		}
	}

	public function test_a_schema_admitting_an_object_or_a_string_is_left_alone_for_a_bare_value(): void {
		$seen = $this->registerRecordingAbility( $this->listSchema( 'string', array( 'array', 'object' ) ) );
		$this->executor->call( 'woocommerce/product-create', array( 'tags' => array( 'item' => array( 'A' ) ) ) );
		$this->assertSame( array( 'item' => array( 'A' ) ), $seen['received']['tags'] );

		$seen = $this->registerRecordingAbility( $this->listSchema( 'string', array( 'array', 'string' ) ) );
		$this->executor->call( 'woocommerce/product-create', array( 'tags' => 'Kitchen' ) );
		$this->assertSame( 'Kitchen', $seen['received']['tags'] );
	}

	/**
	 * Live proof run: Woo's product-create input is a top-level `oneOf` of
	 * object branches with no `properties` of its own, and one branch has no
	 * regular_price at all; 18 was refused six times in a row.
	 *
	 * @param string $keyword allOf, anyOf or oneOf.
	 * @return array<string,mixed>
	 */
	private function wooShapedSchema( string $keyword = 'oneOf' ): array {
		$price = array(
			'type'    => 'string',
			'pattern' => '^(?:-?(?:[0-9]+(?:[\\.][0-9]+)?|[\\.][0-9]+)|)$',
		);

		return array(
			'type'   => 'object',
			$keyword => array(
				array(
					'type'                 => 'object',
					'properties'           => array(
						'name'          => array( 'type' => 'string' ),
						'regular_price' => $price,
					),
					'required'             => array( 'name' ),
					'additionalProperties' => false,
				),
				array(
					'type'                 => 'object',
					'properties'           => array(
						'name'          => array( 'type' => 'string' ),
						'regular_price' => $price,
						'sale_price'    => array( 'type' => 'string' ),
					),
					'required'             => array( 'sale_price' ),
					'additionalProperties' => false,
				),
				array(
					'type'                 => 'object',
					'properties'           => array(
						'name' => array( 'type' => 'string' ),
					),
					'required'             => array( 'name' ),
					'additionalProperties' => false,
				),
			),
		);
	}

	public function test_numbers_are_repaired_through_a_woo_shaped_one_of_object_schema(): void {
		$seen = $this->registerRecordingAbility( $this->wooShapedSchema() );

		$this->executor->call(
			'woocommerce/product-create',
			array(
				'name'          => 'Mug',
				'regular_price' => 18,
				'sale_price'    => 12.5,
				'stock'         => 3,
			)
		);

		$this->assertSame( '18', $seen['received']['regular_price'] );
		$this->assertSame( '12.5', $seen['received']['sale_price'] );
		$this->assertSame( 'Mug', $seen['received']['name'] );
		$this->assertSame( 3, $seen['received']['stock'] );

		$this->executor->call( 'woocommerce/product-create', array( 'regular_price' => 18.5 ) );
		$this->assertSame( '18.5', $seen['received']['regular_price'] );
	}

	/** Live J13: shipping-zone-save got `"enabled":"true"`, which WordPress's input check refuses. */
	public function test_true_and_false_text_become_booleans_only_for_boolean_properties(): void {
		$seen = $this->registerRecordingAbility(
			array(
				'type'       => 'object',
				'properties' => array(
					'enabled' => array( 'type' => 'boolean' ),
					'flag'    => array( 'type' => array( 'boolean', 'string' ) ),
					'label'   => array( 'type' => 'string' ),
				),
			)
		);

		$this->executor->call(
			'woocommerce/product-create',
			array(
				'enabled' => 'true',
				'flag'    => 'false',
				'label'   => 'true',
			)
		);

		$this->assertTrue( $seen['received']['enabled'] );
		$this->assertSame( 'false', $seen['received']['flag'] );
		$this->assertSame( 'true', $seen['received']['label'] );

		$this->executor->call( 'woocommerce/product-create', array( 'enabled' => 'FALSE' ) );
		$this->assertFalse( $seen['received']['enabled'] );

		$this->executor->call( 'woocommerce/product-create', array( 'enabled' => 'yes' ) );
		$this->assertSame( 'yes', $seen['received']['enabled'] );
	}

	public function test_all_of_and_any_of_are_repaired_the_same_way(): void {
		foreach ( array( 'allOf', 'anyOf' ) as $keyword ) {
			$seen = $this->registerRecordingAbility( $this->wooShapedSchema( $keyword ) );

			$this->executor->call( 'woocommerce/product-create', array( 'regular_price' => 18 ) );

			$this->assertSame( '18', $seen['received']['regular_price'], $keyword );
		}
	}

	public function test_a_branch_that_accepts_a_number_blocks_the_conversion(): void {
		foreach ( array( array( 'string', 'number' ), 'number', 'integer' ) as $type ) {
			$schema = $this->wooShapedSchema();
			$schema['oneOf'][1]['properties']['regular_price'] = array( 'type' => $type );
			$seen = $this->registerRecordingAbility( $schema );

			$this->executor->call( 'woocommerce/product-create', array( 'regular_price' => 18 ) );

			$this->assertSame( 18, $seen['received']['regular_price'], wp_json_encode( $type ) );
		}
	}

	public function test_a_declaring_branch_with_a_typeless_property_blocks_the_conversion(): void {
		$schema = $this->wooShapedSchema();
		$schema['oneOf'][1]['properties']['regular_price'] = array( 'description' => 'anything' );
		$seen = $this->registerRecordingAbility( $schema );

		$this->executor->call( 'woocommerce/product-create', array( 'regular_price' => 18 ) );

		$this->assertSame( 18, $seen['received']['regular_price'] );
	}

	public function test_a_property_level_one_of_of_string_and_null_converts(): void {
		$seen = $this->registerRecordingAbility(
			array(
				'type'       => 'object',
				'properties' => array(
					'sale_price' => array(
						'oneOf' => array( array( 'type' => 'string' ), array( 'type' => 'null' ) ),
					),
					'either'     => array(
						'oneOf' => array( array( 'type' => 'string' ), array( 'type' => 'number' ) ),
					),
					'open'       => array(
						'oneOf' => array( array( 'type' => 'string' ), array( 'minLength' => 1 ) ),
					),
				),
			)
		);

		$this->executor->call(
			'woocommerce/product-create',
			array(
				'sale_price' => 9,
				'either'     => 9,
				'open'       => 9,
			)
		);

		$this->assertSame( '9', $seen['received']['sale_price'] );
		$this->assertSame( 9, $seen['received']['either'] );
		$this->assertSame( 9, $seen['received']['open'] );
	}

	public function test_differing_branch_declarations_narrow_to_the_union_of_their_types(): void {
		$schema = $this->wooShapedSchema();
		$schema['oneOf'][1]['properties']['regular_price'] = array(
			'type'      => 'string',
			'maxLength' => 8,
		);
		$seen = $this->registerRecordingAbility( $schema );

		$this->executor->call( 'woocommerce/product-create', array( 'regular_price' => 18 ) );

		$this->assertSame( '18', $seen['received']['regular_price'] );
	}

	// --- refusals run before the built-in park -------------------------------

	private function parkedGate( bool $approved = false ): BuiltinGate {
		return new BuiltinGate( active: true, tier: 1, verb: 'agsafe-smoke/write', approvalId: 'builtin:1:call_x', approved: $approved );
	}

	public function test_a_validate_refusal_beats_the_built_in_park(): void {
		$permission_checked                  = false;
		$GLOBALS['senroflux_test_abilities'] = array(
			'agsafe-smoke/write' => new SenroFlux_Test_Fake_Ability(
				'agsafe-smoke/write',
				permission_result: static function () use ( &$permission_checked ) {
					$permission_checked = true;

					return true;
				}
			),
		);

		$outcome = $this->executor->call(
			'agsafe-smoke/write',
			array(),
			$this->parkedGate(),
			static fn (): WP_Error => new WP_Error( 'bad_markup', 'The post content is not well-formed block markup.' )
		);

		$this->assertSame( 'denied', $outcome->kind );
		$this->assertSame( 'bad_markup', $outcome->errorCode );
		$this->assertFalse( $permission_checked );
	}

	public function test_a_call_that_passes_validate_still_parks_in_built_in_mode(): void {
		$GLOBALS['senroflux_test_abilities'] = array(
			'agsafe-smoke/write' => new SenroFlux_Test_Fake_Ability( 'agsafe-smoke/write' ),
		);

		$outcome = $this->executor->call( 'agsafe-smoke/write', array(), $this->parkedGate(), static fn () => null );

		$this->assertSame( 'approval_required', $outcome->kind );
	}

	public function test_the_history_placeholder_refusal_beats_the_built_in_park(): void {
		$GLOBALS['senroflux_test_abilities'] = array(
			'agsafe-smoke/write' => new SenroFlux_Test_Fake_Ability( 'agsafe-smoke/write' ),
		);

		$outcome = $this->executor->call(
			'agsafe-smoke/write',
			array( 'content' => sprintf( ToolExecutor::HISTORY_PLACEHOLDER_FORMAT, 120 ) ),
			$this->parkedGate()
		);

		$this->assertSame( 'denied', $outcome->kind );
		$this->assertSame( 'history_placeholder', $outcome->errorCode );
	}

	public function test_validate_runs_again_on_the_approved_re_entry(): void {
		$GLOBALS['senroflux_test_abilities'] = array(
			'agsafe-smoke/write' => new SenroFlux_Test_Fake_Ability( 'agsafe-smoke/write', execute_result: array( 'ok' => true ) ),
		);
		$calls                               = 0;
		$validate                            = static function () use ( &$calls ): ?WP_Error {
			++$calls;

			return 2 === $calls ? new WP_Error( 'site_changed', 'No longer valid.' ) : null;
		};

		$first  = $this->executor->call( 'agsafe-smoke/write', array(), $this->parkedGate(), $validate );
		$second = $this->executor->call( 'agsafe-smoke/write', array(), $this->parkedGate( true ), $validate );

		$this->assertSame( 'approval_required', $first->kind );
		$this->assertSame( 'denied', $second->kind );
		$this->assertSame( 'site_changed', $second->errorCode );
		$this->assertSame( 2, $calls );
	}

	public function test_as_mode_validate_refuses_before_check_permissions_is_called(): void {
		$permission_checked                  = false;
		$GLOBALS['senroflux_test_abilities'] = array(
			'agsafe-smoke/write' => new SenroFlux_Test_Fake_Ability(
				'agsafe-smoke/write',
				permission_result: static function () use ( &$permission_checked ) {
					$permission_checked = true;

					return new WP_Error( 'approval_required', 'parked', array( 'approval_id' => 'as:1' ) );
				}
			),
		);

		$outcome = $this->executor->call(
			'agsafe-smoke/write',
			array(),
			null,
			static fn (): WP_Error => new WP_Error( 'bad_markup', 'nope' )
		);

		$this->assertSame( 'denied', $outcome->kind );
		$this->assertFalse( $permission_checked );
	}
}
