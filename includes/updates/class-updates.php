<?php
/**
 * Event streams behind the updates page.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Updates;

use EasyQ\Actor;
use EasyQ\Categories\Categories;
use EasyQ\Database\DB;
use EasyQ\Database\Row;
use EasyQ\Database\Schema;
use EasyQ\Favorites\Favorites;
use EasyQ\Posts\Activity_Item;
use EasyQ\Posts\Post;
use EasyQ\Posts\Post_Repository;
use EasyQ\Search\Basic_Search;

defined( 'ABSPATH' ) || exit;

/**
 * Records what happens to questions in streams, for the users who follow them.
 *
 * Ported from Question2Answer `qa-include/plugins/qa-event-updates.php`, `app/events.php`,
 * `db/events.php` and `db/favorites.php`, whose comment explains the two kinds of stream:
 * every event goes into the shared stream of its entity, and is copied to the streams of
 * its followers until an entity has too many of them. Those then read the shared stream.
 *
 * @since 0.1.0
 */
final class Updates {

	/**
	 * Entity code of events about a user that follow no favorite.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PERSONAL = '-';

	/**
	 * Update type: a question was asked on an answer, or a comment followed another.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const FOLLOWS = 'F';

	/**
	 * Update type: a comment on a question.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const COMMENT_ON_QUESTION = 'U';

	/**
	 * Update type: a comment on an answer.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const COMMENT_ON_ANSWER = 'N';

	/**
	 * Events kept per question in a stream, as `QA_DB_MAX_EVENTS_PER_Q`.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const MAX_PER_QUESTION = 5;

	/**
	 * Events kept in a stream, as the Q2A option `max_store_user_updates`.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const MAX_STORED = 50;

	/**
	 * Option with the number of followers from which an entity uses its shared stream only,
	 * as the Q2A option `max_copy_user_updates`.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const THRESHOLD_OPTION = 'easyq_updates_threshold';

	/**
	 * Default of the threshold.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const DEFAULT_THRESHOLD = 10;

	/**
	 * Sets up the service.
	 *
	 * @since 0.1.0
	 *
	 * @param Post_Repository $posts      Post storage.
	 * @param Categories      $categories Categories.
	 */
	public function __construct(
		private readonly Post_Repository $posts,
		private readonly Categories $categories
	) {
	}

