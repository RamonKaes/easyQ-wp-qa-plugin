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
	 * The site is part of it: the salts are shared by the sites of a network, and post and user
	 * IDs repeat or are shared there, so a token of one site must not hold on another.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function token( int $post_id ): string {
		return wp_hash( 'easyq_unsubscribe|' . get_current_blog_id() . '|' . $post_id );
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

	/**
	 * Meta key of a post whose author without an account confirmed the address for notifications.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CONFIRMED_META = 'easyq_notify_confirmed';

	/**
	 * Tells whether emails about a post may go to its address: an account, or an address its
	 * author confirmed, so nobody gets emails for an address they typed for someone else.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $post Post.
	 * @return bool
	 */
	public static function is_confirmed( Post $post ): bool {
		return null !== $post->user_id || '1' === get_metadata( 'easyq_post', $post->id, self::CONFIRMED_META, true );
	}

	/**
	 * Confirms the address of a post for notifications.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $post Post.
	 */
	public static function confirm( Post $post ): void {
		update_metadata( 'easyq_post', $post->id, self::CONFIRMED_META, '1' );
	}

	/**
	 * Returns the page that asks to confirm the address of a post.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function confirm_url( int $post_id ): string {
		return Routes::url(
			'unsubscribe',
			null,
			null,
			array(
				'notify' => 'confirm',
				'post'   => $post_id,
				'token'  => self::token( $post_id ),
			)
		);
	}

	/**
	 * Asks an author without an account to confirm the address they gave for notifications,
	 * once per post, as a double opt-in.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $post Post.
	 * @return bool Whether an email was sent.
	 */
	public static function ask_for_confirmation( Post $post ): bool {
		// Asked once per post: a queued post that is approved later is not asked again.
		if ( null !== $post->user_id || null === $post->notify || ! is_email( $post->notify ) || metadata_exists( 'easyq_post', $post->id, self::CONFIRMED_META ) ) {
			return false;
		}
		update_metadata( 'easyq_post', $post->id, self::CONFIRMED_META, '0' );

		$name = get_option( 'blogname' );
		$site = wp_specialchars_decode( is_string( $name ) ? $name : '', ENT_QUOTES );
		/* translators: %s: site name. */
		$subject = sprintf( __( '[%s] Confirm the emails about your post', 'easyq' ), $site );
		/* translators: 1: site name, 2: confirmation address. */
		$body = sprintf( __( "Hello,\n\nyou posted on %1\$s without an account and asked for emails about the answers and comments. Please confirm your address here:\n\n%2\$s\n\nIf it was not you, ignore this email; you will get no further emails.\n\n%1\$s", 'easyq' ), $site, self::confirm_url( $post->id ) );

		return Mailer::send_email( 'notify_confirm', new Recipient( $post->notify, null, null, $post ), $subject, $body );
	}
}
