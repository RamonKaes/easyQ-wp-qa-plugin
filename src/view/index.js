/**
 * Interactivity of the easyQ blocks in the frontend.
 *
 * Every interactive element is a regular form that works without JavaScript;
 * this module sends it in the background and updates the page in place.
 *
 * @since 0.1.0
 */
import { store, getContext, getElement } from '@wordpress/interactivity';

/**
 * Loads a Q&A address with the Interactivity Router: the Q&A regions of the
 * page (the question and the notices) are replaced, the rest of the page stays.
 * Afterwards the element of the address's anchor gets the focus, and a notice
 * is announced to screen readers.
 *
 * @since 0.1.0
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
		// Only fields of the Q&A regions, not of the theme's header, for example.
		document.querySelector(
			'[data-wp-router-region^="easyq/"] [aria-invalid="true"]'
		) ||
		( hash && document.getElementById( decodeURIComponent( hash ) ) );
	if ( target ) {
		if ( ! target.matches( 'input, textarea, select, button, a[href]' ) ) {
			target.setAttribute( 'tabindex', '-1' );
		}
		target.focus();
	}

	const notice = document.querySelector(
		'[data-wp-router-region="easyq/notices"]:not([hidden]) [role]'
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
		 * @since 0.1.0
		 *
		 * @return {boolean} Whether the button is pressed.
		 */
		get isPressed() {
			const context = getContext();
			return context.vote === context.direction;
		},

		/**
		 * Which comment forms are open, by the key `c{post ID}`; the server fills it, see Forms::comment_form().
		 *
		 * @since 0.1.0
		 *
		 * @type {Object<string, boolean>}
		 */
		commentForms: {},

		/**
		 * Key of the comment form that a Reply button opened and whose field gets the focus.
		 *
		 * @since 0.1.0
		 *
		 * @type {string}
		 */
		commentFocus: '',
	},
	callbacks: {
		/**
		 * Moves the focus into the answer form once the Answer button opened it,
		 * as the button disappears.
		 *
		 * @since 0.1.0
		 */
		focusAnswer() {
			if ( getContext().answerOpen ) {
				getElement().ref.querySelector( 'textarea' )?.focus();
			}
		},

		/**
		 * Moves the focus into a comment form once a Reply button opened it.
		 *
		 * @since 0.1.0
		 */
		focusComment() {
			const { ref } = getElement();
			const key = `c${ ref.id.replace( 'easyq-comment-form-', '' ) }`;
			if ( state.commentForms[ key ] && state.commentFocus === key ) {
				state.commentFocus = '';
				ref.querySelector( 'textarea' )?.focus();
			}
		},
	},
	actions: {
		/**
		 * Follows a link within the Q&A area without reloading the page, e.g.
		 * "Show previous comments". Links opened in a new tab work as usual.
		 *
		 * @since 0.1.0
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
		 * Opens the answer form in place of the Answer button, which without
		 * JavaScript loads the page with the form.
		 *
		 * @since 0.1.0
		 *
		 * @param {MouseEvent} event Click event of the button.
		 */
		openAnswer( event ) {
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
			getContext().answerOpen = true;
		},

		/**
		 * Opens the comment form of the question or answer of a comment, from
		 * its Reply button, as Question2Answer does. Links opened in a new tab
		 * work as usual.
		 *
		 * @since 0.1.0
		 *
		 * @param {MouseEvent} event Click event of the button.
		 */
		openComment( event ) {
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
			const key = getContext().commentForm;
			state.commentForms[ key ] = true;
			state.commentFocus = key;
		},

		/**
		 * Keeps the state of a comment form in step when it is opened or closed by its summary.
		 *
		 * @since 0.1.0
		 *
		 * @param {Event} event Toggle event of the details element.
		 */
		toggleComment( event ) {
			const key = `c${ event.target.id.replace(
				'easyq-comment-form-',
				''
			) }`;
			state.commentForms[ key ] = event.target.open;
		},

		/**
		 * Suggests existing tags for the word being typed in a tag field, as
		 * Question2Answer completes tags. The suggestions are the field's text
		 * with the word completed, offered by the field's datalist.
		 *
		 * @since 0.1.0
		 *
		 * @param {InputEvent} event Input event of the field.
		 */
		*suggestTags( event ) {
			const context = getContext();
			const value = event.target.value;
			// The word being typed follows the last separator; tags never contain one.
			const separator = context.comma ? /,\s*/ : /\s+/;
			const words = value.split( separator );
			const word = words.pop().trim();
			const request = ( context.request ?? 0 ) + 1;
			context.request = request;
			if ( ! word || /[\s,]$/.test( value ) ) {
				context.suggestions = [];
				return;
			}

			let tags = [];
			try {
				// The address may carry the route as `rest_route`, without pretty permalinks.
				const url = new URL( `${ state.restUrl }tags` );
				url.searchParams.set( 'search', word );
				url.searchParams.set(
					'per_page',
					context.count + words.length
				);
				const response = yield fetch( url, {
					headers: { 'X-WP-Nonce': state.nonce },
				} );
				tags = response.ok ? yield response.json() : [];
			} catch {
				tags = [];
			}
			// A later input asked again; its answer counts.
			if ( request !== context.request ) {
				return;
			}

			const typed = words.map( ( tag ) => tag.trim().toLowerCase() );
			const prefix = value.slice( 0, value.length - word.length );
			context.suggestions = tags
				.map( ( tag ) => tag.name )
				.filter( ( name ) => ! typed.includes( name ) )
				.slice( 0, context.count )
				.map( ( name ) => prefix + name );
		},

		/**
		 * Sends an answer or comment form in the background. The server handles
		 * it like a regular submission and returns the address to show, which
		 * contains the new post or the form with its errors. Without a usable
		 * response the form is submitted normally.
		 *
		 * @since 0.1.0
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
		 * @since 0.1.0
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
			context.up = post.votes.up;
			context.down = post.votes.down;
			context.vote = post.votes.user;
		},

		/**
		 * Dismisses a notice. If that is refused, the form is submitted normally.
		 *
		 * @since 0.1.0
		 *
		 * @param {SubmitEvent} event Submit event of the notice form.
		 */
		*dismissNotice( event ) {
			event.preventDefault();
			const form = event.target;
			const context = getContext();

			const response = yield fetch(
				`${ state.restUrl }notices/${ context.noticeId }`,
				{
					method: 'DELETE',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': state.nonce },
				}
			);

			if ( ! response.ok ) {
				form.submit();
				return;
			}

			context.dismissed = true;
		},

		/**
		 * Adds or removes a favorite. If that is refused, the form is submitted
		 * normally so the page explains why.
		 *
		 * @since 0.1.0
		 *
		 * @param {SubmitEvent} event Submit event of the favorite form.
		 */
		*favorite( event ) {
			event.preventDefault();
			const form = event.target;
			const context = getContext();

			const response = yield fetch( `${ state.restUrl }favorites`, {
				method: context.favorite ? 'DELETE' : 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': state.nonce,
				},
				body: JSON.stringify( {
					type: context.favoriteType,
					id: context.favoriteId,
				} ),
			} );

			if ( ! response.ok ) {
				form.submit();
				return;
			}

			context.favorite = ! context.favorite;
			context.label = context.favorite
				? context.labels.remove
				: context.labels.add;
			form.elements.favorite.value = context.favorite ? '0' : '1';
		},
	},
} );
