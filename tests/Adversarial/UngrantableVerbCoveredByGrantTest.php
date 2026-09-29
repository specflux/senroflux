<?php
/**
 * S22 gate robustness suite: an ungrantable Tier-2 verb (S19,
 * `Pack::ungrantableVerbs()`) must never receive a pre-approval grant, even
 * when an accepted plan lists it — using the REAL commerce pack's own
 * `commerce/refund`, not a stand-in list.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Adversarial;

use PHPUnit\Framework\TestCase;
use SenroFlux_Test_Fake_Ability;
use SenroFlux_Test_Grants;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\SenroFlux\Approval\GrantBridge;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Packs\Commerce\CommercePack;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\GrantEligibility;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Tools\PlanTools;
use Specflux\SenroFlux\Tools\ToolExecutor;
use Specflux\SenroFlux\Tests\Run\FakeGateway;
use Specflux\SenroFlux\Tests\Run\RecordingBridge;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use wpdb;

final class UngrantableVerbCoveredByGrantTest extends TestCase {

	private wpdb $db;

	private \Specflux\SenroFlux\Run\WpdbRunStore $store;

	private FakeGateway $gateway;

	private SenroFlux_Test_Grants $grants;

	private CommercePack $pack;

	protected function setUp(): void {
		$this->db              = new wpdb();
		$this->db->queryReturn = 1;
		$GLOBALS['wpdb']       = $this->db;
		$this->store           = new \Specflux\SenroFlux\Run\WpdbRunStore( $this->db );
		$this->gateway         = new FakeGateway();
		$this->pack            = new CommercePack();

		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$GLOBALS['senroflux_test_transients']      = array();
		$GLOBALS['senroflux_test_filters']         = array();
		$GLOBALS['senroflux_test_abilities']       = array(
			'commerce/refund'        => new SenroFlux_Test_Fake_Ability( 'commerce/refund', true, array( 'ok' => true ) ),
			'commerce/coupon-enable' => new SenroFlux_Test_Fake_Ability( 'commerce/coupon-enable', true, array( 'id' => 7 ) ),
		);

		RequestContext::reset();
		$this->grants = senroflux_test_grants( true );
		add_filter( 'senroflux_enable_preapproval', static fn (): bool => true, 10, 1 );
	}

	protected function tearDown(): void {
		remove_all_filters();
		RequestContext::reset();
		GrantEligibility::forgetRun();
		senroflux_test_no_agent_safety();
		Plugin::reset();
		Plugin::set_dependency_probe( null );
	}

	private static function turn( MessagePart ...$parts ): ModelTurn {
		return new ModelTurn( new ModelMessage( $parts ), 10, 5 );
	}

	private static function textTurn( string $text ): ModelTurn {
		return self::turn( new MessagePart( $text ) );
	}

	private static function callTurn( string $call_id, string $tool, array $args = array() ): ModelTurn {
		return self::turn( new MessagePart( new FunctionCall( $call_id, $tool, $args ) ) );
	}

	/**
	 * `commerce/refund` is a real, currently-registered ungrantable Tier-2
	 * verb (S19, {@see CommercePack::ungrantableVerbs()}) — proves the
	 * gate reads the PACK's own list, not a fixture stand-in for it.
	 */
	public function test_the_commerce_packs_own_refund_verb_never_receives_a_preapproval_grant(): void {
		$this->assertContains( 'commerce/refund', $this->pack->ungrantableVerbs(), 'pin: this test is meaningless if refund stops being ungrantable' );

		$pack   = $this->pack;
		$runner = new Runner(
			$this->store,
			new ToolExecutor(),
			$this->gateway,
			new RecordingBridge(),
			null,
			static fn (): array => $pack->verbMap(),
			null,
			null,
			null,
			new GrantBridge(),
			static fn ( $run, string $pack_verb ): ?string => 'senroflux/' . str_replace( 'commerce/', '', $pack_verb ),
			null,
			null,
			static fn ( $run ): array => $pack->ungrantableVerbs() // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- the real resolver shape is callable(Run):list<string>.
		);

		$run_id                  = $this->store->createRun( 1, 'test-consumer', 'Refund an order', array( 'commerce/*' ), Budget::defaults() );
		$this->gateway->script[] = self::callTurn(
			'call_p',
			PlanTools::FUNCTION_NAME,
			array(
				'goal'        => 'Refund an order',
				'steps'       => array(
					array(
						'text'  => 'Issue the refund',
						'verbs' => array( 'commerce/refund' ),
					),
				),
				'assumptions' => array(),
			)
		);
		$parked                  = $runner->tick( $run_id, 0, null );
		$this->assertSame( 'awaiting_plan', $parked['run']['status'] );

		$this->gateway->script[] = self::textTurn( 'Plan accepted.' );
		$before                  = $this->store->getRun( $run_id )->stepCount;
		$runner->tick( $run_id, $before, array( 'plan' => array( 'action' => 'accept_preapprove' ) ) );

		$this->assertSame(
			array(),
			$this->grants->issued,
			'commerce/refund is ungrantable: accept_preapprove must issue it NO grant, however the plan lists it'
		);
	}

	/**
	 * The same plan, but a grantable Tier-2 verb (`commerce/coupon-enable`,
	 * an ORDINARY grantable verb sharing neither ability nor pack-verb with
	 * refund or the pack's OTHER ungrantable verb, `commerce/order-note-customer`)
	 * rides alongside it — proves the ungrantable verb is skipped
	 * specifically, not that pre-approval broke wholesale.
	 */
	public function test_a_grantable_sibling_verb_in_the_same_plan_still_gets_its_grant(): void {
		$pack   = $this->pack;
		$runner = new Runner(
			$this->store,
			new ToolExecutor(),
			$this->gateway,
			new RecordingBridge(),
			null,
			static fn (): array => $pack->verbMap(),
			null,
			null,
			null,
			new GrantBridge(),
			static fn ( $run, string $pack_verb ): ?string => 'senroflux/' . str_replace( 'commerce/', '', $pack_verb ),
			null,
			null,
			static fn ( $run ): array => $pack->ungrantableVerbs() // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		);

		$run_id                  = $this->store->createRun( 1, 'test-consumer', 'Enable a coupon and refund', array( 'commerce/*' ), Budget::defaults() );
		$this->gateway->script[] = self::callTurn(
			'call_p',
			PlanTools::FUNCTION_NAME,
			array(
				'goal'        => 'Enable a coupon and refund',
				'steps'       => array(
					array(
						'text'  => 'Enable the coupon, then refund',
						'verbs' => array( 'commerce/coupon-enable', 'commerce/refund' ),
					),
				),
				'assumptions' => array(),
			)
		);
		$parked                  = $runner->tick( $run_id, 0, null );
		$this->assertSame( 'awaiting_plan', $parked['run']['status'] );

		$this->gateway->script[] = self::textTurn( 'Plan accepted.' );
		$before                  = $this->store->getRun( $run_id )->stepCount;
		$runner->tick( $run_id, $before, array( 'plan' => array( 'action' => 'accept_preapprove' ) ) );

		$this->assertCount( 1, $this->grants->issued );
		$this->assertSame( 'senroflux/coupon-enable', $this->grants->issued[0]['verb'] );
	}
}
