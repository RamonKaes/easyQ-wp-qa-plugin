<?php
/**
 * Addresses of Q&A objects.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Frontend;

use EasyQ\Categories\Category;
use EasyQ\Posts\Post;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Util\Text;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the addresses of questions, posts, tags, categories and users.
 *
 * @since 0.1.0
 */
final class URLs {

	/**
	 * Returns the address of a question, with the title in the slug.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $question Question.
	 * @return string
	 */
	public static function question( Post $question ): string {
		if ( ! Routes::pretty() ) {
			return Routes::url( 'question', $question->id );
		}

		return Routes::url( 'question', $question->id . '/' . self::slug( (string) $question->title ) );
	}

	/**
	 * Returns the address of any post: the question, with an anchor for answers and comments.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $post Post.
	 * @return string
	 */
	public static function post( Post $post ): string {
		$question = Services::post_service()->question_of( $post );
		if ( null === $question ) {
			return Routes::url( 'home' );
		}

		return self::question( $question ) . ( $question->id === $post->id ? '' : '#easyq-post-' . $post->id );
	}

	/**
	 * Returns the address of a tag page.
	 *
	 * @since 0.1.0
	 *
	 * @param string $tag Tag.
	 * @return string
	 */
	public static function tag( string $tag ): string {
		return Routes::url( 'tag', $tag );
	}

	/**
	 * Returns the address of a category page.
	 *
	 * @since 0.1.0
	 *
	 * @param Category $category Category.
	 * @return string
	 */
	public static function category( Category $category ): string {
		return Routes::url( 'category', implode( '/', $category->path_slugs() ) );
	}

	/**
	 * Returns the address of a user profile.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_User     $user    User.
	 * @param string|null $section Profile section.
	 * @return string
	 */
	public static function user( WP_User $user, ?string $section = null ): string {
		return Routes::url( 'user', $user->user_nicename, $section );
	}

	/**
	 * Returns the slug of a question title: lowercase words joined by hyphens, limited in length.
	 *
	 * @since 0.1.0
	 *
	 * @param string $title Title.
	 * @return string
	 */
	public static function slug( string $title ): string {
		// Block words are censored in the address too, as in the title (`qa_q_request()`).
		$slug   = sanitize_title( Services::content_renderer()->text( $title ) );
		$length = max( 1, Options::get_int( 'viewing', 'q_urls_title_length' ) );

		// `sanitize_title()` encodes other scripts as `%xx`; the length counts characters and
		// the cut never falls inside one.
		$text = rawurldecode( $slug );
		if ( Text::length( $text ) > $length ) {
			$text = rtrim( (string) mb_substr( $text, 0, $length ), '-' );
			$cut  = strrpos( $text, '-' );
			$text = false === $cut ? $text : substr( $text, 0, $cut );
			$slug = strtolower( utf8_uri_encode( $text ) );
		}

		return '' === $slug ? 'question' : $slug;
	}
}
