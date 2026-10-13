<?php
/**
 * Rendering of form blocks.
 *
 * @package easyQ
 */

namespace EasyQ\Blocks\Render;

use EasyQ\Blocks\Block_Context;
use EasyQ\Blocks\Render;
use EasyQ\Frontend\Flash;
use EasyQ\Frontend\Form_Handler;
use EasyQ\Frontend\Routes;
use EasyQ\Posts\Post;
use EasyQ\Rest\Post_Input;
use EasyQ\Services;
use EasyQ\Settings\Options;
use WP_Block;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the forms to ask, answer, comment, edit, close and search.
 *
 * The forms are plain HTML forms that work without JavaScript; they use core
 * class names so the theme styles them.
 */
final class Forms {

	/**
	 * Renders the form to ask a question.
	 *
	 * @return string
	 */
	public static function ask_form(): string {
		$viewer = Block_Context::viewer();
		$reason = Services::permissions()->error( 'permit_post_q', $viewer );
		if ( null !== $reason ) {
			return sprintf( '<div %s>%s</div>', get_block_wrapper_attributes(), self::permission_message( $reason ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Prefills the title from the ask box.
		$title = isset( $_GET['title'] ) && is_string( $_GET['title'] ) ? sanitize_text_field( wp_unslash( $_GET['title'] ) ) : Flash::value( 'ask', 'title' );

		$fields = self::field( 'ask', 'title', __( 'Your question in one sentence', 'easyq' ), 'text', $title, array( 'required' => 'required' ) )
			. self::field( 'ask', 'content', __( 'More information for the question', 'easyq' ), 'textarea', Flash::value( 'ask', 'content' ) );

		if ( Post_Input::uses_categories() && array() !== Services::categories()->all() ) {
			$fields .= self::category_field( Flash::value( 'ask', 'category' ) );
		}

		if ( Post_Input::uses_tags() ) {
			$hint    = Options::get_bool( 'posting', 'tag_separator_comma' ) ? __( 'Separate tags with commas.', 'easyq' ) : __( 'Separate tags with spaces; use hyphens to combine words.', 'easyq' );
			$fields .= self::field( 'ask', 'tags', __( 'Tags', 'easyq' ), 'text', Flash::value( 'ask', 'tags' ), array(), $hint );
		}

		$fields .= self::author_fields( 'ask', __( 'Email me if my question is answered or commented on', 'easyq' ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The answer a new question follows on from.
		$follow = isset( $_GET['follow'] ) && is_string( $_GET['follow'] ) ? absint( $_GET['follow'] ) : 0;

		return self::form( 'ask', 0, $fields, __( 'Ask the question', 'easyq' ), $follow > 0 ? array( 'follow' => $follow ) : array() );
	}

	/**
	 * Renders the form to answer the question.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks.
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function answer_form( array $attributes, string $content, WP_Block $block ): string {
		$question = Block_Context::post( $block );
		if ( null === $question || Post::QUESTION !== $question->type ) {
			return '';
		}

		if ( ! Render::rules( $question )['answer_button'] ) {
			return '';
		}

		$reason = Services::permissions()->error( 'permit_post_a', Block_Context::viewer(), $question );
		$body   = null !== $reason
			? self::permission_message( $reason )
			: self::form(
				'answer',
				$question->id,
				self::field( 'answer', 'content', __( 'Your answer', 'easyq' ), 'textarea', Flash::value( 'answer', 'content' ), array( 'required' => 'required' ) )
				. self::author_fields( 'answer', __( 'Email me if my answer is selected or commented on', 'easyq' ) ),
				__( 'Answer', 'easyq' ),
				array()
			);

		return sprintf(
			'<section %s id="easyq-answer-form"><h2>%s</h2>%s</section>',
			get_block_wrapper_attributes(),
			esc_html__( 'Your answer', 'easyq' ),
			$body
		);
	}

	/**
	 * Renders the form to comment on the post from context.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks.
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function comment_form( array $attributes, string $content, WP_Block $block ): string {
		$post = Block_Context::post( $block );
		if ( null === $post || ! in_array( $post->type, array( Post::QUESTION, Post::ANSWER ), true ) ) {
			return '';
		}

		$rules = Render::rules( $post );
		if ( ! $rules['comment_button'] ) {
			return '';
		}

		$reason = Services::permissions()->error( 'permit_post_c', Block_Context::viewer(), $post );
		$body   = null !== $reason
			? self::permission_message( $reason )
			: self::form(
				'comment',
				$post->id,
				self::field( 'comment', 'content', __( 'Your comment', 'easyq' ), 'textarea', '', array( 'required' => 'required' ), '', 'easyq-comment-' . $post->id )
				. self::author_fields( 'comment', __( 'Email me if a comment is added after mine', 'easyq' ), 'easyq-comment-' . $post->id ),
				__( 'Add comment', 'easyq' ),
				array()
			);

		return sprintf(
			'<details %s id="easyq-comment-form-%d"><summary>%s</summary>%s</details>',
			get_block_wrapper_attributes(),
			$post->id,
			esc_html__( 'Add a comment', 'easyq' ),
			$body
		);
	}

	/**
	 * Renders a field that starts asking a question.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @return string
	 */
	public static function ask_box( array $attributes ): string {
		$placeholder = is_string( $attributes['placeholder'] ?? null ) && '' !== $attributes['placeholder'] ? $attributes['placeholder'] : __( 'What is your question?', 'easyq' );

		return self::single_field_form( Routes::url( 'ask' ), 'title', __( 'Ask a question', 'easyq' ), $placeholder, __( 'Ask', 'easyq' ), '' );
	}

	/**
	 * Renders the search field for questions.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @return string
	 */
	public static function search( array $attributes ): string {
		$placeholder = is_string( $attributes['placeholder'] ?? null ) ? $attributes['placeholder'] : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Shows the current search terms.
		$value = isset( $_GET['q'] ) && is_string( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';

		return self::single_field_form( Routes::url( 'search' ), 'q', __( 'Search questions', 'easyq' ), $placeholder, __( 'Search', 'easyq' ), $value, 'search' );
	}

	/**
	 * Returns the form to edit a post.
	 *
	 * @param Post $post Post.
	 * @return string
	 */
	public static function edit_form( Post $post ): string {
		$fields = '';

		if ( Post::QUESTION === $post->base_type() ) {
			$fields .= self::field( 'edit', 'title', __( 'Title', 'easyq' ), 'text', (string) $post->title, array( 'required' => 'required' ) );
		}

		$fields .= self::field( 'edit', 'content', __( 'Content', 'easyq' ), 'textarea', (string) $post->content );

		if ( Post::QUESTION === $post->base_type() && Post_Input::uses_tags() ) {
			$fields .= self::field( 'edit', 'tags', __( 'Tags', 'easyq' ), 'text', implode( Options::get_bool( 'posting', 'tag_separator_comma' ) ? ', ' : ' ', $post->tag_list() ) );
		}

		$viewer = Block_Context::viewer();
		if ( null !== $viewer->user_id && user_can( $viewer->user_id, 'easyq_edit_silently' ) ) {
			$fields .= sprintf(
				'<p><label><input type="checkbox" name="silent" value="1"> %s</label></p>',
				esc_html__( 'Do not show that the post was edited', 'easyq' )
			);
		}

		$cancel = sprintf( '<a href="%s">%s</a>', esc_url( remove_query_arg( 'easyq_edit' ) ), esc_html__( 'Cancel', 'easyq' ) );

		return self::form( 'edit', $post->id, $fields, __( 'Save changes', 'easyq' ), array(), $cancel );
	}

	/**
	 * Returns the form to close a question, folded until opened.
	 *
	 * @param Post $question Question.
	 * @return string
	 */
	public static function close_form( Post $question ): string {
		$fields = Form_Handler::hidden_fields( 'action' )
			. sprintf( '<input type="hidden" name="easyq_post" value="%d"><input type="hidden" name="easyq_action" value="close">', $question->id )
			. self::field( 'action', 'note', __( 'Reason for closing', 'easyq' ), 'textarea', '', array(), '', 'easyq-close-' . $question->id )
			. self::field( 'action', 'duplicate_of', __( 'Or: number of the question this one duplicates', 'easyq' ), 'number', '', array( 'min' => '1' ), '', 'easyq-close-' . $question->id );

		return sprintf(
			'<details class="wp-block-button"><summary>%s</summary><form method="post">%s<div class="wp-block-button"><button type="submit" class="wp-block-button__link wp-element-button">%s</button></div></form></details>',
			esc_html__( 'Close', 'easyq' ),
			$fields,
			esc_html__( 'Close question', 'easyq' )
		);
	}

	/**
	 * Returns a form with fields and a submit button.
	 *
	 * @param string               $form    Form name.
	 * @param int                  $post_id Post the form relates to; 0 for none.
	 * @param string               $fields  Field HTML.
	 * @param string               $label   Button label.
	 * @param array<string, mixed> $hidden  Further hidden fields.
	 * @param string               $after   HTML after the button, e.g. a cancel link.
	 * @return string
	 */
	private static function form( string $form, int $post_id, string $fields, string $label, array $hidden, string $after = '' ): string {
		$hidden_html = Form_Handler::hidden_fields( $form );
		if ( $post_id > 0 ) {
			$hidden_html .= sprintf( '<input type="hidden" name="easyq_post" value="%d">', $post_id );
		}
		foreach ( $hidden as $name => $value ) {
			$hidden_html .= sprintf( '<input type="hidden" name="%s" value="%s">', esc_attr( $name ), esc_attr( is_scalar( $value ) ? (string) $value : '' ) );
		}

		$wrapper = in_array( $form, array( 'ask' ), true ) ? get_block_wrapper_attributes() : '';
		$notice  = self::form_notice( $form );

		return sprintf(
			'<form method="post" %s>%s%s%s<div class="wp-block-buttons"><div class="wp-block-button"><button type="submit" class="wp-block-button__link wp-element-button">%s</button></div></div>%s</form>',
			$wrapper,
			$notice,
			$hidden_html,
			$fields,
			esc_html( $label ),
			$after
		);
	}

	/**
	 * Returns a labeled form field with its error message.
	 *
	 * @param string                $form       Form name.
	 * @param string                $name       Field name.
	 * @param string                $label      Label.
	 * @param string                $type       Input type, or `textarea`.
	 * @param string                $value      Value.
	 * @param array<string, string> $attributes Further attributes.
	 * @param string                $hint       Help text.
	 * @param string                $id_prefix  Prefix of the field ID, to keep IDs unique when a form appears several times.
	 * @return string
	 */
	private static function field( string $form, string $name, string $label, string $type, string $value, array $attributes = array(), string $hint = '', string $id_prefix = '' ): string {
		$id    = ( '' === $id_prefix ? 'easyq-' . $form : $id_prefix ) . '-' . $name;
		$error = Flash::error( $form, $name );
		$attrs = '';

		$described = array();
		if ( '' !== $hint ) {
			$described[] = $id . '-hint';
		}
		if ( '' !== $error ) {
			$described[]                = $id . '-error';
			$attributes['aria-invalid'] = 'true';
		}
		if ( array() !== $described ) {
			$attributes['aria-describedby'] = implode( ' ', $described );
		}

		foreach ( $attributes as $attribute => $attribute_value ) {
			$attrs .= sprintf( ' %s="%s"', esc_attr( $attribute ), esc_attr( $attribute_value ) );
		}

		$input = 'textarea' === $type
			? sprintf( '<textarea id="%s" name="%s" rows="8"%s>%s</textarea>', esc_attr( $id ), esc_attr( $name ), $attrs, esc_textarea( $value ) )
			: sprintf( '<input type="%s" id="%s" name="%s" value="%s"%s>', esc_attr( $type ), esc_attr( $id ), esc_attr( $name ), esc_attr( $value ), $attrs );

		return sprintf(
			'<p><label for="%s">%s</label><br>%s%s%s</p>',
			esc_attr( $id ),
			esc_html( $label ),
			$input,
			'' === $hint ? '' : sprintf( '<br><small id="%s-hint">%s</small>', esc_attr( $id ), esc_html( $hint ) ),
			'' === $error ? '' : sprintf( '<br><strong id="%s-error" role="alert">%s</strong>', esc_attr( $id ), esc_html( $error ) )
		);
	}

	/**
	 * Returns the category selection of the ask form.
	 *
	 * @param string $selected Selected category ID.
	 * @return string
	 */
	private static function category_field( string $selected ): string {
		$options = sprintf( '<option value="">%s</option>', esc_html__( '— Select a category —', 'easyq' ) );

		foreach ( self::category_tree( null, 0 ) as [ $category, $depth ] ) {
			$options .= sprintf(
				'<option value="%d"%s>%s%s</option>',
				$category->id,
				selected( $selected, (string) $category->id, false ),
				esc_html( str_repeat( '— ', $depth ) ),
				esc_html( $category->title )
			);
		}

		$error = Flash::error( 'ask', 'category' );

		return sprintf(
			'<p><label for="easyq-ask-category">%s</label><br><select id="easyq-ask-category" name="category">%s</select>%s</p>',
			esc_html__( 'Category', 'easyq' ),
			$options,
			'' === $error ? '' : sprintf( '<br><strong role="alert">%s</strong>', esc_html( $error ) )
		);
	}

	/**
	 * Returns the categories below a parent with their depth, depth first.
	 *
	 * @param int|null $parent_id Parent category.
	 * @param int      $depth     Depth of the children.
	 * @return array<int, array{0: \EasyQ\Categories\Category, 1: int}>
	 */
	private static function category_tree( ?int $parent_id, int $depth ): array {
		$tree = array();
		foreach ( Services::categories()->children( $parent_id ) as $category ) {
			$tree[] = array( $category, $depth );
			$tree   = array_merge( $tree, self::category_tree( $category->id, $depth + 1 ) );
		}

		return $tree;
	}

	/**
	 * Returns the notification and anonymous author fields.
	 *
	 * @param string $form      Form name.
	 * @param string $notify    Label of the notification checkbox.
	 * @param string $id_prefix Prefix of the field IDs.
	 * @return string
	 */
	private static function author_fields( string $form, string $notify, string $id_prefix = '' ): string {
		$viewer = Block_Context::viewer();
		$html   = sprintf(
			'<p><label><input type="checkbox" name="notify" value="1"%s> %s</label></p>',
			checked( Options::get_bool( 'posting', 'notify_users_default' ), true, false ),
			esc_html( $notify )
		);

		if ( null === $viewer->user_id ) {
			$html .= self::field( $form, 'email', __( 'Email address for notifications', 'easyq' ), 'email', Flash::value( $form, 'email' ), array(), '', $id_prefix );

			if ( Options::get_bool( 'posting', 'allow_anonymous_naming' ) ) {
				$html .= self::field( $form, 'name', __( 'Your name (optional)', 'easyq' ), 'text', Flash::value( $form, 'name' ), array(), '', $id_prefix );
			}

			$privacy = get_privacy_policy_url();
			$html   .= sprintf(
				'<p><small>%s</small></p>',
				'' === $privacy
					? esc_html__( 'Posting without an account stores a cookie in your browser, so you can edit your post later.', 'easyq' )
					: sprintf(
						/* translators: %s: link to the privacy policy. */
						esc_html__( 'Posting without an account stores a cookie in your browser, so you can edit your post later. See the %s.', 'easyq' ),
						sprintf( '<a href="%s">%s</a>', esc_url( $privacy ), esc_html__( 'privacy policy', 'easyq' ) )
					)
			);
		}

		return $html;
	}

	/**
	 * Returns the message after a failed or successful submission of a form.
	 *
	 * @param string $form Form name.
	 * @return string
	 */
	private static function form_notice( string $form ): string {
		$flash = Flash::current();
		if ( null === $flash || $flash['form'] !== $form || 'error' !== $flash['type'] ) {
			return '';
		}

		return sprintf( '<p role="alert"><strong>%s</strong></p>', esc_html( $flash['message'] ) );
	}

	/**
	 * Returns why the viewer cannot use a form, with login and registration links where they help.
	 *
	 * @param string $reason Permission error reason.
	 * @return string
	 */
	public static function permission_message( string $reason ): string {
		$current = ( is_ssl() ? 'https://' : 'http://' ) . ( isset( $_SERVER['HTTP_HOST'] ) && is_string( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '' ) . ( isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' );

		if ( 'login' === $reason ) {
			$links = sprintf( '<a href="%s">%s</a>', esc_url( wp_login_url( $current ) ), esc_html__( 'Log in', 'easyq' ) );
			if ( get_option( 'users_can_register' ) ) {
				$links .= ' ' . esc_html__( 'or', 'easyq' ) . ' ' . sprintf( '<a href="%s">%s</a>', esc_url( wp_registration_url() ), esc_html__( 'register', 'easyq' ) );
			}

			/* translators: %s: links to log in and register. */
			return sprintf( '<p>%s</p>', sprintf( esc_html__( '%s to take part.', 'easyq' ), $links ) );
		}

		$message = match ( $reason ) {
			'confirm'   => __( 'Please confirm your email address to take part.', 'easyq' ),
			'approve'   => __( 'Your account must be approved by a moderator before you can take part.', 'easyq' ),
			'userblock' => __( 'Your account is blocked.', 'easyq' ),
			'ipblock'   => __( 'Posting is not possible from your IP address.', 'easyq' ),
			'limit'     => __( 'You have reached the limit for this hour. Please try again later.', 'easyq' ),
			default     => __( 'You are not allowed to do this.', 'easyq' ),
		};

		return sprintf( '<p>%s</p>', esc_html( $message ) );
	}

	/**
	 * Returns a form with one field and a button, using the markup of the core search block.
	 *
	 * @param string $action      Form address.
	 * @param string $name        Field name.
	 * @param string $label       Field label.
	 * @param string $placeholder Placeholder.
	 * @param string $button      Button label.
	 * @param string $value       Field value.
	 * @param string $role        ARIA role of the form, e.g. `search`.
	 * @return string
	 */
	private static function single_field_form( string $action, string $name, string $label, string $placeholder, string $button, string $value, string $role = '' ): string {
		$id     = wp_unique_id( 'easyq-field-' );
		$hidden = '';

		// Without pretty permalinks, the route is part of the query string and must survive a GET form.
		$query = (string) wp_parse_url( $action, PHP_URL_QUERY );
		if ( '' !== $query ) {
			wp_parse_str( $query, $params );
			foreach ( $params as $param => $param_value ) {
				$hidden .= sprintf( '<input type="hidden" name="%s" value="%s">', esc_attr( (string) $param ), esc_attr( is_string( $param_value ) ? $param_value : '' ) );
			}
		}

		return sprintf(
			'<form method="get" action="%s" %s%s><label class="wp-block-search__label" for="%s">%s</label><div class="wp-block-search__inside-wrapper">%s<input class="wp-block-search__input" type="%s" id="%s" name="%s" value="%s" placeholder="%s" required><button type="submit" class="wp-block-search__button wp-element-button">%s</button></div></form>',
			esc_url( $action ),
			get_block_wrapper_attributes( array( 'class' => 'wp-block-search wp-block-search__button-outside wp-block-search__text-button' ) ),
			'' === $role ? '' : sprintf( ' role="%s"', esc_attr( $role ) ),
			esc_attr( $id ),
			esc_html( $label ),
			$hidden,
			'search' === $role ? 'search' : 'text',
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( $value ),
			esc_attr( $placeholder ),
			esc_html( $button )
		);
	}
}
