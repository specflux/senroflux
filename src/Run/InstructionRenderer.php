<?php
/**
 * Render a run's skill instructions.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Run;

use Specflux\SenroFlux\Skills\Skill;
use Specflux\SenroFlux\Skills\SkillSource;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Assembles the plain-text instruction block handed to the model: the skills
 * grouped by source (harness, then pack, then consumer), each rendered as
 * "# <title>" followed by its verbatim body, then the run tail separated by a
 * horizontal rule. Skill bodies are content and are never translated.
 */
final class InstructionRenderer {

	/**
	 * Render the skill sections (harness, pack, consumer) joined with a blank
	 * line, then the tail after a "\n\n---\n\n" separator when the tail is
	 * non-empty.
	 *
	 * @param list<Skill> $skills The collected skills for this run, in order.
	 * @param Tail        $tail   The run tail (budget + notes).
	 * @return string Plain-text instructions.
	 */
	public static function render( array $skills, Tail $tail ): string {
		$sections = array(
			SkillSource::Harness->value  => array(),
			SkillSource::Pack->value     => array(),
			SkillSource::Consumer->value => array(),
		);

		foreach ( $skills as $skill ) {
			$sections[ $skill->source->value ][] = '# ' . $skill->title . "\n" . $skill->body;
		}

		$blocks = array();
		foreach ( $sections as $section ) {
			if ( ! empty( $section ) ) {
				$blocks[] = implode( "\n\n", $section );
			}
		}

		$instructions = implode( "\n\n", $blocks );

		$tail_render = $tail->render();
		if ( '' !== $tail_render ) {
			$instructions .= "\n\n---\n\n" . $tail_render;
		}

		return $instructions;
	}
}
