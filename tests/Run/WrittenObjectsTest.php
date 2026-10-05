<?php
/**
 * Proof-run defect (journey J1, run 1): the report listed only the post a
 * run created — the attachment its stock import created and the category it
 * made were missing, because the posts pack never named where those writes'
 * ids live. Runs the REAL media abilities through the REAL {@see PostsPack}
 * id seams and the REAL {@see Runner}, then reads `objects_json` and the
 * built report back.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Packs\Content\Media;
use Specflux\SenroFlux\Packs\ObjectLookup;
use Specflux\SenroFlux\Packs\Posts\PostsPack;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\StepKind;
use Specflux\SenroFlux\Run\Tracker;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\ToolExecutor;
use Specflux\SenroFlux\Tools\VerbTier;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use wpdb;

final class WrittenObjectsTest extends TestCase {

	private WpdbRunStore $store;

	private FakeGateway $gateway;

	private Runner $runner;

	private PostsPack $pack;

	protected function setUp(): void {
		require_once __DIR__ . '/../stubs/blocks.php';
		require_once __DIR__ . '/../stubs/media.php';

		$GLOBALS['senroflux_test_abilities']          = array();
		$GLOBALS['senroflux_test_posts']              = array();
		$GLOBALS['senroflux_test_postmeta']           = array();
		$GLOBALS['senroflux_test_terms']              = array();
		$GLOBALS['senroflux_test_post_terms']         = array();
		$GLOBALS['senroflux_test_next_post_id']       = 1;
		$GLOBALS['senroflux_test_next_term_id']       = 1;
		$GLOBALS['senroflux_test_ability_categories'] = array();
		$GLOBALS['senroflux_test_user_caps']          = array(
			'edit_post'         => true,
			'upload_files'      => true,
			'manage_categories' => true,
			'assign_categories' => true,
		);
		$GLOBALS['senroflux_test_current_user_id']    = 1;
		$GLOBALS['senroflux_test_transients']         = array();

		Media::reset();
		Media::registerCategory();
		Media::register();
		Media::useRunContext( null, null );

		$db              = new wpdb();
		$db->queryReturn = 1;
		$this->store     = new WpdbRunStore( $db );
		$this->gateway   = new FakeGateway();
		$this->pack      = new PostsPack();
		$this->runner    = new Runner(
			$this->store,
			new ToolExecutor(),
			$this->gateway,
			new RecordingBridge(),
			static fn ( string|int $object_id ): array => ObjectLookup::resolve( $object_id ),
			fn () => $this->pack->verbMap(),
			fn ( $run, string $ability, array $args ) => $this->pack->verbFor( $ability, $args ),
			fn () => $this->pack,
			fn ( $run, string $verb ) => $this->pack->objectIdKey( $verb ),
			object_id_prefix_resolver: fn ( $run, string $verb ) => $this->pack->objectIdPrefix( $verb ),
			write_object_id_resolver: fn ( $run, string $verb, array $args, array $output ) => $this->pack->objectIdForWrite( $verb, $args, $output ),
			read_object_id_resolver: fn ( $run, string $verb, array $args ) => $this->pack->objectIdForRead( $verb, $args )
		);

		$post                                 = new \stdClass();
		$post->ID                             = 7;
		$post->post_type                      = 'post';
		$post->post_status                    = 'draft';
		$post->post_title                     = 'Desk setup';
		$post->post_content                   = '';
		$GLOBALS['senroflux_test_posts'][7]   = $post;
		$attachment                           = new \stdClass();
		$attachment->ID                       = 501;
		$attachment->post_type                = 'attachment';
		$attachment->post_mime_type           = 'image/jpeg';
		$attachment->post_title               = 'image-501';
		$attachment->guid                     = 'https://example.test/wp-content/uploads/image-501.jpg';
		$attachment->post_status              = 'inherit';
		$attachment->post_parent              = 0;
		$GLOBALS['senroflux_test_posts'][501] = $attachment;
		$GLOBALS['senroflux_test_postmeta'][501]['_wp_attachment_image_alt']   = 'A red bicycle';
		$GLOBALS['senroflux_test_postmeta'][501]['_senroflux_stock_source_id'] = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
	}

	protected function tearDown(): void {
		Media::forgetRunContext();
		unset( $GLOBALS['wpdb'] );
	}

	private function createRun(): int {
		$run_id = $this->store->createRun( 1, 'test', 'Write the desk post', array( 'senroflux/*' ), Budget::defaults() );

		$this->store->appendStep(
			$run_id,
			StepKind::User,
			( new UserMessage( array( new MessagePart( 'Write the desk post' ) ) ) )->toArray()
		);
		$plan_seq = $this->store->appendStep(
			$run_id,
			StepKind::Plan,
			array(
				'goal'        => 'Write the desk post',
				'steps'       => array(
					array(
						'text'  => 'Import a photo, make a category, attach both',
						'verbs' => array( 'posts/media-stock-import', 'posts/create-term', 'posts/set-terms', 'posts/set-featured-image' ),
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

	/**
	 * Script one model turn per call, then a closing text turn, and tick the
	 * run once — a tick runs until the model finishes or the run parks.
	 *
	 * @param list<array{0:string,1:array<string,mixed>}> $calls Ability name (no namespace) and args, in order.
	 * @return array<string,mixed> The tick's result.
	 */
	private function runCalls( int $run_id, array $calls ): array {
		foreach ( $calls as $index => $call ) {
			$this->gateway->script[] = new ModelTurn(
				new ModelMessage(
					array(
						new MessagePart( 'Calling ' . $call[0] . '.' ),
						new MessagePart( new FunctionCall( 'call_' . $index, 'wpab__senroflux__' . $call[0], $call[1] ) ),
					)
				),
				10,
				5
			);
		}
		$this->gateway->script[] = new ModelTurn( new ModelMessage( array( new MessagePart( 'Done.' ) ) ), 10, 5 );

		return $this->runner->tick( $run_id, $this->store->getRun( $run_id )->stepCount, null );
	}

	/** @return array<string,mixed> */
	private function objects( int $run_id ): array {
		$objects = $this->store->getRun( $run_id )->objects;

		return is_array( $objects ) ? $objects : array();
	}

	/** The seq of the tool_result a given ability produced. */
	private function resultSeq( int $run_id, string $ability ): int {
		foreach ( $this->store->getSteps( $run_id ) as $step ) {
			if ( StepKind::ToolResult === $step->kind && str_contains( (string) wp_json_encode( $step->messageArray ), 'wpab__senroflux__' . $ability ) ) {
				return (int) $step->seq;
			}
		}

		$this->fail( 'no tool_result for ' . $ability );
	}

	private const STOCK_IMPORT = array(
		'stock-image-import',
		array(
			'id'  => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
			'alt' => 'A red bicycle',
		),
	);

	private const CREATE_TERM = array(
		'create-term',
		array(
			'taxonomy' => 'category',
			'name'     => 'Workstation Comfort',
		),
	);

	public function test_a_stock_import_a_new_term_and_the_post_edits_each_record_a_write_on_their_own_object(): void {
		$run_id = $this->createRun();

		$this->runCalls(
			$run_id,
			array(
				self::STOCK_IMPORT,
				self::CREATE_TERM,
				array(
					'set-terms',
					array(
						'post_id'  => 7,
						'taxonomy' => 'category',
						'term_ids' => array( 1 ),
					),
				),
				array(
					'set-featured-image',
					array(
						'post_id'       => 7,
						'attachment_id' => 501,
					),
				),
			)
		);

		$objects = $this->objects( $run_id );
		$this->assertSame( $this->resultSeq( $run_id, 'stock-image-import' ), $objects['attachment:501']['last_write_seq'] ?? null, 'the imported attachment is a written object' );
		$this->assertSame( $this->resultSeq( $run_id, 'create-term' ), $objects['term:1']['last_write_seq'] ?? null, 'the created category is a written object' );
		$this->assertSame(
			$this->resultSeq( $run_id, 'set-featured-image' ),
			$objects['7']['last_write_seq'] ?? null,
			'term assignment and the featured image both change the post; the later write is the one on record'
		);
		$this->assertGreaterThan( $this->resultSeq( $run_id, 'set-terms' ), $objects['7']['last_write_seq'] );
	}

	public function test_the_report_lists_every_written_object_with_its_own_type_link_and_check_state(): void {
		$run_id = $this->createRun();

		$this->runCalls( $run_id, array( self::STOCK_IMPORT, self::CREATE_TERM, array( 'read-media', array( 'attachment_id' => 501 ) ) ) );

		$rows = array();
		foreach ( $this->runner->report( $run_id )['changes'] as $row ) {
			$rows[ $row['object_id'] ] = $row;
		}

		$this->assertSame( array( 'attachment:501', 'term:1' ), array_keys( $rows ) );

		$this->assertSame( 'attachment', $rows['attachment:501']['object_type'] );
		$this->assertNotNull( $rows['attachment:501']['edit_url'] );
		$this->assertTrue( $rows['attachment:501']['verified'], 'read-media after the import verifies the attachment' );

		$this->assertSame( 'category', $rows['term:1']['object_type'] );
		$this->assertSame( 'Workstation Comfort', $rows['term:1']['title'] );
		$this->assertStringContainsString( 'term.php', (string) $rows['term:1']['edit_url'] );
		$this->assertFalse( $rows['term:1']['verified'], 'no ability reads a term back, so it must not claim to be verified' );
	}

	public function test_a_term_the_run_cannot_read_back_never_triggers_a_re_read_nudge(): void {
		$run_id = $this->createRun();

		$result = $this->runCalls( $run_id, array( self::CREATE_TERM ) );

		$this->assertArrayHasKey( 'term:1', $this->objects( $run_id ) );
		$this->assertSame( array(), Tracker::unverified( $this->objects( $run_id ) ) );
		$this->assertSame( 'completed', $result['run']['status'] ?? null, 'finishing is not held up by an object nothing can re-read' );
	}
}
