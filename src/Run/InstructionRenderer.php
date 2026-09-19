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
	 * line, then the gate-mode block, then the tail after a "\n\n---\n\n"
	 * separator when the tail is non-empty.
	 *
	 * 0.3 S3: gate-mode-aware, but stays unaware of PLUGINS — it never names
	 * Agent Safety, in either mode. One sentence shape, only the threshold
	 * clause differs; both modes get the "prefer one create" instruction.
	 *
	 * @param list<Skill> $skills          The collected skills for this run, in order.
	 * @param Tail        $tail            The run tail (budget + notes).
	 * @param GateMode    $gate_mode       The run's pinned gate mode.
	 * @param string|null $withheld_notice 0.3 S6: the pack's one-line notice for this
	 *                                     run's withheld roles, or null for none.
	 * @return string Plain-text instructions.
	 */
	public static function render( array $skills, Tail $tail, GateMode $gate_mode = GateMode::AgentSafety, ?string $withheld_notice = null ): string {
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

		$blocks[] = self::gateBlock( $gate_mode );

		if ( null !== $withheld_notice && '' !== $withheld_notice ) {
			$blocks[] = $withheld_notice;
		}

		$instructions = implode( "\n\n", $blocks );

		$tail_render = $tail->render();
		if ( '' !== $tail_render ) {
			$instructions .= "\n\n---\n\n" . $tail_render;
		}

		return $instructions;
	}

	/**
	 * The one gate-mode sentence, plus the "prefer one create" instruction
	 * that applies in both modes.
	 */
	private static function gateBlock( GateMode $gate_mode ): string {
		$sentence = GateMode::BuiltIn === $gate_mode
			? 'Every call that changes the site stops for a person to approve it before it runs; reads do not.'
			: 'Every call above this site\'s configured risk threshold stops for a person to approve it before it runs; reads do not.';

		return $sentence . ' When creating something that carries content, prefer one create call with the full content over a create followed by several updates.';
	}
}
