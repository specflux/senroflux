<?php
/**
 * S22 gate robustness suite: "an expired grant".
 *
 * SenroFlux's built-in gate mode has NO grants at all (see GateMode::BuiltIn
 * — every Tier-1+ call parks on the run's own transient/DB park, with no
 * TTL or count to expire). Pre-approval grants only exist under
 * GateMode::AgentSafety (S14 AS-12), and their COUNT/TTL bookkeeping is
 * entirely Agent Safety's own `grants()` service — {@see
 * \Specflux\SenroFlux\Approval\GrantBridge} only issues and revokes by
 * correlation id; SenroFlux never decrements or ages a grant itself (see
 * tests/stubs/agent-safety.php's SenroFlux_Test_Grants, which records
 * issue()/revokeAll() calls and nothing about consumption).
 *
 * The nearest SenroFlux-side guarantee — and the one this test proves — is
 * structural rather than time-based: a grant cannot outlive the run whose
 * correlation scope it was issued under. A grant object matching a FINISHED
 * run's correlation id and verb (the shape a leaked/replayed grant would
 * have, which is what "expired" amounts to from SenroFlux's side) is never
 * eligible for a DIFFERENT, live run — {@see
 * \Specflux\SenroFlux\Run\GrantEligibility} only answers true inside the
 * currently-ticking run's own correlation scope.
 *
 * A true count/TTL expiry — Agent Safety's own grants table saying "this
 * grant still exists but is spent/aged out" — is out of scope for this repo
 * and cannot be exercised without Agent Safety installed; that gap is
 * flagged here rather than papered over with a fixture that pretends to
 * model it.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Adversarial;

use PHPUnit\Framework\TestCase;
use SenroFlux_Test_Fake_Ability;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\SenroFlux\Approval\GrantBridge;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\GrantEligibility;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\ToolExecutor;
use Specflux\SenroFlux\Tools\VerbTier;
use Specflux\SenroFlux\Tests\Run\FakeGateway;
use Specflux\SenroFlux\Tests\Run\RecordingBridge;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use wpdb;

final class ExpiredGrantTest extends TestCase {

	private wpdb $db;

	private WpdbRunStore $store;

	private FakeGateway $gateway;

	private Runner $runner;

	protected function setUp(): void {
		$this->db              = new wpdb();
		$this->db->queryReturn = 1;
		$GLOBALS['wpdb']       = $this->db;
		$this->store           = new WpdbRunStore( $this->db );
		$this->gateway         = new FakeGateway();
		$this->runner          = new Runner( $this->store, new ToolExecutor(), $this->gateway, new RecordingBridge() );

		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$GLOBALS['senroflux_test_transients']      = array();
		$GLOBALS['senroflux_test_filters']         = array();
		$GLOBALS['senroflux_test_abilities']       = array(
			'agsafe-smoke/read' => new SenroFlux_Test_Fake_Ability( 'agsafe-smoke/read', true, array( 'ok' => true ) ),
		);

		RequestContext::reset();
		senroflux_test_grants( true );
		add_filter(
			'senroflux_verb_map',
			static fn ( array $map ): array => $map + array( 'agsafe-smoke/read' => VerbTier::TIER_0 ),
			10,
			1
		);
	}

	protected function tearDown(): void {
		remove_all_filters();
		RequestContext::reset();
		GrantEligibility::forgetRun();
		senroflux_test_no_agent_safety();
		Plugin::reset();
		Plugin::set_dependency_probe( null );
	}

	private function createRun(): int {
		return $this->store->createRun( 1, 'test-consumer', 'Do something', array( 'agsafe-smoke/*' ), Budget::defaults() );
	}

	private static function correlationFor( int $run_id ): string {
		return 'senroflux:run:' . $run_id;
	}

	/** A stand-in for Agent Safety's Grant value object. */
	private static function grant( string $correlation_id, string $verb ): object {
		return new class( $correlation_id, $verb ) {
			public function __construct( public string $correlationId, public string $verb ) { // phpcs:ignore
			}
		};
	}

	private static function grantEligible( ?object $grant, string $verb, array $args ): mixed {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Agent Safety's own hook.
		return apply_filters( 'agent_safety_grant_eligible', false, $grant, $verb, $args );
	}

	/**
	 * A grant scoped to a run that has ALREADY FINISHED (the closest
	 * SenroFlux-side model of "expired": nothing issued for that scope may
	 * be spent again) is never eligible for a brand-new, currently-ticking
	 * run — even though the verb matches and the object id matches the new
	 * run's own write.
	 */
	public function test_a_grant_scoped_to_an_already_finished_run_cannot_authorize_a_call_in_a_new_run(): void {
		GrantEligibility::boot();

		$finished_run_id         = $this->createRun();
		$this->gateway->script[] = new ModelTurn( new ModelMessage( array( new MessagePart( 'Done.' ) ) ), 10, 5 );
		$this->gateway->script[] = new ModelTurn( new ModelMessage( array( new MessagePart( 'Done.' ) ) ), 10, 5 );
		$this->runner->tick( $finished_run_id, 0, null );
		$this->assertSame( 'completed', $this->store->getRun( $finished_run_id )->status->value );

		// The "expired" grant: it names the FINISHED run's correlation id.
		$stale_grant = self::grant( self::correlationFor( $finished_run_id ), 'agsafe-smoke/publish' );

		$new_run_id = $this->createRun();
		$this->store->updateRun( $new_run_id, array( 'objects_json' => array( '42' => array( 'written_at' => 1 ) ) ) );

		$answer = null;
		$GLOBALS['senroflux_test_abilities']['agsafe-smoke/read']->on_execute =
			static function () use ( &$answer, $stale_grant ): void {
				$answer = self::grantEligible( $stale_grant, 'agsafe-smoke/publish', array( 'id' => 42 ) );
			};

		$this->gateway->script[] = new ModelTurn(
			new ModelMessage( array( new MessagePart( new FunctionCall( 'c1', 'wpab__agsafe-smoke__read', array() ) ) ) ),
			10,
			5
		);
		$this->gateway->script[] = new ModelTurn( new ModelMessage( array( new MessagePart( 'Done.' ) ) ), 10, 5 );
		$this->runner->tick( $new_run_id, 0, null );

		$this->assertNotNull( $answer, 'the read must actually have run inside the new run scope' );
		$this->assertFalse( $answer, "a finished run's leaked/expired grant must never authorize a call in a different, live run" );
	}

	/**
	 * Every terminal path revokes the run's own grants (already exhaustively
	 * covered by {@see \Specflux\SenroFlux\Tests\Run\GrantsTest}) — pinned
	 * here once more as the concrete mechanism that makes a grant incapable
	 * of outliving its run in the first place.
	 */
	public function test_completing_a_run_revokes_its_grants_so_nothing_survives_to_expire(): void {
		$grants                  = senroflux_test_grants( true );
		$run_id                  = $this->createRun();
		$this->gateway->script[] = new ModelTurn( new ModelMessage( array( new MessagePart( 'Done.' ) ) ), 10, 5 );

		$this->runner->tick( $run_id, 0, null );

		$this->assertContains( self::correlationFor( $run_id ), $grants->revoked );
	}
}
