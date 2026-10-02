/**
 * Runs-pack fix: `startRun()` must actually send the chosen pack over
 * admin-ajax — the API layer half of the bug (the PHP side never even
 * looked for a `pack` field before this fix).
 */

import { startRun, fetchSetupState } from '../api';

describe( 'startRun', () => {
	const config = { consumer: 'senroflux-admin', nonce: 'abc123', ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php' };

	beforeEach( () => {
		global.fetch = jest.fn().mockResolvedValue( {
			json: () => Promise.resolve( { success: true, data: { run: { id: 1 } } } ),
		} );
	} );

	it( 'sends the pack field when a pack is given', async () => {
		await startRun( 'Publish the spring page', config, 'pages' );

		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
		const [ , options ] = global.fetch.mock.calls[ 0 ];
		const body = new URLSearchParams( options.body.toString() );

		expect( body.get( 'action' ) ).toBe( 'senroflux_start' );
		expect( body.get( 'consumer' ) ).toBe( 'senroflux-admin' );
		expect( body.get( 'goal' ) ).toBe( 'Publish the spring page' );
		expect( body.get( 'pack' ) ).toBe( 'pages' );
	} );

	it( 'omits the pack field when no pack is given', async () => {
		await startRun( 'Publish the spring page', config );

		const [ , options ] = global.fetch.mock.calls[ 0 ];
		const body = new URLSearchParams( options.body.toString() );

		expect( body.has( 'pack' ) ).toBe( false );
	} );
} );

/**
 * `startRun`'s model params: a chosen `{ provider, id }` pair must become
 * `model_provider`/`model_id` POST fields; `null`/omitted must send NEITHER
 * field, so a caller that never picked a model gets the exact same request
 * sent before the model picker existed.
 */
describe( 'startRun model params', () => {
	const config = { consumer: 'senroflux-admin', nonce: 'abc', ajaxUrl: 'http://example.test/admin-ajax.php' };

	beforeEach( () => {
		global.fetch = jest.fn().mockResolvedValue( {
			json: () => Promise.resolve( { success: true, data: { run: { id: 1, step_count: 0 } } } ),
		} );
	} );

	it( 'sends no model_provider/model_id fields when model is null', async () => {
		await startRun( 'Do the thing', config, 'pages', null );

		const [ , options ] = global.fetch.mock.calls[ 0 ];
		const body = new URLSearchParams( options.body.toString() );
		expect( body.has( 'model_provider' ) ).toBe( false );
		expect( body.has( 'model_id' ) ).toBe( false );
	} );

	it( 'sends no model_provider/model_id fields when model is omitted entirely', async () => {
		await startRun( 'Do the thing', config, 'pages' );

		const [ , options ] = global.fetch.mock.calls[ 0 ];
		const body = new URLSearchParams( options.body.toString() );
		expect( body.has( 'model_provider' ) ).toBe( false );
		expect( body.has( 'model_id' ) ).toBe( false );
	} );

	it( 'sends model_provider/model_id when a model is chosen', async () => {
		await startRun( 'Do the thing', config, 'pages', { provider: 'openai', id: 'gpt-5' } );

		const [ , options ] = global.fetch.mock.calls[ 0 ];
		const body = new URLSearchParams( options.body.toString() );
		expect( body.get( 'model_provider' ) ).toBe( 'openai' );
		expect( body.get( 'model_id' ) ).toBe( 'gpt-5' );
	} );
} );

describe( 'startRun follow-up (stage 22b)', () => {
	const config = { consumer: 'senroflux-admin', nonce: 'abc123', ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php' };

	beforeEach( () => {
		global.fetch = jest.fn().mockResolvedValue( {
			json: () => Promise.resolve( { success: true, data: { run: { id: 2 } } } ),
		} );
	} );

	it( 'sends follow_up_of when a source run is given', async () => {
		await startRun( 'Tidy it', config, 'pages', null, 7 );

		const body = new URLSearchParams( global.fetch.mock.calls[ 0 ][ 1 ].body.toString() );
		expect( body.get( 'follow_up_of' ) ).toBe( '7' );
	} );

	it( 'sends no follow_up_of otherwise', async () => {
		await startRun( 'Tidy it', config, 'pages' );

		const body = new URLSearchParams( global.fetch.mock.calls[ 0 ][ 1 ].body.toString() );
		expect( body.has( 'follow_up_of' ) ).toBe( false );
	} );
} );

describe( 'fetchSetupState (stage 22b, J1)', () => {
	const config = { consumer: 'senroflux-admin', nonce: 'abc123', ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php' };

	it( 'posts the setup-panel action and returns the state', async () => {
		global.fetch = jest.fn().mockResolvedValue( {
			json: () =>
				Promise.resolve( {
					success: true,
					data: { html: '<div id="senroflux-setup-panel"></div>', start_enabled: true, packs: [], unavailable_packs: [] },
				} ),
		} );

		const state = await fetchSetupState( config );

		const body = new URLSearchParams( global.fetch.mock.calls[ 0 ][ 1 ].body.toString() );
		expect( body.get( 'action' ) ).toBe( 'senroflux_setup_panel' );
		expect( body.get( 'nonce' ) ).toBe( 'abc123' );
		expect( state.start_enabled ).toBe( true );
	} );
} );
