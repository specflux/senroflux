import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { SelectControl } from '@wordpress/components';

/** Automatic sentinel value for the model `<select>`. */
const AUTOMATIC_VALUE = '';

/**
 * Flatten `modelChoices` (`{ providerId: { name, models: [{id, name}] } }`)
 * into an ordered list the `<select>` can index into by POSITION rather than
 * by a composite string key — provider ids and model ids are opaque values
 * from the AI Client registry, so building a separator-joined key risks a
 * collision if either ever contains that separator. An index is always safe.
 *
 * @param {Object} modelChoices `senrofluxRunsConfig.modelChoices`.
 * @return {Array<{provider: string, providerName: string, id: string, name: string}>}
 */
function flattenModelChoices( modelChoices ) {
	const flat = [];
	Object.keys( modelChoices || {} ).forEach( ( providerId ) => {
		const provider = modelChoices[ providerId ];
		( provider.models || [] ).forEach( ( model ) => {
			flat.push( {
				provider: providerId,
				providerName: provider.name,
				id: model.id,
				name: model.name,
			} );
		} );
	} );
	return flat;
}

/**
 * The message box (S10). It only ever starts a NEW run — this system has no
 * mid-run "send another instruction" affordance (a park resolution is the
 * only human input a running conversation accepts), so `onSend` fires once,
 * when idle, and the box goes disabled for the rest of the run's life:
 * "parked" ("Answer the card above to continue") and "running" (ticks are
 * driven automatically once started; see `App`'s `driveTicks`) both disable
 * it, same as before 17c wired the actual submit.
 *
 * A NEW run needs a capability pack (runs-pack fix): a pack-less start has an
 * empty verb map, so every read/plan call the model makes is refused
 * fail-closed ({@see \Specflux\SenroFlux\Tools\VerbTier}) and the run
 * deadlocks. `packs` is the CURRENT viewer's runnable packs
 * ({@see \Specflux\SenroFlux\Admin\RunsScreen::runnablePacks()}), each a data
 * `{ name, label }` pair. Exactly one pack auto-selects; two or more show a
 * "Choose what to work on" placeholder and hold Start disabled until one is
 * picked; zero packs render no picker at all and Start stays disabled.
 *
 * Runs-pack fix: a pack whose preflight refuses this viewer used to simply
 * vanish, so when EVERY pack failed the box went silent — no picker AND no
 * explanation. `unavailablePacks` renders that reason as a short notice
 * (below the picker, or in its place when there is none), for every case
 * from "one pack unavailable, one runnable" up to "zero runnable".
 *
 * A per-run model picker sits alongside the pack picker, defaulting to
 * "Automatic" — `onSend` is called with that choice as its third argument
 * (`null` for automatic) so `App`/`startRun` never has to re-derive it. The
 * picker is omitted entirely when `modelChoices` names no configured
 * provider, since there is nothing to choose between.
 *
 * @param {Object}   props
 * @param {string}   props.state          'idle' | 'parked' | 'running'.
 * @param {Function} [props.onSend]       `( goal, pack, model ) => void`, starts a new run. `model` is `{ provider, id } | null`.
 * @param {string}   [props.initialText]  Pre-fills the box (0.3 S10, the
 *                                        command palette's "SenroFlux: run
 *                                        "<text>"" command) — this ONLY seeds
 *                                        the textarea; it never calls
 *                                        `onSend()` itself, so landing here
 *                                        from the palette never starts a run
 *                                        on its own.
 * @param {Array<{name:string,label:string}>}  [props.packs]             The viewer's runnable packs.
 * @param {Array<{name:string,reason:string}>} [props.unavailablePacks] Packs whose preflight
 *                                                                       refused this viewer, with why
 *                                                                       ({@see \Specflux\SenroFlux\Admin\RunsScreen::unavailablePacks()}) —
 *                                                                       rendered as a short notice so a
 *                                                                       viewer with zero (or fewer)
 *                                                                       runnable packs finds out why,
 *                                                                       rather than the box just going
 *                                                                       quiet.
 * @param {Object}   [props.modelChoices] `senrofluxRunsConfig.modelChoices` — `{ providerId: { name, models: [{id, name}] } }`, only configured providers.
 * @param {boolean}  [props.startBlocked] A blocking setup check fails (no model provider, J1): Start is disabled
 *                                        and says why. The server-rendered setup panel above the app names the fix.
 * @param {?{runId:number,pack:string}} [props.followUp] A follow-up run is being started from a finished run
 *                                        (0.3 S20): the pack is locked to that run's, and `onSend` gets its id as a 4th argument.
 * @param {Function} [props.onCancelFollowUp] `() => void` — leaves follow-up mode.
 */
