/**
 * Ported from the deleted `RunsScreenParkCardsTest` (0c1107e):
 * `test_the_detail_view_names_a_withheld_role` and its negative case. S6
 * withholds roles the starter lacks the capability for; without this line
 * the run silently has fewer abilities than the pack advertises.
 */

import { render, screen } from '@testing-library/react';
import Chat from '../components/Chat';

const baseRun = {
	id: 1,
	goal: 'A goal',
	status: 'completed',
	gate_mode: 'agent_safety',
};

describe( 'a withheld role is disclosed on the run', () => {
	it( 'names the withheld role when one is withheld', () => {
		render( <Chat run={ { ...baseRun, withheld_roles: [ 'generate' ] } } steps={ [] } /> );

		expect( screen.getByText( /generate/ ) ).toBeInTheDocument();
		expect(
			document.querySelector( '.senroflux-withheld-roles' )
		).toBeInTheDocument();
	} );

	it( 'names nothing when no role is withheld', () => {
		render( <Chat run={ { ...baseRun, withheld_roles: [] } } steps={ [] } /> );

		expect( document.querySelector( '.senroflux-withheld-roles' ) ).toBeNull();
	} );

	it( 'names nothing when the run carries no withheld_roles field at all', () => {
		render( <Chat run={ baseRun } steps={ [] } /> );

		expect( document.querySelector( '.senroflux-withheld-roles' ) ).toBeNull();
	} );
} );

describe( 'the run view shows the pack\'s own withheld-roles line at start (S6)', () => {
	const notice = 'Images are off for this run — your account can\'t upload files.';

	it( 'shows the pack notice, not the generic sentence', () => {
		render(
			<Chat run={ { ...baseRun, withheld_roles: [ 'upload' ], withheld_notice: notice } } steps={ [] } />
		);

		const line = document.querySelector( '.senroflux-withheld-roles' );
		expect( line ).toHaveTextContent( notice );
		expect( screen.queryByText( /Some abilities are off/ ) ).not.toBeInTheDocument();
	} );

	it( 'falls back to a generic line when the pack had nothing to say, so the gap is never silent', () => {
		render( <Chat run={ { ...baseRun, withheld_roles: [ 'upload' ], withheld_notice: null } } steps={ [] } /> );

		expect( document.querySelector( '.senroflux-withheld-roles' ) ).toHaveTextContent( /Some abilities are off/ );
	} );

	it( 'shows nothing at all for a user holding every capability, even with a stray notice', () => {
		render( <Chat run={ { ...baseRun, withheld_roles: [], withheld_notice: null } } steps={ [] } /> );

		expect( document.querySelector( '.senroflux-withheld-roles' ) ).toBeNull();
	} );
} );
