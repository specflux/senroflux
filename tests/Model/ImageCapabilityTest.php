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
}
