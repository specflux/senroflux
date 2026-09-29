<?php
/**
 * Test doubles shared by the Runner tests: a scripted gateway and a
 * recording approval bridge. Loaded from tests/bootstrap.php so any test
 * file can use them (not just RunnerTest.php).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use Specflux\SenroFlux\Approval\ApprovalBridge;
use Specflux\SenroFlux\Model\ModelGatewayInterface;
use Specflux\SenroFlux\Model\ModelTurn;
use WP_Error;

if ( ! class_exists( FakeGateway::class ) ) {
	/**
	 * Scripted gateway: pops one prepared turn per generateTurn() call and
	 * counts invocations.
	 */
	final class FakeGateway implements ModelGatewayInterface {

		/** @var list<ModelTurn> */
		public array $script = array();

		/** @var list<array{history_count:int}> */
		public array $calls = array();

		/** @var list<string> The system instruction each generateTurn() received. */
		public array $systemInstructions = array();

		/** @var list<\Specflux\SenroFlux\Tools\ToolRegistry> The tool surface each generateTurn() received. */
		public array $toolsLog = array();

		/** @var list<list<mixed>> The message history each generateTurn() received, verbatim. */
		public array $histories = array();

		/** @var (\Closure(int):void)|null Called with the 1-based call number before each turn is returned. */
		public ?\Closure $onCall = null;

		/** @var list<array{0:string,1:string}|null> The model preference each generateTurn() received. */
		public array $modelPreferences = array();

		public function generateTurn( array $history, string $system_instruction, \Specflux\SenroFlux\Tools\ToolRegistry $tools, ?array $model_preference = null ): ModelTurn|WP_Error {
			$this->calls[]              = array( 'history_count' => count( $history ) );
			$this->histories[]          = array_values( $history );
			$this->systemInstructions[] = $system_instruction;
			$this->toolsLog[]           = $tools;
			$this->modelPreferences[]   = $model_preference;

			if ( null !== $this->onCall ) {
				( $this->onCall )( count( $this->calls ) );
			}

			if ( array() === $this->script ) {
				return new WP_Error( 'script_empty', 'FakeGateway has no scripted turns left.' );
			}

			return array_shift( $this->script );
		}
	}
}

if ( ! class_exists( RecordingBridge::class ) ) {
	/**
	 * Recording bridge: stands in for Agent Safety's approvals API.
	 */
	final class RecordingBridge extends ApprovalBridge {

		/** @var array<string,bool> */
		public array $approvals = array();

		public function approve( string $approval_id, int $user_id ): bool {
			$this->approvals[ $approval_id ] = true;

			return true;
		}

		public function isAvailable(): bool {
			return true;
		}
	}
}
