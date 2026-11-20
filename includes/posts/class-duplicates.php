<?php
/**
 * Refusal of content posted twice.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Posts;

use EasyQ\Actor;
use EasyQ\Database\DB;
use EasyQ\Database\Schema;
use EasyQ\Services;
use EasyQ\Util\Text;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Refuses a post whose content was just posted.
 *
 * Questions are compared, as in Question2Answer (`qa-include/pages/ask.php`), with the
 * five newest questions by the words of their title and content. Answers and comments
 * are compared, as core compares comments (`wp_allow_comment()`), with those of the
 * same author on the same post. A lock per site and type makes the check and the insert
 * one step, so that a double submission does not pass twice.
 *
 * @since 0.1.0
 */
final class Duplicates {

	/**
	 * Newest questions a new question is compared with, as in Question2Answer.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const RECENT_QUESTIONS = 5;

	/**
	 * Seconds to wait for a post of the same type in progress.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const LOCK_WAIT = 10;

	/**
	 * Creates a post unless the same content was posted.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor           $actor   Author.
	 * @param string          $type    Base type: Post::QUESTION, Post::ANSWER or Post::COMMENT.
	 * @param int|null        $parent_id Post an answer or comment is on, null for a question.
	 * @param string          $title   Title of a question, empty otherwise.
	 * @param string          $content Content.
	 * @param string          $format  Content format.
	 * @param callable(): int $create  Creates the post and returns its ID.
	 * @return int|WP_Error The new post ID, or the error `easyq_duplicate` with status 409.
	 */
	public static function create( Actor $actor, string $type, ?int $parent_id, string $title, string $content, string $format, callable $create ): int|WP_Error {
		$wpdb   = DB::wpdb();
		$lock   = $wpdb->prefix . 'easyq_post_' . $type;
		$locked = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::LOCK_WAIT ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A lock, not data.

		try {
			if ( self::exists( $actor, $type, $parent_id, $title, Text::content_to_text( $content, $format ) ) ) {
				return new WP_Error(
					'easyq_duplicate',
					__( 'This was just posted. It looks as though you have already said that.', 'easyq' ),
					array( 'status' => 409 )
				);
			}

			return $create();
		} finally {
			if ( $locked ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A lock, not data.
			}
		}
	}

	/**
	 * Checks whether the same content was posted.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor    $actor     Author.
	 * @param string   $type      Base type.
	 * @param int|null $parent_id Post an answer or comment is on.
	 * @param string   $title     Title of a question.
	 * @param string   $text      Plain text of the content.
	 * @return bool
	 */
	private static function exists( Actor $actor, string $type, ?int $parent_id, string $title, string $text ): bool {
		$wpdb  = DB::wpdb();
		$table = Schema::table( 'posts' );

		// The author: an account, the visitor cookie of a guest, or the address of a guest who declined the cookie.
		$user_id   = $actor->user_id ?? 0;
		$is_guest  = null === $actor->user_id;
		$cookie_id = $is_guest ? $actor->cookie_id ?? 0 : 0;
		$ip        = $is_guest && null === $actor->cookie_id ? (string) $actor->stored_ip() : '';

		if ( Post::QUESTION === $type ) {
			// Visible questions of anyone, and the author's own waiting ones (a double submission under moderation).
			$rows = DB::rows(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
				$wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE type = 'Q' OR ( type = 'Q_QUEUED' AND ( user_id = %d OR ( user_id IS NULL AND cookie_id = %d ) OR ( %s <> '' AND user_id IS NULL AND cookie_id IS NULL AND created_ip = %s ) ) ) ORDER BY id DESC LIMIT %d", $table, $user_id, $cookie_id, $ip, $ip, self::RECENT_QUESTIONS ) )
			);
		} else {
			$rows = DB::rows(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
				$wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE parent_id = %d AND type IN (%s, %s) AND ( user_id = %d OR ( user_id IS NULL AND cookie_id = %d ) OR ( %s <> '' AND user_id IS NULL AND cookie_id IS NULL AND created_ip = %s ) )", $table, (int) $parent_id, $type, $type . '_QUEUED', $user_id, $cookie_id, $ip, $ip ) )
			);
		}

		$words = self::words( $text );
		$title = self::words( $title );
		foreach ( $rows as $row ) {
			$post = Post::from_row( $row );
			// Another author's question counts only if the author may see it; the 409 must not tell what is hidden.
			if ( Post::QUESTION === $type && ! self::is_own( $post, $actor ) && ! Services::permissions()->shows( $post, $actor ) ) {
				continue;
			}
			if ( self::words( (string) $post->title ) === $title && self::words( $post->text() ) === $words ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tells whether a post is the actor's own.
	 *
	 * @since 0.1.0
	 *
	 * @param Post  $post  Post.
	 * @param Actor $actor Actor.
	 * @return bool
	 */
	private static function is_own( Post $post, Actor $actor ): bool {
		if ( null !== $actor->user_id ) {
			return $post->user_id === $actor->user_id;
		}
		if ( null !== $post->user_id ) {
			return false;
		}
		if ( null !== $actor->cookie_id ) {
			return $post->cookie_id === $actor->cookie_id;
		}

		// A guest who declined the cookie, known by the address as in the query.
		$ip = $actor->stored_ip();

		return null === $post->cookie_id && null !== $ip && $post->created_ip === $ip;
	}

	/**
	 * Returns the words of a text, joined by spaces, as Question2Answer compares them.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function words( string $text ): string {
		return implode( ' ', Text::to_words( $text ) );
	}
}
