<?php
/**
 * Form submissions without JavaScript.
 *
 * @package easyQ
 */

namespace EasyQ\Frontend;

use EasyQ\Rest\Controller;
use EasyQ\Services;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the forms of the Q&A blocks: asking, answering, commenting, voting and post actions.
 *
 * Every form is sent to the REST API internally, so the same permission checks,
 * validation and rate limits apply. Afterwards the visitor is redirected
 * (post/redirect/get) with a message.
 */
final class Form_Handler {

	/**
	 * Field with the form name.
	 *
	 * @var string
	 */
	public const FIELD = 'easyq_form';

	/**
	 * Fields passed on to the REST API, per form.
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
	);

	/**
	 * Hooks the handler into WordPress.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'handle' ), 5 );
	}

	/**
	 * Returns the hidden fields every form needs.
	 *
	 * @param string $form Form name.
	 * @return string HTML.
	 */
	public static function hidden_fields( string $form ): string {
		return sprintf( '<input type="hidden" name="%s" value="%s">', esc_attr( self::FIELD ), esc_attr( $form ) )
			. wp_nonce_field( 'easyq_' . $form, '_easyq_nonce', true, false );
	}

	/**
	 * Handles a submitted form and redirects.
	 */
	public function handle(): void {
		$url = $this->process();
		if ( null !== $url ) {
			wp_safe_redirect( $url );
			exit;
		}
	}

	/**
	 * Processes a submitted form.
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
			default   => array( 'POST', '/posts/' . $target . '/actions/' . $action ),
		};

		$request = new WP_REST_Request( $method, '/' . Controller::NAMESPACE . $route );
		foreach ( $values as $key => $value ) {
			$request->set_param( $key, $value );
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

		$id      = is_array( $data ) && is_numeric( $data['id'] ?? null ) ? (int) $data['id'] : $target;
		$queued  = is_array( $data ) && true === ( $data['queued'] ?? false );
		$message = $this->success_message( $form, $queued );

		return $this->with_flash( $this->success_url( $form, $id, $back ), Flash::store( 'success', $message, $form ) );
	}

	/**
	 * Returns the submitted values the REST API accepts for a form.
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
					? sanitize_textarea_field( wp_unslash( $_POST[ $field ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
					: sanitize_text_field( wp_unslash( $_POST[ $field ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}
		}

		return $values;
	}

	/**
	 * Returns the ID of the post a form relates to.
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
	 * @param string $form Form name.
	 * @param int    $id   ID of the created or changed post.
	 * @param string $back Address of the form page.
	 * @return string
	 */
	private function success_url( string $form, int $id, string $back ): string {
		$post     = Services::posts()->find( $id );
		$question = null === $post ? null : Services::post_service()->question_of( $post );

		if ( null === $question || 'vote' === $form ) {
			return $back . ( null === $post ? '' : '#easyq-post-' . $post->id );
		}

		return Urls::question( $question ) . ( $post->id !== $question->id ? '#easyq-post-' . $post->id : '' );
	}

	/**
	 * Returns the message after a successful submission.
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
			default   => __( 'Done.', 'easyq' ),
		};
	}

	/**
	 * Returns the address of the page the form was submitted from.
	 *
	 * @return string
	 */
	private function back_url(): string {
		$referer = wp_get_referer();
		$url     = false === $referer ? Routes::url( 'home' ) : $referer;

		return remove_query_arg( Flash::QUERY_VAR, $url );
	}

	/**
	 * Adds the message token to an address.
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
