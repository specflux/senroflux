import { __ } from '@wordpress/i18n';
import { isParkedStatus, isTerminalStatus } from '../utils';

const LABELS = {
	pending: __( 'Pending', 'senroflux' ),
	running: __( 'Running', 'senroflux' ),
	awaiting_approval: __( 'Needs you · waiting for approval', 'senroflux' ),
	awaiting_user: __( 'Needs you · waiting for your answer', 'senroflux' ),
	awaiting_plan: __( 'Needs you · waiting on the plan', 'senroflux' ),
	completed: __( 'Completed', 'senroflux' ),
	failed: __( 'Failed', 'senroflux' ),
	cancelled: __( 'Cancelled', 'senroflux' ),
};

/** The run-list/header status pill (S10 park kinds are named in the row). */
export default function StatusPill( { status, stalled } ) {
	const modifier = isParkedStatus( status )
		? 'needs'
		: isTerminalStatus( status )
		? 'done'
		: 'running';

	return (
		<span className={ `senroflux-pill senroflux-pill-${ modifier }` }>
			{ stalled ? __( 'Paused while closed — open to continue', 'senroflux' ) : LABELS[ status ] || status }
		</span>
	);
}
