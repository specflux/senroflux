<?php
/**
 * `@api` facade over the pages pack's layout vocabulary (S23; SPEC-SENROFLUX-PRO.md
 * §5 F3).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Api;

use Specflux\SenroFlux\Packs\Pages\Layouts;
use Specflux\SenroFlux\Packs\Pages\Vocabulary;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * A thin, `@api` read surface over `Packs\Pages\Layouts` (itself `@internal`
 * — a third party reaches the pages pack's layout vocabulary ONLY through
 * this class, never by calling `Layouts` directly).
 *
 * Every method here defers to `Layouts` for the actual rule: this class adds
 * no rules of its own, so the model-visible behaviour of `render()` (used by
 * `PagesPack`) and the behaviour a third party sees through `validate()` can
 * never drift apart.
 */
final class LayoutVocabulary {

	/**
	 * Every layout name a `sections` item may use, e.g. `['hero', 'text',
	 * 'text-with-image', ...]`.
	 *
	 * @return list<string>
	 */
	public static function names(): array {
		return Layouts::names();
	}

	/**
	 * The JSON Schema of one `sections` item the model sends.
	 *
	 * DOCUMENTED LIMIT: this is intentionally a PERMISSIVE, approximate
	 * schema — `layout` is the only field this schema itself constrains
	 * (to `names()`); every other field is typed loosely and
	 * `additionalProperties` stays true. The real per-layout field rules
	 * (which fields a given layout takes, their word limits, image-slot
	 * pairing, etc.) live only in `Layouts::render()`'s slot map and
	 * `checkSlot()`, and are NOT duplicated into schema form here — doing so
	 * would be a second, driftable copy of the same rule. Use `validate()`
	 * for the authoritative accept/refuse answer; use this schema only for a
	 * tool declaration's shape (what keys exist, roughly what type), never
	 * as a stand-in for `validate()`.
	 *
	 * @return array<string,mixed>
	 */
	public static function sectionSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'layout'  => array(
					'type' => 'string',
					'enum' => self::names(),
				),
				'heading' => array( 'type' => 'string' ),
				'text'    => array( 'type' => 'string' ),
				'markup'  => array( 'type' => 'string' ),
				'image'   => array(
					'type'       => 'object',
					'properties' => array(
						'url' => array( 'type' => 'string' ),
						'alt' => array( 'type' => 'string' ),
					),
				),
				'button'  => array(
					'type'       => 'object',
					'properties' => array(
						'label' => array( 'type' => 'string' ),
						'url'   => array( 'type' => 'string' ),
					),
				),
				'items'   => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => true,
					),
				),
			),
			'required'             => array( 'layout' ),
			'additionalProperties' => true,
		);
	}

	/**
	 * Refuse (a `WP_Error`) or allow (null) one `sections` item, EXACTLY as
	 * `Layouts::render()` would for the same input — this calls that same
	 * validation path (never a duplicated rule set), so the two can never
	 * disagree.
	 *
	 * IMPLEMENTATION NOTE: `Layouts` has no validate-only entry point —
	 * validation and rendering are the same pass. This method calls
	 * `Layouts::render()` and DISCARDS the markup it returns on success,
	 * keeping only the accept/refuse answer. A future `Layouts` split into a
	 * validate-only step would let this stop rendering; until then this is
	 * the only way to guarantee "refuses exactly what render() refuses"
	 * without a second rule set.
	 *
	 * @param array<string,mixed> $section            The item: `layout` plus its fields.
	 * @param bool                $images_budget_zero Whether the run's `images` budget is 0
	 *                                                 (see `Layouts::render()`'s own parameter).
	 */
	public static function validate( array $section, bool $images_budget_zero = false ): ?WP_Error {
		$result = Layouts::render( $section, 0, new Vocabulary(), $images_budget_zero );

		return $result instanceof WP_Error ? $result : null;
	}

	/**
	 * Every image URL named across a list of `sections` items, in order —
	 * the multi-section counterpart of `Layouts::imageUrls()` (which takes
	 * one item).
	 *
	 * @param list<array<string,mixed>> $sections The `sections` list.
	 * @return list<string>
	 */
	public static function imageUrls( array $sections ): array {
		$urls = array();
		foreach ( $sections as $section ) {
			if ( is_array( $section ) ) {
				$urls = array_merge( $urls, Layouts::imageUrls( $section ) );
			}
		}

		return $urls;
	}

	/**
	 * The model-facing layout rules text the pages pack's `pages/layout-rules`
	 * skill is built from, as a list of lines (join with `"\n"` for the prose
	 * form). Content, never translated (S15 — skill bodies stay English).
	 *
	 * @return list<string>
	 */
	public static function rulesLines(): array {
		return Layouts::rulesLines();
	}
}