	/**
	 * Hooks the service into WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'easyq_event', array( $this, 'on_event' ), 10, 3 );
	}

	/**
	 * Records the events of an action, as the event module of Question2Answer does.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $event  Event name.
	 * @param array<string, mixed> $params Event parameters.
	 * @param Actor                $actor  Who caused it.
	 */
	public function on_event( string $event, array $params, Actor $actor ): void {
		$user    = $actor->user_id;
		$post_id = self::int( $params, 'post_id' );
		$old     = $params['old'] ?? null;
		$owner   = $old instanceof Post ? $old->user_id : null;

		switch ( $event ) {
			case 'q_post':
				$this->question_posted( $post_id, self::int( $params, 'parent_id' ), $user );
				break;

			case 'a_post':
				$parent = $this->posts->find( self::int( $params, 'parent_id' ) );
				$this->for_question_and_user( self::int( $params, 'parent_id' ), $post_id, null, $user, $parent?->user_id );
				break;

			case 'c_post':
				$this->comment_posted( $params, $post_id, $user );
				break;

			case 'q_edit':
				$type = null;
				if ( ! empty( $params['title_changed'] ) || ! empty( $params['content_changed'] ) ) {
					$type = Post::UPDATE_CONTENT;
				} elseif ( ! empty( $params['tags_changed'] ) ) {
					$type = Post::UPDATE_TAGS;
				}
				if ( null !== $type ) {
					$this->for_question_and_user( $post_id, $post_id, $type, $user, $owner );
					if ( ! empty( $params['tags_changed'] ) ) {
						$this->for_tags( $this->posts->find( $post_id ), Post::UPDATE_TAGS, $user );
					}
				}
				break;

			case 'a_select':
				$answer = $this->posts->find( $post_id );
				$this->for_question_and_user( self::int( $params, 'parent_id' ), $post_id, Post::UPDATE_SELECTED, $user, $answer?->user_id );
				break;

			case 'q_reopen':
			case 'q_close':
				$this->for_question_and_user( $post_id, $post_id, Post::UPDATE_CLOSED, $user, $owner );
				break;

			case 'q_hide':
				if ( null !== $owner ) {
					$this->personal( $owner, $post_id, $post_id, Post::UPDATE_VISIBLE, $user );
				}
				break;

			case 'q_reshow':
				$this->for_question_and_user( $post_id, $post_id, Post::UPDATE_VISIBLE, $user, $owner );
				break;

			case 'q_move':
				$this->for_question_and_user( $post_id, $post_id, Post::UPDATE_CATEGORY, $user, $owner );
				$this->for_category( self::int( $params, 'category_id' ), $post_id, Post::UPDATE_CATEGORY, $user );
				break;

			case 'a_edit':
				if ( ! empty( $params['content_changed'] ) ) {
					$this->for_question_and_user( self::int( $params, 'parent_id' ), $post_id, Post::UPDATE_CONTENT, $user, $owner );
				}
				break;

			case 'a_hide':
				if ( null !== $owner ) {
					$this->personal( $owner, self::int( $params, 'parent_id' ), $post_id, Post::UPDATE_VISIBLE, $user );
				}
				break;

			case 'a_reshow':
				$this->for_question_and_user( self::int( $params, 'parent_id' ), $post_id, Post::UPDATE_VISIBLE, $user, $owner );
				break;

			case 'c_edit':
				if ( ! empty( $params['content_changed'] ) ) {
					$this->for_question_and_user( self::int( $params, 'question_id' ), $post_id, Post::UPDATE_CONTENT, $user, $owner );
				}
				break;

			case 'a_to_c':
				$this->for_question_and_user( self::int( $params, 'question_id' ), $post_id, ! empty( $params['content_changed'] ) ? Post::UPDATE_CONTENT : Post::UPDATE_TYPE, $user, $owner );
				break;

			case 'c_hide':
				if ( null !== $owner ) {
					$this->personal( $owner, self::int( $params, 'question_id' ), $post_id, Post::UPDATE_VISIBLE, $user );
				}
				break;

			case 'c_reshow':
				$this->for_question_and_user( self::int( $params, 'question_id' ), $post_id, Post::UPDATE_VISIBLE, $user, $owner );
				break;

			case 'q_delete':
				$this->delete_where( 'question_id', $post_id );
				break;

			case 'a_delete':
			case 'c_delete':
				$this->delete_where( 'last_post_id', $post_id );
				break;

			case 'u_delete':
				$this->forget_user( self::int( $params, 'user_id' ) );
				break;

			case 'q_favorite':
				$this->favorite_added( (int) $user, Favorites::TYPES['question'], $post_id );
				break;
			case 'u_favorite':
				$this->favorite_added( (int) $user, Favorites::TYPES['user'], self::int( $params, 'user_id' ) );
				break;
			case 'tag_favorite':
				$this->favorite_added( (int) $user, Favorites::TYPES['tag'], self::int( $params, 'word_id' ) );
				break;
			case 'cat_favorite':
				$this->favorite_added( (int) $user, Favorites::TYPES['category'], self::int( $params, 'category_id' ) );
				break;

			case 'q_unfavorite':
				$this->favorite_removed( (int) $user, Favorites::TYPES['question'], $post_id );
				break;
			case 'u_unfavorite':
				$this->favorite_removed( (int) $user, Favorites::TYPES['user'], self::int( $params, 'user_id' ) );
				break;
			case 'tag_unfavorite':
				$this->favorite_removed( (int) $user, Favorites::TYPES['tag'], self::int( $params, 'word_id' ) );
				break;
			case 'cat_unfavorite':
				$this->favorite_removed( (int) $user, Favorites::TYPES['category'], self::int( $params, 'category_id' ) );
				break;
		}
	}

