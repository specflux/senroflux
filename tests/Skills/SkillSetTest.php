<?php
/**
 * SkillSet::harnessSkills(), ::collect() and ::ceilingError() tests.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Skills;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Skills\Skill;
use Specflux\SenroFlux\Skills\SkillSet;
use Specflux\SenroFlux\Skills\SkillSource;
use WP_Error;

final class SkillSetTest extends TestCase {

	protected function tearDown(): void {
		remove_all_filters( 'senroflux_run_skills' );
		remove_all_filters( 'senroflux_skills_max_tokens' );
	}

	public function test_harness_skills_are_four_required_harness_skills_in_order(): void {
		$skills = SkillSet::harnessSkills();

		$this->assertCount( 4, $skills );
		$this->assertSame(
			array( 'harness/identity', 'harness/injection-rule', 'harness/workflow', 'harness/authority' ),
			array_map( static fn ( Skill $skill ): string => $skill->id, $skills )
		);

		foreach ( $skills as $skill ) {
			$this->assertTrue( $skill->required, $skill->id . ' is required' );
			$this->assertSame( SkillSource::Harness, $skill->source, $skill->id . ' comes from the harness' );
			$this->assertSame( '1', $skill->version, $skill->id . ' ships version 1' );
		}
	}

	public function test_collect_builds_harness_then_pack_then_consumer(): void {
		$pack = array(
			new Skill( 'pack/copy-rules', 'Copy rules', 'Pack body one.', false, SkillSource::Pack ),
			new Skill( 'pack/tone', 'Tone', 'Pack body two.', false, SkillSource::Pack ),
		);

		add_filter(
			'senroflux_run_skills',
			static fn ( array $skills ): array => array_merge(
				$skills,
				array( new Skill( 'consumer/brand', 'Brand', 'Consumer body.', false, SkillSource::Consumer ) )
			)
		);

		$skills = SkillSet::collect( 'user-42', 'Build a landing page', $pack );

		$this->assertSame(
			array(
				'harness/identity',
				'harness/injection-rule',
				'harness/workflow',
				'harness/authority',
				'pack/copy-rules',
				'pack/tone',
				'consumer/brand',
			),
			array_map( static fn ( Skill $skill ): string => $skill->id, $skills )
		);
	}

	public function test_skills_disable_drops_non_required_but_never_required(): void {
		$pack = array(
			new Skill( 'pack/optional', 'Optional', 'Pack body.', false, SkillSource::Pack ),
		);

		$skills = SkillSet::collect(
			'user-42',
			'Build a landing page',
			$pack,
			array( 'harness/identity', 'pack/optional', 'pack/missing' )
		);

		$ids = array_map( static fn ( Skill $skill ): string => $skill->id, $skills );

		$this->assertContains( 'harness/identity', $ids, 'a required harness skill is never disabled' );
		$this->assertNotContains( 'pack/optional', $ids, 'a listed non-required skill is dropped' );
	}

	public function test_collect_re_adds_a_harness_skill_removed_by_the_filter(): void {
		add_filter(
			'senroflux_run_skills',
			static fn ( array $skills ): array => array_values(
				array_filter(
					$skills,
					static fn ( Skill $skill ): bool => 'harness/identity' !== $skill->id
				)
			)
		);

		$skills = SkillSet::collect( 'user-42', 'Build a landing page' );
		$ids    = array_map( static fn ( Skill $skill ): string => $skill->id, $skills );

		$this->assertContains( 'harness/identity', $ids, 'a required harness skill removed by the filter is re-added' );

		foreach ( $skills as $skill ) {
			$this->assertTrue( $skill->required, 'every harness skill stays required' );
		}

		$this->assertCount(
			4,
			array_filter( $skills, static fn ( Skill $skill ): bool => SkillSource::Harness === $skill->source )
		);
	}

	public function test_collect_dedupes_by_id_and_first_wins(): void {
		$pack = array(
			new Skill( 'harness/identity', 'Duplicate identity', 'Pack duplicate body.', false, SkillSource::Pack ),
			new Skill( 'pack/tone', 'Tone', 'Pack body.', false, SkillSource::Pack ),
		);

		$skills = SkillSet::collect( 'user-42', 'Build a landing page', $pack );

		$this->assertSame(
			array(
				'harness/identity',
				'harness/injection-rule',
				'harness/workflow',
				'harness/authority',
				'pack/tone',
			),
			array_map( static fn ( Skill $skill ): string => $skill->id, $skills )
		);

		// First occurrence wins: the harness identity body is retained, not the pack duplicate.
		$this->assertSame( SkillSet::harnessSkills()[0]->body, $skills[0]->body );
	}

	public function test_ceiling_error_returns_null_within_the_default_ceiling(): void {
		$this->assertNull( SkillSet::ceilingError( SkillSet::harnessSkills() ) );
	}

	public function test_ceiling_error_returns_wp_error_when_over_and_never_truncates(): void {
		add_filter(
			'senroflux_skills_max_tokens',
			static fn ( int $max ): int => min( $max, 2 )
		);

		$skill  = new Skill( 'pack/long', 'Long body', str_repeat( 'x', 100 ), false, SkillSource::Pack );
		$before = $skill->body;

		$error = SkillSet::ceilingError( array( $skill ) );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'skills_too_large', $error->get_error_code() );
		$this->assertSame( 'The run\'s skills exceed the instruction ceiling.', $error->get_error_message() );

		$data = $error->get_error_data();
		$this->assertSame( 400, $data['status'] );
		$this->assertSame( 2, $data['max_tokens'] );
		$this->assertGreaterThan( 2, $data['tokens'] );

		// ceilingError NEVER mutates the skills it is given.
		$this->assertSame( $before, $skill->body );
		$this->assertSame( 100, strlen( $skill->body ) );
	}
}
