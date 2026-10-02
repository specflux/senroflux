<?php
/**
 * `propose-plan`'s optional `adopted` / `left_for_you` lists (0.3 stage 22b,
 * S7/S12): the plan is where a run NAMES the existing objects it takes into
 * scope and the default-install leftovers it will not touch; the report
 * reads them back from the accepted plan.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Tools;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Tools\PlanTools;
use WP_Error;

final class PlanAdoptedTest extends TestCase {

	/** @return array<string,mixed> */
	private static function plan( array $extra = array() ): array {
		return array(
			'goal'  => 'Build the site',
			'steps' => array(
				array(
					'text'  => 'Read the site',
					'verbs' => array( 'Read' ),
				),
			),
		) + $extra;
	}

	public function test_adopted_and_left_for_you_are_normalised_onto_the_payload(): void {
		$result = PlanTools::validateProposePlan(
			self::plan(
				array(
					'adopted'      => array(
						array(
							'id'    => 7,
							'title' => '  About  ',
						),
					),
					'left_for_you' => array(
						array(
							'id'    => '2',
							'title' => 'Sample Page',
						),
					),
				)
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			array(
				array(
					'id'    => '7',
					'title' => 'About',
				),
			),
			$result['adopted']
		);
		$this->assertSame(
			array(
				array(
					'id'    => '2',
					'title' => 'Sample Page',
				),
			),
			$result['left_for_you']
		);
	}

	public function test_a_plan_without_them_keeps_its_old_payload_shape(): void {
		$result = PlanTools::validateProposePlan( self::plan() );

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'adopted', $result );
		$this->assertArrayNotHasKey( 'left_for_you', $result );
	}

	public function test_an_entry_without_an_id_is_refused(): void {
		$result = PlanTools::validateProposePlan( self::plan( array( 'adopted' => array( array( 'title' => 'About' ) ) ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'adopted', $result->get_error_message() );
	}

	public function test_a_non_list_value_is_refused(): void {
		$result = PlanTools::validateProposePlan( self::plan( array( 'left_for_you' => 'Sample Page' ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'left_for_you', $result->get_error_message() );
	}

	public function test_more_than_the_cap_is_refused(): void {
		$many   = array_fill( 0, PlanTools::MAX_OBJECT_LIST + 1, array( 'id' => '1' ) );
		$result = PlanTools::validateProposePlan( self::plan( array( 'adopted' => $many ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_the_schema_advertises_both_lists(): void {
		$declaration = PlanTools::proposePlanDeclaration( array( 'Read' ) );
		$json        = (string) wp_json_encode( is_object( $declaration ) && method_exists( $declaration, 'toArray' ) ? $declaration->toArray() : $declaration );

		$this->assertStringContainsString( '"adopted"', $json );
		$this->assertStringContainsString( '"left_for_you"', $json );
	}
}
