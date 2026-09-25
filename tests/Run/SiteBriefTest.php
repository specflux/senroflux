<?php
/**
 * SiteBrief tests (0.3 S20): the 2,000-char cap refuses without truncating,
 * autoload-off persistence, append-under-cap, and the seq-0 fingerprint.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Run\SiteBrief;

final class SiteBriefTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['senroflux_test_options'] = array();
	}

	public function test_get_defaults_to_empty_string(): void {
		$this->assertSame( '', SiteBrief::get() );
	}

	public function test_set_persists_the_text_with_autoload_off(): void {
		$result = SiteBrief::set( 'Always mention our shipping policy.' );

		$this->assertTrue( $result );
		$this->assertSame( 'Always mention our shipping policy.', SiteBrief::get() );
	}

	public function test_emptying_the_field_clears_the_brief(): void {
		SiteBrief::set( 'Something.' );
		SiteBrief::set( '' );

		$this->assertSame( '', SiteBrief::get() );
	}

	public function test_over_the_cap_is_refused_with_brief_too_long_and_never_truncated(): void {
		SiteBrief::set( 'Existing brief.' );

		$too_long = str_repeat( 'a', SiteBrief::MAX_CHARS + 1 );
		$result   = SiteBrief::set( $too_long );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( SiteBrief::ERROR_TOO_LONG, $result->get_error_code() );

		// Never truncated: the OPTION is unchanged (the caller keeps the
		// attempted text in its own form; this method never writes it).
		$this->assertSame( 'Existing brief.', SiteBrief::get() );
	}

	public function test_exactly_the_cap_is_accepted(): void {
		$exact = str_repeat( 'a', SiteBrief::MAX_CHARS );

		$this->assertTrue( SiteBrief::set( $exact ) );
		$this->assertSame( $exact, SiteBrief::get() );
	}

	public function test_multibyte_characters_are_measured_with_mb_strlen(): void {
		// 2,001 emoji (each 1 mb_strlen unit but 4 raw bytes) — a byte-length
		// check would refuse very differently from an mb_strlen check.
		$text = str_repeat( '🙂', SiteBrief::MAX_CHARS + 1 );

		$result = SiteBrief::set( $text );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( SiteBrief::ERROR_TOO_LONG, $result->get_error_code() );
	}

	public function test_with_appended_line_joins_with_a_newline(): void {
		$this->assertSame( "First.\nSecond.", SiteBrief::withAppendedLine( 'First.', 'Second.' ) );
		$this->assertSame( 'Second.', SiteBrief::withAppendedLine( '', 'Second.' ) );
		$this->assertSame( 'Second.', SiteBrief::withAppendedLine( '   ', 'Second.' ) );
	}

	public function test_hash_is_null_for_empty_and_a_sha256_otherwise(): void {
		$this->assertNull( SiteBrief::hash( '' ) );
		$this->assertSame( hash( 'sha256', 'x' ), SiteBrief::hash( 'x' ) );
	}
}
