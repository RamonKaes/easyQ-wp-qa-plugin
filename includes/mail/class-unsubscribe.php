<?php
/**
 * Links to stop the emails about a post.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Mail;

use EasyQ\Database\DB;
use EasyQ\Database\Schema;
use EasyQ\Frontend\Routes;
use EasyQ\Posts\Post;
use EasyQ\REST\Controller;
use EasyQ\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Signs the links in notification emails, which work without logging in.
 *
 * A token only allows stopping the emails about one post, or confirming the one address it
 * was sent to (confirm_token()), so it needs no expiry and no storage.
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
		if ( null !== $post->user_id ) {
			return true;
		}

		// The confirmation holds for the address that was confirmed, not for one set later.
		$confirmed = get_metadata( 'easyq_post', $post->id, self::CONFIRMED_META, true );

		return null !== $post->notify && is_string( $confirmed ) && hash_equals( self::address_hash( $post->notify ), $confirmed );
	}

	/**
	 * Meta key with the addresses of a post that were asked to confirm.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ASKED_META = 'easyq_notify_asked';

	/**
	 * Addresses of a post that are asked to confirm at most, so that changing the address does
	 * not send emails to anyone without end.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_ASKED = 3;

	/**
	 * Prefix of the transient that pauses the requests to confirm an address for an hour,
	 * named after a hash of the address, see ask_for_confirmation().
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PAUSE_PREFIX = 'easyq_notify_pause_';

	/**
	 * Returns the stored form of an address: a keyed hash, so the meta does not hold it again.
	 *
	 * @since 0.1.0
	 *
	 * @param string $address Address.
	 * @return string
	 */
	private static function address_hash( string $address ): string {
		return wp_hash( 'easyq_notify|' . strtolower( trim( $address ) ) );
	}

	/**
	 * Returns the token of the confirmation link: bound to the post and its address, so a link
	 * sent for one address confirms no other, and apart from the token that stops the emails.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $post Post.
	 * @return string
	 */
	public static function confirm_token( Post $post ): string {
		return wp_hash( 'easyq_notify_confirm|' . get_current_blog_id() . '|' . $post->id . '|' . strtolower( trim( (string) $post->notify ) ) );
	}

	/**
	 * Checks the token of a confirmation link against the current address of the post.
	 *
	 * @since 0.1.0
	 *
	 * @param Post|null $post  Post.
	 * @param string    $token Token.
	 * @return bool
	 */
	public static function verify_confirm( ?Post $post, string $token ): bool {
		return null !== $post && null === $post->user_id && null !== $post->notify && hash_equals( self::confirm_token( $post ), $token );
	}

	/**
	 * Confirms the address of a post for notifications.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $post Post.
	 */
	public static function confirm( Post $post ): void {
		if ( null === $post->notify ) {
			return;
		}

		// The guest confirmed the address: for each of their posts that asks for emails to it.
		foreach ( array_merge( array( $post ), self::posts_of_guest( $post ) ) as $own ) {
			update_metadata( 'easyq_post', $own->id, self::CONFIRMED_META, self::address_hash( $post->notify ) );
		}
	}

	/**
	 * Returns the other posts of the same guest, by the visitor cookie, that ask for emails to
	 * the same address.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $post Post of a guest.
	 * @return Post[]
	 */
	private static function posts_of_guest( Post $post ): array {
		if ( null !== $post->user_id || null === $post->cookie_id || null === $post->notify ) {
			return array();
		}

		$wpdb = DB::wpdb();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
		$ids = DB::int_col( $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE user_id IS NULL AND cookie_id = %d AND LOWER(notify) = %s AND id <> %d', Schema::table( 'posts' ), $post->cookie_id, strtolower( trim( $post->notify ) ), $post->id ) ) );

		return array_values( Services::posts()->find_many( $ids ) );
	}

	/**
	 * Returns the page that asks to confirm the address of a post.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $post Post.
	 * @return string
	 */
	public static function confirm_url( Post $post ): string {
		return Routes::url(
			'unsubscribe',
			null,
			null,
			array(
				'notify' => 'confirm',
				'post'   => $post->id,
				'token'  => self::confirm_token( $post ),
			)
		);
	}

	/**
	 * Asks an author without an account to confirm the address they gave for notifications,
	 * as a double opt-in: each address once per post, and at most MAX_ASKED addresses.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $post Post.
	 * @return bool Whether an email was sent.
	 */
	public static function ask_for_confirmation( Post $post ): bool {
		if ( null !== $post->user_id || null === $post->notify || ! is_email( $post->notify ) || self::is_confirmed( $post ) ) {
			return false;
		}

		// An address the guest confirmed on another post holds for this one, without an email.
		foreach ( self::posts_of_guest( $post ) as $own ) {
			if ( self::is_confirmed( $own ) ) {
				self::confirm( $post );

				return false;
			}
		}

		// Each address once: a queued post that is approved later is not asked again, nor an
		// address set back; and not more than a few, as an edit could otherwise mail anyone.
		$asked = get_metadata( 'easyq_post', $post->id, self::ASKED_META, true );
		$asked = is_array( $asked ) ? array_values( array_filter( $asked, 'is_string' ) ) : array();
		$hash  = self::address_hash( $post->notify );
		if ( in_array( $hash, $asked, true ) || count( $asked ) >= self::MAX_ASKED ) {
			return false;
		}
		// And each address once an hour, whatever the post: posting again and again would
		// otherwise send a request to any address with each post. The link of the request
		// confirms the address for every post of the guest that asks for it (confirm()).
		$pause = self::PAUSE_PREFIX . md5( $hash );
		if ( false !== get_transient( $pause ) ) {
			return false;
		}
		set_transient( $pause, 1, HOUR_IN_SECONDS );
		$asked[] = $hash;
		update_metadata( 'easyq_post', $post->id, self::ASKED_META, $asked );

		$name = get_option( 'blogname' );
		$site = wp_specialchars_decode( is_string( $name ) ? $name : '', ENT_QUOTES );
		/* translators: %s: site name. */
		$subject = sprintf( __( '[%s] Confirm the emails about your post', 'easyq' ), $site );
		/* translators: 1: site name, 2: confirmation address. */
		$body = sprintf( __( "Hello,\n\nyou posted on %1\$s without an account and asked for emails about the answers and comments. Please confirm your address here:\n\n%2\$s\n\nIf it was not you, ignore this email; you will get no further emails.\n\n%1\$s", 'easyq' ), $site, self::confirm_url( $post ) );

		return Mailer::send_email( 'notify_confirm', new Recipient( $post->notify, null, null, $post ), $subject, $body );
	}
}
