import { __ } from '@wordpress/i18n';

/**
 * S10 empty state: "Nothing has run yet", a promise sentence that follows the
 * gate mode, and example goals from the packs the viewer can actually run.
 */
export default function EmptyState( { gateMode, examples, onPickExample } ) {
	const promise =
		'agent_safety' === gateMode
			? __( 'It reads what is there, asks when something is unclear, shows you a plan, and waits for your click before anything irreversible.', 'senroflux' )
			: __( 'It reads what is there, asks when something is unclear, shows you a plan, and asks before it changes anything.', 'senroflux' );

	return (
		<div className="senroflux-empty-state">
			<h2>{ __( 'Nothing has run yet', 'senroflux' ) }</h2>
			<p>{ promise }</p>
			{ examples.length > 0 && (
				<ul className="senroflux-empty-examples">
					{ examples.map( ( example ) => (
						<li key={ example }>
							<button type="button" className="senroflux-empty-example" onClick={ () => onPickExample( example ) }>
								{ example }
							</button>
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}
