<?php
/**
 * Private messages and wall posts.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Messages;

use EasyQ\Actor;
use EasyQ\Database\DB;
use EasyQ\Database\Schema;
use EasyQ\Events\Events;
use EasyQ\Limits\Limits;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Users\Site_Meta;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Sends private messages and keeps the walls of users.
 *
 * Ported from Question2Answer `qa-include/app/messages.php`, `db/messages.php` and the pages
 * `message.php`, `messages.php` and `user-wall.php`.
 *
 * @since 0.1.0
 */
final class Messages {

	/**
	 * Type of wall posts.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const WALL = 'public';

	/**
	 * Type of private messages.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PRIVATE = 'private';

	/**
	 * User meta: the user takes no private messages, as `QA_USER_FLAGS_NO_MESSAGES`.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NO_MESSAGES_META = 'easyq_no_messages';

	/**
	 * User meta: others may not post on the wall of the user, as `QA_USER_FLAGS_NO_WALL_POSTS`.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NO_WALL_META = 'easyq_no_wall_posts';

	/**
	 * Longest text of a message.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_LENGTH = 12000;

	/**
	 * Messages of a conversation shown above the form, as `QA_DB_RETRIEVE_MESSAGES`.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const CONVERSATION_LENGTH = 20;

	/**
	 * Hooks the service into WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'easyq_u_delete', array( self::class, 'user_deleted' ) );
	}

	/**
	 * Deletes the messages of a deleted user and the posts on their wall.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $params Event parameters with `user_id`.
	 */
	public static function user_deleted( array $params ): void {
		if ( is_numeric( $params['user_id'] ?? null ) ) {
			self::delete_all_of( (int) $params['user_id'] );
		}
	}

