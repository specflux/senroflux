<?php
/**
 * S22 gate robustness suite: a withheld role's ability, called anyway (a
 * stale allow-list, an injected/hallucinated call), must be REFUSED at
 * execution — never parked, never silently executed.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Adversarial;

use PHPUnit\Framework\TestCase;
use SenroFlux_Test_Fake_Ability;
use Specflux\SenroFlux\Approval\ApprovalBridge;
use Specflux\SenroFlux\Approval\GrantBridge;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\GateMode;
use Specflux\SenroFlux\Run\Run;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\ToolExecutor;
use Specflux\SenroFlux\Tests\Run\FakeGateway;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use wpdb;

final class WithheldRoleCalledAnywayTest extends TestCase {

	private wpdb $db;

	private WpdbRunStore $store;

	private FakeGateway $gateway;

	private Runner $runner;

	protected function setUp(): void {
		$this->db              = new wpdb();
		$this->db->queryReturn = 1;
		$this->store           = new WpdbRunStore( $this->db );
		$this->gateway         = new FakeGateway();

		$this->runner = new Runner(
			$this->store,
			new ToolExecutor(),
			$this->gateway,
			new ApprovalBridge(),
			null,
			// Tier 0 so the call reaches executeCall() directly (the S7 plan
			// fence and Agent Safety tiering are exercised elsewhere).
			static fn (): array => array( 'adversarial/withheld-op' => 0 ),
			null,
			null,
			null,
			new GrantBridge(),
			null,
			null,
			// S6 withheld-abilities resolver.
			static fn ( Run $run ): array => in_array( 'restricted', $run->withheldRoles, true )
				? array( 'adversarial/withheld-op' )
				: array()
		);

		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$GLOBALS['senroflux_test_transients']      = array();
		// The ability IS registered — a model could still name it (an
		// injected call or a stale allow-list) — so the refusal must come
		// from the withheld check, not from the ability simply not existing.
		$GLOBALS['senroflux_test_abilities'] = array(
			'adversarial/withheld-op' => new SenroFlux_Test_Fake_Ability( 'adversarial/withheld-op' ),
		);
	}

	private function createRun( array $withheld_roles ): int {
		return $this->store->createRun(
			1,
			'test-consumer',
			'Attempt a withheld operation',
			array( 'adversarial/withheld-op' ), // Allow-list still admits it: proves this is not the allow-list check.
			Budget::defaults(),
			null,
			null,
			null,
			GateMode::AgentSafety,
			$withheld_roles
		);
	}

	private static function callTurn( string $function_name, array $args ): ModelTurn {
		return new ModelTurn(
			new ModelMessage( array( new MessagePart( new FunctionCall( 'call_x', $function_name, $args ) ) ) ),
			10,
			5
		);
	}

	private static function textTurn( string $text ): ModelTurn {
		return new ModelTurn( new ModelMessage( array( new MessagePart( $text ) ) ), 10, 5 );
	}

	public function test_calling_a_withheld_roles_ability_anyway_is_refused_never_parked_never_executed(): void {
		$run_id = $this->createRun( array( 'restricted' ) );

		$this->gateway->script[] = self::callTurn( 'wpab__adversarial__withheld-op', array() );
		$this->gateway->script[] = self::textTurn( 'Could not do it.' );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		// Never parked and never bounced back for a new plan: the run
		// reaches `completed` straight off the model's own follow-up turn.
		$this->assertSame( 'completed', $result['run']['status'] );

		$this->assertSame( 'error', $result['new_steps'][2]['status'] );
		$this->assertStringContainsString(
			'missing the capability',
			$result['new_steps'][2]['message']['parts'][0]['functionResponse']['response']['error'] ?? ''
		);
	}
}
