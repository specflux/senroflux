import { render, screen } from '@testing-library/react';
import TierBadge from '../components/TierBadge';

describe( 'the tier badge appears in Agent Safety mode only', () => {
	it( 'renders the badge in agent_safety mode', () => {
		render( <TierBadge gateMode="agent_safety" tier={ 1 } /> );
		expect( screen.getByTestId( 'tier-badge' ) ).toBeInTheDocument();
	} );

	it( 'renders NOTHING in built_in mode, even with a valid tier', () => {
		const { container } = render( <TierBadge gateMode="built_in" tier={ 2 } /> );
		expect( container ).toBeEmptyDOMElement();
		expect( screen.queryByTestId( 'tier-badge' ) ).not.toBeInTheDocument();
	} );

	it( 'renders nothing when the tier is unknown, even in agent_safety mode', () => {
		const { container } = render( <TierBadge gateMode="agent_safety" tier={ null } /> );
		expect( container ).toBeEmptyDOMElement();
	} );

	/**
	 * A parked approval stores its tier as a STRING. This test is the one the
	 * original `Number.isInteger()` guard failed: the plan card passed numbers
	 * and showed badges, the approval card passed `"1"` and showed none, so in
	 * Agent Safety mode the approver was never told the tier of the call in
	 * front of them. Caught by the Playwright Agent Safety spec, not by a unit
	 * test — hence this one.
	 */
	it( 'renders the badge for a STRING tier, as a parked approval sends it', () => {
		render( <TierBadge gateMode="agent_safety" tier="1" /> );
		expect( screen.getByTestId( 'tier-badge' ) ).toHaveTextContent( 'Tier 1' );
	} );

	it( 'renders the irreversible label for a string Tier 2', () => {
		render( <TierBadge gateMode="agent_safety" tier="2" /> );
		expect( screen.getByTestId( 'tier-badge' ) ).toHaveTextContent( 'Tier 2 · irreversible' );
	} );

	it( 'still renders nothing for a string that is not an integer tier', () => {
		const { container } = render( <TierBadge gateMode="agent_safety" tier="not-a-tier" /> );
		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'still renders nothing for a STRING tier in built_in mode', () => {
		const { container } = render( <TierBadge gateMode="built_in" tier="2" /> );
		expect( container ).toBeEmptyDOMElement();
	} );
} );
