// @ts-check
const { test } = require( '@playwright/test' );
const path = require( 'path' );
const { setupFor } = require( '../support/global-setup' );

const storageStatePath = path.join( __dirname, '..', 'support', 'storage-state.built_in.json' );

test( 'built-in mode setup: Agent Safety inactive, admin logged in', async () => {
	await setupFor( 'built_in', storageStatePath );
} );
