<?php
/**
 * Rendering of walls and private messages.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Blocks\Render;

use EasyQ\Blocks\Block_Context;
use EasyQ\Blocks\Lists;
use EasyQ\Blocks\Render;
use EasyQ\Frontend\Flash;
use EasyQ\Frontend\Routes;
use EasyQ\Frontend\URLs;
use EasyQ\Messages\Message;
use EasyQ\Messages\Messages;
use EasyQ\Services;
use EasyQ\Settings\Options;
use WP_Block;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the wall and private message blocks, with the markup of the core comment blocks.
 *
 * @since 0.1.0
 */
final class Messages_Render {

	/**
	 * Query variable of the page of a wall or message box.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PAGE_VAR = 'easyq_page';

	/**
	 * List ID of the messages and wall posts for the pagination block; a page shows one of them.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const LIST_ID = 'easyq-messages';

	/**
	 * Renders the wall of a user.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks.
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function user_wall( array $attributes, string $content, WP_Block $block ): string {
		$owner = Block_Context::user( $block );
		if ( null === $owner || ! Options::get_bool( 'users', 'allow_user_walls' ) ) {
			return '';
		}

		$viewer   = Block_Context::viewer();
		$per_page = max( 1, Options::get_int( 'users', 'page_size_wall' ) );
		$page     = self::page();
		$found    = Services::messages()->wall( $owner->ID, $page, $per_page );

		$form = '';
		if ( 1 === $page ) {
			$reason = Services::messages()->wall_error( $viewer, $owner );
			$form   = null === $reason
				? Forms::form(
					'wall',
					$owner->ID,
					Forms::field( 'wall', 'content', __( 'Your post', 'easyq' ), 'textarea', Flash::value( 'wall', 'content' ), array( 'rows' => '3' ) ),
					__( 'Post on the wall', 'easyq' ),
					array()
				)
				: self::refusal( $reason );
		}

		self::prime_users( $found['messages'] );
		$items = '';
		foreach ( $found['messages'] as $post ) {
			$delete = Services::messages()->can_delete_wall_post( $viewer, $post )
				? Render::action_button( 'wall_delete', $owner->ID, __( 'Delete', 'easyq' ), array( 'easyq_message' => $post->id ) )
				: '';
			$items .= self::item( $post, $post->from_user_id, $delete );
		}

		return sprintf(
			'<section %s id="wall"><h2>%s</h2>%s%s%s</section>',
			get_block_wrapper_attributes(),
			/* translators: %s: user name. */
			esc_html( sprintf( __( 'Wall of %s', 'easyq' ), $owner->display_name ) ),
			$form,
			'' === $items ? sprintf( '<p>%s</p>', esc_html__( 'Nothing posted yet.', 'easyq' ) ) : '<ol class="wp-block-comment-template">' . $items . '</ol>',
			self::pagination( $found['messages'], $page, $found['total'], $per_page )
		);
	}

	/**
	 * Renders the private messages: the inbox or sent messages, or the conversation with a user.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function messages(): string {
		$viewer = Block_Context::viewer();
		$route  = Block_Context::router()->current();
		if ( null === $viewer->user_id || ! Options::get_bool( 'users', 'allow_private_messages' ) ) {
			return '';
		}

		return 'message' === $route?->name ? self::conversation( (int) $viewer->user_id, $route->user() ) : self::box( (int) $viewer->user_id );
	}

	/**
	 * Renders the conversation with a user and the form to write to them, as `pages/message.php`.
	 *
	 * @since 0.1.0
	 *
	 * @param int           $user_id Reader.
	 * @param \WP_User|null $other   The other user.
	 * @return string
	 */
	private static function conversation( int $user_id, ?\WP_User $other ): string {
		if ( null === $other ) {
			return '';
		}

		$reason = Services::messages()->message_error( Block_Context::viewer(), $other );
		$form   = null === $reason
			? Forms::form(
				'message',
				0,
				Forms::field(
					'message',
					'content',
					/* translators: %s: user name. */
					sprintf( __( 'Message for %s', 'easyq' ), $other->display_name ),
					'textarea',
					Flash::value( 'message', 'content' ),
					array( 'rows' => '6' ),
					/* translators: %s: site name. */
					sprintf( __( 'This will be sent as a notification from %s. Your email address will not be revealed unless you include it in the message.', 'easyq' ), get_bloginfo( 'name' ) )
				),
				__( 'Send', 'easyq' ),
				array( 'to' => $other->ID )
			)
			: self::refusal( $reason );

		$items = '';
		if ( Options::get_bool( 'users', 'show_message_history' ) ) {
			foreach ( array_reverse( Services::messages()->conversation( $user_id, $other->ID ) ) as $message ) {
				$items .= self::item( $message, $message->from_user_id, '' );
			}
		}

		return sprintf(
			'<div %s>%s%s</div>',
			get_block_wrapper_attributes(),
			$form,
			'' === $items ? '' : sprintf(
				'<h2>%s</h2><ol class="wp-block-comment-template">%s</ol>',
				/* translators: %s: user name. */
				esc_html( sprintf( __( 'Recent messages with %s', 'easyq' ), $other->display_name ) ),
				$items
			)
		);
	}

	/**
	 * Renders the received or sent messages, as `pages/messages.php`.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id Reader.
	 * @return string
	 */
	private static function box( int $user_id ): string {
		if ( ! Options::get_bool( 'users', 'show_message_history' ) ) {
			return '';
		}

		$box      = self::box_name();
		$per_page = max( 1, Options::get_int( 'users', 'page_size_pms' ) );
		$page     = self::page();
		$found    = Services::messages()->box( $user_id, $box, $page, $per_page );

		self::prime_users( $found['messages'] );
		$items = '';
		foreach ( $found['messages'] as $message ) {
			$other    = 'sent' === $box ? $message->to_user_id : $message->from_user_id;
			$person   = null === $other ? false : get_userdata( $other );
			$buttons  = false === $person ? '' : Render::link_button( Routes::url( 'message', $person->user_nicename ), __( 'Reply', 'easyq' ) );
			$buttons .= Render::action_button( 'message_delete', 0, __( 'Delete', 'easyq' ), array( 'easyq_message' => $message->id ) );
			$items   .= self::item( $message, $other, $buttons, 'sent' === $box );
		}

		$nav = sprintf(
			'<nav aria-label="%s"><a href="%s"%s>%s</a> <a href="%s"%s>%s</a></nav>',
			esc_attr__( 'Message boxes', 'easyq' ),
			esc_url( Routes::url( 'messages' ) ),
			'sent' === $box ? '' : ' aria-current="page"',
			esc_html__( 'Inbox', 'easyq' ),
			esc_url( add_query_arg( 'box', 'sent', Routes::url( 'messages' ) ) ),
			'sent' === $box ? ' aria-current="page"' : '',
			esc_html__( 'Sent', 'easyq' )
		);

		return sprintf(
			'<div %s>%s%s%s</div>',
			get_block_wrapper_attributes(),
			$nav,
			'' === $items ? sprintf( '<p>%s</p>', esc_html__( 'No messages.', 'easyq' ) ) : '<ol class="wp-block-comment-template">' . $items . '</ol>',
			self::pagination( $found['messages'], $page, $found['total'], $per_page )
		);
	}

	/**
	 * Loads the users of messages at once, as core does for the authors of posts.
	 *
	 * @since 0.1.0
	 *
	 * @param Message[] $messages Messages.
	 */
	private static function prime_users( array $messages ): void {
		$ids = array();
		foreach ( $messages as $message ) {
			$ids[] = $message->from_user_id;
			$ids[] = $message->to_user_id;
		}

		cache_users( array_values( array_unique( array_filter( $ids ) ) ) );
	}

	/**
	 * Returns the requested box: `inbox` or `sent`.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function box_name(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading which list to show.
		return isset( $_GET['box'] ) && 'sent' === $_GET['box'] ? 'sent' : 'inbox';
	}

	/**
	 * Renders one message with the markup of the core comment blocks.
	 *
	 * @since 0.1.0
	 *
	 * @param Message  $message  Message.
	 * @param int|null $user_id  User to name: the author, or for sent messages the recipient.
	 * @param string   $buttons  Buttons, escaped HTML.
	 * @param bool     $sent     Whether it is shown among sent messages.
	 * @return string
	 */
	private static function item( Message $message, ?int $user_id, string $buttons, bool $sent = false ): string {
		$user = null === $user_id ? false : get_userdata( $user_id );
		$name = false === $user ? esc_html__( 'Deleted user', 'easyq' ) : sprintf( '<a href="%s">%s</a>', esc_url( URLs::user( $user ) ), esc_html( $user->display_name ) );

		return sprintf(
			'<li id="message-%1$d" class="comment"><div class="wp-block-comment-author-name">%2$s</div><div class="wp-block-comment-date"><time datetime="%3$s">%4$s</time></div><div class="wp-block-comment-content">%5$s</div>%6$s</li>',
			$message->id,
			/* translators: %s: user name. */
			$sent ? sprintf( esc_html__( 'To %s', 'easyq' ), $name ) : $name,
			esc_attr( mysql_to_rfc3339( $message->created ) ),
			esc_html( Render::date( $message->created ) ),
			$message->html(),
			'' === $buttons ? '' : Render::buttons( $buttons )
		);
	}

	/**
	 * Returns why something cannot be written, with links to log in where that helps.
	 *
	 * @since 0.1.0
	 *
	 * @param string $reason Reason from Messages.
	 * @return string
	 */
	private static function refusal( string $reason ): string {
		return in_array( $reason, array( 'login', 'confirm', 'approve', 'userblock', 'ipblock', 'limit' ), true )
			? Forms::permission_message( $reason )
			: sprintf( '<p>%s</p>', esc_html( Messages::reason_message( $reason ) ) );
	}

	/**
	 * Returns the requested page.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	private static function page(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the page of a list.
		return isset( $_GET[ self::PAGE_VAR ] ) && is_numeric( $_GET[ self::PAGE_VAR ] ) ? max( 1, absint( $_GET[ self::PAGE_VAR ] ) ) : 1;
	}

	/**
	 * Renders the pagination block for the messages shown, as the lists of questions do.
	 *
	 * @since 0.1.0
	 *
	 * @param Message[] $messages Messages on the current page.
	 * @param int       $page     Current page.
	 * @param int       $total    Number of items.
	 * @param int       $per_page Items per page.
	 * @return string
	 */
	private static function pagination( array $messages, int $page, int $total, int $per_page ): string {
		if ( $total <= $per_page ) {
			return '';
		}

		Lists::store( self::LIST_ID, array_map( static fn ( Message $message ): int => $message->id, $messages ), $total, $page, $per_page, self::PAGE_VAR );

		$block = new WP_Block(
			array(
				'blockName'    => 'easyq/query-pagination',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			),
			array( 'easyq/listId' => self::LIST_ID )
		);

		return $block->render();
	}
}
