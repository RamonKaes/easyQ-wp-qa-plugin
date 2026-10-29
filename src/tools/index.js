/**
 * Runs the maintenance tools through the REST API, batch by batch, with a
 * progress bar. Without this script, each click on a tool runs one batch.
 */
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

let running = false;

/**
 * Asks before leaving the page while a tool runs, as Question2Answer does.
 *
 * @param {Event} event The beforeunload event.
 */
function warn( event ) {
	if ( running ) {
		event.preventDefault();
	}
}

/**
 * Shows how far a tool is.
 *
 * @param {HTMLFormElement} form  Form of the tool.
 * @param {Object}          state Response of the last batch.
 */
function show( form, state ) {
	const progress = form.querySelector( 'progress' );
	const status = form.querySelector( '[data-easyq-status]' );

	progress.max = Math.max( 1, state.total );
	progress.value = state.done;
	status.textContent = sprintf(
		/* translators: 1: number of the step, 2: number of steps, 3: items done, 4: items in the step. */
		__( 'Step %1$d of %2$d: %3$s of %4$s done.', 'easyq' ),
		state.step_number,
		state.steps,
		state.done.toLocaleString(),
		state.total.toLocaleString()
	);
}

/**
 * Runs a tool to the end.
 *
 * @param {SubmitEvent} event Submit event of the tool form.
 */
async function run( event ) {
	event.preventDefault();
	if ( running ) {
		return;
	}

	const form = event.currentTarget;
	const status = form.querySelector( '[data-easyq-status]' );
	const progress = form.querySelector( 'progress' );
	const buttons = document.querySelectorAll( 'form[data-easyq-tool] button' );

	running = true;
	buttons.forEach( ( button ) => ( button.disabled = true ) );
	progress.hidden = false;
	progress.removeAttribute( 'value' );
	status.textContent = __( 'Starting…', 'easyq' );

	let state = { step: '', after: 0 };
	try {
		do {
			state = await apiFetch( {
				path: '/easyq/v1/tools/' + form.dataset.easyqTool,
				method: 'POST',
				data: { step: state.step, after: state.after },
			} );
			show( form, state );
		} while ( ! state.complete );

		progress.value = progress.max;
		status.textContent = __( 'Done.', 'easyq' );
	} catch ( error ) {
		status.textContent =
			error?.message ?? __( 'The tool stopped after an error.', 'easyq' );
	} finally {
		running = false;
		buttons.forEach( ( button ) => ( button.disabled = false ) );
	}
}

window.addEventListener( 'beforeunload', warn );
document
	.querySelectorAll( 'form[data-easyq-tool]' )
	.forEach( ( form ) => form.addEventListener( 'submit', run ) );
