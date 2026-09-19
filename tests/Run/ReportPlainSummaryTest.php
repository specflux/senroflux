<?php
/**
 * Report::plainSummary() unit tests (defect fix, S12): a live run's summary
 * showed literal `**bold**` markdown in the rendered report — the model was
 * told plain sentences and did not fully comply, so the renderer must strip
 * common markdown tokens defensively, on top of the harness instruction.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Run\Report;

final class ReportPlainSummaryTest extends TestCase {

	public function test_strips_bold_markers(): void {
		$this->assertSame( 'Saved bold text.', Report::plainSummary( 'Saved **bold** text.' ) );
		$this->assertSame( 'Saved bold text.', Report::plainSummary( 'Saved __bold__ text.' ) );
	}

	public function test_strips_italic_markers_without_touching_a_lone_asterisk(): void {
		$this->assertSame( 'Saved em text.', Report::plainSummary( 'Saved *em* text.' ) );
		$this->assertSame( '5 * 3 = 15', Report::plainSummary( '5 * 3 = 15' ), 'a lone asterisk is not an emphasis marker' );
	}

	public function test_strips_inline_code_and_leading_headings(): void {
		$this->assertSame( 'Ran update-alt on it.', Report::plainSummary( 'Ran `update-alt` on it.' ) );
		$this->assertSame( "Summary\nDone.", Report::plainSummary( "# Summary\nDone." ) );
	}

	public function test_never_introduces_or_removes_html_the_caller_must_still_escape(): void {
		// plainSummary() is markup-token stripping only, never an HTML
		// renderer — a script tag survives it verbatim so the EXISTING
		// esc_html() call at the render site is still the thing that makes
		// it safe.
		$stripped = Report::plainSummary( 'Saved **bold** <script>alert(1)</script> text.' );
		$this->assertSame( 'Saved bold <script>alert(1)</script> text.', $stripped );
		$this->assertStringNotContainsString( '<script>', esc_html( $stripped ) );
	}

	public function test_plain_text_with_no_markdown_is_unchanged(): void {
		$text = 'Updated the post and set its featured image.';
		$this->assertSame( $text, Report::plainSummary( $text ) );
	}
}
