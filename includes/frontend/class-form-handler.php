<?php
/**
 * Form submissions without JavaScript.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Frontend;

use EasyQ\REST\Controller;
use EasyQ\Services;
use EasyQ\Users\Avatars;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the forms of the Q&A blocks: asking, answering, commenting, voting and post actions.
 *
 * Every form is sent to the REST API internally, so the same permission checks,
 * validation and rate limits apply. Afterwards the visitor is redirected
 * (post/redirect/get) with a message.
 *
 * @since 0.1.0
 */
final class Form_Handler {

	/**
	 * Field with the form name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const FIELD = 'easyq_form';

	/**
	 * Fields passed on to the REST API, per form.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string[]>
	 */
	private const FIELDS = array(
		'ask'     => array( 'title', 'content', 'tags', 'category', 'notify', 'email', 'name', 'extra', 'follow' ),
		'answer'  => array( 'content', 'notify', 'email', 'name' ),
		'comment' => array( 'content', 'notify', 'email', 'name' ),
		'edit'    => array( 'title', 'content', 'tags', 'category', 'silent' ),
		'vote'    => array( 'vote' ),
		'action'  => array( 'answer', 'duplicate_of', 'note', 'parent', 'category' ),
		'account' => array( 'remove_avatar' ),
	);

	/**
	 * Prefix of the profile field inputs of the account form.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PROFILE_FIELD_PREFIX = 'field_';

	/**
	 * Hooks the handler into WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'handle' ), 5 );
	}

	/**
	 * Returns the hidden fields every form needs.
	 *
	 * @since 0.1.0
	 *
	 * @param string $form Form name.
	 * @return string HTML.
	 */
	public static function hidden_fields( string $form ): string {
		// Not wp_nonce_field(): its field has an ID, which repeats when a page has several forms.
		return sprintf( '<input type="hidden" name="%s" value="%s" />', esc_attr( self::FIELD ), esc_attr( $form ) )
			. sprintf( '<input type="hidden" name="_easyq_nonce" value="%s" />', esc_attr( wp_create_nonce( 'easyq_' . $form ) ) )
			. wp_referer_field( false );
	}

