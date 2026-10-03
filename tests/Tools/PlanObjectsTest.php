<?php
/**
 * `propose-plan`'s per-step `objects` (the existing objects a pre-approval may
 * cover): validated against the report's object lookup, fail closed.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Tools;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Tools\PlanTools;
use WP_Error;

final class PlanObjectsTest extends TestCase {

	/** @param array<string,mixed> $step_extra */
	private static function plan( array $step_extra ): array {
		return array(
			'goal'  => 'Raise prices',
			'steps' => array(
				array(
					'text'  => 'Raise the mug',
					'verbs' => array( 'commerce/price-change' ),
				) + $step_extra,
			),
		);
	}

	/** Lookup that knows posts 12 and 13 and term:7 only. */
	private static function lookup(): callable {
		return static fn ( string|int $id ): array => array(
			'object_type' => in_array( (string) $id, array( '12', '13', 'term:7' ), true ) ? 'product' : 'unknown',
			'title'       => 'T',
		);
	}

	public function test_ids_are_normalised_to_deduplicated_strings(): void {
		$result = PlanTools::validateProposePlan(
			self::plan( array( 'objects' => array( 12, '13', ' 12 ', 'term:7' ) ) ),
			null,
			null,
			null,
			null,
			self::lookup()
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( '12', '13', 'term:7' ), $result['steps'][0]['objects'] );
	}

	public function test_a_step_without_objects_keeps_its_old_shape(): void {
		$result = PlanTools::validateProposePlan( self::plan( array() ), null, null, null, null, self::lookup() );

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'objects', $result['steps'][0] );
	}

	public function test_unknown_ids_are_refused_by_name(): void {
		$result = PlanTools::validateProposePlan(
			self::plan( array( 'objects' => array( '12', '9999', '55' ) ) ),
			null,
			null,
			null,
			null,
			self::lookup()
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( PlanTools::ERROR_INVALID_PLAN, $result->get_error_code() );
		$this->assertStringContainsString( '9999, 55', $result->get_error_message() );
	}

	public function test_without_a_lookup_any_listed_object_is_refused(): void {
		$result = PlanTools::validateProposePlan( self::plan( array( 'objects' => array( '12' ) ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_more_than_the_cap_is_refused(): void {
		$ids    = array_map( 'strval', range( 1, PlanTools::MAX_STEP_OBJECTS + 1 ) );
		$result = PlanTools::validateProposePlan( self::plan( array( 'objects' => $ids ) ), null, null, null, null, static fn (): array => array( 'object_type' => 'product' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( '25', $result->get_error_message() );
	}

	/** @dataProvider nonScalarProvider */
	public function test_only_scalar_ids_are_accepted( mixed $bad ): void {
		$result = PlanTools::validateProposePlan( self::plan( array( 'objects' => array( $bad ) ) ), null, null, null, null, self::lookup() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( PlanTools::ERROR_INVALID_PLAN, $result->get_error_code() );
	}

	/** @return array<string,array{mixed}> */
	public static function nonScalarProvider(): array {
		return array(
			'array'  => array( array( '12' ) ),
			'float'  => array( 12.5 ),
			'bool'   => array( true ),
			'empty'  => array( '  ' ),
			'object' => array( (object) array( 'id' => 12 ) ),
		);
	}

	public function test_objects_must_be_a_list(): void {
		$result = PlanTools::validateProposePlan( self::plan( array( 'objects' => '12' ) ), null, null, null, null, self::lookup() );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_the_declaration_advertises_objects_with_the_cap(): void {
		$declaration = PlanTools::proposePlanDeclaration();
		$schema      = is_array( $declaration ) ? $declaration['inputSchema'] : $declaration->toArray()['parameters'];
		$objects     = $schema['properties']['steps']['items']['properties']['objects'];

		$this->assertSame( 'array', $objects['type'] );
		$this->assertSame( 25, $objects['maxItems'] );
		$this->assertSame( array( 'type' => 'string' ), $objects['items'] );
		$this->assertStringContainsString( 'pre-approval covers only objects named in the plan', $schema['properties']['steps']['items']['properties']['text']['description'] );
	}
}
