<?php
/**
 * Post storage.
 *
 * @package easyQ
 */

namespace EasyQ\Posts;

use EasyQ\Actor;
use EasyQ\Database\Db;
use EasyQ\Database\Schema;
use EasyQ\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes rows of the posts table and keeps its cached columns up to date.
 *
 * Ported from Question2Answer `qa-include/db/post-create.php`, `db/post-update.php`,
 * `db/votes.php` and `db/hotness.php`.
 */
final class Post_Repository {

	/**
	 * Object cache group for posts.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'easyq_posts';

	/**
	 * Returns a post.
	 *
	 * @param int $id Post ID.
	 * @return Post|null
	 */
	public function find( int $id ): ?Post {
		$cached = wp_cache_get( $id, self::CACHE_GROUP );
		if ( $cached instanceof Post ) {
			return $cached;
		}

		$row = Db::get_row( Db::wpdb()->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'posts' ), $id ) );
		if ( null === $row ) {
			return null;
		}

		$post = Post::from_row( $row );
		wp_cache_set( $id, $post, self::CACHE_GROUP );

		return $post;
	}

	/**
	 * Returns several posts.
	 *
	 * @param int[] $ids Post IDs.
	 * @return array<int, Post> Posts keyed by ID, in ID order.
	 */
	public function find_many( array $ids ): array {
		$ids    = array_values( array_unique( array_map( 'intval', $ids ) ) );
		$posts  = array();
		$cached = array() === $ids ? array() : wp_cache_get_multiple( $ids, self::CACHE_GROUP );
		foreach ( $cached as $id => $post ) {
			if ( $post instanceof Post ) {
				$posts[ (int) $id ] = $post;
			}
		}

		$missing = array_values( array_diff( $ids, array_keys( $posts ) ) );
		if ( array() !== $missing ) {
			$rows = Db::get_results(
				Db::wpdb()->prepare(
					'SELECT * FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $missing ), '%d' ) ) . ')',
					array_merge( array( Schema::table( 'posts' ) ), $missing )
				)
			);

			foreach ( $this->to_posts( $rows ) as $id => $post ) {
				wp_cache_set( $id, $post, self::CACHE_GROUP );
				$posts[ $id ] = $post;
			}
		}

		ksort( $posts );

		return $posts;
	}

	/**
	 * Returns the direct children of a post.
	 *
	 * @param int $parent_id Parent post ID.
	 * @return array<int, Post> Posts keyed by ID, in ID order.
	 */
	public function children( int $parent_id ): array {
		$rows = Db::get_results(
			Db::wpdb()->prepare( 'SELECT * FROM %i WHERE parent_id = %d ORDER BY id', Schema::table( 'posts' ), $parent_id )
		);

		return $this->to_posts( $rows );
	}

	/**
	 * Returns all answers of a question, whatever their status.
	 *
	 * @param int $question_id Question ID.
	 * @return array<int, Post> Answers keyed by ID.
	 */
	public function answers_of( int $question_id ): array {
		return array_filter(
			$this->children( $question_id ),
			static fn ( Post $post ): bool => Post::ANSWER === $post->base_type()
		);
	}

	/**
	 * Returns comments and follow-on questions of a question and of its answers, whatever their status.
	 *
	 * @param int $question_id Question ID.
	 * @return array<int, Post> Posts keyed by ID.
	 */
	public function comments_and_follows_of( int $question_id ): array {
		$parent_ids = array_merge( array( $question_id ), array_keys( $this->answers_of( $question_id ) ) );
		$wpdb       = Db::wpdb();

		$rows = Db::get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE parent_id IN (' . implode( ',', array_fill( 0, count( $parent_ids ), '%d' ) ) . ") AND LEFT(type, 1) IN ('C', 'Q') ORDER BY id",
				array_merge( array( Schema::table( 'posts' ) ), $parent_ids )
			)
		);

		return $this->to_posts( $rows );
	}

	/**
	 * Returns the note a question was closed with, if it was closed for another reason than a duplicate.
	 *
	 * @param Post $question Question.
	 * @return Post|null
	 */
	public function close_note_of( Post $question ): ?Post {
		if ( null === $question->closed_by_id ) {
			return null;
		}

		$note = $this->find( $question->closed_by_id );

		return null !== $note && Post::NOTE === $note->type && $note->parent_id === $question->id ? $note : null;
	}

	/**
	 * Inserts a post.
	 *
	 * @param string      $type        Stored type, e.g. `Q` or `A_QUEUED`.
	 * @param int|null    $parent_id   Parent post ID.
	 * @param int|null    $category_id Category ID.
	 * @param Actor       $actor       Author.
	 * @param string|null $title       Title.
	 * @param string      $content     Content.
	 * @param string      $format      Content format.
	 * @param string|null $tags        Comma-separated tags.
	 * @param string|null $notify      Notification address.
	 * @param string|null $author_name Name of an anonymous author.
	 * @return int The new post ID, 0 on failure.
	 */
	public function insert( string $type, ?int $parent_id, ?int $category_id, Actor $actor, ?string $title, string $content, string $format, ?string $tags, ?string $notify, ?string $author_name ): int {
		$is_user = null !== $actor->user_id;

		$id = Db::insert(
			Schema::table( 'posts' ),
			array(
				'type'        => $type,
				'parent_id'   => $parent_id,
				'category_id' => $category_id,
				'user_id'     => $actor->user_id,
				'cookie_id'   => $is_user ? null : $actor->cookie_id,
				'created_ip'  => $actor->stored_ip(),
				'title'       => $title,
				'content'     => $content,
				'format'      => $format,
				'tags'        => $tags,
				'notify'      => $notify,
				'author_name' => $is_user ? null : $author_name,
				'created'     => Db::now(),
			),
			array( '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( $id > 0 ) {
			$this->calc_category_path( $id );
		}

		return $id;
	}

	/**
	 * Changes columns of a post and records who changed it, if an actor is given.
	 *
	 * @param int                  $id          Post ID.
	 * @param array<string, mixed> $columns     New column values.
	 * @param Actor|null           $actor       Who made the change, or null to leave the change metadata untouched.
	 * @param string|null          $update_type Kind of change, see the Post::UPDATE_* constants; required with an actor.
	 */
	public function update( int $id, array $columns, ?Actor $actor = null, ?string $update_type = null ): void {
		if ( null !== $actor ) {
			$columns['updated']      = Db::now();
			$columns['update_type']  = $update_type;
			$columns['last_user_id'] = $actor->user_id;
			$columns['last_ip']      = $actor->stored_ip();
		}

		if ( array() === $columns ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table; the cache is cleared below.
		Db::wpdb()->update( Schema::table( 'posts' ), $columns, array( 'id' => $id ) );
		$this->forget( $id );
	}

	/**
	 * Sets the selected answer of a question.
	 *
	 * The previous answer loses its "selected" change metadata; the new answer records who selected it.
	 *
	 * @param Post       $question    Question.
	 * @param int|null   $selected_id Answer ID, or null to clear the selection.
	 * @param Actor|null $actor       Who selected the answer.
	 */
	public function set_selected_answer( Post $question, ?int $selected_id, ?Actor $actor ): void {
		$wpdb  = Db::wpdb();
		$table = Schema::table( 'posts' );

		if ( null !== $question->selected_child_id ) {
			Db::query(
				$wpdb->prepare(
					'UPDATE %i SET updated = NULL, update_type = NULL, last_user_id = NULL, last_ip = NULL WHERE id = %d AND update_type = %s',
					$table,
					$question->selected_child_id,
					Post::UPDATE_SELECTED
				)
			);
			$this->forget( $question->selected_child_id );
		}

		$this->update( $question->id, array( 'selected_child_id' => $selected_id ) );

		if ( null !== $selected_id && null !== $actor ) {
			$this->update( $selected_id, array(), $actor, Post::UPDATE_SELECTED );
		}
	}

	/**
	 * Deletes a post together with its votes, flags and meta data.
	 *
	 * The search index must be cleared before, see Search\Index::unindex_post().
	 *
	 * @param int $id Post ID.
	 */
	public function delete( int $id ): void {
		$wpdb = Db::wpdb();

		Db::query( $wpdb->prepare( 'DELETE FROM %i WHERE post_id = %d', Schema::table( 'uservotes' ), $id ) );
		Schema::delete_all_meta( 'easyq_post', $id );
		Db::query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d', Schema::table( 'posts' ), $id ) );

		$this->forget( $id );
	}

	/**
	 * Recalculates the category path columns of a range of posts from their category.
	 *
	 * @param int      $first_id First post ID.
	 * @param int|null $last_id  Last post ID, or null for a single post.
	 */
	public function calc_category_path( int $first_id, ?int $last_id = null ): void {
		$last_id    = $last_id ?? $first_id;
		$posts      = Schema::table( 'posts' );
		$categories = Schema::table( 'categories' );

		Db::query(
			Db::wpdb()->prepare(
				'UPDATE %i AS x, (SELECT p.id, '
				. 'COALESCE(c2.parent_id, c1.parent_id, c0.parent_id, c0.id) AS path_1, '
				. 'IF(c2.parent_id IS NOT NULL, c1.parent_id, IF(c1.parent_id IS NOT NULL, c0.parent_id, IF(c0.parent_id IS NOT NULL, c0.id, NULL))) AS path_2, '
				. 'IF(c2.parent_id IS NOT NULL, c0.parent_id, IF(c1.parent_id IS NOT NULL, c0.id, NULL)) AS path_3 '
				. 'FROM %i AS p LEFT JOIN %i AS c0 ON p.category_id = c0.id LEFT JOIN %i AS c1 ON c0.parent_id = c1.id LEFT JOIN %i AS c2 ON c1.parent_id = c2.id '
				. 'WHERE p.id BETWEEN %d AND %d) AS a '
				. 'SET x.category_path_1 = a.path_1, x.category_path_2 = a.path_2, x.category_path_3 = a.path_3 WHERE x.id = a.id',
				$posts,
				$posts,
				$categories,
				$categories,
				$categories,
				$first_id,
				$last_id
			)
		);

		$this->forget_range( $first_id, $last_id );
	}

	/**
	 * Recalculates the category path columns of all posts in the given categories.
	 *
	 * @param int[] $category_ids Category IDs.
	 */
	public function calc_category_paths_in( array $category_ids ): void {
		if ( array() === $category_ids ) {
			return;
		}

		$wpdb     = Db::wpdb();
		$post_ids = Db::get_int_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE category_id IN (' . implode( ',', array_fill( 0, count( $category_ids ), '%d' ) ) . ')',
				array_merge( array( Schema::table( 'posts' ) ), $category_ids )
			)
		);

		foreach ( $post_ids as $post_id ) {
			$this->calc_category_path( $post_id );
		}
	}

	/**
	 * Returns the category and its ancestors of a post, from the category itself upwards.
	 *
	 * @param int $id Post ID.
	 * @return int[] Category IDs; empty if the post has no category.
	 */
	public function category_ids_of( int $id ): array {
		$post = $this->find( $id );
		if ( null === $post || null === $post->category_id ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_merge( array( $post->category_id ), $post->category_path ) ) ) );
	}

	/**
	 * Copies category and category path of a question to other posts, e.g. its answers and comments.
	 *
	 * @param int[] $ids      Post IDs.
	 * @param Post  $question Question with the new category.
	 */
	public function copy_category( array $ids, Post $question ): void {
		foreach ( $ids as $id ) {
			$this->update(
				$id,
				array(
					'category_id'     => $question->category_id,
					'category_path_1' => $question->category_path[0],
					'category_path_2' => $question->category_path[1],
					'category_path_3' => $question->category_path[2],
				)
			);
		}
	}

	/**
	 * Recalculates the answer count and the highest answer vote of a question.
	 *
	 * @param int $question_id Question ID.
	 */
	public function recount_answers( int $question_id ): void {
		$table = Schema::table( 'posts' );

		Db::query(
			Db::wpdb()->prepare(
				"UPDATE %i AS x, (SELECT COUNT(*) AS answer_count, COALESCE(GREATEST(MAX(net_votes), 0), 0) AS answer_max_votes FROM %i WHERE parent_id = %d AND type = 'A') AS a "
				. 'SET x.answer_count = a.answer_count, x.answer_max_votes = a.answer_max_votes WHERE x.id = %d',
				$table,
				$table,
				$question_id,
				$question_id
			)
		);

		$this->forget( $question_id );
	}

	/**
	 * Recalculates the vote counts of a post from the stored votes.
	 *
	 * @param int $id Post ID.
	 */
	public function recount_votes( int $id ): void {
		Db::query(
			Db::wpdb()->prepare(
				'UPDATE %i AS x, (SELECT COALESCE(SUM(GREATEST(0, vote)), 0) AS upvotes, -COALESCE(SUM(LEAST(0, vote)), 0) AS downvotes FROM %i WHERE post_id = %d) AS a '
				. 'SET x.upvotes = a.upvotes, x.downvotes = a.downvotes, x.net_votes = a.upvotes - a.downvotes WHERE x.id = %d',
				Schema::table( 'posts' ),
				Schema::table( 'uservotes' ),
				$id,
				$id
			)
		);

		$this->forget( $id );
	}

	/**
	 * Recalculates the flag count of a post from the stored flags.
	 *
	 * @param int $id Post ID.
	 */
	public function recount_flags( int $id ): void {
		Db::query(
			Db::wpdb()->prepare(
				'UPDATE %i AS x, (SELECT COUNT(*) AS flag_count FROM %i WHERE post_id = %d AND flag = 1) AS a SET x.flag_count = a.flag_count WHERE x.id = %d',
				Schema::table( 'posts' ),
				Schema::table( 'uservotes' ),
				$id,
				$id
			)
		);

		$this->forget( $id );
	}

	/**
	 * Recalculates the hotness of a range of questions.
	 *
	 * Hotness combines question age, latest answer age, answer count, net votes and
	 * views, weighted by the site settings. The zero point is 1 January 2010.
	 *
	 * @param int      $first_id First post ID.
	 * @param int|null $last_id  Last post ID, or null for a single post.
	 */
	public function update_hotness( int $first_id, ?int $last_id = null ): void {
		$last_id = $last_id ?? $first_id;
		$table   = Schema::table( 'posts' );

		// The multipliers come from Question2Answer and give the factors roughly equal influence.
		Db::query(
			Db::wpdb()->prepare(
				'UPDATE %i AS x, (SELECT parents.id, MAX(parents.created) AS q_created, COALESCE(MAX(children.created), MAX(parents.created)) AS a_created, '
				. 'COUNT(children.id) AS answers, MAX(parents.net_votes) AS net_votes, MAX(parents.views) AS views '
				. "FROM %i AS parents LEFT JOIN %i AS children ON parents.id = children.parent_id AND children.type = 'A' "
				. 'WHERE parents.id BETWEEN %d AND %d GROUP BY parents.id) AS a SET x.hotness = ('
				. '((TO_DAYS(a.q_created) - 734138) * 86400.0 + TIME_TO_SEC(a.q_created)) * %f + '
				. '((TO_DAYS(a.a_created) - 734138) * 86400.0 + TIME_TO_SEC(a.a_created)) * %f + '
				. '(a.answers + 0.0) * %f + (a.net_votes + 0.0) * %f + (a.views + 0.0) * %f'
				. ') WHERE x.id = a.id',
				$table,
				$table,
				$table,
				$first_id,
				$last_id,
				Options::get_int( 'lists', 'hot_weight_q_age' ),
				Options::get_int( 'lists', 'hot_weight_a_age' ),
				Options::get_int( 'lists', 'hot_weight_answers' ) * 160000,
				Options::get_int( 'lists', 'hot_weight_votes' ) * 160000,
				Options::get_int( 'lists', 'hot_weight_views' ) * 4000
			)
		);

		$this->forget_range( $first_id, $last_id );
	}

	/**
	 * Increments the view counter of a question.
	 *
	 * @param int $id Question ID.
	 */
	public function increment_views( int $id ): void {
		Db::query( Db::wpdb()->prepare( 'UPDATE %i SET views = views + 1 WHERE id = %d', Schema::table( 'posts' ), $id ) );
		$this->forget( $id );
	}

	/**
	 * Removes a post from the object cache.
	 *
	 * @param int $id Post ID.
	 */
	public function forget( int $id ): void {
		wp_cache_delete( $id, self::CACHE_GROUP );
	}

	/**
	 * Removes a range of posts from the object cache.
	 *
	 * @param int $first_id First post ID.
	 * @param int $last_id  Last post ID.
	 */
	private function forget_range( int $first_id, int $last_id ): void {
		if ( $last_id - $first_id > 100 && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( self::CACHE_GROUP );
			return;
		}

		for ( $id = $first_id; $id <= $last_id; $id++ ) {
			$this->forget( $id );
		}
	}

	/**
	 * Converts rows into posts keyed by ID.
	 *
	 * @param object[] $rows Rows of the posts table.
	 * @return array<int, Post>
	 */
	private function to_posts( array $rows ): array {
		$posts = array();
		foreach ( $rows as $row ) {
			$post               = Post::from_row( $row );
			$posts[ $post->id ] = $post;
		}

		return $posts;
	}
}
