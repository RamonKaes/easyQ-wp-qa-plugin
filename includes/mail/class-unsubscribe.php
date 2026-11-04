<?php
/**
 * Links to stop the emails about a post.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Mail;

use EasyQ\Frontend\Routes;
use EasyQ\Posts\Post;
use EasyQ\REST\Controller;
use EasyQ\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Signs the links in notification emails, which work without logging in.
 *
 * A token only allows stopping the emails about one post, so it needs no expiry
 * and no storage.
 *
 * @since 0.1.0
 */
final class Unsubscribe {

	/**
	 * Returns the token of a post.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function token( int $post_id ): string {
		return wp_hash( 'easyq_unsubscribe|' . $post_id );
	}

	/**
	 * Checks the token of a post.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $post_id Post ID.
	 * @param string $token   Token.
	 * @return bool
	 */
	public static function verify( int $post_id, string $token ): bool {
		return hash_equals( self::token( $post_id ), $token );
	}

	/**
	 * Returns the address of the page that asks to stop the emails.
	 *
	 * Mail programs and scanners open links in emails, so opening the page changes nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function page_url( int $post_id ): string {
		return Routes::url(
			'unsubscribe',
			null,
			null,
			array(
				'post'  => $post_id,
				'token' => self::token( $post_id ),
			)
		);
	}

	/**
	 * Returns the address that mail programs post to for a one-click unsubscribe (RFC 8058).
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function one_click_url( int $post_id ): string {
		return add_query_arg(
			array(
				'post'  => $post_id,
				'token' => self::token( $post_id ),
			),
			rest_url( Controller::NAMESPACE . '/unsubscribe' )
		);
	}

	/**
	 * Stops the emails about a post.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $post Post.
	 */
	public static function stop( Post $post ): void {
		Services::posts()->update( $post->id, array( 'notify' => null ) );
	}
}
