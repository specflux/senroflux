<?php
/**
 * Where a skill came from.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Skills;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * The origin of a skill, which drives both grouping (harness, then pack, then
 * consumer) and the harness's authority over pack guidance.
 */
enum SkillSource: string {

	case Harness  = 'harness';
	case Pack     = 'pack';
	case Consumer = 'consumer';
}
