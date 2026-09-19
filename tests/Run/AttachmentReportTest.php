<?php
/**
 * Report-defect regression (live run 55, 0.3 stages 6/12, defect 1): an
 * attachment write (`posts/update-alt`) must produce a real report row —
 * not "unknown".
 *
 * Runs the REAL `senroflux/update-alt` ability (via {@see Media}) through
 * the REAL {@see PostsPack} verb/id-key/id-prefix seams and the REAL
 * {@see Runner}, with a `$post_lookup` that mirrors the dispatcher wired in
 * `Plugin::runner()` — the same seam a real run uses.
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

final class AttachmentReportTest extends TestCase {

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
	// Defect 1: an attachment write must not resolve to "unknown"
	// ------------------------------------------------------------------

	public function test_an_attachment_write_reports_a_real_row_not_unknown(): void {
		$this->seedAttachment( 63 );
		$run_id = $this->createRun();

		$this->gateway->script[] = self::turn(
			new MessagePart( 'Saving alt text.' ),
			new MessagePart(
				new FunctionCall(
					'call_alt',
					'wpab__senroflux__update-alt',
					array(
						'attachment_id' => 63,
						'alt'           => 'a red bicycle',
					)
				)
			)
		);
		$this->gateway->script[] = self::textTurn( 'Done.' );
		// S12: the first finish with an unverified write only nudges (stays
		// running, S12's existing behaviour) — the report is built on the
		// SECOND finish attempt, exactly like a post's own write/verify
		// cycle (VerificationReportTest test_b).
		$first = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );
		$this->assertSame( 'running', $first['run']['status'] ?? null );

		$this->gateway->script[] = self::textTurn( 'Done.' );
		$result                  = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'] ?? null );
		$report  = $result['ui']['report'] ?? array();
		$changes = $report['changes'] ?? array();
		$this->assertNotSame( array(), $changes, 'the alt write must open a change row' );

		$row = $changes[0];
		$this->assertNotSame( 'unknown', $row['object_type'] ?? null, 'defect 1: the row must resolve, not fall back to unknown' );
		$this->assertSame( 'attachment', $row['object_type'] ?? null );
		$this->assertSame( 'image-63', $row['title'] ?? null );
		$this->assertNotNull( $row['edit_url'] ?? null );
	}
}
