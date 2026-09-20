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
