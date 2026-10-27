<?php
/**
 * Post input validation.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Posts;

use EasyQ\Settings\Options;
use EasyQ\Util\Text;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Checks questions, answers and comments entered by users against the site settings.
 *
 * Errors are keyed by field: title, content, tags, email. Ported from
 * Question2Answer `qa-include/plugins/qa-filter-basic.php`.
 *
 * @since 0.1.0
 */
final class Post_Validator {

	/**
	 * Longest stored title in characters.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_TITLE_LENGTH = 800;

	/**
	 * Longest stored content in characters.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_CONTENT_LENGTH = 12000;

	/**
	 * Longest stored tag string in characters.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_TAGS_LENGTH = 800;

	/**
	 * Longest name of an anonymous author in characters.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_NAME_LENGTH = 40;

	/**
	 * Longest email address in characters.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_EMAIL_LENGTH = 100;

	/**
	 * Validates a question.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $fields Fields: title, content, format, tags (string[]), notify (bool), email, name. When editing, missing fields are not checked.
	 * @param bool                 $is_new Whether this is a new question; new questions need title and content.
	 * @return WP_Error Errors keyed by field; empty if the question is valid.
	 */
	public function validate_question( array $fields, bool $is_new = true ): WP_Error {
		$errors = new WP_Error();

		if ( $is_new ) {
			$fields += array(
				'title'   => '',
				'content' => '',
			);
		}

		$min_title = Options::get_int( 'posting', 'min_len_q_title' );
		$max_title = max( $min_title, min( Options::get_int( 'posting', 'max_len_q_title' ), self::MAX_TITLE_LENGTH ) );
		$this->check_length( $errors, $fields, 'title', $min_title, $max_title );

		$this->check_content( $errors, $fields, Options::get_int( 'posting', 'min_len_q_content' ) );

		if ( isset( $fields['tags'] ) && is_array( $fields['tags'] ) ) {
			$count = count( $fields['tags'] );
			$max   = Options::get_int( 'posting', 'max_num_q_tags' );
			$min   = min( Options::get_int( 'posting', 'min_num_q_tags' ), $max );

			if ( $count < $min ) {
				/* translators: %d: minimum number of tags. */
				$errors->add( 'tags', sprintf( _n( 'Please add at least %d tag.', 'Please add at least %d tags.', $min, 'easyq' ), $min ) );
			} elseif ( $count > $max ) {
				/* translators: %d: maximum number of tags. */
				$errors->add( 'tags', sprintf( _n( 'Please add at most %d tag.', 'Please add at most %d tags.', $max, 'easyq' ), $max ) );
			} elseif ( Text::length( Text::tags_to_string( array_map( static fn ( $tag ): string => is_scalar( $tag ) ? (string) $tag : '', $fields['tags'] ) ) ) > self::MAX_TAGS_LENGTH ) {
				$errors->add( 'tags', $this->max_length_message( self::MAX_TAGS_LENGTH ) );
			}
		}

		$this->check_author( $errors, $fields );

		return $errors;
	}

	/**
	 * Validates an answer.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $fields Fields: content, format, notify, email, name.
	 * @return WP_Error Errors keyed by field; empty if the answer is valid.
	 */
	public function validate_answer( array $fields ): WP_Error {
		$errors = new WP_Error();

		$this->check_content( $errors, $fields + array( 'content' => '' ), Options::get_int( 'posting', 'min_len_a_content' ) );
		$this->check_author( $errors, $fields );

		return $errors;
	}

	/**
	 * Validates a comment.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $fields Fields: content, format, notify, email, name.
	 * @return WP_Error Errors keyed by field; empty if the comment is valid.
	 */
	public function validate_comment( array $fields ): WP_Error {
		$errors = new WP_Error();

		$this->check_content( $errors, $fields + array( 'content' => '' ), Options::get_int( 'posting', 'min_len_c_content' ) );
		$this->check_author( $errors, $fields );

		return $errors;
	}

	/**
	 * Checks the stored length of the content and the length of its text.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Error             $errors     Errors to add to.
	 * @param array<string, mixed> $fields     Fields.
	 * @param int                  $min_length Minimum length of the text.
	 */
	private function check_content( WP_Error $errors, array $fields, int $min_length ): void {
		if ( ! array_key_exists( 'content', $fields ) ) {
			return;
		}

		$content = is_scalar( $fields['content'] ) ? (string) $fields['content'] : '';
		$format  = isset( $fields['format'] ) && is_scalar( $fields['format'] ) ? (string) $fields['format'] : '';

		$this->check_length( $errors, array( 'content' => $content ), 'content', 0, self::MAX_CONTENT_LENGTH );
		if ( ! $errors->get_error_message( 'content' ) ) {
			$this->check_length( $errors, array( 'content' => Text::content_to_text( $content, $format ) ), 'content', $min_length, null );
		}
	}

	/**
	 * Checks the fields describing the author: notification address and name.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Error             $errors Errors to add to.
	 * @param array<string, mixed> $fields Fields.
	 */
	private function check_author( WP_Error $errors, array $fields ): void {
		$email = isset( $fields['email'] ) && is_scalar( $fields['email'] ) ? trim( (string) $fields['email'] ) : '';

		if ( ! empty( $fields['notify'] ) && '' !== $email ) {
			if ( ! is_email( $email ) ) {
				$errors->add( 'email', __( 'Please enter a valid email address.', 'easyq' ) );
			} elseif ( Text::length( $email ) > self::MAX_EMAIL_LENGTH ) {
				$errors->add( 'email', $this->max_length_message( self::MAX_EMAIL_LENGTH ) );
			}
		}

		$this->check_length( $errors, $fields, 'name', 0, self::MAX_NAME_LENGTH );
	}

	/**
	 * Checks the length of a field if it is present.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Error             $errors Errors to add to.
	 * @param array<string, mixed> $fields Fields.
	 * @param string               $key    Field to check.
	 * @param int                  $min    Minimum length in characters.
	 * @param int|null             $max    Maximum length in characters, or null for none.
	 */
	private function check_length( WP_Error $errors, array $fields, string $key, int $min, ?int $max ): void {
		if ( ! array_key_exists( $key, $fields ) ) {
			return;
		}

		$length = Text::length( is_scalar( $fields[ $key ] ) ? (string) $fields[ $key ] : '' );

		if ( $length < $min ) {
			$errors->add(
				$key,
				1 === $min
					? __( 'Please fill in this field.', 'easyq' )
					/* translators: %d: minimum number of characters. */
					: sprintf( _n( 'Please enter at least %d character.', 'Please enter at least %d characters.', $min, 'easyq' ), $min )
			);
		} elseif ( null !== $max && $length > $max ) {
			$errors->add( $key, $this->max_length_message( $max ) );
		}
	}

	/**
	 * Returns the error message for a field that is too long.
	 *
	 * @since 0.1.0
	 *
	 * @param int $max Maximum length in characters.
	 * @return string
	 */
	private function max_length_message( int $max ): string {
		/* translators: %d: maximum number of characters. */
		return sprintf( _n( 'Please enter at most %d character.', 'Please enter at most %d characters.', $max, 'easyq' ), $max );
	}
}
