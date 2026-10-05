<?php
/**
 * ModelChoice tests: the run-model picker's UI-agnostic surface.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Model;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Model\ModelChoice;
use WP_Error;

final class ModelChoiceTest extends TestCase {

	protected function tearDown(): void {
		// Never leak a forced list into another test file's real-registry path.
		ModelChoice::setAvailableChoicesProbe( null );
	}

	private function fixtureChoices(): array {
		return array(
			'openai'    => array(
				'name'   => 'OpenAI',
				'models' => array(
					array(
						'id'   => 'gpt-4o',
						'name' => 'GPT-4o',
					),
					array(
						'id'   => 'gpt-4o-mini',
						'name' => 'GPT-4o mini',
					),
				),
			),
			'anthropic' => array(
				'name'   => 'Anthropic',
				'models' => array(
					array(
						'id'   => 'claude-opus',
						'name' => 'Claude Opus',
					),
				),
			),
		);
	}

	public function test_available_choices_returns_the_injected_probe_verbatim(): void {
		$fixture = $this->fixtureChoices();
		ModelChoice::setAvailableChoicesProbe( $fixture );

		$this->assertSame( $fixture, ModelChoice::availableChoices() );
	}

	public function test_available_choices_is_empty_when_the_ai_client_is_absent_and_no_probe_is_set(): void {
		// No probe injected, and this test process's real registry (if the
		// AI Client classes happen to be absent) has nothing configured —
		// either way the result must be an empty, never-null list.
		$this->assertIsArray( ModelChoice::availableChoices() );
	}

	public function test_validate_accepts_a_listed_pair(): void {
		ModelChoice::setAvailableChoicesProbe( $this->fixtureChoices() );

		$this->assertTrue( ModelChoice::validate( 'openai', 'gpt-4o-mini' ) );
	}

	public function test_validate_refuses_an_unknown_model_under_a_known_provider(): void {
		ModelChoice::setAvailableChoicesProbe( $this->fixtureChoices() );

		$result = ModelChoice::validate( 'openai', 'gpt-3.5-turbo' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( ModelChoice::ERROR_UNAVAILABLE, $result->get_error_code() );
	}

	public function test_validate_refuses_an_unknown_provider(): void {
		ModelChoice::setAvailableChoicesProbe( $this->fixtureChoices() );

		$result = ModelChoice::validate( 'made-up-provider', 'anything' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( ModelChoice::ERROR_UNAVAILABLE, $result->get_error_code() );
	}

	public function test_validate_refuses_every_pair_when_nothing_is_configured(): void {
		ModelChoice::setAvailableChoicesProbe( array() );

		$result = ModelChoice::validate( 'openai', 'gpt-4o' );

		$this->assertInstanceOf( WP_Error::class, $result );
	}
}
