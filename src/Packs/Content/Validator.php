<?php
/**
 * The write-validation seam every content pack's validator implements (S4).
 *
 * TARGET REPO PATH: src/Packs/Content/Validator.php
 *
 * `Content\Abilities::registerSource()` holds one of these per pack slug, so
 * `create-post` / `update-post` / `publish-post` validate against the
 * RUNNING pack's rules without ever taking a `pack` argument from the model
 * (S4 point 4). Only `Pages\Validator` implements it today; a posts or site
 * validator (S5/S7) plugs into the same registrar by implementing this and
 * nothing else.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Content;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * A pack's write validator, as far as the shared content registrar needs to
 * know about it.
 */
interface Validator {

	/**
	 * Validate then clean markup for persistence. When `ok` is false `content`
	 * is the untouched input and `wp_error` carries the refusal; when `ok` is
	 * true `content` is the cleaned markup to persist.
	 *
	 * @param string              $content Serialized block markup.
	 * @param array<string,mixed> $ctx     Context (e.g. post_type); used for messaging.
	 * @return array{ok:bool, content:string, wp_error:\WP_Error|null}
	 */
	public function clean( string $content, array $ctx = array() ): array;
}
