/**
 * Small page-object-ish helpers shared by specs, kept deliberately thin —
 * specs assert against the real DOM/text a user would see, not against
 * these helpers' own opinions.
 */

/** Navigate to the Runs screen. */
async function gotoRuns( page, query = '' ) {
	await page.goto( `/wp-admin/admin.php?page=senroflux-runs${ query }` );
	await page.waitForSelector( '#senroflux-runs-root' );
}

/** Wait for the loading placeholder to clear. */
async function waitLoaded( page ) {
	await page.waitForSelector( '.senroflux-loading', { state: 'detached' } ).catch( () => {} );
}

/** Start a new run from the message box (idle state only). */
async function startRun( page, goal ) {
	const box = page.locator( '.senroflux-message-box-input' );
	await box.fill( goal );
	await page.locator( '.senroflux-message-box button:has-text("Start run")' ).click();
}

/** Wait for the open park card of a given kind ('question'|'plan'|'approval'). */
async function waitForPark( page, kind, headingText ) {
	const heading = page.locator( '#senroflux-park-heading' );
	await heading.waitFor( { state: 'visible', timeout: 30000 } );
	if ( headingText ) {
		await heading.filter( { hasText: headingText } ).waitFor( { timeout: 30000 } );
	}
	return page.locator( '.senroflux-park-card' );
}

/** Answer an open question park by free text. */
async function answerQuestion( page, text ) {
	await page.locator( '.senroflux-answer-text' ).fill( text );
	await page.locator( '.senroflux-park-actions button:has-text("Answer")' ).click();
}

/** Answer an open question park by picking a listed choice. */
async function answerChoice( page, choiceLabel ) {
	await page.locator( `.senroflux-answer-choices label:has-text("${ choiceLabel }") input` ).check();
	await page.locator( '.senroflux-park-actions button:has-text("Answer")' ).click();
}

/** Accept the open plan park. */
async function acceptPlan( page ) {
	await page.locator( '.senroflux-park-actions button:has-text("Accept plan")' ).click();
}

/** Approve the open approval park. */
async function approveCall( page ) {
	await page.locator( '.senroflux-park-actions button:has-text("Approve")' ).click();
}

/** Wait until no park card and the run isn't busy (ticks settled). */
async function waitSettled( page ) {
	await page.waitForFunction(
		() => ! document.querySelector( '.senroflux-park-card' ) && ! document.querySelector( '.senroflux-typing' ),
		null,
		{ timeout: 30000 }
	);
}

module.exports = {
	gotoRuns,
	waitLoaded,
	startRun,
	waitForPark,
	answerQuestion,
	answerChoice,
	acceptPlan,
	approveCall,
	waitSettled,
};
