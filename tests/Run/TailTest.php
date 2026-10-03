<?php
/**
 * Tail elapsed-gap rendering (0.3 S9).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Run\Clock;
use Specflux\SenroFlux\Run\InstructionRenderer;
use Specflux\SenroFlux\Run\Tail;
use Specflux\SenroFlux\Skills\SkillSet;

final class TailTest extends TestCase {

	public function test_no_gap_sentence_with_no_elapsed_gap(): void {
		$tail = new Tail( 2, 1, 5, 3, 1000 );

		$this->assertStringNotContainsString( 'Resumed after', $tail->render() );
	}

	public function test_no_gap_sentence_five_minutes_after_the_previous_step(): void {
		$tail = new Tail( 2, 1, 5, 3, 1000, elapsed_gap_seconds: 5 * MINUTE_IN_SECONDS );

		$this->assertStringNotContainsString( 'Resumed after', $tail->render() );
	}

	public function test_no_gap_sentence_exactly_at_the_ten_minute_threshold(): void {
		$tail = new Tail( 2, 1, 5, 3, 1000, elapsed_gap_seconds: Tail::ELAPSED_GAP_THRESHOLD_SECONDS );

		$this->assertStringNotContainsString( 'Resumed after', $tail->render() );
	}

	public function test_gap_sentence_just_over_the_ten_minute_threshold(): void {
		$tail = new Tail( 2, 1, 5, 3, 1000, elapsed_gap_seconds: Tail::ELAPSED_GAP_THRESHOLD_SECONDS + 1 );

		$this->assertStringContainsString(
			'Resumed after 10 minutes. The site may have changed since your last read.',
			$tail->render()
		);
	}

	public function test_gap_sentence_names_hours(): void {
		$tail = new Tail( 2, 1, 5, 3, 1000, elapsed_gap_seconds: 26 * HOUR_IN_SECONDS );

		$this->assertStringContainsString(
			'Resumed after 26 hours. The site may have changed since your last read.',
			$tail->render()
		);
	}

	public function test_gap_sentence_names_days(): void {
		$tail = new Tail( 2, 1, 5, 3, 1000, elapsed_gap_seconds: 3 * DAY_IN_SECONDS );

		$this->assertStringContainsString(
			'Resumed after 3 days. The site may have changed since your last read.',
			$tail->render()
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['senroflux_test_timezone'] );
		Clock::reset();
	}

	public function test_date_line_states_site_local_date_and_timezone(): void {
		$GLOBALS['senroflux_test_timezone'] = 'Asia/Singapore';
		// 2026-10-02 23:10 UTC is 07:10 on Saturday 3 October in Singapore.
		$tail = new Tail( 2, 1, 5, 3, 1000, now_utc: gmmktime( 23, 10, 0, 10, 2, 2026 ) );

		$this->assertStringContainsString(
			'Today is Saturday, 3 October 2026, 07:10 (site timezone Asia/Singapore, UTC+08:00). Use this date for any relative date',
			$tail->render()
		);
		$this->assertStringContainsString( 'site-local `Y-m-d H:i:s` datetime', $tail->render() );
	}

	public function test_no_date_line_without_a_clock_reading(): void {
		$this->assertStringNotContainsString( 'Today is', ( new Tail( 2, 1, 5, 3, 1000 ) )->render() );
	}

	public function test_resumed_turn_carries_both_the_date_and_the_gap_sentence(): void {
		$tail = new Tail( 2, 1, 5, 3, 1000, elapsed_gap_seconds: 26 * HOUR_IN_SECONDS, now_utc: gmmktime( 12, 0, 0, 10, 3, 2026 ) );
		$text = $tail->render();

		$this->assertStringContainsString( 'Today is Saturday, 3 October 2026, 12:00 (site timezone UTC, UTC+00:00).', $text );
		$this->assertStringContainsString( 'Resumed after 26 hours.', $text );
	}

	public function test_date_changes_the_tail_but_not_the_skills_section(): void {
		$skills  = SkillSet::harnessSkills();
		$monday  = InstructionRenderer::render( $skills, new Tail( 2, 1, 5, 3, 1000, now_utc: gmmktime( 9, 0, 0, 10, 5, 2026 ) ) );
		$tuesday = InstructionRenderer::render( $skills, new Tail( 2, 1, 5, 3, 1000, now_utc: gmmktime( 9, 0, 0, 10, 6, 2026 ) ) );

		$this->assertNotSame( $monday, $tuesday );
		$this->assertSame( explode( "\n\n---\n\n", $monday )[0], explode( "\n\n---\n\n", $tuesday )[0], 'skills text is date-free' );
		foreach ( $skills as $skill ) {
			$this->assertStringNotContainsString( 'Today is', $skill->body );
		}
	}
}
