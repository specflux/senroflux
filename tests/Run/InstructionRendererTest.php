<?php
/**
 * InstructionRenderer grouping + tail rendering tests.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Run\InstructionRenderer;
use Specflux\SenroFlux\Run\Tail;
use Specflux\SenroFlux\Skills\Skill;
use Specflux\SenroFlux\Skills\SkillSet;
use Specflux\SenroFlux\Skills\SkillSource;

final class InstructionRendererTest extends TestCase {

	public function test_render_groups_harness_before_pack_before_consumer_before_tail(): void {
		$skills = array_merge(
			SkillSet::harnessSkills(),
			array(
				new Skill( 'pack/copy-rules', 'Copy rules', 'Pack body.', false, SkillSource::Pack ),
				new Skill( 'consumer/brand', 'Brand', 'Consumer body.', false, SkillSource::Consumer ),
			)
		);

		$tail         = new Tail( 2, 1, 5, 3, 1000 );
		$text         = InstructionRenderer::render( $skills, $tail );
		$pos_harness  = strpos( $text, '# ' . SkillSet::harnessSkills()[0]->title );
		$pos_pack     = strpos( $text, '# Copy rules' );
		$pos_consumer = strpos( $text, '# Brand' );
		$pos_tail     = strpos( $text, '---' );

		$this->assertNotFalse( $pos_harness, 'harness heading present' );
		$this->assertNotFalse( $pos_pack, 'pack heading present' );
		$this->assertNotFalse( $pos_consumer, 'consumer heading present' );
		$this->assertNotFalse( $pos_tail, 'tail separator present' );

		$this->assertLessThan( $pos_pack, $pos_harness, 'harness section precedes pack' );
		$this->assertLessThan( $pos_consumer, $pos_pack, 'pack section precedes consumer' );
		$this->assertLessThan( $pos_tail, $pos_consumer, 'consumer section precedes tail' );
	}

	public function test_heading_format_is_hash_space_title(): void {
		$skills = SkillSet::harnessSkills();
		$text   = InstructionRenderer::render( $skills, new Tail( 2, 1, 5, 3, 1000 ) );

		foreach ( $skills as $skill ) {
			$this->assertStringContainsString( '# ' . $skill->title . "\n" . $skill->body, $text );
		}
	}

	public function test_tail_is_appended_verbatim_with_no_blank_lines_between_sections(): void {
		$text = InstructionRenderer::render( SkillSet::harnessSkills(), new Tail( 2, 1, 5, 3, 1000 ) );

		$this->assertStringEndsWith(
			"\n\n---\n\nBudget: 2 questions, 1 plans, 3 tool calls, 5 steps and 1000 tokens remain.",
			$text
		);
		$this->assertStringNotContainsString( "\n\n\n", $text, 'no blank lines between sections' );
	}

	public function test_tail_notes_reflect_remaining_fields(): void {
		$tail = new Tail(
			0,
			1,
			5,
			3,
			1000,
			'plan_required',
			array( 'First object', 'Second object' ),
			'English'
		);

		$text = InstructionRenderer::render( SkillSet::harnessSkills(), $tail );

		$this->assertStringContainsString( 'Budget: 0 questions, 1 plans, 3 tool calls, 5 steps and 1000 tokens remain.', $text );
		$this->assertStringContainsString( 'No questions remain: state your assumptions in the plan.', $text );
		$this->assertStringContainsString( 'Your last write was refused: `plan_required` — propose a plan first.', $text );
		$this->assertStringContainsString( 'Before finishing, re-read: First object, Second object.', $text );
		$this->assertStringContainsString( 'Speak to the user in English.', $text );
	}

	public function test_tail_not_in_plan_refusal_line(): void {
		$text = InstructionRenderer::render(
			SkillSet::harnessSkills(),
			new Tail( 1, 1, 5, 3, 1000, 'not_in_plan' )
		);

		$this->assertStringContainsString(
			'Your last write was refused: `not_in_plan` — stay inside the accepted plan or propose a new one.',
			$text
		);
	}
}
