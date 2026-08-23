<?php
/**
 * ToolRegistry tests: the allow-list ∩ abilities intersection (stage 5).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Tools;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\Run;
use Specflux\SenroFlux\Run\RunStatus;
use Specflux\SenroFlux\Tools\ToolRegistry;
use SenroFlux_Test_Fake_Ability;

final class ToolRegistryTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['senroflux_test_abilities'] = array(
			'agsafe-smoke/spend'   => new SenroFlux_Test_Fake_Ability( 'agsafe-smoke/spend', description: 'Spend an amount' ),
			'agsafe-smoke/blocked' => new SenroFlux_Test_Fake_Ability( 'agsafe-smoke/blocked', description: 'Blocked verb' ),
			'core/get-site-info'   => new SenroFlux_Test_Fake_Ability( 'core/get-site-info' ),
			'secret/hidden-tool'   => new SenroFlux_Test_Fake_Ability(
				'secret/hidden-tool',
				meta: array( 'senroflux' => array( 'hidden' => true ) )
			),
			'secret/open-tool'     => new SenroFlux_Test_Fake_Ability( 'secret/open-tool' ),
		);
	}

	private function runWithAllow( array $allow ): Run {
		return new Run(
			id: 7,
			userId: 1,
			consumer: 'test-consumer',
			goal: 'goal',
			status: RunStatus::Pending,
			allow: $allow,
			budget: Budget::defaults()
		);
	}

	public function test_glob_allow_list_admits_only_matching_abilities(): void {
		$registry = ToolRegistry::forRun( $this->runWithAllow( array( 'agsafe-smoke/*' ) ) );

		$this->assertSame(
			array( 'agsafe-smoke/spend', 'agsafe-smoke/blocked' ),
			$registry->names(),
			'a namespace glob admits exactly that namespace, nothing else'
		);
		$this->assertTrue( $registry->admits( 'agsafe-smoke/spend' ) );
		$this->assertFalse( $registry->admits( 'core/get-site-info' ) );
	}

	public function test_exact_names_and_wildcards_mix(): void {
		$registry = ToolRegistry::forRun(
			$this->runWithAllow( array( 'agsafe-smoke/spend', 'secret/*', 'core/get-site-info' ) )
		);

		$this->assertContains( 'agsafe-smoke/spend', $registry->names() );
		$this->assertContains( 'secret/open-tool', $registry->names() );
		$this->assertContains( 'core/get-site-info', $registry->names() );
		$this->assertNotContains( 'agsafe-smoke/blocked', $registry->names() );
		$this->assertNotContains( 'secret/hidden-tool', $registry->names(), 'hidden meta wins over the allow-list' );
	}

	public function test_hidden_abilities_are_never_tools_even_under_star(): void {
		$registry = ToolRegistry::forRun( $this->runWithAllow( array( '*' ) ) );

		$this->assertFalse( $registry->admits( 'secret/hidden-tool' ) );
		$this->assertTrue( $registry->admits( 'secret/open-tool' ) );
	}

	public function test_declarations_use_the_wpab_function_name_mapping(): void {
		$registry = ToolRegistry::forRun( $this->runWithAllow( array( 'agsafe-smoke/spend' ) ) );

		$declarations = $registry->declarations();
		$declaration  = reset( $declarations );

		if ( is_object( $declaration ) ) {
			$this->assertSame( 'wpab__agsafe-smoke__spend', $declaration->getName() );
			return;
		}

		// Array shape (SDK-less context).
		$this->assertSame( 'wpab__agsafe-smoke__spend', $declaration['name'] );
		$this->assertSame( 'Spend an amount', $declaration['description'] ?? null );
	}
}
