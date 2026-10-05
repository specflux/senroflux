<?php
/**
 * `Rest::routeStart()` (REST-pack fix): `POST /runs` never read a `pack`
 * parameter, so a REST consumer had no way to bind a run to a capability
 * pack — only the admin-ajax path (`Ajax::handleStart()`) accepted one. This
 * mirrors {@see AjaxStartTest} exactly, through the REST route instead, to
 * prove the same pack validation/binding now applies there too.
 *
 * TARGET REPO PATH: tests/Http/RestStartTest.php
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Http;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Admin\RunsScreen;
use Specflux\SenroFlux\Approval\ApprovalBridge;
use Specflux\SenroFlux\Http\ConsumerPolicy;
use Specflux\SenroFlux\Http\Rest;
use Specflux\SenroFlux\Model\ModelGatewayInterface;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Packs\Pack;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Schema;
use Specflux\SenroFlux\Tools\ToolExecutor;
use Specflux\SenroFlux\Tools\ToolRegistry;
use WP_Error;
use WP_REST_Request;
use wpdb;

final class RestStartTest extends TestCase {

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
		remove_all_filters( 'senroflux_runs_capability' );
		remove_all_filters( 'senroflux_packs' );
		remove_all_filters( ConsumerPolicy::FILTER );
		add_filter( 'senroflux_runs_capability', static fn (): string => 'manage_options' );
	}

	protected function tearDown(): void {
		remove_all_filters( 'senroflux_runs_capability' );
		remove_all_filters( 'senroflux_packs' );
		remove_all_filters( ConsumerPolicy::FILTER );
	}

	public function test_runs_consumer_with_a_valid_pack_starts_a_run_carrying_that_pack(): void {
		$this->seedRunnerGraph();
		$this->registerFakePack( 'pages' );
		add_filter( ConsumerPolicy::FILTER, array( new RunsScreen(), 'registerAdminConsumer' ) );

		$response = ( new Rest() )->routeStart(
			new WP_REST_Request(
				array(
					'consumer' => RunsScreen::CONSUMER,
					'goal'     => 'Publish the spring page',
					'pack'     => 'pages',
				)
			)
		);

		$data = $response->get_data();
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'pages', $data['run']['pack'] ?? null );
	}

	public function test_runs_consumer_without_a_pack_is_refused_bad_request_and_creates_no_run(): void {
		$this->seedRunnerGraph();
		$this->registerFakePack( 'pages' );
		add_filter( ConsumerPolicy::FILTER, array( new RunsScreen(), 'registerAdminConsumer' ) );

		$response = ( new Rest() )->routeStart(
			new WP_REST_Request(
				array(
					'consumer' => RunsScreen::CONSUMER,
					'goal'     => 'Publish the spring page',
				)
			)
		);

		$data = $response->get_data();
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'senroflux_bad_request', $data['code'] ?? null );

		$table = Schema::runsTable( $GLOBALS['wpdb'] );
		$this->assertSame( array(), $GLOBALS['wpdb']->tables[ $table ] ?? array(), 'no run row may exist after the refusal' );
	}

	public function test_an_unknown_pack_is_refused_the_same_way_as_start(): void {
		$this->seedRunnerGraph();
		add_filter(
			ConsumerPolicy::FILTER,
			static fn (): array => array(
				'mac' => array( 'allow' => array( 'senroflux/read-content' ) ),
			)
		);

		$response = ( new Rest() )->routeStart(
			new WP_REST_Request(
				array(
					'consumer' => 'mac',
					'goal'     => 'Do something',
					'pack'     => 'no-such-pack',
				)
			)
		);

		$data = $response->get_data();
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'pack_unknown', $data['code'] ?? null );
	}

	public function test_a_non_runs_consumer_may_still_start_pack_less(): void {
		$this->seedRunnerGraph();
		add_filter(
			ConsumerPolicy::FILTER,
			static fn (): array => array(
				'mac' => array( 'allow' => array( 'senroflux/read-content' ) ),
			)
		);

		$response = ( new Rest() )->routeStart(
			new WP_REST_Request(
				array(
					'consumer' => 'mac',
					'goal'     => 'Summarize traffic',
				)
			)
		);

		$data = $response->get_data();
		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'pack', $data['run'] ?? array() );
		$this->assertNull( $data['run']['pack'] );
	}

	/**
	 * S7: a pack's own (flat-and-high) default budget becomes the ceiling
	 * ConsumerPolicy clamps against when the registered consumer sets NO
	 * budget of its own — mirrors `AjaxStartTest`'s equivalent, through REST.
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

		$response = ( new Rest() )->routeStart(
			new WP_REST_Request(
				array(
					'consumer' => 'mac',
					'goal'     => 'Do something site-wide',
					'pack'     => 'site',
				)
			)
		);

		$data = $response->get_data();
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 120, $data['run']['budget']['max_tool_calls'] ?? null );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

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