	/**
	 * Handles a submitted form and redirects.
	 *
	 * @since 0.1.0
	 */
	public function handle(): void {
		$url = $this->process();
		if ( null === $url ) {
			return;
		}

		// Forms sent in the background (src/view) get the address as JSON and update the page in place.
		if ( isset( $_SERVER['HTTP_X_EASYQ_IN_PLACE'] ) ) {
			wp_send_json( array( 'url' => wp_validate_redirect( $url, home_url( '/' ) ) ) );
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Processes a submitted form.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The address to redirect to, or null if no easyQ form was submitted.
	 */
	public function process(): ?string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The nonce is verified below, per form.
		if ( ! isset( $_POST[ self::FIELD ] ) || ! is_string( $_POST[ self::FIELD ] ) ) {
			return null;
		}

		$form = sanitize_key( wp_unslash( $_POST[ self::FIELD ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! isset( self::FIELDS[ $form ] ) ) {
			return null;
		}

		$back  = $this->back_url();
		$nonce = isset( $_POST['_easyq_nonce'] ) && is_string( $_POST['_easyq_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_easyq_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'easyq_' . $form ) ) {
			return $this->with_flash( $back, Flash::store( 'error', __( 'The form has expired. Please try again.', 'easyq' ), $form ) );
		}

		$values = $this->values( $form );
		$target = $this->target();
		$action = isset( $_POST['easyq_action'] ) && is_string( $_POST['easyq_action'] ) ? sanitize_key( wp_unslash( $_POST['easyq_action'] ) ) : '';

		[ $method, $route ] = match ( $form ) {
			'ask'     => array( 'POST', '/questions' ),
			'answer'  => array( 'POST', '/questions/' . $target . '/answers' ),
			'comment' => array( 'POST', '/posts/' . $target . '/comments' ),
			'edit'    => array( 'PUT', $this->edit_route( $target ) ),
			'vote'    => array( 'POST', '/posts/' . $target . '/vote' ),
			'account' => array( 'POST', '/users/me' ),
			default   => array( 'POST', '/posts/' . $target . '/actions/' . $action ),
		};

		$request = new WP_REST_Request( $method, '/' . Controller::NAMESPACE . $route );
		foreach ( $values as $key => $value ) {
			$request->set_param( $key, $value );
		}

		if ( 'account' === $form ) {
			$values += $this->add_account_input( $request );
		}

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$error = $response->as_error();
		if ( null !== $error ) {
			$data   = $error->get_error_data();
			$fields = array();
			foreach ( is_array( $data ) && is_array( $data['fields'] ?? null ) ? $data['fields'] : array() as $field => $message ) {
				if ( is_string( $field ) && is_string( $message ) ) {
					$fields[ $field ] = $message;
				}
			}

			return $this->with_flash( $back, Flash::store( 'error', $error->get_error_message(), $form, $fields, $values ) );
		}

		$id = is_array( $data ) && is_numeric( $data['id'] ?? null ) ? (int) $data['id'] : $target;
		// After deleting, as core responds with the `previous` post: its question, unless it was the question.
		$previous = is_array( $data ) && true === ( $data['deleted'] ?? false ) && is_array( $data['previous'] ?? null ) ? $data['previous'] : null;
		if ( null !== $previous && is_numeric( $previous['question'] ?? null ) && (int) $previous['question'] !== $target ) {
			$id = (int) $previous['question'];
		}
		$queued  = is_array( $data ) && true === ( $data['queued'] ?? false );
		$message = $this->success_message( $form, $queued );

		return $this->with_flash( $this->success_url( $form, $id, $back ), Flash::store( 'success', $message, $form ) );
	}

	/**
	 * Returns the submitted values the REST API accepts for a form.
	 *
	 * @since 0.1.0
	 *
	 * @param string $form Form name.
	 * @return array<string, string>
	 */
	private function values( string $form ): array {
		$values = array();

		foreach ( self::FIELDS[ $form ] as $field ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in process().
			if ( isset( $_POST[ $field ] ) && is_string( $_POST[ $field ] ) ) {
				// The REST API validates and sanitizes the values; content keeps its line breaks.
				$values[ $field ] = 'content' === $field || 'note' === $field
					? sanitize_textarea_field( wp_unslash( $_POST[ $field ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in process().
					: sanitize_text_field( wp_unslash( $_POST[ $field ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in process().
			}
		}

		return $values;
	}

	/**
	 * Adds the profile fields and the uploaded avatar of the account form to a request.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request to the REST API.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return array<string, string> Submitted profile field values keyed by input name, to fill the form again after errors.
	 */
	private function add_account_input( WP_REST_Request $request ): array {
		$fields = array();
		$inputs = array();
		foreach ( Services::user_fields()->all() as $field ) {
			$name = self::PROFILE_FIELD_PREFIX . $field->name;
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in process().
			if ( isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ) {
				// The field type decides how the value is sanitized; see User_Fields::set_value().
				$fields[ $field->name ] = sanitize_textarea_field( wp_unslash( $_POST[ $name ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in process().
				$inputs[ $name ]        = $fields[ $field->name ];
			}
		}
		$request->set_param( 'fields', $fields );

		// The entry is checked here; the REST API validates the file itself (wp_handle_sideload() with image types only).
		$file = Avatars::file_entry( $_FILES['avatar'] ?? null ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified in process(); file_entry() checks every field.
		if ( null !== $file ) {
			$request->set_file_params( array( 'avatar' => $file ) );
		}

		return $inputs;
	}

	/**
	 * Returns the ID of the post a form relates to.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	private function target(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in process().
		return isset( $_POST['easyq_post'] ) && is_string( $_POST['easyq_post'] ) ? absint( $_POST['easyq_post'] ) : 0;
	}

	/**
	 * Returns the REST route to edit a post.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id Post ID.
	 * @return string
	 */
	private function edit_route( int $id ): string {
		$post = Services::posts()->find( $id );

		return match ( $post?->base_type() ) {
			'Q'     => '/questions/' . $id,
			'A'     => '/answers/' . $id,
			default => '/comments/' . $id,
		};
	}

	/**
	 * Returns where to go after a successful submission.
	 *
	 * @since 0.1.0
	 *
	 * @param string $form Form name.
	 * @param int    $id   ID of the created or changed post.
	 * @param string $back Address of the form page.
	 * @return string
	 */
	private function success_url( string $form, int $id, string $back ): string {
		if ( 'account' === $form ) {
			return Routes::url( 'account' );
		}

		$post     = Services::posts()->find( $id );
		$question = null === $post ? null : Services::post_service()->question_of( $post );

		// After a question was deleted, its page no longer exists.
		if ( null === $post && 'action' === $form ) {
			return Routes::url( 'home' );
		}

		if ( null === $question || 'vote' === $form ) {
			return $back . ( null === $post ? '' : '#easyq-post-' . $post->id );
		}

		return URLs::question( $question ) . ( $post->id === $question->id ? '' : '#easyq-post-' . $post->id );
	}

	/**
	 * Returns the message after a successful submission.
	 *
	 * @since 0.1.0
	 *
	 * @param string $form   Form name.
	 * @param bool   $queued Whether the post waits for moderation.
	 * @return string
	 */
	private function success_message( string $form, bool $queued ): string {
		if ( $queued ) {
			return __( 'Thank you! Your post will be published once a moderator approved it.', 'easyq' );
		}

		return match ( $form ) {
			'ask'     => __( 'Your question has been published.', 'easyq' ),
			'answer'  => __( 'Your answer has been published.', 'easyq' ),
			'comment' => __( 'Your comment has been published.', 'easyq' ),
			'edit'    => __( 'Your changes have been saved.', 'easyq' ),
			'account' => __( 'Your profile has been saved.', 'easyq' ),
			default   => __( 'Done.', 'easyq' ),
		};
	}

	/**
	 * Returns the address of the page the form was submitted from.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	private function back_url(): string {
		// Not wp_get_referer(): it ignores a referer that equals the current address, and forms
		// are always sent to the page they are on. The address is checked like a redirect instead.
		$referer = wp_get_raw_referer();
		$url     = wp_validate_redirect( false === $referer ? '' : $referer, Routes::url( 'home' ) );

		return remove_query_arg( Flash::QUERY_VAR, $url );
	}

	/**
	 * Adds the message token to an address.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url   Address, possibly with a fragment.
	 * @param string $token Message token.
	 * @return string
	 */
	private function with_flash( string $url, string $token ): string {
		$fragment = '';
		$hash     = strpos( $url, '#' );
		if ( false !== $hash ) {
			$fragment = substr( $url, $hash );
			$url      = substr( $url, 0, $hash );
		}

		return add_query_arg( Flash::QUERY_VAR, $token, $url ) . $fragment;
	}
}
