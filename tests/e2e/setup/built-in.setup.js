// @ts-check
const { test } = require( '@playwright/test' );
const path = require( 'path' );
const { setupFor } = require( '../support/global-setup' );

const storageStatePath = path.join( __dirname, '..', 'support', 'storage-state.built_in.json' );

test( 'built-in mode setup: Agent Safety inactive, admin logged in', async ( {}, testInfo ) => {
	// Activating every required plugin plus the readiness assertions is
	// several sequential wp-cli round trips (container exec overhead each
	// time); the default 45s spec timeout is tuned for a spec, not setup.
	testInfo.setTimeout( 120000 );
	await setupFor( 'built_in', storageStatePath );
} );
