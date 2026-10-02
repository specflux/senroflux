<?php
/**
 * The report's object lookup: one place that knows every pack's object ids.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs;

use Specflux\SenroFlux\Packs\Commerce\ReportLookup;
use Specflux\SenroFlux\Packs\Content\Media;
use Specflux\SenroFlux\Packs\Site\FrontPage;
use Specflux\SenroFlux\Packs\Site\Navigation;
use Specflux\SenroFlux\Packs\Site\Style;
use Specflux\SenroFlux\Run\Report;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Resolves one written-object id (as a pack's {@see Pack::objectIdPrefix()}
 * qualified it) to the shape {@see Report} needs. The harness treats ids as
 * opaque, so this is the composition root's dispatcher — Plugin.php hands it
 * to the Runner as the report's `$post_lookup`. An id with no known prefix is
 * a bare post id.
 */
final class ObjectLookup {

	/**
	 * @param string|int $object_id The tracked object id.
	 * @return array{object_type:string,title:string,status:string,edit_url:?string,preview_url:?string}
	 */
	public static function resolve( string|int $object_id ): array {
		$object_id = (string) $object_id;

		if ( str_starts_with( $object_id, Media::OBJECT_ID_PREFIX ) ) {
			return Media::attachmentLookup( (int) substr( $object_id, strlen( Media::OBJECT_ID_PREFIX ) ) );
		}

		if ( str_starts_with( $object_id, Media::TERM_ID_PREFIX ) ) {
			return Media::termLookup( (int) substr( $object_id, strlen( Media::TERM_ID_PREFIX ) ) );
		}

		// Proof-run defect fix: the commerce pack's coupons, orders,
		// shipping zones, tax rates and saved report pages.
		$commerce = ReportLookup::resolve( $object_id );
		if ( null !== $commerce ) {
			return $commerce;
		}

		// S12 (defect fix): the site pack's singleton objects — none is a
		// post, so wpPostLookup() would resolve them "unknown".
		if ( Navigation::OBJECT_ID === $object_id ) {
			return Navigation::reportLookup();
		}

		if ( FrontPage::OBJECT_ID === $object_id ) {
			return FrontPage::reportLookup();
		}

		if ( Style::OBJECT_ID === $object_id ) {
			return Style::reportLookup();
		}

		return ( Report::wpPostLookup() )( $object_id );
	}
}
