/**
 * Rendered-DOM evidence for the six S10 live-review findings, per the
 * stage-17a brief: "you cannot verify the layout by reading your own code."
 *
 * The REAL `style.css` (not a mock, not a hand copy) is read from disk and
 * injected into jsdom's `<head>` before each render, so `getComputedStyle()`
 * reflects the actual shipped stylesheet — a change to `style.css` that
 * regresses one of these properties fails this file, not just a visual
 * review.
 */

import fs from 'fs';
import path from 'path';
import { render, screen } from '@testing-library/react';
import ParkCard from '../components/ParkCard';
import RunList from '../components/RunList';

const CSS_PATH = path.join( __dirname, '..', 'style.css' );
const CSS_SOURCE = fs.readFileSync( CSS_PATH, 'utf8' );

function injectRealStylesheet() {
	const style = document.createElement( 'style' );
	style.textContent = CSS_SOURCE;
	document.head.appendChild( style );
	return () => document.head.removeChild( style );
}

describe( 'long goal titles wrap, they do not run off the viewport', () => {
	it( 'the run-row title has no nowrap/ellipsis and DOES allow anywhere-wrapping', () => {
		const removeStyle = injectRealStylesheet();
		try {
			const longGoal =
				'Build a landing page for our spring workshops with a hero, three workshop cards with dates, a short FAQ and a signup button that goes to /register and publish it when it looks right';
			render(
				<RunList
					runs={ [ { id: 1, goal: longGoal, status: 'running', pack: 'pages' } ] }
					activeTab="all"
					onTabChange={ () => {} }
					selectedRunId={ null }
					onSelectRun={ () => {} }
				/>
			);

			const title = screen.getByText( longGoal );
			const computed = getComputedStyle( title );

			expect( computed.whiteSpace ).not.toBe( 'nowrap' );
			expect( computed.textOverflow ).not.toBe( 'ellipsis' );
			expect( computed.overflowWrap ).toBe( 'anywhere' );
		} finally {
			removeStyle();
		}
	} );
} );

describe( 'approval-card arguments wrap and show in full', () => {
	it( 'the arguments box has no overflow:hidden / ellipsis, and DOES wrap', () => {
		// Textual proof against the actual CSS source: the `.senroflux-args`
		// rule block itself never sets `overflow: hidden` or `text-overflow:
		// ellipsis` anywhere in the stylesheet.
		const argsBlockMatch = CSS_SOURCE.match( /\.senroflux-args\s*{[^}]*}/g ) || [];
		argsBlockMatch.forEach( ( block ) => {
			expect( block ).not.toMatch( /overflow:\s*hidden/ );
			expect( block ).not.toMatch( /text-overflow:\s*ellipsis/ );
		} );

		const removeStyle = injectRealStylesheet();
		try {
			const longArg =
				'A very long single-word-style-argument-value-that-would-otherwise-overflow-the-card-horizontally-if-it-were-allowed-to-do-so-instead-of-wrapping-anywhere';
			render(
				<ParkCard
					kind="approval"
					gateMode="agent_safety"
					payload={ { verb: 'senroflux/publish-page', tier: 2, args: { prompt: longArg } } }
				/>
			);

			const pre = screen.getByText( ( _content, node ) => node?.tagName === 'PRE' );
			const computed = getComputedStyle( pre );

			expect( computed.whiteSpace ).toBe( 'pre-wrap' );
			expect( computed.overflowWrap ).toBe( 'anywhere' );
			expect( computed.overflow ).not.toBe( 'hidden' );
			expect( computed.textOverflow ).not.toBe( 'ellipsis' );
		} finally {
			removeStyle();
		}
	} );
} );

describe( 'the park-heading focus ring hugs the heading, not the whole card', () => {
	it( 'only .senroflux-park-heading (never .senroflux-park-card) carries a :focus-visible rule', () => {
		const cardFocusRules = CSS_SOURCE.match( /\.senroflux-park-card[^,{]*:focus-visible/g );
		const headingFocusRules = CSS_SOURCE.match( /\.senroflux-park-heading:focus-visible/g );

		expect( cardFocusRules ).toBeNull();
		expect( headingFocusRules ).not.toBeNull();
	} );

	it( 'moves DOM focus to the heading element itself when a park renders', () => {
		render(
			<ParkCard kind="question" gateMode="built_in" payload={ { text: 'Which one?', rationale: '' } } />
		);

		const heading = screen.getByRole( 'heading', { name: 'Question for you' } );
		expect( document.activeElement ).toBe( heading );
	} );
} );

describe( 'Approve and Reject need spacing between them', () => {
	it( 'the park-actions container has a non-zero gap between its buttons', () => {
		const actionsBlockMatch = CSS_SOURCE.match( /\.senroflux-park-actions\s*{[^}]*}/ );
		expect( actionsBlockMatch ).not.toBeNull();
		expect( actionsBlockMatch[ 0 ] ).toMatch( /gap:\s*(?!0(?:px)?\s*;)\S/ );

		const removeStyle = injectRealStylesheet();
		try {
			render(
				<ParkCard
					kind="approval"
					gateMode="built_in"
					payload={ { verb: 'senroflux/publish-page', tier: 2, args: {} } }
				/>
			);
			const approve = screen.getByRole( 'button', { name: 'Approve' } );
			const gapContainer = approve.parentElement;
			expect( getComputedStyle( gapContainer ).gap ).toBe( '10px' );
		} finally {
			removeStyle();
		}
	} );
} );

describe( 'tab counts match the list they filter', () => {
	it( 'the "Needs you" tab label carries the SAME count as the rows shown for it', () => {
		const runs = [
			{ id: 1, status: 'awaiting_approval', viewer_may_tick: true, goal: 'a', pack: 'pages' },
			{ id: 2, status: 'running', viewer_may_tick: false, goal: 'b', pack: 'pages' },
		];
		render(
			<RunList
				runs={ runs }
				activeTab="needs_you"
				onTabChange={ () => {} }
				selectedRunId={ null }
				onSelectRun={ () => {} }
			/>
		);

		expect( screen.getByRole( 'tab', { name: /Needs you \(1\)/ } ) ).toBeInTheDocument();
		expect( screen.getAllByRole( 'listitem' ) ).toHaveLength( 1 );
	} );
} );
