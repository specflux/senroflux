import { __, sprintf } from '@wordpress/i18n';

/**
 * The tier badge (S10): "the tier badge appears in Agent Safety mode only".
 * `gateMode !== 'agent_safety'` renders NOTHING — not a hidden badge, not an
 * empty span — because the built-in gate must never leak the tier concept.
 */
export default function TierBadge( { gateMode, tier } ) {
	if ( 'agent_safety' !== gateMode || ! Number.isInteger( tier ) ) {
		return null;
	}

	const className = `senroflux-tier senroflux-tier-${ tier }`;
	const label =
		2 === tier
			? __( 'Tier 2 · irreversible', 'senroflux' )
			: 1 === tier
			? __( 'Tier 1', 'senroflux' )
			: sprintf( __( 'Tier %d', 'senroflux' ), tier );

	return (
		<span className={ className } data-testid="tier-badge">
			{ label }
		</span>
	);
}