export default function MessageBox( {
	state,
	onSend,
	initialText,
	packs,
	unavailablePacks,
	modelChoices,
	startBlocked,
	followUp,
	onCancelFollowUp,
} ) {
	const packList = Array.isArray( packs ) ? packs : [];
	const unavailableList = Array.isArray( unavailablePacks ) ? unavailablePacks : [];
	const [ text, setText ] = useState( initialText || '' );
	const [ pack, setPack ] = useState( () => ( 1 === packList.length ? packList[ 0 ].name : '' ) );
	const [ modelIndex, setModelIndex ] = useState( AUTOMATIC_VALUE );
	const flatModels = flattenModelChoices( modelChoices );

	// Keeps the single-pack case auto-selected even if `packs` only resolves
	// after mount (e.g. a later config load); a no-op once `pack` is already
	// that pack's name.
	useEffect( () => {
		if ( 1 === packList.length && pack !== packList[ 0 ].name ) {
			setPack( packList[ 0 ].name );
		}
	}, [ pack, packList ] );

	// A selected pack that is no longer runnable (a focus refresh dropped it)
	// must not stay selected.
	useEffect( () => {
		if ( '' !== pack && ! packList.some( ( p ) => p.name === pack ) ) {
			setPack( '' );
		}
	}, [ pack, packList ] );

	// A follow-up runs the SOURCE run's pack (S20), whatever the picker held.
	const effectivePack = followUp ? followUp.pack : pack;
	const packReady = followUp
		? true
		: 0 === packList.length
		? false
		: 1 === packList.length
		? true
		: '' !== pack;
	const boxDisabled = 'idle' !== state || ! onSend;
	// The text box stays usable while Start is blocked, so a goal typed (or
	// pre-filled by the command palette) is not lost while the user fixes setup.
	const disabled = boxDisabled || ! packReady;
	const startDisabled = disabled || Boolean( startBlocked );

	const textRef = useRef( null );
	// Entering follow-up mode puts the cursor in the (empty) goal box.
	useEffect( () => {
		if ( followUp && textRef.current ) {
			textRef.current.focus();
		}
	}, [ followUp ] );

	const placeholder =
		'parked' === state
			? __( 'Answer the card above to continue', 'senroflux' )
			: 'running' === state
			? __( 'Working. Keep this page open; the run pauses if you leave and continues when you come back.', 'senroflux' )
			: __( 'Describe what you want done…', 'senroflux' );

	// Grouped by provider via real `<optgroup>` elements (SelectControl
	// renders whatever `children` it is given instead of its own flat
	// `options` list once `children` is non-empty) — each `<option>`'s
	// `value` is its POSITION in `flatModels`, matching how `submit()` below
	// decodes the selection.
	const modelGroups = new Map();
	flatModels.forEach( ( model, index ) => {
		if ( ! modelGroups.has( model.provider ) ) {
			modelGroups.set( model.provider, {
				providerName: model.providerName,
				options: [],
			} );
		}
		modelGroups.get( model.provider ).options.push( { index, model } );
	} );

	const submit = () => {
		const goal = text.trim();
		if ( '' === goal || ! onSend || ! packReady || startBlocked ) {
			return;
		}
		const chosenModel =
			AUTOMATIC_VALUE === modelIndex ? null : flatModels[ Number( modelIndex ) ];
		const model = chosenModel ? { provider: chosenModel.provider, id: chosenModel.id } : null;
		if ( followUp ) {
			onSend( goal, followUp.pack, model, followUp.runId );
		} else {
			onSend( goal, packList.length > 0 ? pack : undefined, model );
		}
		setText( '' );
	};

	return (
		<div className="senroflux-message-box-wrap">
			{ unavailableList.length > 0 && (
				<ul className="senroflux-unavailable-packs">
					{ unavailableList.map( ( p ) => (
						<li key={ p.name }>
							{ sprintf(
								/* translators: 1: a capability pack's name, e.g. "widgets". 2: why it can't run right now. */
								__( '%1$s pack unavailable: %2$s', 'senroflux' ),
								p.name,
								p.reason
							) }
						</li>
					) ) }
				</ul>
			) }
			{ followUp && (
				<p className="senroflux-followup-note">
					{ sprintf(
						/* translators: %d: the id of the finished run being followed up. */
						__( 'Follow-up to run #%d. It keeps the same pack and starts from the objects that run changed; it re-reads each before changing it.', 'senroflux' ),
						followUp.runId
					) }{ ' ' }
					<button type="button" className="button-link" onClick={ () => onCancelFollowUp && onCancelFollowUp() }>
						{ __( 'Cancel follow-up', 'senroflux' ) }
					</button>
				</p>
			) }
			<div className="senroflux-message-box">
				{ packList.length > 0 && (
					<SelectControl
						className="senroflux-message-box-pack"
						label={ __( 'Pack', 'senroflux' ) }
						hideLabelFromVision
						__next40pxDefaultSize
						disabled={ boxDisabled || Boolean( followUp ) }
						value={ effectivePack }
						onChange={ setPack }
					>
						{ 1 !== packList.length && (
							<option value="" disabled dir="auto">
								{ __( 'Choose what to work on', 'senroflux' ) }
							</option>
						) }
						{ /* Pack and model names are data, not UI chrome (S22). */ }
						{ packList.map( ( p ) => (
							<option key={ p.name } value={ p.name } dir="auto" data-senroflux-content>
								{ p.label }
							</option>
						) ) }
					</SelectControl>
				) }
				{ flatModels.length > 0 && (
					<SelectControl
						className="senroflux-message-box-model"
						label={ __( 'Model', 'senroflux' ) }
						hideLabelFromVision
						disabled={ boxDisabled }
						value={ modelIndex }
						onChange={ setModelIndex }
						__next40pxDefaultSize
					>
						<option value={ AUTOMATIC_VALUE }>
							{ __( 'Automatic (let WordPress choose)', 'senroflux' ) }
						</option>
						{ Array.from( modelGroups.entries() ).map( ( [ providerId, group ] ) => (
							<optgroup key={ providerId } label={ group.providerName }>
								{ group.options.map( ( { index, model } ) => (
									<option key={ index } value={ String( index ) } dir="auto" data-senroflux-content>
										{ model.name }
									</option>
								) ) }
							</optgroup>
						) ) }
					</SelectControl>
				) }
				<textarea
					ref={ textRef }
					className="senroflux-message-box-input"
					disabled={ disabled }
					placeholder={ placeholder }
					value={ text }
					onChange={ ( e ) => setText( e.target.value ) }
					onKeyDown={ ( e ) => {
						if ( 'Enter' === e.key && ! e.shiftKey && ! startDisabled ) {
							e.preventDefault();
							submit();
						}
					} }
				/>
				<button
					type="button"
					className="button button-primary"
					disabled={ startDisabled || '' === text.trim() }
					aria-describedby={ startBlocked ? 'senroflux-start-blocked' : undefined }
					onClick={ submit }
				>
					{ __( 'Start run', 'senroflux' ) }
				</button>
			</div>
			{ startBlocked && (
				<p id="senroflux-start-blocked" className="senroflux-start-blocked">
					{ __( 'Finish the setup notice above to start a run.', 'senroflux' ) }
				</p>
			) }
		</div>
	);
}
