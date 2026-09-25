/**
 * Scripted turn builders fed to the fake provider's option-backed queue
 * (`senroflux_e2e_script`, consumed one step per model turn — S9). Every
 * ability call uses the real `wpab__<namespace>__<name>` tool-name shape a
 * live model would receive from ToolRegistry (mirrors the raw
 * `wpab__senroflux__create-post` regression finding), never a shortcut form.
 */

const call = ( name, args = {} ) => ( { type: 'calls', calls: [ { name, args } ] } );
const text = ( value ) => ( { type: 'text', text: value } );

const PLAN_GOAL = 'Build the launch page';

/**
 * The "full tour" scenario (S10 + regression assertions):
 *   plan -> question -> three separate Tier-0 calls (ledger collapse,
 *   regression #1) -> a Tier-1 write (plan-fence + approval, the tier-badge
 *   assertion) -> a brief suggestion -> a final summary.
 */
function fullTour() {
	return [
		call( 'senroflux__propose-plan', {
			goal: PLAN_GOAL,
			steps: [
				{ text: 'Create the launch post', verbs: [ 'senroflux-e2e/create-thing' ] },
			],
			assumptions: [ 'Draft only, never published by this run.' ],
		} ),
		call( 'senroflux__ask-user', {
			text: 'What should the launch post be titled?',
			choices: [ 'Launch Day', 'Big Launch' ],
			rationale: 'Need a title before drafting.',
		} ),
		call( 'wpab__senroflux-e2e__read-thing', { id: 'thing-1' } ),
		call( 'wpab__senroflux-e2e__list-things', {} ),
		call( 'wpab__senroflux-e2e__search-things', { query: 'launch' } ),
		call( 'wpab__senroflux-e2e__create-thing', { title: 'Launch Day' } ),
		call( 'senroflux__suggest-brief-addition', {
			text: 'Mention the launch post in the weekly newsletter.',
		} ),
		text( 'All done — created the launch post and left a suggestion for your site brief.' ),
		// One trailing buffer turn: after a Tier-1 approval resume, the
		// drive loop takes one more internal round-trip (a context re-seed)
		// than the plain step count suggests before it actually reads
		// "completed" — observed empirically driving this scenario live.
		// Without this the fake provider's queue underruns and the run
		// picks up an extra "No scripted response left." bubble.
		text( 'Done.' ),
	];
}

/**
 * The keyboard-only scenario (S22): question, then plan, then approval, in
 * that order, one park at a time.
 */
function keyboardTour() {
	return [
		call( 'senroflux__ask-user', {
			text: 'Which section should this go under?',
			choices: [ 'News', 'Announcements' ],
			rationale: 'Need a section before drafting.',
		} ),
		call( 'senroflux__propose-plan', {
			goal: PLAN_GOAL,
			steps: [
				{ text: 'Create the launch post', verbs: [ 'senroflux-e2e/create-thing' ] },
			],
			assumptions: [],
		} ),
		call( 'wpab__senroflux-e2e__create-thing', { title: 'Launch Day' } ),
		text( 'Done.' ),
	];
}

/** A trivial one-turn scenario: no parks, completes immediately. */
function quickComplete() {
	return [ text( 'Nothing to do here — completed immediately.' ) ];
}

/** A run that parks on its very first turn (a plan) and nothing else. */
function planOnly( goal = PLAN_GOAL ) {
	return [
		call( 'senroflux__propose-plan', {
			goal,
			steps: [ { text: 'Create the launch post', verbs: [ 'senroflux-e2e/create-thing' ] } ],
			assumptions: [],
		} ),
	];
}

module.exports = { PLAN_GOAL, call, text, fullTour, keyboardTour, quickComplete, planOnly };
