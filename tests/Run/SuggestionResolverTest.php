<?php
/**
 * SuggestionResolver tests (0.3 S20): save appends under the brief cap and
 * refuses without truncating; dismiss records the resolution; an already
 * resolved suggestion is refused; an unknown suggestion is refused.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\SiteBrief;
use Specflux\SenroFlux\Run\StepKind;
use Specflux\SenroFlux\Run\SuggestionResolver;
use Specflux\SenroFlux\Run\WpdbRunStore;
use WP_Error;
use wpdb;

final class SuggestionResolverTest extends TestCase {

	private wpdb $db;

	private WpdbRunStore $store;

	protected function setUp(): void {
		$this->db                          = new wpdb();
		$this->db->queryReturn             = 1;
		$this->store                       = new WpdbRunStore( $this->db );
		$GLOBALS['senroflux_test_options'] = array();
	}

	private function createRunWithSuggestion( string $text = 'Mention free shipping.' ): array {
		$run_id = $this->store->createRun( 1, 'test-consumer', 'Goal', array( '*' ), Budget::defaults() );
		$seq    = $this->store->appendStep(
			$run_id,
			StepKind::Suggestion,
			array( 'text' => $text ),
			'senroflux/suggest-brief-addition',
			null,
			'ok'
		);

		return array( $run_id, $seq );
	}

	public function test_save_appends_the_suggestion_to_the_brief(): void {
		SiteBrief::set( 'Existing line.' );
		[ $run_id, $seq ] = $this->createRunWithSuggestion();

		$result = SuggestionResolver::resolve( $this->store, $run_id, $seq, 'save', null );

		$this->assertIsArray( $result );
		$this->assertSame( 'Existing line.' . "\n" . 'Mention free shipping.', SiteBrief::get() );
	}

	public function test_save_refuses_over_the_cap_without_truncating_and_leaves_the_suggestion_pending(): void {
		$existing = str_repeat( 'a', SiteBrief::MAX_CHARS - 5 );
		SiteBrief::set( $existing );
		[ $run_id, $seq ] = $this->createRunWithSuggestion( 'This line is too long to fit under the cap.' );

		$result = SuggestionResolver::resolve( $this->store, $run_id, $seq, 'save', null );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( SiteBrief::ERROR_TOO_LONG, $result->get_error_code() );
		// Never truncated: the brief is unchanged.
		$this->assertSame( $existing, SiteBrief::get() );

		// Retryable: the suggestion was never marked resolved.
		$second = SuggestionResolver::resolve( $this->store, $run_id, $seq, 'dismiss', null );
		$this->assertIsArray( $second );
	}

	public function test_dismiss_records_the_resolution_without_touching_the_brief(): void {
		SiteBrief::set( 'Untouched.' );
		[ $run_id, $seq ] = $this->createRunWithSuggestion();

		$result = SuggestionResolver::resolve( $this->store, $run_id, $seq, 'dismiss', null );

		$this->assertIsArray( $result );
		$this->assertSame( 'dismiss', $result['action'] );
		$this->assertSame( 'Untouched.', SiteBrief::get() );
	}

	public function test_an_already_resolved_suggestion_is_refused(): void {
		[ $run_id, $seq ] = $this->createRunWithSuggestion();
		SuggestionResolver::resolve( $this->store, $run_id, $seq, 'dismiss', null );

		$second = SuggestionResolver::resolve( $this->store, $run_id, $seq, 'save', null );

		$this->assertInstanceOf( WP_Error::class, $second );
		$this->assertSame( SuggestionResolver::ERROR_ALREADY_RESOLVED, $second->get_error_code() );
	}

	public function test_an_unknown_suggestion_is_refused(): void {
		[ $run_id ] = $this->createRunWithSuggestion();

		$result = SuggestionResolver::resolve( $this->store, $run_id, 999, 'save', null );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( SuggestionResolver::ERROR_NOT_FOUND, $result->get_error_code() );
	}

	public function test_an_invalid_action_is_refused(): void {
		[ $run_id, $seq ] = $this->createRunWithSuggestion();

		$result = SuggestionResolver::resolve( $this->store, $run_id, $seq, 'delete', null );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( SuggestionResolver::ERROR_INVALID_ACTION, $result->get_error_code() );
	}

	public function test_editable_text_overrides_the_suggestion_text_when_saving(): void {
		[ $run_id, $seq ] = $this->createRunWithSuggestion( 'Original text.' );

		$result = SuggestionResolver::resolve( $this->store, $run_id, $seq, 'save', 'Edited text.' );

		$this->assertIsArray( $result );
		$this->assertSame( 'Edited text.', SiteBrief::get() );
	}
}
