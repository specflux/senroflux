<?php
/**
 * Plugin-level tests for 0.3 S4/S8: `Content\Abilities`' stale-write compare
 * must be scoped to the ticking run from `Plugin::tick()` ITSELF — the same
 * place `PublishSummary::useRunContext()` and `Content\Media::useRunContext()`
 * are scoped — never only from a test calling `useRunContext()` directly.
 *
 * Drives a REAL tick loop (`Plugin::instance()->tick()`), a scripted model
 * gateway, and the real `Content\Abilities` ability callbacks end to end, so
 * a regression that drops the scoping line in `Plugin::tick()` fails this
 * test even though `Packs/Content/AbilitiesTest.php` (which calls
 * `useRunContext()` directly) stays green.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Admin\RunsScreen;
use Specflux\SenroFlux\Model\ModelGatewayInterface;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Packs\Content\Abilities;
use Specflux\SenroFlux\Packs\Pages\Validator;
use Specflux\SenroFlux\Packs\Pages\Vocabulary;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\GateMode;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tests\Run\FakeGateway;
use Specflux\SenroFlux\Tests\Run\RecordingBridge;
use Specflux\SenroFlux\Tools\ToolExecutor;
use WP_Error;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use wpdb;

final class PluginContentRunContextTest extends TestCase {

	private WpdbRunStore $store;

	private function loadShims(): void {
		require_once __DIR__ . '/stubs/blocks.php';
	}

	protected function setUp(): void {
		$this->loadShims();

		Plugin::reset();
		Abilities::reset();
		Abilities::resetSources();
		Abilities::forgetRunPack();
		Abilities::forgetRunContext();

		$GLOBALS['senroflux_test_current_user_id']    = 1;
		$GLOBALS['senroflux_test_transients']         = array();
		$GLOBALS['senroflux_test_agent_safety']       = null;
		$GLOBALS['senroflux_test_abilities']          = array();
		$GLOBALS['senroflux_test_inserted_posts']     = array();
		$GLOBALS['senroflux_test_next_post_id']       = 100;
		$GLOBALS['senroflux_test_posts']              = array();
		$GLOBALS['senroflux_test_users']              = array();
		$GLOBALS['senroflux_test_ability_categories'] = array();
		$GLOBALS['senroflux_test_user_caps']          = array(
			'edit_pages' => true,
			'edit_post'  => true,
			'read_post'  => true,
		);

		// Real content abilities, registered exactly as WordPress's own
		// `wp_abilities_api_categories_init` / `wp_abilities_api_init` hooks
		// would (Plugin::boot() wires these through `add_action`; calling
		// them directly here stands in for WordPress firing those hooks —
		// it is NOT the bug seam under test, which is `useRunContext()`).
		Abilities::registerCategory();
		Abilities::register();

		$vocabulary = new Vocabulary();
		Abilities::registerSource( 'pages', new Validator( $vocabulary ), $vocabulary, 'edit_pages' );

		// Tier-0 (fence-free) for both verbs under test: the plan fence and
		// the built-in approval park are a different S7 concern, orthogonal
		// to the S8 stale-write compare this test targets.
		add_filter(
			'senroflux_verb_map',
			static fn (): array => array(
				'senroflux/read-content' => 0,
				'senroflux/update-post'  => 0,
			),
			10,
			0
		);
	}

	protected function tearDown(): void {
		remove_all_filters( 'senroflux_verb_map' );
		Abilities::forgetRunContext();
		Abilities::forgetRunPack();
		Abilities::resetSources();
		Plugin::reset();
		unset( $GLOBALS['wpdb'] );
	}

	/** A draft page the run reads, then writes back. */
	private function seedDraftPage( int $id = 100 ): void {
		$post                    = new \stdClass();
		$post->ID                = $id;
		$post->post_type         = 'page';
		$post->post_title        = 'Existing';
		$post->post_content      = '<!-- wp:paragraph --><p>original</p><!-- /wp:paragraph -->';
		$post->post_status       = 'draft';
		$post->post_name         = 'existing';
		$post->post_parent       = 0;
		$post->post_excerpt      = '';
		$post->post_author       = 1;
		$post->post_date         = '2026-01-01 00:00:00';
		$post->post_modified     = '2026-01-01 00:00:00';
		$post->post_modified_gmt = senroflux_test_next_modified_marker();

		$GLOBALS['senroflux_test_posts'][ $id ] = $post;
	}

	public static function validContent(): string {
		$vocabulary = new Vocabulary();

		// Pattern index 0 is hero; index 1 is text-section (same pairing
		// AbilitiesTest uses as a minimal valid page body).
		return $vocabulary->all()[0]['markup'] . "\n\n" . $vocabulary->all()[1]['markup'];
	}

	public static function callTurn( string $function_name, array $args ): ModelTurn {
		return new ModelTurn(
			new ModelMessage(
				array(
					new MessagePart( 'Working on it…' ),
					new MessagePart( new FunctionCall( 'call_' . $function_name, $function_name, $args ) ),
				)
			),
			10,
			5
		);
	}

	public static function textTurn( string $text ): ModelTurn {
		return new ModelTurn( new ModelMessage( array( new MessagePart( $text ) ) ), 10, 5 );
	}

	/**
	 * Wires a real Runner (real store, real ToolExecutor, real abilities, a
	 * scripted gateway) into the Plugin singleton — mirrors
	 * `PluginGateModeTest::seedRunnerGraph()`.
	 */
	private function seedRunnerGraph( ModelGatewayInterface $gateway ): Runner {
		$db              = new wpdb();
		$db->queryReturn = 1;
		$GLOBALS['wpdb'] = $db;

		$runner = new Runner(
			store: new WpdbRunStore( $db ),
			executor: new ToolExecutor(),
			gateway: $gateway,
			bridge: new RecordingBridge(),
			// S3: match the run's pinned built-in mode — otherwise the
			// default (always AgentSafety) mismatches a BuiltIn-pinned run
			// and every tick fails closed as `gate_mode_changed` before any
			// call executes.
			gate_mode_probe: static fn (): GateMode => Plugin::currentGateMode()
		);

		$this->store = $runner->store();

		$prop = new \ReflectionProperty( Plugin::class, 'runner' );
		$prop->setAccessible( true );
		$prop->setValue( Plugin::instance(), $runner );

		return $runner;
	}

	private function createPagesRun(): int {
		return $this->store->createRun(
			1,
			RunsScreen::CONSUMER, // 0.3 S3: built-in mode may only be driven by SenroFlux's own consumer.
			'Update the existing draft',
			array( 'senroflux/*' ),
			Budget::defaults(),
			'pages',
			null,
			null,
			GateMode::BuiltIn
		);
	}

	/**
	 * THE regression test: a real tick — read-content on an existing draft,
	 * then update-post on it — must succeed. Before the fix, `Plugin::tick()`
	 * never called `Content\Abilities::useRunContext()`, so `currentObjects()`
	 * fell back to fail-closed empty, `read-content` recorded no marker, and
	 * `update-post` refused every write as `stale_write` (409) — even one
	 * immediately following its own read, in the very same tick.
	 */
	public function test_read_then_update_within_one_real_tick_succeeds_not_stale_write(): void {
		Plugin::set_dependency_probe( false ); // Agent Safety absent => built-in mode.
		$this->seedDraftPage( 100 );

		$gateway = new FakeGateway();
		$this->seedRunnerGraph( $gateway );

		$run_id = $this->createPagesRun();

		$gateway->script[] = self::callTurn( 'wpab__senroflux__read-content', array( 'id' => 100 ) );
		$gateway->script[] = self::callTurn(
			'wpab__senroflux__update-post',
			array(
				'id'      => 100,
				'content' => self::validContent(),
			)
		);
		$gateway->script[] = self::textTurn( 'Updated the page.' );

		$result = Plugin::instance()->tick( $run_id, 0, null );

		$this->assertIsArray( $result, is_object( $result ) ? $result->get_error_message() : '' );

		$tool_results = array_values(
			array_filter(
				$result['new_steps'],
				static fn ( array $step ): bool => 'tool_result' === $step['kind']
			)
		);

		$update_result = null;
		foreach ( $tool_results as $step ) {
			if ( 'wpab__senroflux__update-post' === $step['tool_name'] ) {
				$update_result = $step;
			}
		}

		$this->assertNotNull( $update_result, 'the update-post call must have run' );
		$this->assertNotSame(
			'error',
			$update_result['status'] ?? null,
			'update-post refused as stale_write: run context was never scoped around this tick'
		);

		$message = $update_result['message'] ?? array();
		$encoded = (string) wp_json_encode( $message );
		$this->assertStringNotContainsString( 'stale_write', $encoded );

		// Not asserting 'completed': a successful write may still queue a
		// post-write verify nudge (a separate concern from this test's own),
		// which keeps the run 'running' for one more tick. What matters here
		// is that the write itself succeeded rather than being refused.
		$this->assertNotSame( 'failed', $result['run']['status'] );
	}

	/**
	 * The fix must not simply disable the check: a write to an object another
	 * actor modified AFTER this run's read is still refused stale_write, even
	 * though the run context is now correctly scoped around every tick.
	 */
	public function test_an_external_edit_between_read_and_write_is_still_refused_stale_write(): void {
		Plugin::set_dependency_probe( false );
		$this->seedDraftPage( 100 );

		// A gateway that simulates another actor editing the post AFTER this
		// run's read-content call consumed it, but before its update-post
		// call reaches the ability.
		$gateway = new class() implements ModelGatewayInterface {
			public int $calls = 0;

			public function generateTurn( array $history, string $system_instruction, \Specflux\SenroFlux\Tools\ToolRegistry $tools ): ModelTurn|WP_Error {
				unset( $history, $system_instruction, $tools );
				++$this->calls;

				if ( 1 === $this->calls ) {
					return PluginContentRunContextTest::callTurn( 'wpab__senroflux__read-content', array( 'id' => 100 ) );
				}

				if ( 2 === $this->calls ) {
					// External modification, out of band from this run.
					$GLOBALS['senroflux_test_posts'][100]->post_modified_gmt = senroflux_test_next_modified_marker();

					return PluginContentRunContextTest::callTurn(
						'wpab__senroflux__update-post',
						array(
							'id'      => 100,
							'content' => PluginContentRunContextTest::validContent(),
						)
					);
				}

				return PluginContentRunContextTest::textTurn( 'Done.' );
			}
		};

		$this->seedRunnerGraph( $gateway );
		$run_id = $this->createPagesRun();

		$result = Plugin::instance()->tick( $run_id, 0, null );

		$this->assertIsArray( $result, is_object( $result ) ? $result->get_error_message() : '' );

		$update_result = null;
		foreach ( $result['new_steps'] as $step ) {
			if ( 'tool_result' === ( $step['kind'] ?? null ) && 'wpab__senroflux__update-post' === ( $step['tool_name'] ?? null ) ) {
				$update_result = $step;
			}
		}

		$this->assertNotNull( $update_result, 'the update-post call must have run' );
		$this->assertSame( 'error', $update_result['status'] ?? null, 'an externally-modified object must still refuse as stale_write' );

		$encoded = (string) wp_json_encode( $update_result['message'] ?? array() );
		$this->assertStringContainsString(
			'This content changed since the run last read it',
			$encoded,
			'the stale_write refusal message must still surface for a genuinely externally-modified object'
		);
	}
}
