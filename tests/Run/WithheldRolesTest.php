<?php
/**
 * Runner-level defence in depth for 0.3 S6: a withheld role's ability is
 * refused at execution even when it still reaches `executeCall()` (a stale
 * allow-list, a hallucinated/injected call) — never merely relying on the
 * tool-set drop {@see \Specflux\SenroFlux\Plugin::start()} performs.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\TestCase;
use SenroFlux_Test_Fake_Ability;
use Specflux\SenroFlux\Approval\ApprovalBridge;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\GateMode;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\ToolExecutor;
use wpdb;

final class WithheldRolesTest extends TestCase {

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
			// A tier-0 verb map so the call reaches executeCall() directly —
			// the S7 plan fence and Agent Safety tiering are NOT what this
			// test is about (that seam is exercised elsewhere); an unmapped
			// verb fails closed to tier 2 and would park for a plan instead.
			static fn (): array => array( 'senroflux/generate-image' => 0 ),
			null,
			null,
			null,
			new \Specflux\SenroFlux\Approval\GrantBridge(),
			null,
			null,
			// S6: the withheld-abilities resolver — mirrors the small closure
			// Plugin::runner() wires from a real Pack, without needing one here.
			static fn ( \Specflux\SenroFlux\Run\Run $run ): array => in_array( 'generate', $run->withheldRoles, true )
				? array( 'senroflux/generate-image' )
				: array()
		);

		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$GLOBALS['senroflux_test_transients']      = array();
		// The ability IS registered (a model could still name it, e.g. an
		// injected call) — the refusal must come from the withheld check,
		// never from the ability being merely unregistered.
		$GLOBALS['senroflux_test_abilities'] = array(
			'senroflux/generate-image' => new SenroFlux_Test_Fake_Ability( 'senroflux/generate-image' ),
		);
	}

	private function createRun( array $withheld_roles ): int {
		return $this->store->createRun(
			1,
			'test-consumer',
			'Write a post',
			array( 'senroflux/generate-image' ), // Allow-list still admits it — proving this is NOT the allow-list check.
			Budget::defaults(),
			null,
			null,
			null,
			GateMode::AgentSafety,
			$withheld_roles
		);
	}

	private static function callTurn( string $function_name, array $args ): \Specflux\SenroFlux\Model\ModelTurn {
		return new \Specflux\SenroFlux\Model\ModelTurn(
			new \WordPress\AiClient\Messages\DTO\ModelMessage(
				array(
					new \WordPress\AiClient\Messages\DTO\MessagePart(
						new \WordPress\AiClient\Tools\DTO\FunctionCall( 'call_x', $function_name, $args )
					),
				)
			),
			10,
			5
		);
	}

	private static function textTurn( string $text ): \Specflux\SenroFlux\Model\ModelTurn {
		return new \Specflux\SenroFlux\Model\ModelTurn(
			new \WordPress\AiClient\Messages\DTO\ModelMessage(
				array( new \WordPress\AiClient\Messages\DTO\MessagePart( $text ) )
			),
			10,
			5
		);
	}

	public function test_a_withheld_roles_ability_is_refused_never_parked(): void {
		$run_id = $this->createRun( array( 'generate' ) );

		$this->gateway->script[] = self::callTurn( 'wpab__senroflux__generate-image', array() );
		$this->gateway->script[] = self::textTurn( 'Could not add an image.' );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		// Never parked: the run reaches completed on the model's follow-up
		// turn, not `awaiting_approval`.
		$this->assertSame( 'completed', $result['run']['status'] );

		$kinds = array_column( $result['new_steps'], 'kind' );
		$this->assertSame( array( 'user', 'model', 'tool_result', 'model' ), $kinds );
		$this->assertSame( 'error', $result['new_steps'][2]['status'] );
		$this->assertStringContainsString(
			'missing the capability',
			$result['new_steps'][2]['message']['parts'][0]['functionResponse']['response']['error'] ?? ''
		);
	}

	public function test_a_run_with_no_withheld_roles_may_call_the_same_ability(): void {
		$run_id = $this->createRun( array() ); // Nothing withheld.

		$this->gateway->script[] = self::callTurn( 'wpab__senroflux__generate-image', array() );
		$this->gateway->script[] = self::textTurn( 'Done.' );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'ok', $result['new_steps'][2]['status'] ?? null, 'no role withheld: the call executes normally' );
	}

	/** A minimal fixture pack whose `generate` role names its withheld-role line. */
	private function fixturePack(): \Specflux\SenroFlux\Packs\Pack {
		return new class() extends \Specflux\SenroFlux\Packs\Pack {
			public function __construct() {
				parent::__construct( array( 'generate' => 'generate-image' ) );
			}

			public function name(): string {
				return 'fixture';
			}

			public function verbMap(): array {
				return array( 'senroflux/generate-image' => 0 );
			}

			public function roleCapabilities(): array {
				return array( 'generate' => 'upload_files' );
			}

			public function withheldRoleNotice( array $withheld ): ?string {
				return in_array( 'generate', $withheld, true )
					? 'This run cannot add images.'
					: null;
			}

			protected function agentSafetyBindingError( int $user_id ): ?\WP_Error {
				unset( $user_id );

				return null;
			}
		};
	}

	/**
	 * The instruction seam (S6): the pack's own withheld-role line rides the
	 * system instruction when — and only when — a role is actually withheld.
	 * Wired the same way Runner::instructionFor() reaches it in production:
	 * the pack_resolver + `Run::$withheldRoles`.
	 */
	public function test_the_instruction_carries_the_pack_s_withheld_role_line(): void {
		$pack   = $this->fixturePack();
		$runner = new Runner(
			$this->store,
			new ToolExecutor(),
			$this->gateway,
			new ApprovalBridge(),
			null,
			static fn (): array => $pack->verbMap(),
			null,
			static fn (): \Specflux\SenroFlux\Packs\Pack => $pack
		);

		$run_id = $this->store->createRun(
			1,
			'test-consumer',
			'Write a post',
			array( 'senroflux/*' ),
			Budget::defaults(),
			'fixture',
			null,
			null,
			GateMode::AgentSafety,
			array( 'generate' )
		);

		$this->gateway->script[] = self::textTurn( 'Working on it.' );
		$runner->tick( $run_id, 0, null );

		$this->assertNotEmpty( $this->gateway->systemInstructions );
		$this->assertStringContainsString( 'This run cannot add images.', $this->gateway->systemInstructions[0] );
	}

	public function test_the_instruction_carries_no_line_when_nothing_is_withheld(): void {
		$pack   = $this->fixturePack();
		$runner = new Runner(
			$this->store,
			new ToolExecutor(),
			$this->gateway,
			new ApprovalBridge(),
			null,
			static fn (): array => $pack->verbMap(),
			null,
			static fn (): \Specflux\SenroFlux\Packs\Pack => $pack
		);

		$run_id = $this->store->createRun(
			1,
			'test-consumer',
			'Write a post',
			array( 'senroflux/*' ),
			Budget::defaults(),
			'fixture',
			null,
			null,
			GateMode::AgentSafety,
			array() // Nothing withheld.
		);

		$this->gateway->script[] = self::textTurn( 'Working on it.' );
		$runner->tick( $run_id, 0, null );

		$this->assertNotEmpty( $this->gateway->systemInstructions );
		$this->assertStringNotContainsString( 'This run cannot add images.', $this->gateway->systemInstructions[0] );
	}
}
