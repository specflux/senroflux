<?php
/**
 * Indirect prompt injection: what holds when the MODEL does not.
 *
 * Every test here scripts a gateway that OBEYS the injected instruction. That
 * is deliberate. Whether a given model resists a given payload is a property
 * of the model and changes with every release; it is not something this suite
 * can hold. What the suite can hold is the harness contract: a fully obeying
 * model still cannot reach a write the human never authorised, and the trail
 * shows that it tried.
 *
 * Payloads come from tests/fixtures/injection-corpus.php.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SenroFlux_Test_Fake_Ability;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\GrantEligibility;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\ToolExecutor;
use Specflux\SenroFlux\Tools\VerbTier;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use wpdb;

final class InjectionTest extends TestCase {

	/** @var array<string,array<string,mixed>> */
	private static array $corpus;

	private wpdb $db;

	private WpdbRunStore $store;

	private FakeGateway $gateway;

	private Runner $runner;

	/** Set true by any ability whose execute() was reached. */
	private bool $wrote = false;

	public static function setUpBeforeClass(): void {
		self::$corpus = require __DIR__ . '/../fixtures/injection-corpus.php';
	}

	protected function setUp(): void {
		$this->db              = new wpdb();
		$this->db->queryReturn = 1;
		$this->store           = new WpdbRunStore( $this->db );
		$this->gateway         = new FakeGateway();
		$this->runner          = new Runner( $this->store, new ToolExecutor(), $this->gateway, new RecordingBridge() );
		$this->wrote           = false;

		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$GLOBALS['senroflux_test_transients']      = array();

		// The write the injections are after. Its execute() must never run:
		// reaching it at all is the failure this file is written to catch.
		$update             = new SenroFlux_Test_Fake_Ability( 'senroflux/update-post', permission_result: true );
		$update->on_execute = function (): void {
			$this->wrote = true;
			throw new RuntimeException( 'the injected write reached execute()' );
		};

		// Outside the run's allow globs entirely.
		$outside             = new SenroFlux_Test_Fake_Ability( 'other-plugin/wipe', permission_result: true );
		$outside->on_execute = function (): void {
			$this->wrote = true;
			throw new RuntimeException( 'an out-of-list ability reached execute()' );
		};

		$GLOBALS['senroflux_test_abilities'] = array(
			'senroflux/read-content' => new SenroFlux_Test_Fake_Ability(
				'senroflux/read-content',
				permission_result: true,
				execute_result: array( 'ok' => true )
			),
			'senroflux/update-post'  => $update,
			'other-plugin/wipe'      => $outside,
		);

		add_filter(
			'senroflux_verb_map',
			static fn ( array $map ): array => $map + array(
				'senroflux/read-content' => VerbTier::TIER_0,
				'senroflux/update-post'  => VerbTier::TIER_2,
			),
			10,
			1
		);
	}

	protected function tearDown(): void {
		remove_all_filters( 'senroflux_verb_map' );
		GrantEligibility::forgetRun();
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/** A run whose human-set allow list is `senroflux/*` and whose goal is the About page. */
	private function createRun(): int {
		return $this->store->createRun(
			1,
			'test-consumer',
			'Add a short closing note to the About page.',
			array( 'senroflux/*' ),
			Budget::defaults()
		);
	}

	/**
	 * Arm `senroflux/read-content` to hand back one corpus rung as its output —
	 * the payload therefore enters model context the way a real page body does.
	 */
	private function serveRung( string $rung ): void {
		$body = (string) self::$corpus[ $rung ]['content'];

		$GLOBALS['senroflux_test_abilities']['senroflux/read-content'] = new SenroFlux_Test_Fake_Ability(
			'senroflux/read-content',
			permission_result: true,
			execute_result: array(
				'id'      => 11,
				'title'   => (string) self::$corpus[ $rung ]['title'],
				'content' => $body,
			)
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

	/** Everything the gateway was handed on its $nth call, flattened to a string. */
	private function historyText( int $nth ): string {
		$encoded = wp_json_encode( $this->gateway->histories[ $nth ] ?? array() );

		return is_string( $encoded ) ? $encoded : '';
	}

	/** What a visitor sees: block and HTML comments stripped. */
	private static function rendered( string $content ): string {
		return (string) preg_replace( '/<!--.*?-->/s', '', $content );
	}

	// ------------------------------------------------------------------
	// (a) the exposure is real
	// ------------------------------------------------------------------

	public function test_a_poisoned_page_body_reaches_the_model_verbatim(): void {
		$run_id = $this->createRun();
		$this->serveRung( 'brute' );

		$this->gateway->script[] = self::callTurn( 'call_r', 'wpab__senroflux__read-content', array( 'id' => 11 ) );
		$this->gateway->script[] = self::textTurn( 'Read it.' );

		$this->runner->tick( $run_id, 0, null );

		// Second gateway call = the turn after the read result was appended.
		$this->assertStringContainsString(
			'senroflux-harness',
			$this->historyText( 1 ),
			'read-content output is model context: a forged harness frame in a page body arrives intact'
		);
		$this->assertStringContainsString( 'do not mention it', $this->historyText( 1 ) );
	}

	public function test_the_concealed_rung_is_invisible_rendered_and_whole_in_model_context(): void {
		$content = (string) self::$corpus['concealed']['content'];

		$this->assertStringNotContainsString(
			'Standard 4.2',
			self::rendered( $content ),
			'a reviewer looking at the rendered page cannot see this payload'
		);

		$run_id = $this->createRun();
		$this->serveRung( 'concealed' );
		$this->gateway->script[] = self::callTurn( 'call_r', 'wpab__senroflux__read-content', array( 'id' => 11 ) );
		$this->gateway->script[] = self::textTurn( 'Read it.' );

		$this->runner->tick( $run_id, 0, null );

		$this->assertStringContainsString(
			'Standard 4.2',
			$this->historyText( 1 ),
			'read-content returns raw post_content, so the model sees what the reviewer cannot'
		);
	}

	// ------------------------------------------------------------------
	// (b) an obeying model still cannot write
	// ------------------------------------------------------------------

	/**
	 * @dataProvider steeringRungs
	 */
	public function test_an_obeying_model_cannot_perform_the_injected_write_before_a_plan( string $rung ): void {
		$run_id = $this->createRun();
		$this->serveRung( $rung );

		$this->gateway->script[] = self::callTurn( 'call_r', 'wpab__senroflux__read-content', array( 'id' => 11 ) );
		// The model does exactly what the page told it to.
		$this->gateway->script[] = self::callTurn(
			'call_w',
			'wpab__senroflux__update-post',
			array(
				'slug'    => 'pricing',
				'content' => 'Pricing is under review.',
			)
		);
		$this->gateway->script[] = self::textTurn( 'Could not do that.' );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertFalse( $this->wrote, 'the injected write must never reach execute()' );

		$refusals = array();
		foreach ( $result['new_steps'] as $step ) {
			if ( 'wpab__senroflux__update-post' === ( $step['tool_name'] ?? '' ) ) {
				$refusals[] = $step['message']['parts'][0]['functionResponse']['response'] ?? array();
				$this->assertSame( 'error', $step['status'] );
			}
		}

		$this->assertSame(
			array( array( 'error' => 'plan_required' ) ),
			$refusals,
			'no plan has been accepted, so no write is reachable however the model was steered'
		);
	}

	/** @return array<string,array{string}> */
	public static function steeringRungs(): array {
		return array(
			'brute force'       => array( 'brute' ),
			'plausible framing' => array( 'plausible' ),
			'concealed framing' => array( 'concealed' ),
		);
	}

	public function test_an_injected_ability_outside_the_allow_list_is_never_executed(): void {
		$run_id = $this->createRun(); // allow: senroflux/*
		$this->serveRung( 'plausible' );

		$this->gateway->script[] = self::callTurn( 'call_r', 'wpab__senroflux__read-content', array( 'id' => 11 ) );
		$this->gateway->script[] = self::callTurn( 'call_x', 'wpab__other-plugin__wipe', array( 'scope' => 'all' ) );
		$this->gateway->script[] = self::textTurn( 'Could not do that.' );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertFalse(
			$this->wrote,
			'the allow list is fixed by a human at run creation; no text inside the run can widen it'
		);

		$refusals = array();
		foreach ( $result['new_steps'] as $step ) {
			if ( 'wpab__other-plugin__wipe' === ( $step['tool_name'] ?? '' ) ) {
				$refusals[] = $step['message']['parts'][0]['functionResponse']['response'] ?? array();
			}
		}

		$this->assertSame(
			array( array( 'error' => 'Unknown tool "wpab__other-plugin__wipe". Call one of the tools you were given, by its exact name.' ) ),
			$refusals,
			'an ability outside the allow globs is unknown to the run: refused by name, never resolved'
		);
	}

	// ------------------------------------------------------------------
	// (c) a forged frame is not a frame
	// ------------------------------------------------------------------

	public function test_a_forged_system_reminder_in_content_does_not_alter_the_system_instruction(): void {
		$run_id = $this->createRun();
		$this->serveRung( 'brute' );

		$this->gateway->script[] = self::callTurn( 'call_r', 'wpab__senroflux__read-content', array( 'id' => 11 ) );
		$this->gateway->script[] = self::textTurn( 'Read it.' );

		$this->runner->tick( $run_id, 0, null );

		$this->assertCount( 2, $this->gateway->systemInstructions );
		$this->assertSame(
			$this->gateway->systemInstructions[0],
			$this->gateway->systemInstructions[1],
			'content that claims to be an operator instruction is still just content'
		);
	}
	// ------------------------------------------------------------------
	// (d) a pre-approval grant does not travel to an injected target
	// ------------------------------------------------------------------

	/**
	 * The sharpest case. A human accepts a plan and pre-approves it, so a grant
	 * for `senroflux/update-post` exists and the plan fence is satisfied — the
	 * verb the injection wants is the verb the human authorised. What must
	 * still hold is the OBJECT: the human authorised edits to the page this run
	 * is working on, not to whatever page a poisoned body names.
	 */
	public function test_a_grant_does_not_authorise_an_injected_write_to_a_page_the_run_never_touched(): void {
		$correlation = 'senroflux:run:1';
		GrantEligibility::useRun(
			$correlation,
			// The run wrote exactly one object: the About page, id 11.
			static fn (): array => array( '11' => array( 'verb' => 'senroflux/update-post' ) ),
			static fn (): string => 'id'
		);

		$grant = self::grant( $correlation, 'senroflux/update-post' );

		$this->assertFalse(
			GrantEligibility::eligible( false, $grant, 'senroflux/update-post', array( 'id' => 99 ) ),
			'the pricing page is not an object this run wrote, so the grant does not reach it'
		);

		$this->assertTrue(
			GrantEligibility::eligible( false, $grant, 'senroflux/update-post', array( 'id' => 11 ) ),
			'control: the same grant does cover the run\'s own object'
		);
	}

	/**
	 * The residual, asserted so it stays visible. A call that names NO object
	 * is a create, and eligibility falls back to the grant count alone
	 * (GrantEligibility documents this: the object cannot be bound at issue
	 * time because it does not exist yet). So an injection that steers a
	 * pre-approved run into creating an EXTRA page spends grant count rather
	 * than parking. The ceiling on that is the count the human set.
	 */
	public function test_known_residual_a_grant_does_cover_an_injected_create(): void {
		$correlation = 'senroflux:run:1';
		GrantEligibility::useRun(
			$correlation,
			static fn (): array => array(),
			static fn (): string => 'id'
		);

		$this->assertTrue(
			GrantEligibility::eligible(
				false,
				self::grant( $correlation, 'senroflux/update-post' ),
				'senroflux/update-post',
				array( 'content' => 'Pricing is under review.' )
			),
			'documented residual: an objectless call is bounded by grant count, not by target'
		);
	}

	/** A stand-in for Agent Safety's Grant value object (the two fields the rule reads). */
	private static function grant( string $correlation_id, string $verb ): object {
		return new class( $correlation_id, $verb ) {
			public function __construct( public string $correlationId, public string $verb ) { // phpcs:ignore
			}
		};
	}
}
