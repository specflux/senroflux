<?php
/**
 * RunsScreen list-row affordances (0.3 S9, stage 8): park-kind naming, the
 * "Needs you" filter, and stalled-run detection.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Admin;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Admin\RunsScreen;
use Specflux\SenroFlux\Approval\ApprovalBridge;
use Specflux\SenroFlux\Model\ModelGatewayInterface;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Packs\Pack;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\RunStatus;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Setup\Checks;
use Specflux\SenroFlux\Tools\ToolExecutor;
use WP_Error;
use wpdb;

final class RunsScreenListTest extends TestCase {

	protected function setUp(): void {
		Plugin::reset();
		$GLOBALS['senroflux_test_user_caps']       = array( 'manage_options' => true );
		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$_GET                                      = array();
		remove_all_filters( 'senroflux_runs_capability' );
		remove_all_filters( 'senroflux_packs' );
	}

	protected function tearDown(): void {
		// bootstrap.php sets this ONCE, process-wide, as the suite's default
		// ("configured") so ordinary tests never trip the provider check;
		// restore THAT value here, never null — null falls through to the
		// REAL (untestable) provider lookup for every test that runs after
		// this file, which is what broke RunsScreenTest when run after this
		// one during triage of this fix.
		Checks::setProviderProbe( true );
		remove_all_filters( 'senroflux_runs_capability' );
		remove_all_filters( 'senroflux_packs' );
		$_GET = array();
	}

	/**
	 * Defect D (live run): the Runs list showed "Needs you (0) | All (0)"
	 * while runs the viewer owned existed. Root cause: `renderList()` gated
	 * `listRecent()` entirely behind `senroflux()->available()` — which
	 * means "Agent Safety is present", NOT "the plugin can list runs".
	 * SenroFlux's hard dependency on Agent Safety was retired
	 * ({@see \Specflux\SenroFlux\Plugin::ready()}), but this screen never
	 * caught up: on a BUILT-IN-mode install (no Agent Safety —
	 * `dependency_probe(false)`, exactly the live setup), `available()` is
	 * false, so the list was unconditionally emptied regardless of how many
	 * runs actually existed.
	 */
	public function test_the_all_tab_lists_runs_in_built_in_mode_with_no_agent_safety(): void {
		Plugin::set_dependency_probe( false ); // Built-in mode: no Agent Safety, exactly the live setup.
		Checks::setProviderProbe( true ); // Bypass the unrelated provider-configured check.
		add_filter( 'senroflux_runs_capability', static fn (): string => 'manage_options' );

		$db              = new wpdb();
		$db->queryReturn = 1;
		$GLOBALS['wpdb'] = $db;

		$runner = new Runner(
			new WpdbRunStore( $db ),
			new ToolExecutor(),
			new class() implements ModelGatewayInterface {
				public function generateTurn( array $history, string $system_instruction, \Specflux\SenroFlux\Tools\ToolRegistry $tools ): ModelTurn|WP_Error {
					return new WP_Error( 'unused', 'no model calls in this test' );
				}
			},
			new ApprovalBridge()
		);
		$prop   = new \ReflectionProperty( Plugin::class, 'runner' );
		$prop->setAccessible( true );
		$prop->setValue( Plugin::instance(), $runner );

		$pack = new class() extends Pack {
			public function name(): string {
				return 'pages';
			}

			/** @return list<string> */
			public function allowList(): array {
				return array( 'senroflux/read-content' );
			}

			/** @return array<string,int> */
			public function verbMap(): array {
				return array( 'read' => 0 );
			}

			protected function agentSafetyBindingError( int $user_id ): ?WP_Error {
				unset( $user_id );

				return null;
			}

			public function preflight( int $user_id, string $consumer = '', string $goal = '', ?array $skills_disable = null ): true|WP_Error {
				unset( $user_id, $consumer, $goal, $skills_disable );

				return true;
			}
		};
		add_filter( 'senroflux_packs', static fn ( array $packs ): array => $packs + array( 'pages' => $pack ), 10, 1 );

		// Built-in mode only allows RunsScreen::CONSUMER to start a run (S3).
		$started = senroflux()->start( RunsScreen::CONSUMER, 'Publish the about page', array(), array(), 'pages' );
		$this->assertIsArray( $started, 'sanity: the run must actually start, or this test proves nothing — got: ' . ( $started instanceof WP_Error ? $started->get_error_message() : 'n/a' ) );

		$screen = new RunsScreen();
		ob_start();
		$screen->render();
		$html = ob_get_clean();

		$this->assertStringNotContainsString(
			'All (0)',
			$html,
			'a run was just started for this viewer; the All tab must not report zero'
		);
		$this->assertStringContainsString( 'All (1)', $html );
		$this->assertStringNotContainsString( 'No runs yet.', $html );
	}

	public function test_park_kind_label_names_each_park_kind(): void {
		$this->assertSame( 'waiting for your answer', RunsScreen::parkKindLabel( RunStatus::AwaitingUser->value ) );
		$this->assertSame( 'waiting for approval', RunsScreen::parkKindLabel( RunStatus::AwaitingApproval->value ) );
		$this->assertSame( 'waiting on the plan', RunsScreen::parkKindLabel( RunStatus::AwaitingPlan->value ) );
	}

	public function test_park_kind_label_is_null_for_a_non_parked_status(): void {
		$this->assertNull( RunsScreen::parkKindLabel( RunStatus::Running->value ) );
		$this->assertNull( RunsScreen::parkKindLabel( RunStatus::Completed->value ) );
	}

	public function test_list_row_label_prefers_the_park_kind_over_the_ordinary_status_label(): void {
		$run = array(
			'status'  => RunStatus::AwaitingPlan->value,
			'stalled' => false,
		);

		$this->assertSame( 'waiting on the plan', RunsScreen::listRowLabel( $run ) );
	}

	public function test_list_row_label_names_a_stalled_running_run(): void {
		$run = array(
			'status'  => RunStatus::Running->value,
			'stalled' => true,
		);

		$this->assertSame( 'paused while closed — open to continue', RunsScreen::listRowLabel( $run ) );
	}

	public function test_list_row_label_is_the_ordinary_status_label_for_a_live_running_run(): void {
		$run = array(
			'status'  => RunStatus::Running->value,
			'stalled' => false,
		);

		$this->assertSame( RunsScreen::statusLabel( RunStatus::Running->value ), RunsScreen::listRowLabel( $run ) );
	}

	public function test_needs_you_includes_only_parked_runs_the_viewer_may_tick(): void {
		$parked_and_tickable = array(
			'status'          => RunStatus::AwaitingApproval->value,
			'viewer_may_tick' => true,
		);
		$parked_not_tickable = array(
			'status'          => RunStatus::AwaitingApproval->value,
			'viewer_may_tick' => false,
		);
		$running_tickable    = array(
			'status'          => RunStatus::Running->value,
			'viewer_may_tick' => true,
		);
		$stalled_running     = array(
			'status'          => RunStatus::Running->value,
			'viewer_may_tick' => true,
			'stalled'         => true,
		);
		$completed           = array(
			'status'          => RunStatus::Completed->value,
			'viewer_may_tick' => true,
		);

		$this->assertTrue( RunsScreen::needsYou( $parked_and_tickable ) );
		$this->assertFalse( RunsScreen::needsYou( $parked_not_tickable ), 'parked but not this viewer\'s to tick' );
		$this->assertFalse( RunsScreen::needsYou( $running_tickable ), 'running is not a park' );
		$this->assertFalse( RunsScreen::needsYou( $stalled_running ), 'stalled is not a park kind either' );
		$this->assertFalse( RunsScreen::needsYou( $completed ) );
	}
}
