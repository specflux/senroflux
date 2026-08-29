<?php
/**
 * One immutable instruction skill.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Skills;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * An instruction the harness, a pack, or a consumer contributes to a run. The
 * body is plain text (no templating) and is rendered verbatim — skills are
 * content, never translated.
 */
final class Skill {

	/**
	 * @param string      $id       Namespaced skill id, e.g. 'harness/identity'.
	 * @param string      $title    Human-readable heading.
	 * @param string      $body     Plain-text instruction body, no templating.
	 * @param bool        $required Required skills can never be disabled or dropped.
	 * @param SkillSource $source   Where the skill came from.
	 * @param string      $version  Semantic version of the skill text.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $title,
		public readonly string $body,
		public readonly bool $required = false,
		public readonly SkillSource $source = SkillSource::Harness,
		public readonly string $version = '1',
	) {}
}
