<?php
/**
 * DescriptionValidator tests (S19, D3, stage 12).
 *
 * TARGET REPO PATH: tests/Packs/Commerce/DescriptionValidatorTest.php
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Commerce;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Commerce\DescriptionValidator;
use WP_Error;

final class DescriptionValidatorTest extends TestCase {

	private DescriptionValidator $validator;

	protected function setUp(): void {
		$this->validator = new DescriptionValidator();
	}

	public function test_allowed_tags_are_accepted(): void {
		$html = '<p>A <strong>great</strong> widget for <em>everyone</em>.</p>'
			. '<ul><li>Durable</li><li>Affordable</li></ul>'
			. '<ol><li>Step one</li></ol>'
			. '<h3>Specs</h3>'
			. '<p>See <a href="https://example.com">details</a>.</p>';

		$this->assertNull( $this->validator->validate( $html ) );
	}

	public function test_plain_text_with_no_tags_is_accepted(): void {
		$this->assertNull( $this->validator->validate( 'A great widget for everyone.' ) );
	}

	/** @dataProvider disallowedTagProvider */
	public function test_a_disallowed_tag_is_refused( string $html ): void {
		$error = $this->validator->validate( $html );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'disallowed_tag', $error->get_error_code() );
	}

	/** @return list<array{0:string}> */
	public static function disallowedTagProvider(): array {
		return array(
			array( '<script>alert(1)</script>' ),
			array( '<img src="x.jpg">' ),
			array( '<div>Wrapped</div>' ),
			array( '<h1>Too big</h1>' ),
			array( '<h2>Also too big</h2>' ),
			array( '<table><tr><td>No</td></tr></table>' ),
			array( '<span onclick="x()">click</span>' ),
		);
	}

	public function test_an_unsafe_href_scheme_is_refused(): void {
		$error = $this->validator->validate( '<p><a href="javascript:alert(1)">click</a></p>' );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'unsafe_url', $error->get_error_code() );
	}

	public function test_a_relative_href_is_accepted(): void {
		$this->assertNull( $this->validator->validate( '<p><a href="/shop">shop</a></p>' ) );
	}

	public function test_a_description_over_the_word_limit_is_refused(): void {
		$html = '<p>' . implode( ' ', array_fill( 0, DescriptionValidator::MAX_WORDS + 1, 'word' ) ) . '</p>';

		$error = $this->validator->validate( $html );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'description_too_long', $error->get_error_code() );
	}

	public function test_a_description_at_exactly_the_word_limit_is_accepted(): void {
		$html = '<p>' . implode( ' ', array_fill( 0, DescriptionValidator::MAX_WORDS, 'word' ) ) . '</p>';

		$this->assertNull( $this->validator->validate( $html ) );
	}

	public function test_a_disallowed_tag_is_never_silently_stripped(): void {
		// The refusal must name the violation, not a "cleaned" result — this
		// class has no stripping path at all; asserting the error code IS the
		// only output is the test that one was never added by accident.
		$error = $this->validator->validate( '<p>Good</p><script>bad</script>' );

		$this->assertInstanceOf( WP_Error::class, $error );
	}
}
