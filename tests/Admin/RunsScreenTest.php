<?php
/**
 * RunsScreen tests (stage 9): the new-run form, the S13 preflight gate, the
 * server-side start/answer handlers, and the S5 resume-shape assembly.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Admin;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Admin\RunsScreen;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Approval\ApprovalBridge;
use Specflux\SenroFlux\Http\ConsumerPolicy;
use Specflux\SenroFlux\Packs\Pack;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Setup\Checks;
use Specflux\SenroFlux\Tools\ToolExecutor;
use SenroFluxJsonResponse;
use WP_Error;
use wpdb;

final class RunsScreenTest extends TestCase {

	private const SLUG = 'senroflux-runs';

	protected function setUp(): void {
		Plugin::reset();
		$GLOBALS['senroflux_test_user_caps']       = array( 'manage_options' => true );
		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$GLOBALS['senroflux_test_actions']         = array();
		$GLOBALS['senroflux_test_filters']         = array();
		unset( $GLOBALS['senroflux_test_redirect'] );
		unset( $_POST );
		remove_all_filters( 'senroflux_runs_capability' );
		remove_all_filters( 'senroflux_packs' );
		remove_all_filters( 'senroflux_can_tick' );
		remove_all_filters( ConsumerPolicy::FILTER );
		// 0.3 S10: the screen capability is now computed from the registered
		// packs. Most of this file registers a NAMELESS fixture pack that
		// never overrides runCapability() (it is about the new-run form/
		// preflight gate, not S10's computation, which has its own coverage
		// in ScreenCapabilityTest and the dedicated tests below) — pin the
		// pre-0.3 fixed value so every other test here keeps its original
		// meaning.
		add_filter( 'senroflux_runs_capability', static fn (): string => 'manage_options' );
	}

	protected function tearDown(): void {
		remove_all_filters( 'senroflux_runs_capability' );
		remove_all_filters( 'senroflux_packs' );
		remove_all_filters( 'senroflux_can_tick' );
		remove_all_filters( ConsumerPolicy::FILTER );
	}

	/**
	 * A fake `pages` pack: name() + a scriptable preflight().
	 *
	 * @param bool|WP_Error $preflight The preflight outcome to return.
	 */
	private function fakePack( bool|WP_Error $preflight, string $name = 'pages' ): Pack {
		$pack = new class() extends Pack {
			public bool|WP_Error $preflightResult = true;
			public string $packName               = 'pages';

			/** @var list<string> */
			public array $allow = array( 'senroflux/read-content' );

			public function name(): string {
				return $this->packName;
			}

			/** @return list<string> */
			public function allowList(): array {
				return $this->allow;
			}

			/** @return array<string,int> */
			public function verbMap(): array {
				return array( 'read' => 0 );
			}

			protected function agentSafetyBindingError( int $user_id ): ?WP_Error {
				unset( $user_id );

				return null;
			}

			/** @param list<string>|null $skills_disable Ignored by the double. */
			public function preflight( int $user_id, string $consumer = '', string $goal = '', ?array $skills_disable = null ): true|WP_Error {
				unset( $user_id, $consumer, $goal, $skills_disable );

				return true === $this->preflightResult ? true : $this->preflightResult;
			}
		};

		$pack->preflightResult = $preflight;
		$pack->packName        = $name;

		return $pack;
	}

	private function registerFakePack( bool|WP_Error $preflight, string $name = 'pages' ): Pack {
		$pack = $this->fakePack( $preflight, $name );
		add_filter(
			'senroflux_packs',
			static fn ( array $packs ): array => $packs + array( $name => $pack ),
			10,
			1
		);

		return $pack;
	}

	/**
	 * Register `senroflux-admin` in the consumer map the way `register()` does
	 * in production, so `handleNewRun()` can resolve its policy (S13).
	 */
	private function registerAdminConsumer(): void {
		add_filter(
			ConsumerPolicy::FILTER,
			array( new RunsScreen(), 'registerAdminConsumer' ),
			10,
			1
		);
	}

	// ------------------------------------------------------------------
	// New-run form + preflight gate
	// ------------------------------------------------------------------

	public function test_new_run_form_renders_with_pack_select_when_preflight_passes(): void {
		Plugin::set_dependency_probe( true );
		$this->seedRunnerGraph();
		$this->registerFakePack( true );

		ob_start();
		( new RunsScreen() )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="goal"', $html );
		$this->assertStringContainsString( 'name="pack"', $html );
		$this->assertStringContainsString( '<option value="pages"', $html );
		$this->assertStringContainsString( 'value="senroflux_new_run"', $html );
		$this->assertStringContainsString( 'name="max_steps"', $html );
		$this->assertStringContainsString( 'name="action"', $html );
	}

	public function test_preflight_failure_renders_notice_and_renders_no_form(): void {
		Plugin::set_dependency_probe( true );
		$this->seedRunnerGraph();
		$this->registerFakePack( new WP_Error( 'pack_unbound', 'This pack is not bound to your user. Bind `user:1` to the pages pack.', array( 'status' => 400 ) ) );

		ob_start();
		( new RunsScreen() )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'This pack is not bound', $html );
		$this->assertStringContainsString( 'agent-safety-packs', $html, 'the notice links to the Agent Capability Packs screen' );
		$this->assertStringNotContainsString( 'name="goal"', $html, 'NO form is rendered when preflight fails' );
		$this->assertStringNotContainsString( '<option value="pages"', $html );
	}

	// ------------------------------------------------------------------
	// handleNewRun: one start path through senroflux()->start
	// ------------------------------------------------------------------

	public function test_handle_new_run_starts_a_run_and_redirects_to_detail(): void {
		Plugin::set_dependency_probe( true );
		$this->seedRunnerGraph();
		$this->registerFakePack( true );
		$this->registerAdminConsumer();

		$_POST = array(
			'goal'      => 'Publish the about page',
			'pack'      => 'pages',
			'max_steps' => '10',
		);

		$screen = new class() extends RunsScreen {
			public string $redirectedTo     = '';
			public ?int $redirectedRunId    = null;
			public ?string $redirectedError = null;

			protected function redirectBack( int $run_id, ?string $error_code = null ): void {
				$this->redirectedRunId = $run_id;
				$this->redirectedError = $error_code;
				$this->redirectedTo    = admin_url( 'tools.php?page=senroflux-runs&run_id=' . $run_id );
			}
		};

		$screen->handleNewRun();

		$this->assertSame( 1, $screen->redirectedRunId );
		$this->assertNull( $screen->redirectedError );
		$this->assertStringContainsString( 'run_id=1', $screen->redirectedTo );

		// The budget clamp is lower-only: max_steps=10 is below the shipped default.
		$store = new WpdbRunStore( $GLOBALS['wpdb'] );
		$run   = $store->getRun( 1 );
		$this->assertNotNull( $run );
		$this->assertSame( 10, $run->budget['max_steps'] );
	}

	// ------------------------------------------------------------------
	// Resume-shape assembly (S5) — white-box via a test subclass
	// ------------------------------------------------------------------

	public function test_assemble_answer_resume_choice(): void {
		$screen = new class() extends RunsScreen {
			public function exposeAnswer(): array|WP_Error {
				return $this->assembleAnswerResume();
			}
		};

		$_POST['senroflux_answer_action'] = 'answer';
		$_POST['senroflux_answer_choice'] = 'light';

		$this->assertSame( array( 'answer' => array( 'choice' => 'light' ) ), $screen->exposeAnswer() );
	}

	public function test_assemble_answer_resume_other_text(): void {
		$screen = new class() extends RunsScreen {
			public function exposeAnswer(): array|WP_Error {
				return $this->assembleAnswerResume();
			}
		};

		$_POST['senroflux_answer_action'] = 'answer';
		$_POST['senroflux_answer_choice'] = '__other__';
		$_POST['senroflux_answer_other']  = 'A custom answer';

		$this->assertSame( array( 'answer' => array( 'text' => 'A custom answer' ) ), $screen->exposeAnswer() );
	}

	public function test_assemble_answer_resume_skip(): void {
		$screen = new class() extends RunsScreen {
			public function exposeAnswer(): array|WP_Error {
				return $this->assembleAnswerResume();
			}
		};

		$_POST['senroflux_answer_action'] = 'skip';

		$this->assertSame( array( 'skip' => true ), $screen->exposeAnswer() );
	}

	public function test_assemble_answer_resume_empty_is_rejected(): void {
		$screen = new class() extends RunsScreen {
			public function exposeAnswer(): array|WP_Error {
				return $this->assembleAnswerResume();
			}
		};

		$_POST['senroflux_answer_action'] = 'answer';
		$_POST['senroflux_answer_choice'] = '';
		$_POST['senroflux_answer_text']   = '';

		$result = $screen->exposeAnswer();
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'resume_mismatch', $result->get_error_code() );
	}

	public function test_assemble_plan_resume_veto_requires_a_note(): void {
		$screen = new class() extends RunsScreen {
			public function exposePlan(): array|WP_Error {
				return $this->assemblePlanResume();
			}
		};

		$_POST['senroflux_plan_action'] = 'veto';
		$_POST['senroflux_plan_note']   = '';

		$result = $screen->exposePlan();
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'resume_mismatch', $result->get_error_code() );
	}

	public function test_assemble_plan_resume_accept_optional_note(): void {
		$screen = new class() extends RunsScreen {
			public function exposePlan(): array|WP_Error {
				return $this->assemblePlanResume();
			}
		};

		$_POST['senroflux_plan_action'] = 'accept';

		$this->assertSame( array( 'plan' => array( 'action' => 'accept' ) ), $screen->exposePlan() );
	}

	// ------------------------------------------------------------------
	// handleAnswer: the mismatched-shape path never reaches tick()
	// ------------------------------------------------------------------

	public function test_handle_answer_with_empty_response_redirects_back_with_error(): void {
		$screen = new class() extends RunsScreen {
			public string $redirectedTo     = '';
			public ?int $redirectedRunId    = null;
			public ?string $redirectedError = null;

			protected function redirectBack( int $run_id, ?string $error_code = null ): void {
				$this->redirectedRunId = $run_id;
				$this->redirectedError = $error_code;
				$this->redirectedTo    = admin_url( 'tools.php?page=senroflux-runs&run_id=' . $run_id );
			}
		};

		$_POST = array(
			'run_id'                  => '7',
			'step_count'              => '3',
			'senroflux_answer_action' => 'answer',
		);

		$screen->handleAnswer();

		$this->assertSame( 7, $screen->redirectedRunId );
		$this->assertSame( 'resume_mismatch', $screen->redirectedError );
		$this->assertStringContainsString( 'run_id=7', $screen->redirectedTo );
	}

	// ------------------------------------------------------------------
	// handleApprovalDecision (S5/S6): shape, delegation, and the error the
	// redirect must now carry
	// ------------------------------------------------------------------

	public function test_handle_approval_decision_rejects_an_action_outside_the_park_shape(): void {
		$screen = $this->recordingScreen();

		$_POST = array(
			'run_id'                    => '7',
			'step_count'                => '0',
			'senroflux_approval_action' => 'maybe',
		);

		$screen->handleApprovalDecision();

		$this->assertSame( 7, $screen->redirectedRunId );
		$this->assertSame( 'resume_mismatch', $screen->redirectedError );
	}

	public function test_handle_approval_decision_surfaces_a_failed_tick_in_the_redirect(): void {
		Plugin::set_dependency_probe( true );
		$this->seedRunnerGraph();

		$screen = $this->recordingScreen();

		$_POST = array(
			'run_id'                    => '404',
			'step_count'                => '0',
			'senroflux_approval_action' => 'approve',
		);

		$screen->handleApprovalDecision();

		// The handler used to DISCARD the tick result, so this redirected back
		// to an unchanged card with no explanation at all.
		$this->assertSame( 404, $screen->redirectedRunId );
		$this->assertNotNull( $screen->redirectedError, 'a failed tick must surface an error code' );
		$this->assertSame( 'senroflux_not_found', $screen->redirectedError );
	}

	public function test_handle_approval_decision_without_the_capability_redirects_with_forbidden(): void {
		Plugin::set_dependency_probe( true );
		$this->seedRunnerGraph();
		$GLOBALS['senroflux_test_user_caps'] = array( 'read' => true );

		$screen = $this->recordingScreen();

		$_POST = array(
			'run_id'                    => '1',
			'step_count'                => '0',
			'senroflux_approval_action' => 'approve',
		);

		$screen->handleApprovalDecision();

		$this->assertSame( 'senroflux_forbidden', $screen->redirectedError );
	}

	// ------------------------------------------------------------------
	// S13 one start path: the admin consumer + the policy seam
	// ------------------------------------------------------------------

	public function test_register_admin_consumer_only_registers_for_capability_holders(): void {
		$this->registerFakePack( true );
		$screen = new RunsScreen();

		$this->assertArrayHasKey( 'senroflux-admin', $screen->registerAdminConsumer( array() ) );

		$GLOBALS['senroflux_test_user_caps'] = array( 'read' => true );
		$this->assertArrayNotHasKey( 'senroflux-admin', $screen->registerAdminConsumer( array() ) );
	}

	public function test_the_admin_consumer_carries_the_pack_allow_list_and_the_default_ceiling(): void {
		$this->registerFakePack( true );

		$entry = ( new RunsScreen() )->registerAdminConsumer( array() )['senroflux-admin'];

		$this->assertSame( array( 'senroflux/read-content' ), $entry['allow'] );
		$this->assertSame( Budget::defaults(), $entry['budget'] );
	}

	public function test_handle_new_run_is_refused_when_the_admin_consumer_is_not_registered(): void {
		Plugin::set_dependency_probe( true );
		$this->seedRunnerGraph();
		$this->registerFakePack( true );
		// Deliberately DO NOT register the consumer: the start must fail at the
		// policy seam rather than sliding past it.

		$screen = $this->recordingScreen();

		$_POST = array(
			'goal' => 'Publish the about page',
			'pack' => 'pages',
		);

		$screen->handleNewRun();

		$this->assertSame( 'senroflux_unknown_consumer', $screen->redirectedListError );
	}

	// ------------------------------------------------------------------
	// S13 preflight is PER PACK
	// ------------------------------------------------------------------

	public function test_a_blocked_pack_is_disabled_while_a_runnable_pack_stays_selectable(): void {
		Plugin::set_dependency_probe( true );
		$this->seedRunnerGraph();
		$this->registerFakePack( true );
		$this->registerFakePack(
			new WP_Error( 'pack_unbound', 'Bind `user:1` to the widgets pack.', array( 'status' => 400 ) ),
			'widgets'
		);

		ob_start();
		( new RunsScreen() )->render();
		$html = (string) ob_get_clean();

		// The form still stands, because ONE pack is runnable…
		$this->assertStringContainsString( 'name="goal"', $html );
		$this->assertStringContainsString( '<option value="pages" selected>', $html );
		// …and the blocked one is visible but unselectable, with its reason.
		$this->assertStringContainsString( '<option value="widgets" disabled>', $html );
		$this->assertStringContainsString( 'Bind `user:1` to the widgets pack.', $html );
	}

	public function test_every_pack_blocked_renders_the_notice_and_no_form(): void {
		Plugin::set_dependency_probe( true );
		$this->seedRunnerGraph();
		$this->registerFakePack( new WP_Error( 'pack_unbound', 'Bind `user:1` to the pages pack.', array( 'status' => 400 ) ) );
		$this->registerFakePack(
			new WP_Error( 'pack_unbound', 'Bind `user:1` to the widgets pack.', array( 'status' => 400 ) ),
			'widgets'
		);

		ob_start();
		( new RunsScreen() )->render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'name="goal"', $html );
		$this->assertStringContainsString( 'agent-safety-packs', $html );
	}

	// ------------------------------------------------------------------
	// Capability filter + wiring
	// ------------------------------------------------------------------

	public function test_capability_defaults_to_manage_options_and_is_filterable(): void {
		add_filter(
			'senroflux_runs_capability',
			static fn (): string => 'edit_pages',
			10,
			1
		);

		$this->assertSame( 'edit_pages', ( new RunsScreen() )->capability() );
	}

	/** S10: the menu/screen capability is computed from the REGISTERED packs, no filter needed. */
	public function test_an_editor_with_edit_pages_gets_the_menu_capability_edit_pages(): void {
		remove_all_filters( 'senroflux_runs_capability' ); // Override this file's blanket pin.
		$this->fakePackWithCapability( 'pages', 'edit_pages' );
		$this->fakePackWithCapability( 'site', 'manage_options' );
		$GLOBALS['senroflux_test_user_caps'] = array( 'edit_pages' => true );

		$this->assertSame( 'edit_pages', ( new RunsScreen() )->capability() );
	}

	/** S10: a Subscriber holding no pack's run capability gets `do_not_allow`. */
	public function test_a_subscriber_gets_do_not_allow(): void {
		remove_all_filters( 'senroflux_runs_capability' );
		$this->fakePackWithCapability( 'pages', 'edit_pages' );
		$GLOBALS['senroflux_test_user_caps'] = array( 'read' => true );

		$this->assertSame( 'do_not_allow', ( new RunsScreen() )->capability() );
	}

	/** S10: an editor may start the pages pack but not the (manage_options) site pack. */
	public function test_an_editor_can_start_pages_but_not_site(): void {
		remove_all_filters( 'senroflux_runs_capability' );
		Plugin::set_dependency_probe( false ); // built-in mode: runCapability() is the whole test.
		$this->seedRunnerGraph();
		$this->fakePackWithCapability( 'pages', 'edit_pages' );
		$this->fakePackWithCapability( 'site', 'manage_options' );
		$GLOBALS['senroflux_test_user_caps'] = array( 'edit_pages' => true );
		$this->registerAdminConsumer();

		$screen = $this->recordingScreen();
		$_POST  = array(
			'goal' => 'Write a page',
			'pack' => 'site',
		);
		$screen->handleNewRun();

		// The screen itself is reachable (edit_pages unlocks it), but the
		// SITE pack's own capability check still refuses — pack-level
		// governance, unwidened by the screen capability computation.
		$this->assertSame( 'pack_unbound', $screen->redirectedListError );
	}

	/** A fixture pack with a chosen run capability, registered under `senroflux_packs`. */
	private function fakePackWithCapability( string $name, string $capability ): void {
		$pack = new class( $name, $capability ) extends Pack {
			public function __construct( private readonly string $packName, private readonly string $capability ) {
				parent::__construct( array( 'read' => 'read-content' ) );
			}

			public function name(): string {
				return $this->packName;
			}

			public function verbMap(): array {
				return array();
			}

			public function runCapability(): string {
				return $this->capability;
			}

			protected function agentSafetyBindingError( int $user_id ): ?WP_Error {
				unset( $user_id );

				return null;
			}
		};

		add_filter(
			'senroflux_packs',
			static fn ( array $packs ): array => $packs + array( $name => $pack ),
			10,
			1
		);
	}

	public function test_register_wires_menu_assets_and_all_post_endpoints(): void {
		( new RunsScreen() )->register();

		$hooks = array_keys( $GLOBALS['senroflux_test_actions'] );
		$this->assertContains( 'admin_menu', $hooks );
		$this->assertContains( 'admin_enqueue_scripts', $hooks );
		$this->assertContains( 'admin_post_senroflux_cancel_run', $hooks );
		$this->assertContains( 'admin_post_senroflux_new_run', $hooks );
		$this->assertContains( 'admin_post_senroflux_answer', $hooks );
		$this->assertContains( 'admin_post_senroflux_plan_decision', $hooks );
		$this->assertContains( 'admin_post_senroflux_approval_decision', $hooks );
		$this->assertContains( 'admin_init', $hooks, 'the old tools.php URL redirect (S10)' );
		$this->assertContains( 'wp_ajax_senroflux_setup_panel', $hooks );
		$this->assertContains( 'wp_ajax_senroflux_dismiss_agent_safety_check', $hooks );
		$this->assertContains( 'admin_notices', $hooks, 'the one-time activation notice (S10)' );
	}

	// ------------------------------------------------------------------
	// S10: old tools.php URL redirect + the Plugins-screen activation notice
	// ------------------------------------------------------------------

	private function redirectingScreen(): RunsScreen {
		return new class() extends RunsScreen {
			public ?string $redirectedUrl = null;

			protected function redirectAndExit( string $url ): void {
				$this->redirectedUrl = $url;
			}
		};
	}

	public function test_the_old_tools_url_redirects_to_the_top_level_menu(): void {
		global $pagenow;
		$pagenow = 'tools.php';
		$_GET    = array(
			'page'   => 'senroflux-runs',
			'run_id' => '7',
		);

		$screen = $this->redirectingScreen();
		$screen->redirectOldToolsUrl();

		$this->assertNotNull( $screen->redirectedUrl );
		$this->assertStringContainsString( 'admin.php', $screen->redirectedUrl );
		$this->assertStringContainsString( 'page=senroflux-runs', $screen->redirectedUrl );
		$this->assertStringContainsString( 'run_id=7', $screen->redirectedUrl );

		unset( $_GET );
	}

	public function test_a_different_tools_page_is_left_alone(): void {
		global $pagenow;
		$pagenow = 'tools.php';
		$_GET    = array( 'page' => 'something-else' );

		$screen = $this->redirectingScreen();
		$screen->redirectOldToolsUrl();

		$this->assertNull( $screen->redirectedUrl );

		unset( $_GET );
	}

	public function test_a_non_tools_page_is_left_alone(): void {
		global $pagenow;
		$pagenow = 'admin.php';
		$_GET    = array( 'page' => 'senroflux-runs' );

		$screen = $this->redirectingScreen();
		$screen->redirectOldToolsUrl();

		$this->assertNull( $screen->redirectedUrl );

		unset( $_GET );
	}

	public function test_the_activation_notice_shows_once_on_the_plugins_screen_only(): void {
		global $pagenow;
		$pagenow                              = 'plugins.php';
		$GLOBALS['senroflux_test_transients'] = array( RunsScreen::ACTIVATION_NOTICE_TRANSIENT => 1 );

		ob_start();
		( new RunsScreen() )->maybeRenderActivationNotice();
		$first = (string) ob_get_clean();

		ob_start();
		( new RunsScreen() )->maybeRenderActivationNotice();
		$second = (string) ob_get_clean();

		$this->assertStringContainsString( 'SenroFlux is active', $first );
		$this->assertSame( '', $second, 'the transient is cleared after the first render — it never shows twice' );
	}

	public function test_the_activation_notice_never_renders_off_the_plugins_screen(): void {
		global $pagenow;
		$pagenow                              = 'admin.php';
		$GLOBALS['senroflux_test_transients'] = array( RunsScreen::ACTIVATION_NOTICE_TRANSIENT => 1 );

		ob_start();
		( new RunsScreen() )->maybeRenderActivationNotice();
		$html = (string) ob_get_clean();

		$this->assertSame( '', $html );
	}

	// ------------------------------------------------------------------
	// S11: the setup panel (harness checks) + its two ajax endpoints
	// ------------------------------------------------------------------

	public function test_the_setup_panel_shows_the_agent_safety_advisory_when_absent(): void {
		Plugin::set_dependency_probe( false );
		Checks::setProviderProbe( true );
		$this->seedRunnerGraph();
		$this->registerFakePack( true );

		ob_start();
		( new RunsScreen() )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'senroflux-setup-panel', $html );
		$this->assertStringContainsString( 'data-check-id="senroflux/agent-safety"', $html );
		$this->assertStringContainsString( 'senroflux-dismiss-check', $html );
		// The empty state/example goals still render below a merely-advisory panel.
		$this->assertStringContainsString( 'name="goal"', $html );

		Checks::setProviderProbe( null );
	}

	public function test_the_setup_panel_is_empty_when_everything_is_clear(): void {
		Plugin::set_dependency_probe( true );
		Checks::setProviderProbe( true );

		ob_start();
		( new RunsScreen() )->render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'senroflux-setup-check', $html );

		Checks::setProviderProbe( null );
	}

	public function test_the_start_button_is_disabled_server_side_when_the_provider_check_fails(): void {
		Plugin::set_dependency_probe( true );
		Checks::setProviderProbe( false );
		$this->seedRunnerGraph();
		$this->registerFakePack( true );

		ob_start();
		( new RunsScreen() )->render();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/id="senroflux-start-run"[^>]*\bdisabled\b/', $html );

		Checks::setProviderProbe( null );
	}

	public function test_handle_setup_panel_reports_start_disabled_when_the_provider_check_fails(): void {
		Checks::setProviderProbe( false );

		$screen = new RunsScreen();
		$_POST  = array( 'nonce' => 'x' );

		try {
			$screen->handleSetupPanel();
			$this->fail( 'handleSetupPanel() returned without sending a JSON response.' );
		} catch ( SenroFluxJsonResponse $json ) {
			$this->assertTrue( $json->success );
			$this->assertFalse( $json->data['start_enabled'] );
			$this->assertStringContainsString( 'senroflux-setup-panel', $json->data['html'] );
		} finally {
			Checks::setProviderProbe( null );
		}
	}

	public function test_handle_setup_panel_refuses_without_the_capability(): void {
		remove_all_filters( 'senroflux_runs_capability' );
		$GLOBALS['senroflux_test_user_caps'] = array( 'read' => true );

		$screen = new RunsScreen();
		$_POST  = array( 'nonce' => 'x' );

		try {
			$screen->handleSetupPanel();
			$this->fail( 'handleSetupPanel() returned without sending a JSON response.' );
		} catch ( SenroFluxJsonResponse $json ) {
			$this->assertFalse( $json->success );
			$this->assertSame( 403, $json->status );
		}
	}

	public function test_handle_dismiss_agent_safety_check_records_the_dismissal_for_the_current_user_only(): void {
		Plugin::set_dependency_probe( false );
		$GLOBALS['senroflux_test_current_user_id'] = 5;

		$screen = new RunsScreen();
		$_POST  = array( 'nonce' => 'x' );

		try {
			$screen->handleDismissAgentSafetyCheck();
			$this->fail( 'handleDismissAgentSafetyCheck() returned without sending a JSON response.' );
		} catch ( SenroFluxJsonResponse $json ) {
			$this->assertTrue( $json->success );
			$this->assertStringNotContainsString( 'senroflux/agent-safety', $json->data['html'] );
		}

		$this->assertTrue( Checks::agentSafetyDismissedBy( 5 ) );
		$this->assertFalse( Checks::agentSafetyDismissedBy( 6 ), 'a different user is unaffected' );
	}

	/**
	 * A screen that records where it redirected instead of exiting.
	 */
	private function recordingScreen(): RunsScreen {
		return new class() extends RunsScreen {
			public string $redirectedTo         = '';
			public ?int $redirectedRunId        = null;
			public ?string $redirectedError     = null;
			public ?string $redirectedListError = null;

			protected function redirectBack( int $run_id, ?string $error_code = null ): void {
				$this->redirectedRunId = $run_id;
				$this->redirectedError = $error_code;
				$this->redirectedTo    = admin_url( 'tools.php?page=senroflux-runs&run_id=' . $run_id );
			}

			protected function redirectList( string $error_code ): void {
				$this->redirectedListError = $error_code;
				$this->redirectedTo        = admin_url( 'tools.php?page=senroflux-runs' );
			}
		};
	}

	// ------------------------------------------------------------------
	// Graph seeding
	// ------------------------------------------------------------------

	/**
	 * Wire a real runner graph into the container so start()/tick() resolve
	 * against an emulated-wpdb store (mirrors the original suite).
	 */
	private function seedRunnerGraph(): void {
		$db              = new wpdb();
		$db->queryReturn = 1;
		$GLOBALS['wpdb'] = $db;

		$runner = new Runner(
			new WpdbRunStore( $db ),
			new ToolExecutor(),
			new class() implements \Specflux\SenroFlux\Model\ModelGatewayInterface {
				public function generateTurn( array $history, string $system_instruction, \Specflux\SenroFlux\Tools\ToolRegistry $tools ): \Specflux\SenroFlux\Model\ModelTurn|\WP_Error {
					return new WP_Error( 'unused', 'no model calls on this screen' );
				}
			},
			new ApprovalBridge()
		);

		$prop = new \ReflectionProperty( Plugin::class, 'runner' );
		$prop->setAccessible( true );
		$prop->setValue( Plugin::instance(), $runner );
	}
}
