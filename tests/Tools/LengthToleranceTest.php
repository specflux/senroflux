<?php
/**
 * Near-miss text lengths are accepted; clear overruns are refused.
 *
 * Live run 2026-09-28-fix1 scenario 1-1: space-bunny resubmitted a plan ten
 * times at 290, 266, 218, 207, 207, 203, 203 characters against a 200 limit —
 * the refusal named the exact length, the model still could not count to it.
 *
 * @package Specflux\SenroFlux\Tests\Tools
 */

declare(strict_types=1);

namespace Specflux\SenroFlux\Tests\Tools;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Tools\HarnessTools;
use Specflux\SenroFlux\Tools\PlanTools;
use WP_Error;

final class LengthToleranceTest extends TestCase {

	private static function plan( string $goal, string $step_text ): array {
		return array(
			'goal'  => $goal,
			'steps' => array(
				array(
					'text'  => $step_text,
					'verbs' => array( 'agsafe-smoke/read' ),
				),
			),
		);
	}

	public function test_a_step_text_slightly_over_the_advertised_limit_is_accepted(): void {
		$result = PlanTools::validateProposePlan( self::plan( 'G', str_repeat( 'x', 207 ) ) );

		$this->assertIsArray( $result );
	}

	public function test_a_step_text_well_over_the_limit_is_refused_with_the_advertised_limit(): void {
		$result = PlanTools::validateProposePlan( self::plan( 'G', str_repeat( 'x', 290 ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'step 1 "text" is 290 characters; the limit is 200.', $result->get_error_message() );
	}

	public function test_a_goal_slightly_over_the_advertised_limit_is_accepted(): void {
		$this->assertIsArray( PlanTools::validateProposePlan( self::plan( str_repeat( 'g', 230 ), 'Read' ) ) );
	}

	public function test_an_ask_user_text_slightly_over_the_advertised_limit_is_accepted(): void {
		$result = HarnessTools::validateAskUser(
			array(
				'text'      => str_repeat( 'q', 360 ),
				'rationale' => 'Need the facts.',
			)
		);

		$this->assertIsArray( $result );
	}

	public function test_an_ask_user_text_well_over_the_limit_is_still_refused(): void {
		$result = HarnessTools::validateAskUser(
			array(
				'text'      => str_repeat( 'q', 632 ),
				'rationale' => 'Need the facts.',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( '"text" is 632 characters; the limit is 300.', $result->get_error_message() );
	}
}
