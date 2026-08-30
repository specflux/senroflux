<?php
/**
 * S7/S14 (AS-12): the correlation scope, pre-approval grants, their object
 * binding, and revocation on every terminal path (stage-11 check).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\TestCase;
use SenroFlux_Test_Fake_Ability;
use SenroFlux_Test_Grants;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\GrantEligibility;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\RunStatus;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\PlanTools;
use Specflux\SenroFlux\Tools\ToolExecutor;
use Specflux\SenroFlux\Tools\VerbTier;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use wpdb;

final class GrantsTest extends TestCase {

	private wpdb $db;

	private WpdbRunStore $store;

	private FakeGateway $gateway;

	private Runner $runner;

	private SenroFlux_Test_Grants $grants;

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
			'agsafe-smoke/read'    => new SenroFlux_Test_Fake_Ability( 'agsafe-smoke/read', true, array( 'ok' => true ) ),
			'agsafe-smoke/write'   => new SenroFlux_Test_Fake_Ability( 'agsafe-smoke/write', true, array( 'id' => 42 ) ),
			'agsafe-smoke/publish' => new SenroFlux_Test_Fake_Ability( 'agsafe-smoke/publish', true, array( 'id' => 42 ) ),
		);

		RequestContext::reset();
		// Agent Safety present with AS-12 switched on, and SenroFlux's own
		// pre-approval filter on: the state stage 11 is about.
		$this->grants = senroflux_test_grants( true );
		add_filter( 'senroflux_enable_preapproval', static fn (): bool => true, 10, 1 );
		add_filter(
			'senroflux_verb_map',
			static fn ( array $map ): array => $map + array(
				'agsafe-smoke/read'    => VerbTier::TIER_0,
				'agsafe-smoke/write'   => VerbTier::TIER_1,
				'agsafe-smoke/publish' => VerbTier::TIER_2,
			),
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

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * @param array<string,int> $budget_override Keys merged over Budget::defaults().
	 */
	private function createRun( array $budget_override = array() ): int {
		return $this->store->createRun(
			1,
			'test-consumer',
			'Publish the page',
			array( 'agsafe-smoke/*' ),
			array_merge( Budget::defaults(), $budget_override )
		);
	}

	private static function turn( MessagePart ...$parts ): ModelTurn {
		return new ModelTurn( new ModelMessage( $parts ), 10, 5 );
	}

	private static function textTurn( string $text ): ModelTurn {
		return self::turn( new MessagePart( $text ) );
	}

	/**
	 * @param array<string,mixed> $args Call arguments.
	 */
	private static function callTurn( string $call_id, string $tool, array $args = array() ): ModelTurn {
		return self::turn( new MessagePart( new FunctionCall( $call_id, $tool, $args ) ) );
	}

	/**
	 * A plan whose steps are $verbs (one step per entry).
	 *
	 * @param list<list<string>> $verbs Per-step verb lists.
	 * @return array<string,mixed>
	 */
	private static function planArgs( array $verbs ): array {
		$steps = array();
		foreach ( $verbs as $i => $step_verbs ) {
			$steps[] = array(
				'text'  => 'Step ' . ( $i + 1 ),
				'verbs' => $step_verbs,
			);
		}

		return array(
			'goal'        => 'Publish the page',
			'steps'       => $steps,
			'assumptions' => array( 'The draft is reviewed.' ),
		);
	}

	/**
	 * Park a plan on a fresh run and return [run_id, plan step seq].
	 *
	 * @param list<list<string>> $verbs Per-step verb lists.
	 * @return array{0:int,1:int}
	 */
	private function parkPlan( array $verbs, ?Runner $runner = null ): array {
		$runner                  = $runner ?? $this->runner;
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::callTurn( 'call_p', PlanTools::FUNCTION_NAME, self::planArgs( $verbs ) );
		$parked                  = $runner->tick( $run_id, 0, null );
		$this->assertIsArray( $parked );
		$this->assertSame( 'awaiting_plan', $parked['run']['status'] );

		return array( $run_id, (int) $parked['new_steps'][2]['seq'] );
	}

	/** A stand-in for Agent Safety's Grant value object (the two fields the filter reads). */
	private static function grant( string $correlation_id, string $verb ): object {
		return new class( $correlation_id, $verb ) {
			public function __construct( public string $correlationId, public string $verb ) { // phpcs:ignore
			}
		};
	}

	private static function correlationFor( int $run_id ): string {
		return 'senroflux:run:' . $run_id;
	}

	/**
	 * Ask the AS-12 eligibility filter exactly as Agent Safety's pipeline does.
	 *
	 * @param object|null         $grant    Grant double (null = not a grant).
	 * @param string              $verb     The verb being gated.
	 * @param array<string,mixed> $args     The real call arguments.
	 * @param bool                $incoming What an earlier callback decided.
	 */
	private static function grantEligible( ?object $grant, string $verb, array $args, bool $incoming = false ): mixed {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Agent Safety's hook, not this plugin's.
		return apply_filters( 'agent_safety_grant_eligible', $incoming, $grant, $verb, $args );
	}

	// ------------------------------------------------------------------
	// (a) the tick body runs inside the run's correlation scope
	// ------------------------------------------------------------------

	public function test_tick_body_runs_under_the_runs_own_correlation_id(): void {
		$run_id = $this->createRun();
		$seen   = array();

		$GLOBALS['senroflux_test_abilities']['agsafe-smoke/read']->on_execute =
			static function () use ( &$seen ): void {
				$seen[] = RequestContext::correlation();
			};

		$this->gateway->script[] = self::callTurn( 'c1', 'wpab__agsafe-smoke__read' );
		$this->gateway->script[] = self::textTurn( 'Done.' );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'] );
		$this->assertSame( array( self::correlationFor( $run_id ) ), $seen, 'the tool call must run inside the scope' );
		$this->assertSame( array( self::correlationFor( $run_id ) ), RequestContext::$scoped );
		// The scope is restored when the tick returns (`finally`, S14).
		$this->assertSame( '', RequestContext::correlation() );
	}

	public function test_two_runs_ticked_in_one_process_never_share_a_correlation_id(): void {
		$first  = $this->createRun();
		$second = $this->createRun();

		$this->gateway->script[] = self::textTurn( 'Done.' );
		$this->runner->tick( $first, 0, null );
		$this->gateway->script[] = self::textTurn( 'Done.' );
		$this->runner->tick( $second, 0, null );

		$this->assertSame(
			array( self::correlationFor( $first ), self::correlationFor( $second ) ),
			RequestContext::$scoped
		);
		$this->assertNotSame( self::correlationFor( $first ), self::correlationFor( $second ) );
	}

	// ------------------------------------------------------------------
	// (b) a correlation conflict FAILS the tick (a notice is not a stop)
	// ------------------------------------------------------------------

	public function test_tick_fails_the_run_when_the_correlation_scope_throws(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::textTurn( 'Should never be reached.' );

		RequestContext::$conflict = true;

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'failed', $result['run']['status'] );
		$this->assertArrayHasKey( 'report', $result['ui'] );

		$run = $this->store->getRun( $run_id );
		$this->assertSame( RunStatus::Failed, $run->status );
		$this->assertSame( 'correlation_conflict', $run->error['code'] ?? '' );
		$this->assertSame( array(), $this->gateway->calls, 'the model must never be called outside the scope' );
	}

	public function test_a_conflict_never_rewrites_the_outcome_of_a_finished_run(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::textTurn( 'Done.' );
		$this->runner->tick( $run_id, 0, null );
		$this->assertSame( 'completed', $this->store->getRun( $run_id )->status->value );

		RequestContext::$conflict = true;
		$before                   = $this->store->getRun( $run_id )->stepCount;

		$result = $this->runner->tick( $run_id, $before, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'] );
		$this->assertSame( RunStatus::Completed, $this->store->getRun( $run_id )->status );
	}

	public function test_a_throw_from_inside_the_tick_body_is_not_swallowed_as_a_conflict(): void {
		$run_id = $this->createRun();

		$GLOBALS['senroflux_test_abilities']['agsafe-smoke/read']->on_execute =
			static function (): void {
				throw new \RuntimeException( 'boom' );
			};

		$this->gateway->script[] = self::callTurn( 'c1', 'wpab__agsafe-smoke__read' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'boom' );
		$this->runner->tick( $run_id, 0, null );
	}

	// ------------------------------------------------------------------
	// (c) accept_preapprove issues one grant per Tier-2 verb
	// ------------------------------------------------------------------

	public function test_accept_preapprove_issues_one_grant_per_tier_two_verb(): void {
		list( $run_id, $plan_seq ) = $this->parkPlan(
			array(
				array( 'agsafe-smoke/read' ),
				array( 'agsafe-smoke/write' ),
				array( 'agsafe-smoke/publish' ),
			)
		);

		$this->gateway->script[] = self::textTurn( 'Done.' );
		$before                  = $this->store->getRun( $run_id )->stepCount;
		$result                  = $this->runner->tick( $run_id, $before, array( 'plan' => array( 'action' => 'accept_preapprove' ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( $plan_seq, $this->store->getRun( $run_id )->acceptedPlanStepId );

		$this->assertCount( 1, $this->grants->issued, 'only the Tier-2 verb is pre-approved' );
		$this->assertSame(
			array(
				'verb'           => 'agsafe-smoke/publish',
				'count'          => 1,
				'subject'        => 'user:1',
				'correlation_id' => self::correlationFor( $run_id ),
				'granted_by'     => 1,
				'plan_step_id'   => (string) $plan_seq,
			),
			array_diff_key( $this->grants->issued[0], array( 'grant_id' => null ) )
		);
	}

	public function test_the_grant_count_is_the_number_of_plan_steps_reaching_the_verb(): void {
		list( $run_id ) = $this->parkPlan(
			array(
				array( 'agsafe-smoke/publish' ),
				array( 'agsafe-smoke/read' ),
				array( 'agsafe-smoke/publish' ),
			)
		);

		$this->gateway->script[] = self::textTurn( 'Done.' );
		$before                  = $this->store->getRun( $run_id )->stepCount;
		$this->runner->tick( $run_id, $before, array( 'plan' => array( 'action' => 'accept_preapprove' ) ) );

		$this->assertCount( 1, $this->grants->issued );
		$this->assertSame( 2, $this->grants->issued[0]['count'] );
	}

	public function test_pack_verbs_that_collapse_onto_one_ability_share_one_grant_counted_once_per_step(): void {
		// The pages-pack shape: `pages/publish` and `pages/update-live` are two
		// Tier-2 pack verbs carried by ONE ability, so one grant covers both —
		// and a step naming both is one authorised call, not two.
		$runner = new Runner(
			$this->store,
			new ToolExecutor(),
			$this->gateway,
			new RecordingBridge(),
			null,
			static fn (): array => array(
				'agsafe-smoke/read' => VerbTier::TIER_0,
				'pages/publish'     => VerbTier::TIER_2,
				'pages/update-live' => VerbTier::TIER_2,
			),
			null,
			null,
			null,
			new \Specflux\SenroFlux\Approval\GrantBridge(),
			static fn ( $run, string $pack_verb ): ?string => str_starts_with( $pack_verb, 'pages/' )
				? 'senroflux/update-post'
				: null
		);

		list( $run_id ) = $this->parkPlan(
			array(
				array( 'pages/publish', 'pages/update-live' ),
				array( 'pages/publish' ),
			),
			$runner
		);

		$this->gateway->script[] = self::textTurn( 'Done.' );
		$before                  = $this->store->getRun( $run_id )->stepCount;
		$runner->tick( $run_id, $before, array( 'plan' => array( 'action' => 'accept_preapprove' ) ) );

		$this->assertCount( 1, $this->grants->issued );
		$this->assertSame( 'senroflux/update-post', $this->grants->issued[0]['verb'] );
		$this->assertSame( 2, $this->grants->issued[0]['count'] );
	}

	/**
	 * A model that re-plans (run 51 did, to add `pages/update-draft`) gets its
	 * replacement plan accepted — and the FIRST plan's grants must go with the
	 * plan they were bought for, or two accepts stack two plans' worth of
	 * Tier-2 spend on a run that only ever had one live plan.
	 */
	public function test_a_replacement_plan_revokes_the_previous_plans_grants(): void {
		list( $run_id ) = $this->parkPlan( array( array( 'agsafe-smoke/publish' ) ) );

		// Accept #1 (pre-approved), then the model re-plans and parks again.
		$this->gateway->script[] = self::callTurn(
			'call_p2',
			PlanTools::FUNCTION_NAME,
			self::planArgs( array( array( 'agsafe-smoke/write' ), array( 'agsafe-smoke/publish' ) ) )
		);
		$before                  = $this->store->getRun( $run_id )->stepCount;
		$replan                  = $this->runner->tick( $run_id, $before, array( 'plan' => array( 'action' => 'accept_preapprove' ) ) );
		$this->assertIsArray( $replan );
		$this->assertSame( 'awaiting_plan', $replan['run']['status'] );
		$this->assertCount( 1, $this->grants->issued );
		$this->assertSame( array(), $this->grants->revoked, 'nothing is revoked while the first plan stands' );

		// Accept #2. The run stays parked (a third plan) so no TERMINAL
		// revocation can be mistaken for the replacement's.
		$this->gateway->script[] = self::callTurn(
			'call_p3',
			PlanTools::FUNCTION_NAME,
			self::planArgs( array( array( 'agsafe-smoke/publish' ) ) )
		);
		$before                  = $this->store->getRun( $run_id )->stepCount;
		$this->runner->tick( $run_id, $before, array( 'plan' => array( 'action' => 'accept_preapprove' ) ) );

		$this->assertSame(
			array( self::correlationFor( $run_id ) ),
			$this->grants->revoked,
			'accepting the replacement revokes the replaced plan\'s grants exactly once'
		);
		$this->assertCount( 2, $this->grants->issued, 'the replacement issues its own grant' );
		$this->assertSame( 'agsafe-smoke/publish', $this->grants->issued[1]['verb'] );
	}

	/** The same clearing happens on a PLAIN accept: it issues nothing AND leaves nothing. */
	public function test_a_plain_accept_of_a_replacement_plan_clears_the_earlier_grants(): void {
		list( $run_id ) = $this->parkPlan( array( array( 'agsafe-smoke/publish' ) ) );

		$this->gateway->script[] = self::callTurn(
			'call_p2',
			PlanTools::FUNCTION_NAME,
			self::planArgs( array( array( 'agsafe-smoke/publish' ) ) )
		);
		$before                  = $this->store->getRun( $run_id )->stepCount;
		$this->runner->tick( $run_id, $before, array( 'plan' => array( 'action' => 'accept_preapprove' ) ) );

		$this->gateway->script[] = self::callTurn(
			'call_p3',
			PlanTools::FUNCTION_NAME,
			self::planArgs( array( array( 'agsafe-smoke/publish' ) ) )
		);
		$before                  = $this->store->getRun( $run_id )->stepCount;
		$this->runner->tick( $run_id, $before, array( 'plan' => array( 'action' => 'accept' ) ) );

		$this->assertSame( array( self::correlationFor( $run_id ) ), $this->grants->revoked );
		$this->assertCount( 1, $this->grants->issued, 'a plain accept issues nothing of its own' );
	}

	public function test_no_grant_is_issued_when_agent_safety_can_name_no_subject(): void {
		RequestContext::$token = null;

		list( $run_id ) = $this->parkPlan( array( array( 'agsafe-smoke/publish' ) ) );

		$this->gateway->script[] = self::textTurn( 'Done.' );
		$before                  = $this->store->getRun( $run_id )->stepCount;
		$result                  = $this->runner->tick( $run_id, $before, array( 'plan' => array( 'action' => 'accept_preapprove' ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( array(), $this->grants->issued, 'a grant with no principal is a grant to anyone' );
	}

	public function test_a_plain_accept_issues_nothing(): void {
		list( $run_id ) = $this->parkPlan( array( array( 'agsafe-smoke/publish' ) ) );

		$this->gateway->script[] = self::textTurn( 'Done.' );
		$before                  = $this->store->getRun( $run_id )->stepCount;
		$this->runner->tick( $run_id, $before, array( 'plan' => array( 'action' => 'accept' ) ) );

		$this->assertSame( array(), $this->grants->issued );
	}

	public function test_accept_preapprove_is_refused_while_the_agent_safety_switch_is_off(): void {
		$this->grants->enabled = false;

		list( $run_id ) = $this->parkPlan( array( array( 'agsafe-smoke/publish' ) ) );

		$before = $this->store->getRun( $run_id )->stepCount;
		$result = $this->runner->tick( $run_id, $before, array( 'plan' => array( 'action' => 'accept_preapprove' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'preapproval_disabled', $result->get_error_code() );
		$this->assertSame( array(), $this->grants->issued );
		$this->assertSame( RunStatus::AwaitingPlan, $this->store->getRun( $run_id )->status );
	}

	// ------------------------------------------------------------------
	// (d) object binding: agent_safety_grant_eligible
	// ------------------------------------------------------------------

	public function test_eligibility_is_false_outside_a_tick(): void {
		GrantEligibility::boot();

		$this->assertFalse(
			self::grantEligible(
				self::grant( self::correlationFor( 7 ), 'agsafe-smoke/publish' ),
				'agsafe-smoke/publish',
				array( 'id' => 42 )
			)
		);
	}

	public function test_eligibility_binds_a_grant_to_objects_this_run_owns(): void {
		GrantEligibility::boot();

		$run_id = $this->createRun();
		$this->store->updateRun( $run_id, array( 'objects_json' => array( '42' => array( 'written_at' => 1 ) ) ) );

		$answers = array();
		$GLOBALS['senroflux_test_abilities']['agsafe-smoke/read']->on_execute =
			static function () use ( &$answers, $run_id ): void {
				$mine  = self::grant( self::correlationFor( $run_id ), 'agsafe-smoke/publish' );
				$other = self::grant( self::correlationFor( $run_id + 1000 ), 'agsafe-smoke/publish' );

				$answers['owned']       = self::grantEligible( $mine, 'agsafe-smoke/publish', array( 'id' => 42 ) );
				$answers['unowned']     = self::grantEligible( $mine, 'agsafe-smoke/publish', array( 'id' => 99 ) );
				$answers['no_object']   = self::grantEligible( $mine, 'agsafe-smoke/publish', array( 'title' => 'New page' ) );
				$answers['other_run']   = self::grantEligible( $other, 'agsafe-smoke/publish', array( 'id' => 42 ) );
				$answers['other_verb']  = self::grantEligible( $mine, 'agsafe-smoke/write', array( 'id' => 42 ) );
				$answers['not_a_grant'] = self::grantEligible( null, 'agsafe-smoke/publish', array( 'id' => 42 ), true );
			};

		$this->gateway->script[] = self::callTurn( 'c1', 'wpab__agsafe-smoke__read' );
		$this->gateway->script[] = self::textTurn( 'Done.' );
		$this->runner->tick( $run_id, 0, null );

		$this->assertTrue( $answers['owned'] ?? null, 'an object the run wrote is inside the human decision' );
		$this->assertFalse( $answers['unowned'] ?? null, 'an object the run never touched is not' );
		$this->assertTrue( $answers['no_object'] ?? null, 'a create names no object: fenced by the count alone (S14)' );
		$this->assertFalse( $answers['other_run'] ?? null, "another run's grant never applies here" );
		$this->assertFalse( $answers['other_verb'] ?? null, 'a grant is per-verb' );
		$this->assertFalse( $answers['not_a_grant'] ?? null, 'a filter may narrow, never widen' );
	}

	public function test_eligibility_sees_objects_written_earlier_in_the_same_tick(): void {
		GrantEligibility::boot();

		// The write records object 42 (S12); the later read asks the filter
		// about it — the context must read objects_json fresh, not a snapshot
		// taken when the tick opened. Both calls need an accepted plan (S7),
		// so the run gets one first.
		list( $run_id ) = $this->parkPlan(
			array(
				array( 'agsafe-smoke/write' ),
				array( 'agsafe-smoke/read' ),
			)
		);

		$answers = array();
		$GLOBALS['senroflux_test_abilities']['agsafe-smoke/read']->on_execute =
			static function () use ( &$answers, $run_id ): void {
				$answers['after_write'] = self::grantEligible(
					self::grant( self::correlationFor( $run_id ), 'agsafe-smoke/publish' ),
					'agsafe-smoke/publish',
					array( 'id' => 42 )
				);
			};

		$this->gateway->script[] = self::turn(
			new MessagePart( new FunctionCall( 'c1', 'wpab__agsafe-smoke__write', array( 'title' => 'x' ) ) ),
			new MessagePart( new FunctionCall( 'c2', 'wpab__agsafe-smoke__read', array( 'id' => 42 ) ) )
		);
		$this->gateway->script[] = self::textTurn( 'Done.' );
		$before                  = $this->store->getRun( $run_id )->stepCount;
		$this->runner->tick( $run_id, $before, array( 'plan' => array( 'action' => 'accept' ) ) );

		$this->assertArrayHasKey( 'after_write', $answers, 'the read must actually have run' );
		$this->assertTrue( $answers['after_write'] );
	}

	public function test_the_context_is_closed_again_when_the_tick_returns(): void {
		GrantEligibility::boot();

		$run_id = $this->createRun();
		$this->store->updateRun( $run_id, array( 'objects_json' => array( '42' => array( 'written_at' => 1 ) ) ) );
		$this->gateway->script[] = self::textTurn( 'Done.' );
		$this->runner->tick( $run_id, 0, null );

		$this->assertFalse(
			self::grantEligible(
				self::grant( self::correlationFor( $run_id ), 'agsafe-smoke/publish' ),
				'agsafe-smoke/publish',
				array( 'id' => 42 )
			)
		);
	}

	// ------------------------------------------------------------------
	// (e) revokeAll on every terminal path
	// ------------------------------------------------------------------

	public function test_completing_revokes_the_runs_grants(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::textTurn( 'Done.' );

		$this->runner->tick( $run_id, 0, null );

		$this->assertSame( 'completed', $this->store->getRun( $run_id )->status->value );
		$this->assertContains( self::correlationFor( $run_id ), $this->grants->revoked );
	}

	public function test_a_budget_failure_revokes_the_runs_grants(): void {
		$run_id = $this->createRun( array( 'max_steps' => 1 ) );
		$this->store->appendStep(
			$run_id,
			\Specflux\SenroFlux\Run\StepKind::User,
			array(
				'role'  => 'user',
				'parts' => array(),
			)
		);

		$before = $this->store->getRun( $run_id )->stepCount;
		$this->runner->tick( $run_id, $before, null );

		$run = $this->store->getRun( $run_id );
		$this->assertSame( RunStatus::Failed, $run->status );
		$this->assertSame( 'budget_exceeded', $run->error['code'] ?? '' );
		$this->assertContains( self::correlationFor( $run_id ), $this->grants->revoked );
	}

	public function test_a_model_error_revokes_the_runs_grants(): void {
		$run_id = $this->createRun(); // No scripted turn: the gateway errors.

		$this->runner->tick( $run_id, 0, null );

		$run = $this->store->getRun( $run_id );
		$this->assertSame( RunStatus::Failed, $run->status );
		$this->assertSame( 'model_error', $run->error['code'] ?? '' );
		$this->assertContains( self::correlationFor( $run_id ), $this->grants->revoked );
	}

	public function test_a_correlation_conflict_revokes_the_runs_grants(): void {
		$run_id                   = $this->createRun();
		RequestContext::$conflict = true;

		$this->runner->tick( $run_id, 0, null );

		$this->assertContains( self::correlationFor( $run_id ), $this->grants->revoked );
	}

	public function test_a_plan_rejected_cancel_revokes_the_runs_grants(): void {
		$run_id                  = $this->createRun( array( 'max_plans' => 1 ) );
		$this->gateway->script[] = self::callTurn( 'call_p', PlanTools::FUNCTION_NAME, self::planArgs( array( array( 'agsafe-smoke/publish' ) ) ) );
		$this->runner->tick( $run_id, 0, null );

		$before = $this->store->getRun( $run_id )->stepCount;
		$result = $this->runner->tick(
			$run_id,
			$before,
			array(
				'plan' => array(
					'action' => 'veto',
					'note'   => 'No.',
				),
			)
		);

		$this->assertIsArray( $result );
		$run = $this->store->getRun( $run_id );
		$this->assertSame( RunStatus::Cancelled, $run->status );
		$this->assertSame( 'plan_rejected', $run->error['code'] ?? '' );
		$this->assertContains( self::correlationFor( $run_id ), $this->grants->revoked );
	}

	public function test_cancelling_from_the_php_api_revokes_the_runs_grants(): void {
		Plugin::reset();
		Plugin::set_dependency_probe( true );
		$plugin = Plugin::instance();

		$prop = new \ReflectionProperty( Plugin::class, 'runner' );
		$prop->setAccessible( true );
		$prop->setValue( $plugin, $this->runner );

		$run_id = $this->createRun();
		$result = $plugin->cancel( $run_id );

		$this->assertIsArray( $result );
		$this->assertSame( 'cancelled', $this->store->getRun( $run_id )->status->value );
		$this->assertContains( self::correlationFor( $run_id ), $this->grants->revoked );
	}

	public function test_revocation_still_happens_with_the_grants_feature_switched_off(): void {
		// Turning AS-12 off must never strand a budget a human already granted.
		$this->grants->enabled   = false;
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::textTurn( 'Done.' );

		$this->runner->tick( $run_id, 0, null );

		$this->assertContains( self::correlationFor( $run_id ), $this->grants->revoked );
	}

	public function test_an_agent_safety_without_grants_is_not_an_error(): void {
		senroflux_test_no_agent_safety();

		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::textTurn( 'Done.' );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'] );
	}
}