	/**
	 * Lists the updates of a user, the latest per question.
	 *
	 * @since 0.1.0
	 *
	 * @param int  $user_id   User ID.
	 * @param bool $favorites Whether to include updates of favorites.
	 * @param bool $content   Whether to include updates about the user's own content.
	 * @return list<Activity_Item>
	 */
	public function items( int $user_id, bool $favorites = true, bool $content = true ): array {
		$wpdb   = DB::wpdb();
		$events = Schema::table( 'userevents' );
		$shared = Schema::table( 'sharedevents' );
		$follow = Schema::table( 'userfavorites' );
		$posts  = Schema::table( 'posts' );

		// One fixed statement: the first condition picks the own stream, the union the shared streams.
		$rows = DB::rows(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
			$wpdb->get_results(
				$wpdb->prepare(
					"SELECT e.entity_type, e.question_id, e.last_post_id, e.update_type, e.last_user_id, e.updated, LEFT(p.type, 1) AS base_type
					FROM (
						SELECT id, entity_type, question_id, last_post_id, update_type, last_user_id, updated FROM %i
							WHERE user_id = %d AND ( ( %d = 1 AND entity_type <> %s ) OR ( %d = 1 AND entity_type = %s ) )
						UNION
						SELECT s.id, s.entity_type, s.question_id, s.last_post_id, s.update_type, s.last_user_id, s.updated FROM %i AS s
							JOIN %i AS f ON f.entity_type = s.entity_type AND f.entity_id = s.entity_id
							WHERE %d = 1 AND f.user_id = %d AND f.no_user_events > 0
					) AS e
					JOIN %i AS p ON p.id = e.last_post_id AND ( p.type IN ('Q', 'A', 'C') OR e.entity_type = %s )
					JOIN %i AS q ON q.id = e.question_id AND q.type IN ('Q', 'Q_HIDDEN')
						AND ( q.selected_child_id = e.last_post_id OR NOT e.update_type <=> %s )
					ORDER BY e.updated DESC, e.id DESC",
					$events,
					$user_id,
					$favorites ? 1 : 0,
					self::PERSONAL,
					$content ? 1 : 0,
					self::PERSONAL,
					$shared,
					$follow,
					$favorites ? 1 : 0,
					$user_id,
					$posts,
					self::PERSONAL,
					$posts,
					Post::UPDATE_SELECTED
				)
			)
		);

		return self::latest_per_question( $rows );
	}

	/**
	 * Keeps one event per question, as `qa_any_sort_and_dedupe()` does: the latest, unless an
	 * edit just after a post or a personal event just before it says more.
	 *
	 * @since 0.1.0
	 *
	 * @param array<object> $rows Events, newest first.
	 * @return list<Activity_Item>
	 */
	private static function latest_per_question( array $rows ): array {
		$kept = array();
		foreach ( $rows as $row ) {
			$item = new Activity_Item(
				Row::int( $row, 'question_id' ),
				Row::int( $row, 'last_post_id' ),
				Row::string( $row, 'base_type' ),
				Row::string( $row, 'updated' ),
				Row::nullable_string( $row, 'update_type' ),
				Row::nullable_int( $row, 'last_user_id' ),
				self::PERSONAL === Row::string( $row, 'entity_type' )
			);

			$later = $kept[ $item->question_id ] ?? null;
			if ( null === $later ) {
				$kept[ $item->question_id ] = $item;
				continue;
			}

			$close      = abs( strtotime( $later->time ) - strtotime( $item->time ) ) < 5 * MINUTE_IN_SECONDS;
			$later_edit = null !== $later->update_type && null === $item->update_type && $later->base_type === $item->base_type && $later->user_id === $item->user_id;
			if ( $close && ( $later_edit || ( $item->personal && ! $later->personal ) ) ) {
				unset( $kept[ $item->question_id ] );
				$kept[ $item->question_id ] = $item;
			}
		}

		return array_values( $kept );
	}

	/**
	 * Deletes all streams, before they are filled again.
	 *
	 * @since 0.1.0
	 */
	public function truncate(): void {
		$wpdb = DB::wpdb();
		foreach ( array( 'userevents', 'sharedevents' ) as $table ) {
			$sql = $wpdb->prepare( 'DELETE FROM %i', Schema::table( $table ) );
			if ( null !== $sql ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom tables without a WordPress API.
				$wpdb->query( $sql );
			}
		}
	}

