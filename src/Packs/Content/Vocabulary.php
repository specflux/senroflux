<?php
/**
 * The vocabulary seam every content pack's pattern set implements (S4).
 *
 * TARGET REPO PATH: src/Packs/Content/Vocabulary.php
 *
 * `Content\Abilities::registerSource()` holds one of these per pack slug, so
 * `senroflux/list-patterns` can answer with the RUNNING pack's vocabulary
 * without ever taking a `pack` argument from the model (S4 point 4). Only
 * `Pages\Vocabulary` implements it today; a posts or site vocabulary (S5/S7)
 * plugs into the same registrar by implementing this and nothing else.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Content;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * A pack's pattern vocabulary, as far as the shared content registrar needs
 * to know about it.
 */
interface Vocabulary {

	/**
	 * The `senroflux/list-patterns` payload for this pack: metadata + constraints.
	 *
	 * @return array<string,mixed>
	 */
	public function listPayload(): array;
}
