<?php
/**
 * SuggestBriefTool tests (0.3 S20): payload validation + normalisation.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Tools;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Tools\SuggestBriefTool;
use WP_Error;

final class SuggestBriefToolTest extends TestCase {

	public function test_valid_payload_is_trimmed(): void {
		$result = SuggestBriefTool::validate( array( 'text' => '  Mention our shipping policy.  ' ) );

		$this->assertSame( array( 'text' => 'Mention our shipping policy.' ), $result );
	}

	public function test_missing_text_is_invalid(): void {
		$result = SuggestBriefTool::validate( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( SuggestBriefTool::ERROR_INVALID, $result->get_error_code() );
	}

	public function test_blank_text_is_invalid(): void {
		$result = SuggestBriefTool::validate( array( 'text' => '   ' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_non_array_payload_is_invalid(): void {
		$this->assertInstanceOf( WP_Error::class, SuggestBriefTool::validate( 'nope' ) );
		$this->assertInstanceOf( WP_Error::class, SuggestBriefTool::validate( null ) );
	}

	public function test_over_the_cap_is_invalid(): void {
		$result = SuggestBriefTool::validate( array( 'text' => str_repeat( 'a', SuggestBriefTool::MAX_TEXT_CHARS + 1 ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_exactly_the_cap_is_valid(): void {
		$text   = str_repeat( 'a', SuggestBriefTool::MAX_TEXT_CHARS );
		$result = SuggestBriefTool::validate( array( 'text' => $text ) );

		$this->assertSame( array( 'text' => $text ), $result );
	}

	public function test_normalize_trims_and_lower_cases(): void {
		$this->assertSame( 'free shipping', SuggestBriefTool::normalize( '  Free Shipping  ' ) );
	}

	public function test_declarations_always_offers_the_tool(): void {
		$this->assertArrayHasKey( SuggestBriefTool::TOOL_NAME, SuggestBriefTool::declarations() );
	}
}
