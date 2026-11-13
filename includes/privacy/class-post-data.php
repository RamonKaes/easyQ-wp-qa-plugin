<?php
/**
 * Personal data of posts, votes and flags.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Privacy;

use EasyQ\Actor;
use EasyQ\Database\DB;
use EasyQ\Database\Row;
use EasyQ\Database\Schema;
use EasyQ\Frontend\URLs;
use EasyQ\Posts\Post;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Stats\Counts;
use EasyQ\Users\User_Events;
use EasyQ\Util\IP;

defined( 'ABSPATH' ) || exit;

/**
 * Exports and erases the questions, answers, comments, votes and flags of a person.
 *
 * Follows `wp_comments_personal_data_exporter()` and `wp_comments_personal_data_eraser()`:
 * posts are found by the account of the email address and, for posts without an
 * account, by the address entered for notifications; 500 at a time. Erasing keeps
 * the posts without the name of the author, shortens the IP addresses as core does
 * and removes the votes and flags as `qa_delete_user()` does. With the setting
 * `erase_posts`, posts nobody else replied to are deleted instead.
 *
 * @since 0.1.0
 */
final class Post_Data {

	/**
	 * Number of rows per page of the export or erasure, as in core.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const PER_PAGE = 500;

	/**
	 * Exports the posts a person wrote or changed last.
	 *
	 * @since 0.1.0
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, starting at 1.
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public static function export_posts( string $email, int $page = 1 ): array {
		$user_id = self::user_id( $email );
		$rows    = self::posts_of( $user_id, $email, ( max( 1, $page ) - 1 ) * self::PER_PAGE );
		$data    = array();

		foreach ( $rows as $post ) {
			$fields = array(
				array(
					'name'  => __( 'Kind', 'easyq' ),
					'value' => self::kind( $post ),
				),
			);
			if ( null !== $post->title && '' !== $post->title ) {
				$fields[] = array(
					'name'  => __( 'Title', 'easyq' ),
					'value' => $post->title,
				);
			}

			if ( self::is_author( $post, $user_id, $email ) ) {
				$fields[] = array(
					'name'  => __( 'Text', 'easyq' ),
					'value' => (string) $post->content,
				);
				$fields[] = array(
					'name'  => __( 'Date', 'easyq' ),
					'value' => $post->created . ' UTC',
				);
				if ( null !== $post->author_name && '' !== $post->author_name ) {
					$fields[] = array(
						'name'  => __( 'Name', 'easyq' ),
						'value' => $post->author_name,
					);
				}
				if ( null !== $post->notify && '@' !== $post->notify ) {
					$fields[] = array(
						'name'  => __( 'Email address for notifications', 'easyq' ),
						'value' => $post->notify,
					);
				}
				if ( null !== $post->created_ip ) {
					$fields[] = array(
						'name'  => __( 'IP address', 'easyq' ),
						'value' => (string) IP::from_binary( $post->created_ip ),
					);
				}
			}

			if ( self::changed_last( $post, $user_id, $email ) && null !== $post->updated ) {
				$fields[] = array(
					'name'  => __( 'Last change', 'easyq' ),
					'value' => $post->updated . ' UTC',
				);
				if ( null !== $post->last_ip ) {
					$fields[] = array(
						'name'  => __( 'IP address of the last change', 'easyq' ),
						'value' => (string) IP::from_binary( $post->last_ip ),
					);
				}
			}

			$url      = URLs::post( $post );
			$fields[] = array(
				'name'  => __( 'URL', 'easyq' ),
				'value' => sprintf( '<a href="%s" target="_blank">%s</a>', esc_url( $url ), esc_html( $url ) ),
			);

			$data[] = array(
				'group_id'          => 'easyq-posts',
				'group_label'       => __( 'Q&A posts', 'easyq' ),
				'group_description' => __( 'Questions, answers and comments you wrote or changed last.', 'easyq' ),
				'item_id'           => 'easyq-post-' . $post->id,
				'data'              => $fields,
			);
		}

		return array(
			'data' => $data,
			'done' => count( $rows ) < self::PER_PAGE,
		);
	}

	/**
	 * Erases the personal data of the posts a person wrote or changed last.
	 *
	 * Erased posts no longer match, so every page reads the first rows that still do.
	 *
	 * @since 0.1.0
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, as core passes it; not needed, see above.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public static function erase_posts( string $email, int $page = 1 ): array {
		unset( $page );
		$user_id  = self::user_id( $email );
		$rows     = self::posts_of( $user_id, $email, 0, replies_first: true );
		$delete   = 'delete' === Options::get_string( 'general', 'erase_posts' );
		$removed  = false;
		$retained = false;
		$messages = array();

		foreach ( $rows as $post ) {
			if ( $delete && self::is_author( $post, $user_id, $email ) && Post::NOTE !== $post->base_type() ) {
				if ( self::delete( $post ) ) {
					$removed = true;
					continue;
				}

				$retained = true;
				/* translators: %d: Post ID. */
				$messages[] = sprintf( __( 'Q&A post %d has replies by others; its author was removed instead.', 'easyq' ), $post->id );
			}

			self::anonymize( $post, $user_id, $email );
			$removed = true;
		}

		if ( $removed && $user_id > 0 ) {
			Services::points()->update_all( $user_id );
			Counts::invalidate( Counts::USERS_WITH_POINTS );
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => count( $rows ) < self::PER_PAGE,
		);
	}

	/**
	 * Exports the votes and flags of a person.
	 *
	 * @since 0.1.0
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, starting at 1.
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public static function export_votes( string $email, int $page = 1 ): array {
		$user_id = self::user_id( $email );
		$data    = array();
		$rows    = array();

		if ( $user_id > 0 ) {
			$wpdb = DB::wpdb();
			$rows = DB::rows(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
				$wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE user_id = %d AND (vote != 0 OR flag != 0) ORDER BY post_id LIMIT %d OFFSET %d',
						Schema::table( 'uservotes' ),
						$user_id,
						self::PER_PAGE,
						( max( 1, $page ) - 1 ) * self::PER_PAGE
					)
				)
			);
		}

		$posts = Services::posts()->find_many( array_map( static fn ( object $row ): int => Row::int( $row, 'post_id' ), $rows ) );
		foreach ( $rows as $row ) {
			$post_id = Row::int( $row, 'post_id' );
			$vote    = Row::int( $row, 'vote' );
			$fields  = array();
			if ( isset( $posts[ $post_id ] ) ) {
				$url      = URLs::post( $posts[ $post_id ] );
				$fields[] = array(
					'name'  => __( 'Post', 'easyq' ),
					'value' => sprintf( '<a href="%s" target="_blank">%s</a>', esc_url( $url ), esc_html( $url ) ),
				);
			}
			if ( 0 !== $vote ) {
				$fields[] = array(
					'name'  => __( 'Vote', 'easyq' ),
					'value' => $vote > 0 ? __( 'Up', 'easyq' ) : __( 'Down', 'easyq' ),
				);
			}
			if ( 0 !== Row::int( $row, 'flag' ) ) {
				$fields[] = array(
					'name'  => __( 'Flag', 'easyq' ),
					'value' => __( 'Flagged', 'easyq' ),
				);
			}
			$date = Row::nullable_string( $row, 'vote_updated' ) ?? Row::nullable_string( $row, 'vote_created' );
			if ( null !== $date ) {
				$fields[] = array(
					'name'  => __( 'Date of the vote', 'easyq' ),
					'value' => $date . ' UTC',
				);
			}

			$data[] = array(
				'group_id'          => 'easyq-votes',
				'group_label'       => __( 'Q&A votes and flags', 'easyq' ),
				'group_description' => __( 'Your votes on posts and the posts you flagged.', 'easyq' ),
				'item_id'           => 'easyq-vote-' . $post_id,
				'data'              => $fields,
			);
		}

		return array(
			'data' => $data,
			'done' => count( $rows ) < self::PER_PAGE,
		);
	}

	/**
	 * Erases the votes and flags of a person and recounts the posts and points they affect.
	 *
	 * @since 0.1.0
	 *
	 * @param string $email Email address.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public static function erase_votes( string $email ): array {
		$user_id = self::user_id( $email );

		return array(
			'items_removed'  => $user_id > 0 && ( new User_Events( Services::posts(), Services::points() ) )->remove_votes( $user_id ) > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Returns the ID of the account of an email address.
	 *
	 * @since 0.1.0
	 *
	 * @param string $email Email address.
	 * @return int User ID, or 0 if there is no account.
	 */
	private static function user_id( string $email ): int {
		$user = '' === $email ? false : get_user_by( 'email', $email );

		return false === $user ? 0 : $user->ID;
	}

	/**
	 * Returns the posts of a person: written or changed last with their account, or written without one under their address.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $user_id       User ID, or 0.
	 * @param string $email         Email address.
	 * @param int    $offset        Rows to skip.
	 * @param bool   $replies_first Whether comments come before answers and answers before questions,
	 *                              so that a post is deleted after its replies by the same person.
	 * @return list<Post>
	 */
	private static function posts_of( int $user_id, string $email, int $offset, bool $replies_first = false ): array {
		if ( '' === $email ) {
			return array();
		}

		$wpdb  = DB::wpdb();
		$table = Schema::table( 'posts' );
		$sql   = $replies_first
			? $wpdb->prepare( "SELECT * FROM %i WHERE user_id = %d OR last_user_id = %d OR (user_id IS NULL AND notify = %s) ORDER BY LEFT(type, 1) = 'C' DESC, LEFT(type, 1) = 'A' DESC, id DESC LIMIT %d OFFSET %d", $table, $user_id, $user_id, $email, self::PER_PAGE, $offset )
			: $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d OR last_user_id = %d OR (user_id IS NULL AND notify = %s) ORDER BY id LIMIT %d OFFSET %d', $table, $user_id, $user_id, $email, self::PER_PAGE, $offset );
		if ( null === $sql ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom tables without a WordPress API.
		return array_values( array_map( array( Post::class, 'from_row' ), DB::rows( $wpdb->get_results( $sql ) ) ) );
	}

	/**
	 * Checks whether a person wrote a post.
	 *
	 * @since 0.1.0
	 *
	 * @param Post   $post    Post.
	 * @param int    $user_id User ID, or 0.
	 * @param string $email   Email address.
	 * @return bool
	 */
	private static function is_author( Post $post, int $user_id, string $email ): bool {
		return null === $post->user_id ? $email === $post->notify : $user_id > 0 && $post->user_id === $user_id;
	}

	/**
	 * Checks whether a person made the last change of a post.
	 *
	 * Changes without an account have no user; they count for the author without an account.
	 *
	 * @since 0.1.0
	 *
	 * @param Post   $post    Post.
	 * @param int    $user_id User ID, or 0.
	 * @param string $email   Email address.
	 * @return bool
	 */
	private static function changed_last( Post $post, int $user_id, string $email ): bool {
		return null === $post->last_user_id ? null === $post->user_id && self::is_author( $post, $user_id, $email ) : $user_id > 0 && $post->last_user_id === $user_id;
	}

	/**
	 * Removes the person from a post: the author, their name and address, and shortens the IP addresses.
	 *
	 * @since 0.1.0
	 *
	 * @param Post   $post    Post.
	 * @param int    $user_id User ID, or 0.
	 * @param string $email   Email address.
	 */
	private static function anonymize( Post $post, int $user_id, string $email ): void {
		$columns = array();
		if ( self::is_author( $post, $user_id, $email ) ) {
			$columns = array(
				'user_id'     => null,
				'cookie_id'   => null,
				'author_name' => null,
				'notify'      => null,
				'created_ip'  => self::shorten( $post->created_ip ),
			);
		}
		if ( self::changed_last( $post, $user_id, $email ) ) {
			$columns['last_user_id'] = null;
			$columns['last_ip']      = self::shorten( $post->last_ip );
		}

		Services::posts()->update( $post->id, $columns );
	}

	/**
	 * Deletes a post as a moderator would: hides it, then deletes it if nobody else replied to it.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $post Post written by the person.
	 * @return bool Whether the post was deleted.
	 */
	private static function delete( Post $post ): bool {
		$posts   = Services::posts();
		$service = Services::post_service();
		$system  = new Actor();
		$note    = Post::QUESTION === $post->base_type() ? $posts->close_note_of( $post ) : null;
		foreach ( $posts->children( $post->id ) as $child ) {
			if ( $child->id !== $note?->id ) {
				return false;
			}
		}

		$question = $service->question_of( $post );
		$parent   = null === $post->parent_id ? null : $posts->find( $post->parent_id );
		if ( null === $question ) {
			return false;
		}

		if ( ! $post->is_hidden() ) {
			match ( $post->base_type() ) {
				Post::QUESTION => $service->set_question_status( $system, $post, Post::STATUS_HIDDEN ),
				Post::ANSWER   => $service->set_answer_status( $system, $post, $question, Post::STATUS_HIDDEN ),
				default        => null === $parent ? null : $service->set_comment_status( $system, $post, $question, $parent, Post::STATUS_HIDDEN ),
			};
		}

		$hidden   = $posts->find( $post->id );
		$question = $service->question_of( $post );
		if ( null === $hidden || null === $question ) {
			return false;
		}

		$result = match ( $hidden->base_type() ) {
			Post::QUESTION => $service->delete_question( $system, $hidden ),
			Post::ANSWER   => $service->delete_answer( $system, $hidden, $question ),
			default        => null === $parent ? false : $service->delete_comment( $system, $hidden, $question, $posts->find( $parent->id ) ?? $parent ),
		};

		return true === $result;
	}

	/**
	 * Shortens a stored IP address as `wp_privacy_anonymize_data()` does.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $binary Stored address, or null.
	 * @return string|null
	 */
	private static function shorten( ?string $binary ): ?string {
		$ip = IP::from_binary( $binary );

		return null === $ip ? null : IP::to_binary( IP::anonymize( $ip ) );
	}

	/**
	 * Returns the kind of a post for the export.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $post Post.
	 * @return string
	 */
	private static function kind( Post $post ): string {
		$kind = match ( $post->base_type() ) {
			Post::QUESTION => __( 'Question', 'easyq' ),
			Post::ANSWER   => __( 'Answer', 'easyq' ),
			Post::COMMENT  => __( 'Comment', 'easyq' ),
			default        => __( 'Reason for closing', 'easyq' ),
		};

		return match ( $post->status() ) {
			/* translators: %s: Kind of post, e.g. Question. */
			Post::STATUS_HIDDEN => sprintf( __( '%s (hidden)', 'easyq' ), $kind ),
			/* translators: %s: Kind of post, e.g. Question. */
			Post::STATUS_QUEUED => sprintf( __( '%s (awaiting approval)', 'easyq' ), $kind ),
			default             => $kind,
		};
	}
}