	/**
	 * Fills the streams from what is known about a question, as `dorefillevents_refill` in Q2A.
	 *
	 * @since 0.1.0
	 *
	 * @param int $question_id Question ID.
	 */
	public function refill( int $question_id ): void {
		$question = $this->posts->find( $question_id );
		if ( null === $question ) {
			return;
		}

		$posts = array( $question_id => $question ) + $this->posts->children( $question_id );
		foreach ( $this->posts->children( $question_id ) as $child ) {
			$posts += $this->posts->children( $child->id );
		}
		ksort( $posts );

		foreach ( $posts as $id => $post ) {
			if ( ! in_array( $post->base_type(), array( Post::QUESTION, Post::ANSWER, Post::COMMENT ), true ) ) {
				continue;
			}

			$follow_on = Post::QUESTION === $post->base_type() && $id !== $question_id;
			$parent    = null === $post->parent_id ? null : ( $posts[ $post->parent_id ] ?? null );
			$type      = match ( true ) {
				$follow_on => self::FOLLOWS,
				Post::COMMENT === $post->base_type() && Post::QUESTION === $parent?->base_type() => self::COMMENT_ON_QUESTION,
				Post::COMMENT === $post->base_type() && Post::ANSWER === $parent?->base_type() => self::COMMENT_ON_ANSWER,
				default    => null,
			};

			$this->for_question_and_user( $question_id, $id, $type, $post->user_id, $parent?->user_id, $post->created );
			if ( null !== $post->updated && ! $follow_on ) {
				$this->for_question_and_user( $question_id, $id, $post->update_type, $post->last_user_id, $post->user_id, $post->updated );
			}
		}

		$this->for_tags( $question, null, $question->user_id, $question->created );
		$this->for_category( (int) $question->category_id, $question_id, null, $question->user_id, $question->created );

		// Everyone who commented before hears of the comments after theirs.
		foreach ( $posts as $parent_id => $parent ) {
			$earlier = array();
			foreach ( $posts as $id => $comment ) {
				if ( Post::COMMENT !== $comment->base_type() || $comment->parent_id !== $parent_id ) {
					continue;
				}
				foreach ( array_keys( $earlier ) as $user ) {
					if ( $user !== $comment->user_id && $user !== $parent->user_id ) {
						$this->personal( $user, $question_id, $id, self::FOLLOWS, $comment->user_id, $comment->created );
					}
				}
				if ( null !== $comment->user_id ) {
					$earlier[ $comment->user_id ] = true;
				}
			}
		}
	}

	/**
	 * Removes a user from the streams: their own, and as who caused events in others.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 * @return int Number of events changed.
	 */
	public function forget_user( int $user_id ): int {
		$wpdb    = DB::wpdb();
		$changed = 0;
		foreach (
			array(
				$wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d', Schema::table( 'userevents' ), $user_id ),
				$wpdb->prepare( 'UPDATE %i SET last_user_id = NULL WHERE last_user_id = %d', Schema::table( 'userevents' ), $user_id ),
				$wpdb->prepare( 'UPDATE %i SET last_user_id = NULL WHERE last_user_id = %d', Schema::table( 'sharedevents' ), $user_id ),
			) as $sql
		) {
			if ( null !== $sql ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom tables without a WordPress API.
				$changed += (int) $wpdb->query( $sql );
			}
		}

		return $changed;
	}

	/**
	 * Records a new question with what it follows, its tags and its category.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $post_id   Question ID.
	 * @param int      $answer_id Answer it follows, or 0.
	 * @param int|null $user      Who asked.
	 */
	private function question_posted( int $post_id, int $answer_id, ?int $user ): void {
		$answer = 0 === $answer_id ? null : $this->posts->find( $answer_id );
		if ( null !== $answer && null !== $answer->parent_id ) {
			$this->for_question_and_user( $answer->parent_id, $post_id, self::FOLLOWS, $user, $answer->user_id );
		}

		$question = $this->posts->find( $post_id );
		$this->for_question_and_user( $post_id, $post_id, null, $user );
		$this->for_tags( $question, null, $user );
		$this->for_category( (int) $question?->category_id, $post_id, null, $user );
	}

