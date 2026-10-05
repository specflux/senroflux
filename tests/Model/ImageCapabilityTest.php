<?php
/**
 * ImageCapability: whether this site's AI Client can produce an image at
 * all. A run whose models are text-only must never be offered, or asked to
 * approve, a generate-image call that can only fail.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Model;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Model\ImageCapability;

final class ImageCapabilityTest extends TestCase {

	protected function tearDown(): void {
		ImageCapability::setProbe( null );
		ImageCapability::setProviderIds( null );
		$GLOBALS['senroflux_test_transients'] = array();
	}

	public function test_the_probe_decides_when_set(): void {
		ImageCapability::setProbe( true );
		$this->assertTrue( ImageCapability::available() );

		ImageCapability::setProbe( false );
		$this->assertFalse( ImageCapability::available() );
	}

	public function test_a_site_with_no_configured_image_model_cannot_generate_images(): void {
		// No probe: the real AI Client registry of this test process has no
		// configured provider, so nothing can output an image.
		$this->assertFalse( ImageCapability::available() );
	}

	public function test_a_recorded_no_model_failure_withholds_image_generation(): void {
		ImageCapability::setProbe( true );
		ImageCapability::setProviderIds( array( 'openrouter' ) );
		$this->assertTrue( ImageCapability::available() );

		ImageCapability::markUnavailable();

		$this->assertFalse( ImageCapability::available() );
		$this->assertSame( 12 * HOUR_IN_SECONDS, $GLOBALS['senroflux_test_transient_ttls']['senroflux_image_generation_unavailable'] );
	}

	public function test_changing_the_configured_providers_invalidates_the_record(): void {
		ImageCapability::setProbe( true );
		ImageCapability::setProviderIds( array( 'openrouter' ) );
		ImageCapability::markUnavailable();

		ImageCapability::setProviderIds( array( 'openrouter', 'openai' ) );

		$this->assertTrue( ImageCapability::available() );
	}

	public function test_provider_order_does_not_invalidate_the_record(): void {
		ImageCapability::setProbe( true );
		ImageCapability::setProviderIds( array( 'openrouter', 'openai' ) );
		ImageCapability::markUnavailable();

		ImageCapability::setProviderIds( array( 'openai', 'openrouter' ) );

		$this->assertFalse( ImageCapability::available() );
	}
}
