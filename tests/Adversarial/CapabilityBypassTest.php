<?php
/**
 * S22 gate robustness suite: a REST call and an admin-post call, each
 * without the run capability, must both be refused — never silently
 * carried out.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Adversarial;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Admin\RunsScreen;
use Specflux\SenroFlux\Approval\ApprovalBridge;
use Specflux\SenroFlux\Http\Rest;
use Specflux\SenroFlux\Model\ModelGatewayInterface;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\StepKind;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\ToolExecutor;
use WP_Error;
use WP_REST_Request;
use wpdb;

final class CapabilityBypassTest extends TestCase {

	protected function setUp(): void {
		require_once __DIR__ . '/support.php';

		Plugin::reset();
		Plugin::set_dependency_probe( true );
		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$GLOBALS['senroflux_test_transients']      = array();
		$GLOBALS['senroflux_test_filters']         = array();
		unset( $_POST );
		remove_all_filters( 'senroflux_runs_capability' );
		add_filter( 'senroflux_runs_capability', static fn (): string => 'manage_options' );
	}

	protected function tearDown(): void {
		remove_all_filters( 'senroflux_runs_capability' );
		remove_all_filters( 'senroflux_can_tick' );
		unset( $_POST, $GLOBALS['wpdb'] );
		Plugin::reset();
	}

	/**
	 * REST: `Rest::routeSuggestionDecision()` (0.3 S20) re-checks
	 * `manage_options` INSIDE the callback body (fail closed, B0 rule 2) — a
	 * request that somehow slipped past `permission_callback` is still
	 * refused here, with the concrete outcome a caller can act on.
	 */
	public function test_rest_suggestion_decision_without_the_run_capability_is_refused_403(): void {
		$GLOBALS['senroflux_test_user_caps'] = array( 'read' => true ); // No manage_options.

		$response = ( new Rest() )->routeSuggestionDecision(
			new WP_REST_Request(
				array(
					'run_id' => 1,
					'seq'    => 1,
					'action' => 'save',
					'text'   => 'Always mention free shipping.',
				)
			)
		);

		$data = $response->get_data();
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'senroflux_forbidden', $data['code'] ?? null );
	}

	/**
	 * admin-post: `RunsScreen::handleApprovalDecision()` without the
	 * delegation capability (`senroflux_runs_capability`) redirects with
	 * `senroflux_forbidden` rather than acting on the park — the ownership
	 * gate refuses even a syntactically valid decision.
	 */
	public function test_admin_post_approval_decision_without_the_run_capability_redirects_forbidden(): void {
		$this->seedRunnerGraph();
		$GLOBALS['senroflux_test_user_caps'] = array( 'read' => true ); // No manage_options: cannot act for another owner.

		$screen = new class() extends RunsScreen {
			public ?string $redirectedError = null;

			protected function redirectBack( int $run_id, ?string $error_code = null ): void {
				unset( $run_id );
				$this->redirectedError = $error_code;
			}
		};

		$store  = new WpdbRunStore( $GLOBALS['wpdb'] );
		$run_id = $store->createRun(
			42, // A DIFFERENT owner than the current user (1).
			'test-consumer',
			'A goal',
			array( 'senroflux/read-content' ),
			Budget::defaults()
		);
		$store->appendStep( $run_id, StepKind::Plan, array( 'text' => 'A plan.' ), null, 'plan_approval_123', 'ok' );

		$_POST = array(
			'run_id'                    => (string) $run_id,
			'step_count'                => '0',
			'senroflux_approval_action' => 'approve',
		);

		$screen->handleApprovalDecision();

		$this->assertSame( 'senroflux_forbidden', $screen->redirectedError );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	private function seedRunnerGraph(): void {
		$db              = new wpdb();
		$db->queryReturn = 1;
		$GLOBALS['wpdb'] = $db;

		$runner = new Runner(
			new WpdbRunStore( $db ),
			new ToolExecutor(),
			new class() implements ModelGatewayInterface {
				public function generateTurn( array $history, string $system_instruction, \Specflux\SenroFlux\Tools\ToolRegistry $tools, ?array $model_preference = null ): ModelTurn|WP_Error {
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