	/**
	 * Records a new comment for those who commented before, and for its question and parent.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $params  Event parameters.
	 * @param int                  $post_id Comment ID.
	 * @param int|null             $user    Who commented.
	 */
	private function comment_posted( array $params, int $post_id, ?int $user ): void {
		$question = self::int( $params, 'question_id' );
		$earlier  = array();
		foreach ( $this->posts->find_many( is_array( $params['thread'] ?? null ) ? array_map( 'intval', array_filter( $params['thread'], 'is_numeric' ) ) : array() ) as $comment ) {
			if ( null !== $comment->user_id ) {
				$earlier[ $comment->user_id ] = true;
			}
		}

		foreach ( array_keys( $earlier ) as $earlier_user ) {
			if ( $earlier_user !== $user ) {
				$this->personal( $earlier_user, $question, $post_id, self::FOLLOWS, $user );
			}
		}

		$parent = $this->posts->find( self::int( $params, 'parent_id' ) );
		$type   = match ( $parent?->base_type() ) {
			Post::QUESTION => self::COMMENT_ON_QUESTION,
			Post::ANSWER   => self::COMMENT_ON_ANSWER,
			default        => null,
		};

		// "Your comment was followed" goes before "your post was commented on".
		$this->for_question_and_user( $question, $post_id, $type, $user, isset( $earlier[ (int) $parent?->user_id ] ) ? null : $parent?->user_id );
	}

	/**
	 * Adds an event to the streams of a question and its user, and for another user.
	 *
	 * @since 0.1.0
	 *
	 * @param int         $question_id Question.
	 * @param int         $post_id     Post it happened to.
	 * @param string|null $type        Update type.
	 * @param int|null    $user        Who did it.
	 * @param int|null    $other       Other user to tell, unless they did it.
	 * @param string|null $time        Time; now by default.
	 */
	private function for_question_and_user( int $question_id, int $post_id, ?string $type, ?int $user, ?int $other = null, ?string $time = null ): void {
		$this->for_entity( Favorites::TYPES['question'], $question_id, $question_id, $post_id, $type, $user, $time );
		if ( null !== $user ) {
			$this->for_entity( Favorites::TYPES['user'], $user, $question_id, $post_id, $type, $user, $time );
		}
		if ( null !== $other && $other !== $user ) {
			$this->personal( $other, $question_id, $post_id, $type, $user, $time );
		}
	}

	/**
	 * Adds an event to the streams of the tags of a question.
	 *
	 * @since 0.1.0
	 *
	 * @param Post|null   $question Question.
	 * @param string|null $type     Update type.
	 * @param int|null    $user     Who did it.
	 * @param string|null $time     Time; now by default.
	 */
	private function for_tags( ?Post $question, ?string $type, ?int $user, ?string $time = null ): void {
		$tags = null === $question ? array() : $question->tag_list();
		if ( array() === $tags || null === $question ) {
			return;
		}

		foreach ( ( new Basic_Search() )->word_ids( $tags, create: false ) as $word_id ) {
			$this->for_entity( Favorites::TYPES['tag'], $word_id, $question->id, $question->id, $type, $user, $time );
		}
	}

	/**
	 * Adds an event to the streams of a category and its ancestors.
	 *
	 * @since 0.1.0
	 *
	 * @param int         $category_id Category, or 0.
	 * @param int         $question_id Question.
	 * @param string|null $type        Update type.
	 * @param int|null    $user        Who did it.
	 * @param string|null $time        Time; now by default.
	 */
	private function for_category( int $category_id, int $question_id, ?string $type, ?int $user, ?string $time = null ): void {
		if ( 0 === $category_id ) {
			return;
		}

		foreach ( $this->categories->path( $category_id ) as $category ) {
			$this->for_entity( Favorites::TYPES['category'], $category->id, $question_id, $question_id, $type, $user, $time );
		}
	}

