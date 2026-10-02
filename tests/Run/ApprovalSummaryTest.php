<?php
/**
 * The Runs-screen approval card carries the packs' human summary.
 *
 * Live proof shakedown (bug 8): the pack summary builders were hooked only
 * into Agent Safety's `agent_safety_approval_summary` filter, so only Agent
 * Safety's own Pending Agent Actions page showed "current beside proposed";
 * the Runs-screen card printed the verb and raw argument JSON. The park
 * payload (`Runner::approvalUi()`) and the run detail
 * (`Plugin::get()` -> `ui.approval`, via `Runner::parkedApprovalUi()`) now
 * carry the summary, sanitised to a one-element anchor allow-list.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Commerce\CommerceSummary;
use Specflux\SenroFlux\Packs\Pages\PublishSummary;
use Specflux\SenroFlux\Packs\Site\ContentSummary;
use Specflux\SenroFlux\Packs\Site\FrontPage;
use Specflux\SenroFlux\Packs\Site\Navigation;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\GateMode;
use Specflux\SenroFlux\Run\StepKind;
use Specflux\SenroFlux\Tools\ToolRegistry;
use wpdb;

final class ApprovalSummaryTest extends TestCase {

	protected function setUp(): void {
		require_once dirname( __DIR__ ) . '/stubs/blocks.php';
		require_once dirname( __DIR__ ) . '/stubs/commerce.php';
		require_once dirname( __DIR__ ) . '/stubs/navigation.php';

		Plugin::reset();
		$GLOBALS['senroflux_test_current_user_id']          = 1;
		$GLOBALS['senroflux_test_filters']                  = array();
		$GLOBALS['wpdb']                                    = new wpdb();
		$GLOBALS['senroflux_test_posts']                    = array();
		$GLOBALS['senroflux_test_product_rows']             = array();
		$GLOBALS['senroflux_test_products']                 = array();
		$GLOBALS['senroflux_test_orders']                   = array();
		$GLOBALS['senroflux_test_shipping_zones']           = array();
		$GLOBALS['senroflux_test_gateway_supports_refunds'] = true;
		$GLOBALS['senroflux_test_options']                  = array();
		$GLOBALS['senroflux_test_nav_menu_items']           = array();
		$GLOBALS['senroflux_test_registered_nav_menus']     = array();
		$GLOBALS['senroflux_test_nav_menu_locations']       = array();
		$GLOBALS['senroflux_test_is_block_theme']           = true;
		$GLOBALS['senroflux_test_header_template_content']  = null;
		$GLOBALS['senroflux_test_nav_fallback']             = null;
		Navigation::reset();
		FrontPage::reset();
		Plugin::set_dependency_probe( true );

		// The same registrations Plugin::boot() makes — SenroFlux's own,
		// so the Runs card works with Agent Safety absent.
		CommerceSummary::boot();
		PublishSummary::boot();
		ContentSummary::boot();
	}

	protected function tearDown(): void {
		remove_all_filters();
		senroflux_test_no_agent_safety();
		unset( $GLOBALS['wpdb'] );
		Plugin::reset();
		Plugin::set_dependency_probe( null );
	}

	private function seedPost( int $id, string $title, string $post_type, string $status = 'draft' ): void {
		$post                                   = new \stdClass();
		$post->ID                               = $id;
		$post->post_type                        = $post_type;
		$post->post_title                       = $title;
		$post->post_status                      = $status;
		$post->post_name                        = '';
		$post->post_parent                      = 0;
		$post->post_excerpt                     = '';
		$post->post_content                     = '';
		$GLOBALS['senroflux_test_posts'][ $id ] = $post;
	}

	/**
	 * Park a run on an approval for `$ability` the way Runner::park() stores
	 * it, in either gate mode.
	 *
	 * @param array<string,mixed> $args Call args.
	 * @return int Run id.
	 */
	private function seedParkedApproval( string $ability, array $args, GateMode $mode ): int {
		$method = new \ReflectionMethod( Plugin::class, 'runner' );
		$method->setAccessible( true );
		$store = $method->invoke( Plugin::instance() )->store();

		$run_id      = $store->createRun( 1, 'test-consumer', 'Do it', array( 'senroflux/*' ), array(), null, null, null, $mode );
		$tool        = ToolRegistry::functionName( $ability );
		$approval_id = GateMode::BuiltIn === $mode ? 'builtin:abc123' : 'as-approval-1';
		$store->appendStep(
			$run_id,
			StepKind::Approval,
			array(
				'parked'           => true,
				'approval_id'      => $approval_id,
				'verb'             => $ability,
				'tier'             => GateMode::BuiltIn === $mode ? null : '2',
				'tool_name'        => $tool,
				'function_call_id' => 'call_1',
				'args'             => $args,
				'remaining'        => array(),
			),
			$tool,
			$approval_id,
			'parked'
		);
		$store->updateRun( $run_id, array( 'status' => 'awaiting_approval' ) );

		return $run_id;
	}

	/**
	 * @return array<string,array{0:string,1:array<string,mixed>,2:callable,3:list<string>}>
	 */
	public static function cards(): array {
		return array(
			'price change'  => array(
				'senroflux/product-update',
				array(
					'id'            => 100,
					'regular_price' => '5.00',
				),
				static function ( self $t ): void {
					$t->seedPost( 100, 'Blue Mug', 'product', 'publish' );
					$GLOBALS['senroflux_test_product_rows'][100] = (object) array(
						'name'           => 'Blue Mug',
						'regular_price'  => '10.00',
						'sale_price'     => '',
						'stock_quantity' => null,
						'status'         => 'publish',
					);
				},
				array( 'current regular 10.00', 'proposed regular 5.00' ),
			),
			'refund'        => array(
				'senroflux/orders-refund',
				array(
					'order_id' => 50,
					'amount'   => '30',
				),
				static function (): void {
					$GLOBALS['senroflux_test_orders'][50] = (object) array(
						'total'          => 100.0,
						'total_refunded' => 20.0,
						'payment_method' => 'bacs',
						'status'         => 'processing',
						'date_created'   => time(),
						'billing_email'  => '',
					);
				},
				array( 'already refunded 20', 'remaining refundable 80', 'refund amount 30' ),
			),
			'shipping zone' => array(
				'senroflux/shipping-zone-save',
				array(
					'zone_id'   => 7,
					'name'      => 'New Zone',
					'locations' => array(),
					'methods'   => array(),
				),
				static function (): void {
					$GLOBALS['senroflux_test_shipping_zones'][7] = array(
						'name'      => 'Old Zone',
						'locations' => array(),
						'methods'   => array(),
					);
				},
				array( 'current name &quot;Old Zone&quot;', 'proposed name &quot;New Zone&quot;' ),
			),
			'navigation'    => array(
				'senroflux/update-navigation',
				array(
					'items' => array(
						array(
							'label' => 'Shop',
							'url'   => '/shop',
							'order' => 0,
						),
					),
				),
				static function (): void {},
				array( 'current: (none)', 'proposed: &quot;Shop&quot;' ),
			),
			'post publish'  => array(
				'senroflux/publish-post',
				array(
					'id'     => 300,
					'status' => 'publish',
				),
				static function ( self $t ): void {
					$t->seedPost( 300, 'Spring launch', 'post' );
				},
				array( 'Spring launch', '(post)', '>preview</a>' ),
			),
		);
	}

	/**
	 * @param array<string,mixed> $args     Call args.
	 * @param list<string>        $expected Fragments the summary must carry.
	 */
	#[DataProvider( 'cards' )]
	public function test_the_run_detail_carries_the_summary_in_built_in_mode( string $ability, array $args, callable $seed, array $expected ): void {
		$seed( $this );
		$run_id = $this->seedParkedApproval( $ability, $args, GateMode::BuiltIn );

		$detail = Plugin::instance()->get( $run_id );

		$this->assertIsArray( $detail );
		$approval = $detail['ui']['approval'] ?? array();
		foreach ( $expected as $fragment ) {
			$this->assertStringContainsString( $fragment, (string) ( $approval['summary'] ?? '' ) );
		}
		$this->assertSame( $args, $approval['args_preview'], 'the raw arguments stay available' );
		$this->assertSame( 'builtin:abc123', $approval['approval_id'] );
	}

	/**
	 * @param array<string,mixed> $args     Call args.
	 * @param list<string>        $expected Fragments the summary must carry.
	 */
	#[DataProvider( 'cards' )]
	public function test_the_run_detail_carries_the_summary_in_agent_safety_mode( string $ability, array $args, callable $seed, array $expected ): void {
		$seed( $this );
		$run_id = $this->seedParkedApproval( $ability, $args, GateMode::AgentSafety );

		$detail = Plugin::instance()->get( $run_id );

		$this->assertIsArray( $detail );
		$approval = $detail['ui']['approval'] ?? array();
		foreach ( $expected as $fragment ) {
			$this->assertStringContainsString( $fragment, (string) ( $approval['summary'] ?? '' ) );
		}
		$this->assertSame( '2', $approval['tier'] );
	}

	public function test_a_verb_no_pack_summarises_carries_an_empty_summary(): void {
		$run_id = $this->seedParkedApproval( 'agsafe-smoke/write', array( 'x' => 1 ), GateMode::BuiltIn );

		$detail = Plugin::instance()->get( $run_id );

		$this->assertSame( '', $detail['ui']['approval']['summary'] );
	}

	public function test_summary_markup_is_reduced_to_text_and_plain_links(): void {
		add_filter(
			'agent_safety_approval_summary',
			static fn (): string => 'Go <script>alert(1)</script><img src=x onerror=alert(2)> <a href="javascript:alert(3)" onclick="x()">bad</a> <a href="https://example.test/p" onclick="x()">ok</a> &quot;t&quot;',
			20,
			3
		);
		$run_id = $this->seedParkedApproval( 'agsafe-smoke/write', array(), GateMode::BuiltIn );

		$summary = Plugin::instance()->get( $run_id )['ui']['approval']['summary'];

		$this->assertStringNotContainsString( '<script', $summary );
		$this->assertStringNotContainsString( '<img', $summary );
		$this->assertStringNotContainsString( 'onclick', $summary );
		$this->assertStringNotContainsString( 'onerror=alert(2)>', $summary );
		$this->assertStringNotContainsString( 'javascript:', $summary );
		$this->assertStringContainsString( '<a href="https://example.test/p">ok</a>', $summary );
		$this->assertStringContainsString( '&quot;t&quot;', $summary );
	}

	public function test_a_run_not_awaiting_approval_carries_no_approval_ui(): void {
		$run_id = $this->seedParkedApproval( 'agsafe-smoke/write', array(), GateMode::BuiltIn );
		$method = new \ReflectionMethod( Plugin::class, 'runner' );
		$method->setAccessible( true );
		$method->invoke( Plugin::instance() )->store()->updateRun( $run_id, array( 'status' => 'running' ) );

		$this->assertSame( array(), Plugin::instance()->get( $run_id )['ui'] );
	}
}
