<?php
/**
 * Report-defect regression (live run 55, 0.3 stages 6/8/12, defect 2): there
 * was no way to re-read an attachment, so an alt-text/upload change could
 * never be verified. `posts/read-media` fixes that; this proves it clears
 * the verification `posts/update-alt` opened, through the REAL abilities,
 * the REAL {@see PostsPack} seams and the REAL {@see Runner} — the same
 * class {@see AttachmentReportTest} uses for defect 1.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Packs\Content\Media;
use Specflux\SenroFlux\Packs\Posts\PostsPack;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\Report;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\StepKind;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\ToolExecutor;
use Specflux\SenroFlux\Tools\VerbTier;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use wpdb;

final class AttachmentVerifyTest extends TestCase {

	private wpdb $db;

	private WpdbRunStore $store;

	private FakeGateway $gateway;

	private RecordingBridge $bridge;

	private Runner $runner;

	private PostsPack $pack;

	protected function setUp(): void {
		require_once __DIR__ . '/../stubs/blocks.php';
		require_once __DIR__ . '/../stubs/media.php';

		$GLOBALS['senroflux_test_abilities']          = array();
		$GLOBALS['senroflux_test_posts']              = array();
		$GLOBALS['senroflux_test_postmeta']           = array();
		$GLOBALS['senroflux_test_next_post_id']       = 1;
		$GLOBALS['senroflux_test_ability_categories'] = array();
		$GLOBALS['senroflux_test_user_caps']          = array( 'edit_post' => true );
		$GLOBALS['senroflux_test_current_user_id']    = 1;
		$GLOBALS['senroflux_test_transients']         = array();

		Media::reset();
		Media::registerCategory();
		Media::register();

		$this->db              = new wpdb();
		$this->db->queryReturn = 1;
		$this->store           = new WpdbRunStore( $this->db );
		$this->gateway         = new FakeGateway();
		$this->bridge          = new RecordingBridge();
		$this->pack            = new PostsPack();

		Media::useRunContext( null, null ); // Reset any leftover context.

		$this->runner = new Runner(
			$this->store,
			new ToolExecutor(),
			$this->gateway,
			$this->bridge,
			// Mirrors Plugin::runner()'s post_lookup dispatcher exactly.
			static function ( string|int $object_id ): array {
				$object_id = (string) $object_id;
				if ( str_starts_with( $object_id, Media::OBJECT_ID_PREFIX ) ) {
					return Media::attachmentLookup( (int) substr( $object_id, strlen( Media::OBJECT_ID_PREFIX ) ) );
				}

				return ( Report::wpPostLookup() )( $object_id );
			},
			fn () => $this->pack->verbMap(),
			fn ( $run, string $ability, array $args ) => $this->pack->verbFor( $ability, $args ),
			fn () => $this->pack,
			fn ( $run, string $verb ) => $this->pack->objectIdKey( $verb ),
			new \Specflux\SenroFlux\Approval\GrantBridge(),
			null,
			null,
			null,
			null,
			fn ( $run, string $verb ) => $this->pack->objectIdPrefix( $verb )
		);
	}

	protected function tearDown(): void {
		Media::forgetRunContext();
		unset( $GLOBALS['wpdb'] );
	}

	private function seedAttachment( int $id ): void {
		$post                                   = new \stdClass();
		$post->ID                               = $id;
		$post->post_type                        = 'attachment';
		$post->post_mime_type                   = 'image/jpeg';
		$post->post_title                       = 'image-' . $id;
		$post->guid                             = 'https://example.test/wp-content/uploads/image-' . $id . '.jpg';
		$post->post_status                      = 'inherit';
		$GLOBALS['senroflux_test_posts'][ $id ] = $post;
	}

	private function createRun(): int {
		$run_id = $this->store->createRun( 1, 'test', 'Caption the hero image', array( 'senroflux/*' ), Budget::defaults() );

		$this->store->appendStep(
			$run_id,
			StepKind::User,
			( new UserMessage( array( new MessagePart( 'Caption the hero image' ) ) ) )->toArray()
		);
		$plan_seq = $this->store->appendStep(
			$run_id,
			StepKind::Plan,
			array(
				'goal'        => 'Caption the hero image',
				'steps'       => array(
					array(
						'text'  => 'Save alt text',
						'verbs' => array( 'posts/update-alt' ),
						'tier'  => VerbTier::TIER_1,
					),
				),
				'assumptions' => array(),
			),
			'senroflux/propose-plan',
			null,
			'parked'
		);
		$this->store->updateRun( $run_id, array( 'accepted_plan_step_id' => $plan_seq ) );

		return $run_id;
	}

	private function stepCount( int $run_id ): int {
		return $this->store->getRun( $run_id )->stepCount;
	}

	private static function turn( MessagePart ...$parts ): ModelTurn {
		return new ModelTurn( new ModelMessage( $parts ), 10, 5 );
	}

	private static function textTurn( string $text ): ModelTurn {
		return new ModelTurn( new ModelMessage( array( new MessagePart( $text ) ) ), 10, 5 );
	}

	// ------------------------------------------------------------------
	// Defect 2: read-media clears the verification the write opened
	// ------------------------------------------------------------------

	public function test_read_media_after_update_alt_marks_the_row_verified(): void {
		$this->seedAttachment( 63 );
		$run_id = $this->createRun();

		$this->gateway->script[] = self::turn(
			new MessagePart( 'Saving alt text, then confirming it saved.' ),
			new MessagePart(
				new FunctionCall(
					'call_alt',
					'wpab__senroflux__update-alt',
					array(
						'attachment_id' => 63,
						'alt'           => 'a red bicycle',
					)
				)
			),
			new MessagePart( new FunctionCall( 'call_read', 'wpab__senroflux__read-media', array( 'attachment_id' => 63 ) ) )
		);
		$this->gateway->script[] = self::textTurn( 'Done.' );

		$result = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'], 'a read-back lets the finish complete in the same tick' );

		$report = $result['ui']['report'] ?? array();
		$row    = $report['changes'][0] ?? array();
		$this->assertTrue( $row['verified'] ?? false, 'defect 2: read-media must count as verification' );
	}

	/**
	 * Smoke run (2026-09-26): a finished run's plan card read "~0 / 2". The
	 * plan names pack verbs (`posts/update-alt`) but a tool_result only
	 * carried the function name (`wpab__senroflux__update-alt`), so the
	 * Runs screen could never match an executed call to its plan step.
	 */
	public function test_tool_results_record_the_plan_verb_they_ran_as(): void {
		$this->seedAttachment( 63 );
		$run_id = $this->createRun();

		$this->gateway->script[] = self::turn(
			new MessagePart(
				new FunctionCall(
					'call_alt',
					'wpab__senroflux__update-alt',
					array(
						'attachment_id' => 63,
						'alt'           => 'a red bicycle',
					)
				)
			),
			new MessagePart( new FunctionCall( 'call_read', 'wpab__senroflux__read-media', array( 'attachment_id' => 63 ) ) )
		);
		$this->gateway->script[] = self::textTurn( 'Done.' );

		$this->runner->tick( $run_id, $this->stepCount( $run_id ), null );

		$verbs = array();
		foreach ( $this->store->getSteps( $run_id ) as $step ) {
			if ( StepKind::ToolResult === $step->kind ) {
				$verbs[] = $step->messageArray['plan_verb'] ?? null;
			}
		}
		$this->assertSame( array( 'posts/update-alt', 'posts/read-media' ), $verbs );
	}

	/**
	 * Live run evidence (2026-09-27 baseline, scenario-7-1): with
	 * {@see Media}'s run context wired the way `Plugin::tick()` wires it for
	 * every real tick (unlike the test above, which resets it to null and so
	 * never exercises the S5 attachment-cap bookkeeping), an `update-alt` +
	 * `read-media` pair still surfaced a false verify nudge and a phantom
	 * `media-alt:<id>` change row stuck at `verified: false` forever.
	 *
	 * Root cause: `Media::recordAltWrite()` stores a bare `true` under
	 * `media-alt:<id>` in the SAME `objects_json` map {@see Tracker} and
	 * {@see Report} treat as the generic write/verify record — but that key
	 * is never qualified with {@see Media::OBJECT_ID_PREFIX}, so no read ever
	 * verifies it, and both {@see Tracker::unverified()} and
	 * {@see Report::build()} fail-close a non-array entry to permanently
	 * unverified. The cap bookkeeping must stay invisible to that machinery.
	 */
	public function test_update_alt_with_real_media_run_context_verifies_with_no_phantom_row(): void {
		$this->seedAttachment( 63 );
		$run_id = $this->createRun();

		// Mirrors Plugin::tick()'s wiring: the attachment-cap bookkeeping only
		// engages once Media has a real run context, exactly like production.
		Media::useRunContext( $run_id, $this->store );

		$this->gateway->script[] = self::turn(
			new MessagePart( 'Saving alt text, then confirming it saved.' ),
			new MessagePart(
				new FunctionCall(
					'call_alt',
					'wpab__senroflux__update-alt',
					array(
						'attachment_id' => 63,
						'alt'           => 'a red bicycle',
					)
				)
			),
			new MessagePart( new FunctionCall( 'call_read', 'wpab__senroflux__read-media', array( 'attachment_id' => 63 ) ) )
		);
		$this->gateway->script[] = self::textTurn( 'Done.' );

		$result = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );

		$this->assertIsArray( $result );
		$this->assertSame(
			'completed',
			$result['run']['status'] ?? null,
			'a read-back after the write must complete the run without a verify nudge'
		);

		$changes = $result['ui']['report']['changes'] ?? array();
		$this->assertCount( 1, $changes, 'the attachment-cap bookkeeping must never open its own report row' );
		$this->assertTrue( $changes[0]['verified'] ?? null, 'the one row that exists must be verified' );
	}
}