	/**
	 * Adds an event to the shared stream of an entity and to the streams of its followers.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $code        Entity code.
	 * @param int         $entity      Entity ID.
	 * @param int         $question_id Question.
	 * @param int         $post_id     Post it happened to.
	 * @param string|null $type        Update type.
	 * @param int|null    $user        Who did it.
	 * @param string|null $time        Time; now by default.
	 */
	private function for_entity( string $code, int $entity, int $question_id, int $post_id, ?string $type, ?int $user, ?string $time ): void {
		$wpdb    = DB::wpdb();
		$shared  = Schema::table( 'sharedevents' );
		$follow  = Schema::table( 'userfavorites' );
		$time    = $time ?? current_time( 'mysql', true );
		$columns = array(
			'entity_type'  => $code,
			'entity_id'    => $entity,
			'question_id'  => $question_id,
			'last_post_id' => $post_id,
			'update_type'  => $type,
			'last_user_id' => $user,
			'updated'      => $time,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom tables without a WordPress API.
		$wpdb->insert( $shared, $columns );

		$by_question = Favorites::TYPES['question'] === $code && $this->trim(
			$shared,
			array(
				'entity_type' => $code,
				'entity_id'   => $entity,
				'question_id' => $question_id,
			),
			self::MAX_PER_QUESTION
		);
		if ( ! $by_question ) {
			$this->trim(
				$shared,
				array(
					'entity_type' => $code,
					'entity_id'   => $entity,
				),
				self::MAX_STORED
			);
		}

		$followers = DB::int_col(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
			$wpdb->get_col(
				$wpdb->prepare( 'SELECT user_id FROM %i WHERE entity_type = %s AND entity_id = %d AND no_user_events = 0', $follow, $code, $entity )
			)
		);
		foreach ( $followers as $follower ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom tables without a WordPress API.
			$wpdb->insert( Schema::table( 'userevents' ), array( 'user_id' => $follower ) + $columns );
			$this->trim_user( $follower, $question_id );
		}
	}

	/**
	 * Adds an event to the stream of one user, without a favorite.
	 *
	 * @since 0.1.0
	 *
	 * @param int         $user_id     User to tell.
	 * @param int         $question_id Question.
	 * @param int         $post_id     Post it happened to.
	 * @param string|null $type        Update type.
	 * @param int|null    $by          Who did it.
	 * @param string|null $time        Time; now by default.
	 */
	private function personal( int $user_id, int $question_id, int $post_id, ?string $type, ?int $by, ?string $time = null ): void {
		$wpdb = DB::wpdb();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom tables without a WordPress API.
		$wpdb->insert(
			Schema::table( 'userevents' ),
			array(
				'user_id'      => $user_id,
				'entity_type'  => self::PERSONAL,
				'entity_id'    => 0,
				'question_id'  => $question_id,
				'last_post_id' => $post_id,
				'update_type'  => $type,
				'last_user_id' => $by,
				'updated'      => $time ?? current_time( 'mysql', true ),
			)
		);
		$this->trim_user( $user_id, $question_id );
	}

	/**
	 * Keeps the stream of a user within its limits.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $user_id     User ID.
	 * @param int|null $question_id Question an event was added for.
	 */
	private function trim_user( int $user_id, ?int $question_id = null ): void {
		$table = Schema::table( 'userevents' );
		if ( null !== $question_id && $this->trim(
			$table,
			array(
				'user_id'     => $user_id,
				'question_id' => $question_id,
			),
			self::MAX_PER_QUESTION
		) ) {
			return;
		}

		$this->trim( $table, array( 'user_id' => $user_id ), self::MAX_STORED );
	}

	/**
	 * Deletes the oldest rows of a stream beyond a number. Unlike Q2A, by ID as well as by time:
	 * events of the same second would otherwise go together.
	 *
	 * @since 0.1.0
	 *
	 * @param string                    $table  Table.
	 * @param array<string, int|string> $where  Columns that select the stream.
	 * @param int                       $keep   Rows to keep.
	 * @return bool Whether rows were deleted.
	 */
	private function trim( string $table, array $where, int $keep ): bool {
		$wpdb       = DB::wpdb();
		$conditions = array();
		$values     = array( $table );
		foreach ( $where as $column => $value ) {
			$conditions[] = '%i = ' . ( is_int( $value ) ? '%d' : '%s' );
			$values[]     = $column;
			$values[]     = $value;
		}
		$condition = implode( ' AND ', $conditions );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- The condition holds placeholders only.
		$first = DB::row(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
			$wpdb->get_row(
				$wpdb->prepare( "SELECT id, updated FROM %i WHERE $condition ORDER BY updated DESC, id DESC LIMIT %d, 1", array_merge( $values, array( $keep ) ) )
			)
		);
		if ( null === $first ) {
			return false;
		}

		$sql = $wpdb->prepare(
			"DELETE FROM %i WHERE $condition AND ( updated < %s OR ( updated = %s AND id <= %d ) )",
			array_merge( $values, array( Row::string( $first, 'updated' ), Row::string( $first, 'updated' ), Row::int( $first, 'id' ) ) )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
		if ( null !== $sql ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom tables without a WordPress API.
			$wpdb->query( $sql );
		}

		return true;
	}

	/**
	 * Moves an entity with many followers to its shared stream, or copies its recent
	 * events to the stream of the new follower, as `qa_db_favorite_create()` does.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $user_id User ID.
	 * @param string $code    Entity code.
	 * @param int    $entity  Entity ID.
	 */
	private function favorite_added( int $user_id, string $code, int $entity ): void {
		$wpdb      = DB::wpdb();
		$follow    = Schema::table( 'userfavorites' );
		$option    = get_option( self::THRESHOLD_OPTION, self::DEFAULT_THRESHOLD );
		$threshold = is_numeric( $option ) ? (int) $option : self::DEFAULT_THRESHOLD;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
		$shared = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE entity_type = %s AND entity_id = %d AND no_user_events > 0', $follow, $code, $entity ) ) > 0;
		if ( ! $shared ) {
			$shared = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE entity_type = %s AND entity_id = %d', $follow, $code, $entity ) ) >= $threshold;
		}

		if ( $shared ) {
			// The most other shared streams any follower has, to keep reading them cheap.
			$most = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT MAX(c) FROM (SELECT COUNT(*) AS c FROM %i AS shared JOIN %i AS unshared ON shared.user_id = unshared.user_id WHERE shared.no_user_events > 0 AND unshared.entity_type = %s AND unshared.entity_id = %d AND unshared.no_user_events = 0 GROUP BY shared.user_id) AS counts',
					$follow,
					$follow,
					$code,
					$entity
				)
			);
			if ( $most + 1 > $threshold ) {
				update_option( self::THRESHOLD_OPTION, $most + 1, false );
			}

			$sql = $wpdb->prepare( 'UPDATE %i SET no_user_events = 1 WHERE entity_type = %s AND entity_id = %d AND no_user_events = 0', $follow, $code, $entity );
		} else {
			$sql = $wpdb->prepare(
				'INSERT INTO %i (user_id, entity_type, entity_id, question_id, last_post_id, update_type, last_user_id, updated) SELECT %d, entity_type, entity_id, question_id, last_post_id, update_type, last_user_id, updated FROM %i WHERE entity_type = %s AND entity_id = %d',
				Schema::table( 'userevents' ),
				$user_id,
				Schema::table( 'sharedevents' ),
				$code,
				$entity
			);
		}
		if ( null !== $sql ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
			$wpdb->query( $sql );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! $shared ) {
			$this->trim_user( $user_id );
		}
	}

