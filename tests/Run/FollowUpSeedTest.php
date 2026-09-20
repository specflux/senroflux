<?php
/**
 * FollowUpSeed tests (0.3 S20): renders the source report's change rows,
 * omits the notice when there are none.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Run\FollowUpSeed;

final class FollowUpSeedTest extends TestCase {

	public function test_empty_changes_render_nothing(): void {
		$this->assertSame( '', FollowUpSeed::render( array() ) );
	}

	public function test_renders_the_notice_and_one_line_per_change(): void {
		$changes = array(
			array(
				'object_type' => 'post',
				'object_id'   => '42',
				'title'       => 'Pricing',
				'status'      => 'publish',
				'edit_url'    => 'https://example.test/wp-admin/post.php?post=42&action=edit',
				'verified'    => true,
			),
			array(
				'object_type' => 'post',
				'object_id'   => '7',
				'title'       => 'About',
				'status'      => 'draft',
				'edit_url'    => null,
				'verified'    => false,
			),
		);

		$seed = FollowUpSeed::render( $changes );

		$this->assertStringStartsWith(
			'An earlier run touched these objects. Re-read any object before you change it.',
			$seed
		);
		$this->assertStringContainsString( '- post 42: Pricing (publish) — https://example.test/wp-admin/post.php?post=42&action=edit', $seed );
		$this->assertStringContainsString( '- post 7: About (draft)', $seed );
	}

	public function test_a_corrupt_row_is_named_rather_than_skipped(): void {
		$seed = FollowUpSeed::render( array( 'not-an-array', array( 'object_id' => '9' ) ) );

		$this->assertStringContainsString( '- object 9: 9', $seed );
	}
}
