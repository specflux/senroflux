<?php
/**
 * Tail elapsed-gap rendering (0.3 S9).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Run\Tail;

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
}
