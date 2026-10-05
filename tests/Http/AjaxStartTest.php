<?php
/**
 * `Ajax::handleStart()` (runs-pack fix): the Runs screen's own consumer must
 * never start a pack-less run — its allow-list only exists via the union of
 * registered packs (see `RunsScreen::registerAdminConsumer()`), and a
 * pack-less start has an EMPTY verb map, so every read/plan call is refused
 * fail-closed and the run deadlocks. Other HTTP consumers keep their
 * pre-existing pack-less start.
 *
 * TARGET REPO PATH: tests/Http/AjaxStartTest.php
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Http;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Admin\RunsScreen;
use Specflux\SenroFlux\Approval\ApprovalBridge;
use Specflux\SenroFlux\Http\Ajax;
use Specflux\SenroFlux\Http\ConsumerPolicy;
use Specflux\SenroFlux\Model\ModelGatewayInterface;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Packs\Pack;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Schema;
use Specflux\SenroFlux\Tools\ToolExecutor;
use Specflux\SenroFlux\Tools\ToolRegistry;
use SenroFluxJsonResponse;
use WP_Error;
use wpdb;

final class AjaxStartTest extends TestCase {

	protected function setUp(): void {
		Plugin::reset();
		Plugin::set_dependency_probe( true );
		$GLOBALS['senroflux_test_user_caps']       = array(
			'read'           => true,
			'manage_options' => true,
		);
		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$GLOBALS['senroflux_test_transients']      = array();
		$GLOBALS['senroflux_test_filters']         = array();
		unset( $_POST );
		remove_all_filters( 'senroflux_runs_capability' );
		remove_all_filters( 'senroflux_packs' );
		remove_all_filters( ConsumerPolicy::FILTER );
		add_filter( 'senroflux_runs_capability', static fn (): string => 'manage_options' );
	}

	protected function tearDown(): void {
		remove_all_filters( 'senroflux_runs_capability' );
		remove_all_filters( 'senroflux_packs' );
		remove_all_filters( ConsumerPolicy::FILTER );
		unset( $_POST );
	}

	public function test_runs_consumer_with_a_valid_pack_starts_a_run_carrying_that_pack(): void {
		$this->seedRunnerGraph();
		$this->registerFakePack( 'pages' );
		add_filter( ConsumerPolicy::FILTER, array( new RunsScreen(), 'registerAdminConsumer' ) );

		$_POST = array(
			'consumer' => RunsScreen::CONSUMER,
			'goal'     => 'Publish the spring page',
			'pack'     => 'pages',
			'nonce'    => 'test-nonce',
		);

		$json = $this->captureAjaxStart();

		$this->assertTrue( $json->success );
		$this->assertSame( 'pages', $json->data['run']['pack'] );
	}

	public function test_runs_consumer_without_a_pack_is_refused_bad_request_and_creates_no_run(): void {
		$this->seedRunnerGraph();
		$this->registerFakePack( 'pages' );
		add_filter( ConsumerPolicy::FILTER, array( new RunsScreen(), 'registerAdminConsumer' ) );

		$_POST = array(
			'consumer' => RunsScreen::CONSUMER,
			'goal'     => 'Publish the spring page',
			'nonce'    => 'test-nonce',
		);

		$json = $this->captureAjaxStart();

		$this->assertFalse( $json->success );
		$this->assertSame( 400, $json->status );
		$this->assertSame( 'senroflux_bad_request', $json->code() );

		$table = Schema::runsTable( $GLOBALS['wpdb'] );
		$this->assertSame( array(), $GLOBALS['wpdb']->tables[ $table ] ?? array(), 'no run row may exist after the refusal' );
	}

	public function test_a_non_runs_consumer_may_still_start_pack_less(): void {
		$this->seedRunnerGraph();
		add_filter(
			ConsumerPolicy::FILTER,
			static fn (): array => array(
				'mac' => array( 'allow' => array( 'senroflux/read-content' ) ),
			)
		);

		$_POST = array(
			'consumer' => 'mac',
			'goal'     => 'Summarize traffic',
			'nonce'    => 'test-nonce',
		);

		$json = $this->captureAjaxStart();

		$this->assertTrue( $json->success );
		$this->assertNull( $json->data['run']['pack'] );
	}

	/**
	 * S7: a pack's own (flat-and-high) default budget becomes the ceiling
	 * ConsumerPolicy clamps against when the registered consumer sets NO
	 * budget of its own — mirrors
	 * `ConsumerPolicyTest::test_pack_override_applies_when_consumer_sets_no_budget()`,
	 * exercised end to end through `Ajax::handleStart()`.
	 */
	public function test_pack_budget_ceiling_is_not_clamped_to_the_generic_default(): void {
		$this->seedRunnerGraph();
		$this->registerFakePack( 'site', array( 'max_tool_calls' => 120 ) );
		add_filter(
			ConsumerPolicy::FILTER,
			static fn (): array => array(
				'mac' => array( 'allow' => array( 'senroflux/read-content' ) ),
			)
		);

		$_POST = array(
			'consumer' => 'mac',
			'goal'     => 'Do something site-wide',
			'pack'     => 'site',
			'nonce'    => 'test-nonce',
		);

		$json = $this->captureAjaxStart();

		$this->assertTrue( $json->success );
		$this->assertSame( 120, $json->data['run']['budget']['max_tool_calls'] );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/** Run `Ajax::handleStart()` and capture the JSON it would have died with. */
	private function captureAjaxStart(): SenroFluxJsonResponse {
		try {
			( new Ajax() )->handleStart();
		} catch ( SenroFluxJsonResponse $json ) {
			return $json;
		}

		$this->fail( 'handleStart() returned without sending a JSON response.' );
	}

	/** @param array<string,int> $budget_overrides Pack's own default-budget overrides (S7). */
	private function registerFakePack( string $name, array $budget_overrides = array() ): Pack {
		$pack = new class( $name, $budget_overrides ) extends Pack {
			public function __construct( private readonly string $packName, private readonly array $budgetOverrides ) {
				parent::__construct();
			}

			public function name(): string {
				return $this->packName;
			}

			/** @return list<string> */
			public function allowList(): array {
				return array( 'senroflux/read-content' );
			}

			/** @return array<string,int> */
			public function verbMap(): array {
				return array( 'read' => 0 );
			}

			/** @return array<string,int> */
			public function defaultBudget(): array {
				return $this->budgetOverrides;
			}

			protected function agentSafetyBindingError( int $user_id ): ?WP_Error {
				unset( $user_id );

				return null;
			}

			public function preflight( int $user_id, string $consumer = '', string $goal = '', ?array $skills_disable = null ): bool|WP_Error {
				unset( $user_id, $consumer, $goal, $skills_disable );

				return true;
			}
		};

		add_filter(
			'senroflux_packs',
			static fn ( array $packs ): array => $packs + array( $name => $pack ),
			10,
			1
		);

		return $pack;
	}

	private function seedRunnerGraph(): void {
		$db              = new wpdb();
		$db->queryReturn = 1;
		$GLOBALS['wpdb'] = $db;

		$runner = new Runner(
			new WpdbRunStore( $db ),
			new ToolExecutor(),
			new class() implements ModelGatewayInterface {
				public function generateTurn( array $history, string $system_instruction, ToolRegistry $tools, ?array $model_preference = null ): ModelTurn|WP_Error {
					unset( $history, $system_instruction, $tools );

					return new WP_Error( 'unused', 'no model calls in this test' );
				}
			},
			new ApprovalBridge()
		);

		$prop = new \ReflectionProperty( Plugin::class, 'runner' );
		$prop->setAccessible( true );
		$prop->setValue( Plugin::instance(), $runner );
	}
}