	/**
	 * Removes the events of a former favorite from the stream of a user.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $user_id User ID.
	 * @param string $code    Entity code.
	 * @param int    $entity  Entity ID.
	 */
	private function favorite_removed( int $user_id, string $code, int $entity ): void {
		$wpdb = DB::wpdb();
		$sql  = $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d AND entity_type = %s AND entity_id = %d', Schema::table( 'userevents' ), $user_id, $code, $entity );
		if ( null !== $sql ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom tables without a WordPress API.
			$wpdb->query( $sql );
		}
	}

	/**
	 * Deletes the events of a deleted post.
	 *
	 * @since 0.1.0
	 *
	 * @param string $column `question_id` or `last_post_id`.
	 * @param int    $id     Post ID.
	 */
	private function delete_where( string $column, int $id ): void {
		$wpdb = DB::wpdb();
		foreach ( array( 'userevents', 'sharedevents' ) as $table ) {
			$sql = $wpdb->prepare( 'DELETE FROM %i WHERE %i = %d', Schema::table( $table ), $column, $id );
			if ( null !== $sql ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom tables without a WordPress API.
				$wpdb->query( $sql );
			}
		}
	}

	/**
	 * Returns an integer event parameter.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $params Event parameters.
	 * @param string               $key    Name.
	 * @return int 0 if it is not set.
	 */
	private static function int( array $params, string $key ): int {
		return is_numeric( $params[ $key ] ?? null ) ? (int) $params[ $key ] : 0;
	}
}
