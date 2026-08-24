<?php
/**
 * RunsScreen tests (stage 7): capability filter, wiring, list rendering,
 * cancel flow.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Admin;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Admin\RunsScreen;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Approval\ApprovalBridge;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\ToolExecutor;
use wpdb;

final class RunsScreenTest extends TestCase {

	protected function setUp(): void {
		Plugin::reset();
		$GLOBALS['senroflux_test_user_caps']       = array( 'manage_options' => true );
		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$GLOBALS['senroflux_test_actions']         = array();
		unset( $GLOBALS['senroflux_test_redirect'] );
		remove_all_filters( 'senroflux_runs_capability' );
	}

	public function test_capability_defaults_to_manage_options_and_is_filterable(): void {
		add_filter(
			'senroflux_runs_capability',
			static fn (): string => 'edit_pages',
			10,
			1
		);

		$this->assertSame( 'edit_pages', ( new RunsScreen() )->capability() );

		remove_all_filters( 'senroflux_runs_capability' );
		$this->assertSame( 'manage_options', ( new RunsScreen() )->capability() );
	}

	public function test_register_wires_menu_assets_and_cancel_endpoint(): void {
		$GLOBALS['senroflux_test_actions'] = array();

		( new RunsScreen() )->register();

		$hooks = array_keys( $GLOBALS['senroflux_test_actions'] );
		$this->assertNotEmpty( $hooks, 'no hooks recorded at all' );
		$this->assertContains( 'admin_menu', $hooks );
		$this->assertContains( 'admin_enqueue_scripts', $hooks );
		$this->assertContains( 'admin_post_senroflux_cancel_run', $hooks );
	}

	public function test_render_list_shows_runs_with_status_badges(): void {
		Plugin::set_dependency_probe( true );

		$db              = new wpdb();
		$db->queryReturn = 1;
		$GLOBALS['wpdb'] = $db; // The PHP API requires a database handle.
		$this->seedRunnerGraph( $db );

		$store = $this->graphStore( $db );

		$store->createRun( 1, 'specflux-mac', 'Clear the cache please', array( 'agsafe-smoke/*' ), array() );
		$second = $store->createRun( 2, 'other-consumer', 'goal two', array(), array() );
		$store->updateRun( $second, array( 'status' => 'completed' ) );

		ob_start();
		( new RunsScreen() )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Clear the cache please', $html );
		$this->assertStringContainsString( 'senroflux-badge-completed', $html );
		$this->assertStringContainsString( 'specflux-mac', $html );
	}

	public function test_cancel_handler_redirects_back_to_the_detail_view(): void {
		Plugin::set_dependency_probe( true );

		$db              = new wpdb();
		$db->queryReturn = 1;
		$GLOBALS['wpdb'] = $db; // The PHP API requires a database handle.
		$this->seedRunnerGraph( $db );

		$_GET['run_id'] = '999'; // Unknown id: cancel reports not-found, screen still redirects back.

		$screen = new class() extends RunsScreen {
			public string $redirectedTo = '';

			protected function redirectBack( int $run_id ): void {
				$this->redirectedTo = admin_url( 'tools.php?page=senroflux-runs&run_id=' . $run_id );
				unset( $run_id );
			}
		};

		$screen->handleCancel();

		$this->assertStringContainsString(
			'tools.php?page=senroflux-runs&run_id=999',
			$screen->redirectedTo
		);
	}

	/**
	 * Wire a real runner graph into the container despite no global $wpdb:
	 * reflectively set the private runner with an emulated-wpdb store.
	 */
	private function seedRunnerGraph( wpdb $db ): void {
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

	private function graphStore( wpdb $db ): WpdbRunStore {
		return new WpdbRunStore( $db );
	}
}
