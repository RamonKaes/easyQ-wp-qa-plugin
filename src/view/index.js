/**
 * Interactivity of the easyQ blocks in the frontend.
 *
 * Every interactive element is a regular form that works without JavaScript;
 * this module sends it in the background and updates the page in place.
 */
import { store, getContext } from '@wordpress/interactivity';

const { state } = store( 'easyq', {
	state: {
		/**
		 * Whether the vote button of the current context matches the visitor's vote.
		 *
		 * @return {boolean} Whether the button is pressed.
		 */
		get isPressed() {
			const context = getContext();
			return context.vote === context.direction;
		},
	},
	actions: {
		/**
		 * Sends a vote and updates the count. If the vote is refused, for
		 * example because the visitor must log in, the form is submitted
		 * normally so the page explains why.
		 *
		 * @param {SubmitEvent} event Submit event of the vote form.
		 */
		*vote( event ) {
			event.preventDefault();
			const form = event.target;
			const context = getContext();
			const vote =
				context.vote === context.direction ? 0 : context.direction;

			const response = yield fetch(
				`${ state.restUrl }posts/${ context.postId }/vote`,
				{
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': state.nonce,
					},
					body: JSON.stringify( { vote } ),
				}
			);

			if ( ! response.ok ) {
				form.submit();
				return;
			}

			const post = yield response.json();
			context.net = post.votes.net;
			context.vote = post.votes.user;
		},
	},
} );
