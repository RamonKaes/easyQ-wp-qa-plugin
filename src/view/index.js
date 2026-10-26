/**
 * Interactivity of the easyQ blocks in the frontend.
 *
 * Every interactive element is a regular form that works without JavaScript;
 * this module sends it in the background and updates the page in place.
 */
import { store, getContext, getElement } from '@wordpress/interactivity';

/**
 * Loads a Q&A address with the Interactivity Router: the Q&A regions of the
 * page (the question and the notices) are replaced, the rest of the page stays.
 * Afterwards the element of the address's anchor gets the focus, and a notice
 * is announced to screen readers.
 *
 * @param {string} url Address to load.
 */
function* navigateTo( url ) {
	const { actions: router } =
		yield import( '@wordpress/interactivity-router' );
	// The router would announce "Page loaded"; the message, focused field or focused list says more.
	yield router.navigate( url, { screenReaderAnnouncement: false } );

	const hash = new URL( url, window.location.href ).hash.slice( 1 );
	const target =
		document.querySelector( '[aria-invalid="true"]' ) ||
		( hash && document.getElementById( decodeURIComponent( hash ) ) );
	if ( target ) {
		if ( ! target.matches( 'input, textarea, select, button, a[href]' ) ) {
			target.setAttribute( 'tabindex', '-1' );
		}
		target.focus();
	}

	const notice = document.querySelector(
		'[data-wp-router-region="easyq/notices"]:not([hidden])'
	);
	if ( notice ) {
		const { speak } = yield import( '@wordpress/a11y' );
		speak(
			notice.textContent.trim(),
			notice.getAttribute( 'role' ) === 'alert' ? 'assertive' : 'polite'
		);
	}
}

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
		 * Follows a link within the Q&A area without reloading the page, e.g.
		 * "Show previous comments". Links opened in a new tab work as usual.
		 *
		 * @param {MouseEvent} event Click event of the link.
		 */
		*navigate( event ) {
			if (
				event.button !== 0 ||
				event.metaKey ||
				event.ctrlKey ||
				event.shiftKey ||
				event.altKey
			) {
				return;
			}
			event.preventDefault();
			yield* navigateTo( getElement().ref.href );
		},

		/**
		 * Sends an answer or comment form in the background. The server handles
		 * it like a regular submission and returns the address to show, which
		 * contains the new post or the form with its errors. Without a usable
		 * response the form is submitted normally.
		 *
		 * @param {SubmitEvent} event Submit event of the form.
		 */
		*submit( event ) {
			event.preventDefault();
			const form = event.target;
			const button = form.querySelector( '[type="submit"]' );
			button?.setAttribute( 'aria-disabled', 'true' );
			form.setAttribute( 'aria-busy', 'true' );

			let url = null;
			try {
				const response = yield fetch(
					form.getAttribute( 'action' ) || window.location.href,
					{
						method: 'POST',
						body: new FormData( form ),
						credentials: 'same-origin',
						headers: { 'X-EasyQ-In-Place': '1' },
					}
				);
				const data = response.ok ? yield response.json() : null;
				url = typeof data?.url === 'string' ? data.url : null;
			} catch {
				url = null;
			}

			if ( ! url ) {
				form.submit();
				return;
			}

			yield* navigateTo( url );

			// The router keeps the form element and what was typed into it.
			form.removeAttribute( 'aria-busy' );
			button?.removeAttribute( 'aria-disabled' );
			if ( ! form.querySelector( '[aria-invalid="true"]' ) ) {
				form.reset();
			}
		},

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