	/**
	 * Deletes all messages a user wrote or received, and the posts on their wall.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 * @return int Number of deleted messages.
	 */
	public static function delete_all_of( int $user_id ): int {
		$wpdb = DB::wpdb();
		$sql  = $wpdb->prepare( 'DELETE FROM %i WHERE from_user_id = %d OR to_user_id = %d', Schema::table( 'messages' ), $user_id, $user_id );
		if ( null === $sql ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom tables without a WordPress API.
		return (int) $wpdb->query( $sql );
	}

	/**
	 * Checks whether a user takes private messages.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function accepts_messages( int $user_id ): bool {
		return empty( Site_Meta::get( $user_id, self::NO_MESSAGES_META ) );
	}

	/**
	 * Checks whether others may post on the wall of a user.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function accepts_wall_posts( int $user_id ): bool {
		return empty( Site_Meta::get( $user_id, self::NO_WALL_META ) );
	}

	/**
	 * Sets whether a user takes private messages and wall posts.
	 *
	 * @since 0.1.0
	 *
	 * @param int       $user_id  User ID.
	 * @param bool|null $messages Private messages; null to leave as it is.
	 * @param bool|null $wall     Wall posts; null to leave as it is.
	 */
	public static function set_preferences( int $user_id, ?bool $messages, ?bool $wall ): void {
		foreach ( array(
			self::NO_MESSAGES_META => $messages,
			self::NO_WALL_META     => $wall,
		) as $key => $accepts ) {
			if ( true === $accepts ) {
				Site_Meta::delete( $user_id, $key );
			} elseif ( false === $accepts ) {
				Site_Meta::update( $user_id, $key, 1 );
			}
		}
	}

	/**
	 * Returns why an actor may not post on a wall, as `qa_wall_error_html()`.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor   $actor Who wants to post.
	 * @param WP_User $owner Owner of the wall.
	 * @return string|null Reason, see Permissions::error(), or `wall_blocked` or `walls_off`; null if allowed.
	 */
	public function wall_error( Actor $actor, WP_User $owner ): ?string {
		if ( ! Options::get_bool( 'users', 'allow_user_walls' ) ) {
			return 'walls_off';
		}

		if ( ! self::accepts_wall_posts( $owner->ID ) && $actor->user_id !== $owner->ID ) {
			return 'wall_blocked';
		}

		return Services::permissions()->error( 'permit_post_wall', $actor, null, Limits::WALL_POSTS );
	}

	/**
	 * Returns why an actor may not send a private message to a user.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor   $actor Who wants to send.
	 * @param WP_User $to    Recipient.
	 * @return string|null Reason, see Permissions::error(), or `messages_off`, `self` or `messages_blocked`; null if allowed.
	 */
	public function message_error( Actor $actor, WP_User $to ): ?string {
		return match ( true ) {
			! Options::get_bool( 'users', 'allow_private_messages' ) => 'messages_off',
			null === $actor->user_id                                 => 'login',
			$actor->user_id === $to->ID                              => 'self',
			! self::accepts_messages( $to->ID )                      => 'messages_blocked',
			default                                                  => Services::permissions()->error( null, $actor, null, Limits::MESSAGES ),
		};
	}

	/**
	 * Posts on the wall of a user, as `qa_wall_add_post()`.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor   $actor   Author.
	 * @param WP_User $owner   Owner of the wall.
	 * @param string  $content Text.
	 * @return int|WP_Error ID of the post.
	 */
	public function post_on_wall( Actor $actor, WP_User $owner, string $content ): int|WP_Error {
		$reason = $this->wall_error( $actor, $owner );
		if ( null !== $reason ) {
			return self::refused( $reason );
		}

		$valid = self::validate( $content );
		if ( $valid instanceof WP_Error ) {
			return $valid;
		}

		$id = $this->insert( self::WALL, $actor->user_id, $owner->ID, $content );
		Events::dispatch(
			'u_wall_post',
			array(
				'user_id'    => $owner->ID,
				'message_id' => $id,
				'content'    => $content,
				'format'     => '',
				'text'       => $content,
			),
			$actor
		);

		return $id;
	}

	/**
	 * Lists the posts on a wall, newest first.
	 *
	 * @since 0.1.0
	 *
	 * @param int $owner_id Owner of the wall.
	 * @param int $page     Page, starting at 1.
	 * @param int $per_page Posts per page.
	 * @return array{messages: list<Message>, total: int}
	 */
	public function wall( int $owner_id, int $page, int $per_page ): array {
		$wpdb  = DB::wpdb();
		$table = Schema::table( 'messages' );

		return array(
			'messages' => self::messages(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
				$wpdb->get_results(
					$wpdb->prepare( 'SELECT * FROM %i WHERE type = %s AND to_user_id = %d ORDER BY created DESC, id DESC LIMIT %d, %d', $table, self::WALL, $owner_id, ( max( 1, $page ) - 1 ) * $per_page, $per_page )
				)
			),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
			'total'    => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE type = %s AND to_user_id = %d', $table, self::WALL, $owner_id ) ),
		);
	}

	/**
	 * Checks whether an actor may delete a wall post, as `qa_wall_posts_add_rules()`: the owner
	 * of the wall, the author as long as nobody else posted after it, and moderators.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor   $actor Who wants to delete.
	 * @param Message $post  Wall post.
	 * @return bool
	 */
	public function can_delete_wall_post( Actor $actor, Message $post ): bool {
		if ( null === $actor->user_id || self::WALL !== $post->type ) {
			return false;
		}

		if ( $post->to_user_id === $actor->user_id ) {
			return true;
		}

		$permissions = Services::permissions();
		if ( null === $permissions->error( 'permit_hide_show', $actor ) && null === $permissions->error( 'permit_delete_hidden', $actor ) ) {
			return true;
		}

		if ( $post->from_user_id !== $actor->user_id ) {
			return false;
		}

		$wpdb = DB::wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
		return null === $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE type = %s AND to_user_id = %d AND ( from_user_id IS NULL OR from_user_id <> %d ) AND ( created > %s OR ( created = %s AND id > %d ) ) LIMIT 1',
				Schema::table( 'messages' ),
				self::WALL,
				(int) $post->to_user_id,
				$actor->user_id,
				$post->created,
				$post->created,
				$post->id
			)
		);
	}

	/**
	 * Deletes a wall post, as `qa_wall_delete_post()`.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $actor Who deletes it.
	 * @param int   $id    Message ID.
	 * @return Message|WP_Error The deleted post.
	 */
	public function delete_wall_post( Actor $actor, int $id ): Message|WP_Error {
		$post = $this->find( $id );
		if ( null === $post || self::WALL !== $post->type ) {
			return new WP_Error( 'easyq_not_found', __( 'This post does not exist.', 'easyq' ), array( 'status' => 404 ) );
		}

		if ( ! $this->can_delete_wall_post( $actor, $post ) ) {
			return new WP_Error( 'easyq_permission', __( 'You cannot delete this post.', 'easyq' ), array( 'status' => 403 ) );
		}

		$this->delete( $id );
		Events::dispatch(
			'u_wall_delete',
			array(
				'message_id' => $id,
				'old'        => $post,
			),
			$actor
		);

		return $post;
	}

	/**
	 * Sends a private message: stores it if the history is shown, and reports it, which emails it.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor   $actor   Sender.
	 * @param WP_User $to      Recipient.
	 * @param string  $content Text.
	 * @return int|null|WP_Error ID of the stored message; null if messages are not kept.
	 */
	public function send( Actor $actor, WP_User $to, string $content ): int|null|WP_Error {
		$reason = $this->message_error( $actor, $to );
		if ( null !== $reason ) {
			return self::refused( $reason );
		}

		$valid = self::validate( $content );
		if ( $valid instanceof WP_Error ) {
			return $valid;
		}

		$id = Options::get_bool( 'users', 'show_message_history' ) ? $this->insert( self::PRIVATE, $actor->user_id, $to->ID, $content ) : null;
		Events::dispatch(
			'u_message',
			array(
				'user_id'    => $to->ID,
				'message_id' => $id,
				'message'    => $content,
			),
			$actor
		);

		return $id;
	}

	/**
	 * Lists the received or sent private messages of a user that they did not delete, newest first.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $user_id  User ID.
	 * @param string $box      `inbox` or `sent`.
	 * @param int    $page     Page, starting at 1.
	 * @param int    $per_page Messages per page.
	 * @return array{messages: list<Message>, total: int}
	 */
	public function box( int $user_id, string $box, int $page, int $per_page ): array {
		$wpdb   = DB::wpdb();
		$table  = Schema::table( 'messages' );
		$column = 'sent' === $box ? 'from_user_id' : 'to_user_id';
		$hidden = 'sent' === $box ? 'from_hidden' : 'to_hidden';

		return array(
			'messages' => self::messages(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
				$wpdb->get_results(
					$wpdb->prepare( 'SELECT * FROM %i WHERE type = %s AND %i = %d AND %i = 0 ORDER BY created DESC, id DESC LIMIT %d, %d', $table, self::PRIVATE, $column, $user_id, $hidden, ( max( 1, $page ) - 1 ) * $per_page, $per_page )
				)
			),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
			'total'    => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE type = %s AND %i = %d AND %i = 0', $table, self::PRIVATE, $column, $user_id, $hidden ) ),
		);
	}

	/**
	 * Returns the latest private messages between two users that the first did not delete, oldest first.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id  User who reads.
	 * @param int $other_id The other user.
	 * @return list<Message>
	 */
	public function conversation( int $user_id, int $other_id ): array {
		$wpdb = DB::wpdb();

		return array_reverse(
			self::messages(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
				$wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE type = %s AND ( ( from_user_id = %d AND to_user_id = %d AND from_hidden = 0 ) OR ( from_user_id = %d AND to_user_id = %d AND to_hidden = 0 ) ) ORDER BY created DESC, id DESC LIMIT %d',
						Schema::table( 'messages' ),
						self::PRIVATE,
						$user_id,
						$other_id,
						$other_id,
						$user_id,
						self::CONVERSATION_LENGTH
					)
				)
			)
		);
	}

	/**
	 * Deletes a private message for one side, and for good once both deleted it, as `qa_pm_delete()`.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $actor Sender or recipient.
	 * @param int   $id    Message ID.
	 * @return Message|WP_Error The message.
	 */
	public function delete_private( Actor $actor, int $id ): Message|WP_Error {
		$message = $this->find( $id );
		if ( null === $message || self::PRIVATE !== $message->type || null === $actor->user_id
			|| ( $message->to_user_id !== $actor->user_id && $message->from_user_id !== $actor->user_id ) ) {
			return new WP_Error( 'easyq_not_found', __( 'This message does not exist.', 'easyq' ), array( 'status' => 404 ) );
		}

		$wpdb  = DB::wpdb();
		$table = Schema::table( 'messages' );
		$sides = array();
		if ( $message->to_user_id === $actor->user_id ) {
			$sides[] = 'to_hidden';
		}
		if ( $message->from_user_id === $actor->user_id ) {
			$sides[] = 'from_hidden';
		}
		foreach ( $sides as $side ) {
			$sql = $wpdb->prepare( 'UPDATE %i SET %i = 1 WHERE id = %d', $table, $side, $id );
			if ( null !== $sql ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom tables without a WordPress API.
				$wpdb->query( $sql );
			}
		}

		$sql = $wpdb->prepare( 'DELETE FROM %i WHERE id = %d AND from_hidden = 1 AND to_hidden = 1', $table, $id );
		if ( null !== $sql ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom tables without a WordPress API.
			$wpdb->query( $sql );
		}

		return $message;
	}

	/**
	 * Returns a message.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id Message ID.
	 * @return Message|null
	 */
	public function find( int $id ): ?Message {
		$wpdb = DB::wpdb();
		$row  = DB::row(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
			$wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'messages' ), $id ) )
		);

		return null === $row ? null : Message::from_row( $row );
	}

	/**
	 * Lists all messages a user wrote or received, for the personal data tools.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 * @return list<Message>
	 */
	public function all_of( int $user_id ): array {
		$wpdb = DB::wpdb();

		return self::messages(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
			$wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE from_user_id = %d OR to_user_id = %d ORDER BY created ASC, id ASC', Schema::table( 'messages' ), $user_id, $user_id )
			)
		);
	}

	/**
	 * Returns the message for a reason why something is not allowed.
	 *
	 * @since 0.1.0
	 *
	 * @param string $reason Reason.
	 * @return string
	 */
	public static function reason_message( string $reason ): string {
		return match ( $reason ) {
			'walls_off'        => __( 'User walls are turned off.', 'easyq' ),
			'wall_blocked'     => __( 'This user has turned off posts on their wall.', 'easyq' ),
			'messages_off'     => __( 'Private messages are turned off.', 'easyq' ),
			'messages_blocked' => __( 'This user does not take private messages.', 'easyq' ),
			'self'             => __( 'You cannot send a message to yourself.', 'easyq' ),
			'limit'            => __( 'You have reached the limit for this hour. Please try again later.', 'easyq' ),
			'login'            => __( 'Please log in first.', 'easyq' ),
			'confirm'          => __( 'Please confirm your email address first.', 'easyq' ),
			'approve'          => __( 'Your account must be approved first.', 'easyq' ),
			default            => __( 'Sorry, you are not allowed to do this.', 'easyq' ),
		};
	}

	/**
	 * Returns the error for a reason why something is not allowed, with the HTTP status for the REST API.
	 *
	 * @since 0.1.0
	 *
	 * @param string $reason Reason.
	 * @return WP_Error
	 */
	public static function refused( string $reason ): WP_Error {
		$status = match ( $reason ) {
			'login' => 401,
			'limit' => 429,
			default => 403,
		};

		return new WP_Error( 'easyq_' . $reason, self::reason_message( $reason ), array( 'status' => $status ) );
	}

	/**
	 * Checks the text of a message.
	 *
	 * @since 0.1.0
	 *
	 * @param string $content Text.
	 * @return true|WP_Error
	 */
	private static function validate( string $content ): bool|WP_Error {
		if ( '' === trim( $content ) ) {
			return new WP_Error(
				'easyq_empty',
				__( 'Please write a message.', 'easyq' ),
				array(
					'status' => 400,
					'fields' => array( 'content' => __( 'Please write a message.', 'easyq' ) ),
				)
			);
		}

		if ( mb_strlen( $content ) > self::MAX_LENGTH ) {
			/* translators: %d: number of characters. */
			$message = sprintf( __( 'Please shorten the message to %d characters.', 'easyq' ), self::MAX_LENGTH );

			return new WP_Error(
				'easyq_too_long',
				$message,
				array(
					'status' => 400,
					'fields' => array( 'content' => $message ),
				)
			);
		}

		return true;
	}

	/**
	 * Stores a message.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $type    Type.
	 * @param int|null $from    Author.
	 * @param int      $to      Recipient or wall owner.
	 * @param string   $content Text.
	 * @return int ID.
	 */
	private function insert( string $type, ?int $from, int $to, string $content ): int {
		$wpdb = DB::wpdb();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom tables without a WordPress API.
		$wpdb->insert(
			Schema::table( 'messages' ),
			array(
				'type'         => $type,
				'from_user_id' => $from,
				'to_user_id'   => $to,
				'content'      => $content,
				'format'       => '',
				'created'      => current_time( 'mysql', true ),
			)
		);

		return $wpdb->insert_id;
	}

	/**
	 * Deletes a message.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id Message ID.
	 */
	private function delete( int $id ): void {
		$wpdb = DB::wpdb();
		$sql  = $wpdb->prepare( 'DELETE FROM %i WHERE id = %d', Schema::table( 'messages' ), $id );
		if ( null !== $sql ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom tables without a WordPress API.
			$wpdb->query( $sql );
		}
	}

	/**
	 * Turns rows into messages.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $rows Rows of a query.
	 * @return list<Message>
	 */
	private static function messages( mixed $rows ): array {
		return array_values( array_map( array( Message::class, 'from_row' ), DB::rows( $rows ) ) );
	}
}
