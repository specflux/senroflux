// @ts-check
const { test } = require( '@playwright/test' );
const path = require( 'path' );
const { setupFor } = require( '../support/global-setup' );

const storageStatePath = path.join( __dirname, '..', 'support', 'storage-state.agent_safety.json' );

test( 'Agent Safety mode setup: plugin active, pack bound, admin logged in', async () => {
	await setupFor( 'agent_safety', storageStatePath );
} );
