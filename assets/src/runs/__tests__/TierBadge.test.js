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
} );
