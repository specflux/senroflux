/**
 * The command palette loader (0.3 S10). The one property that matters most
 * here is negative: this command must NEVER become a one-keystroke way to
 * launch an agent run. There is no `startRun`/`tickRun` import in
 * `../index.js` for the callback to reach, and this file asserts that
 * behaviorally too — the callback only ever calls `window.open()` with a
 * `goal`-carrying URL, nothing that talks to admin-ajax or REST.
 */

import { select } from '@wordpress/data';
import { store as commandsStore } from '@wordpress/commands';
import { useSenrofluxRunCommandLoader } from '../index';

describe( 'the SenroFlux run command loader', () => {
	const originalOpen = window.open;
	const originalFetch = global.fetch;

	beforeEach( () => {
		window.senrofluxCommandsConfig = { runsUrl: 'http://example.test/wp-admin/admin.php?page=senroflux-runs' };
		window.open = jest.fn();
		global.fetch = jest.fn( () => {
			throw new Error( 'the palette command must never make a network request' );
		} );
	} );

	afterEach( () => {
		window.open = originalOpen;
		global.fetch = originalFetch;
		delete window.senrofluxCommandsConfig;
	} );

	it( 'registers itself into the core/commands loader registry on import', () => {
		const loaders = select( commandsStore ).getCommandLoaders();
		expect( loaders.some( ( loader ) => 'senroflux/run-goal' === loader.name ) ).toBe( true );
	} );

	it( 'offers nothing while the search box is empty', () => {
		expect( useSenrofluxRunCommandLoader( { search: '' } ) ).toEqual( { commands: [], isLoading: false } );
		expect( useSenrofluxRunCommandLoader( { search: '   ' } ) ).toEqual( { commands: [], isLoading: false } );
	} );

	it( 'builds a dynamic label from the typed search text', () => {
		const { commands } = useSenrofluxRunCommandLoader( { search: 'publish the spring page' } );
		expect( commands ).toHaveLength( 1 );
		expect( commands[ 0 ].label ).toBe( 'SenroFlux: run "publish the spring page"' );
	} );

	it( 'only navigates with the goal pre-filled — it never starts a run', () => {
		const { commands } = useSenrofluxRunCommandLoader( { search: 'draft an FAQ page' } );
		const close = jest.fn();

		commands[ 0 ].callback( { close } );

		expect( close ).toHaveBeenCalledTimes( 1 );
		expect( global.fetch ).not.toHaveBeenCalled();
		expect( window.open ).toHaveBeenCalledTimes( 1 );

		const [ url ] = window.open.mock.calls[ 0 ];
		expect( url ).toContain( 'page=senroflux-runs' );
		expect( url ).toContain( 'goal=' );
		expect( decodeURIComponent( url ) ).toContain( 'draft an FAQ page' );
	} );
} );
