<?php
/**
 * Abilities tests (stage 8, S10).
 *
 * TARGET REPO PATH: tests/Packs/Pages/AbilitiesTest.php
 *
 * Exercises the polyfill ability registration and the write behaviours the
 * pack owns: create-post's `status_not_allowed` refusal (only draft allowed),
 * create returning an `id` (the S12 tracker key), and the list-patterns
 * payload.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Pages;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Pages\Abilities;
use Specflux\SenroFlux\Packs\Pages\Vocabulary;
use WP_Error;

final class AbilitiesTest extends TestCase {

	private function loadShims(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/blocks.php';
	}

	protected function setUp(): void {
		$this->loadShims();

		$GLOBALS['senroflux_test_abilities']          = array();
		$GLOBALS['senroflux_test_inserted_posts']     = array();
		$GLOBALS['senroflux_test_next_post_id']       = 100;
		$GLOBALS['senroflux_test_posts']              = array();
		$GLOBALS['senroflux_test_ability_categories'] = array();

		Abilities::reset();
		Abilities::registerCategory();
		Abilities::register();
	}

	private function validContent(): string {
		$vocabulary = new Vocabulary();
		$fill       = static fn ( string $m ): string => (string) preg_replace( '/\{\{[a-z_]+\}\}/', 'lorem', $m );

		// Pattern index 0 is hero; index 1 is text-section.
		return $fill( $vocabulary->all()[0]['markup'] ) . $fill( $vocabulary->all()[1]['markup'] );
	}

	public function test_create_post_status_not_allowed_refuses_publish(): void {
		$ability = wp_get_ability( 'senroflux/create-post' );
		$result  = $ability->execute(
			array(
				'post_type' => 'page',
				'title'     => 'T',
				'content'   => $this->validContent(),
				'status'    => 'publish',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'status_not_allowed', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['senroflux_test_inserted_posts'], 'a refusal must persist nothing' );
	}

	public function test_create_post_returns_id_and_forces_draft(): void {
		$ability = wp_get_ability( 'senroflux/create-post' );
		$result  = $ability->execute(
			array(
				'post_type' => 'page',
				'title'     => 'Pricing',
				'content'   => $this->validContent(),
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertSame( 100, $result['id'] );
		$this->assertSame( 'draft', $result['status'] );
		$this->assertCount( 1, $GLOBALS['senroflux_test_inserted_posts'] );
		$this->assertSame( 'draft', $GLOBALS['senroflux_test_inserted_posts'][0]['post_status'] );
	}

	public function test_create_post_refuses_invalid_markup(): void {
		$ability = wp_get_ability( 'senroflux/create-post' );
		$result  = $ability->execute(
			array(
				'post_type' => 'page',
				'title'     => 'T',
				'content'   => '<!-- wp:image --><figure></figure><!-- /wp:image -->',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'unknown_block', $result->get_error_code() );
	}

	public function test_list_patterns_returns_seven_patterns(): void {
		$ability = wp_get_ability( 'senroflux/list-patterns' );
		$result  = $ability->execute( array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'patterns', $result );
		$this->assertCount( 7, $result['patterns'] );
		$this->assertArrayHasKey( 'name', $result['patterns'][0] );
		$this->assertArrayHasKey( 'constraints', $result['patterns'][0] );
	}
}
