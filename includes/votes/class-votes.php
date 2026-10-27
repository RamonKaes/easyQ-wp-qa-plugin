<?php
/**
 * Votes and flags.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Votes;

use EasyQ\Actor;
use EasyQ\Database\DB;
use EasyQ\Database\Row;
use EasyQ\Database\Schema;
use EasyQ\Events\Events;
use EasyQ\Points\Points;
use EasyQ\Posts\Post;
use EasyQ\Posts\Post_Repository;
use EasyQ\Posts\Post_Service;
use EasyQ\Settings\Options;
use EasyQ\Stats\Counts;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Records votes and flags of users on posts.
 *
 * Ported from Question2Answer `qa-include/app/votes.php` and `db/votes.php`.
 * Permission and rate limit checks happen in the callers.
 *
 * @since 0.1.0
 */
final class Votes {

	/**
	 * Votes and flags of users on posts, loaded during the current request, keyed by `post:user`.
	 *
	 * A page asks for them many times, e.g. once per block of a post. Every write
	 * clears them; see forget().
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, array{vote: int, flag: int}>
	 */
	private static array $rows = array();

	/**
	 * Sets up the service.
	 *
	 * @since 0.1.0
	 *
	 * @param Post_Repository $posts        Post storage.
	 * @param Post_Service    $post_service Post operations, used to hide posts with too many flags.
	 * @param Points          $points       User points.
	 */
	public function __construct(
		private readonly Post_Repository $posts,
		private readonly Post_Service $post_service,
		private readonly Points $points
	) {
	}

	/**
	 * Checks the rules of the post itself for voting: visibility, settings and own posts.
	 *
	 * @since 0.1.0
	 *
	 * @param Post     $post    Post.
	 * @param int|null $user_id Voting user.
	 * @return true|WP_Error
	 */
	public function can_vote( Post $post, ?int $user_id ): bool|WP_Error {
		if ( $post->is_hidden() ) {
			return new WP_Error( 'post_hidden', __( 'You cannot vote on hidden posts.', 'easyq' ) );
		}

		if ( $post->is_queued() ) {
			return new WP_Error( 'post_queued', __( 'You cannot vote on posts waiting for approval.', 'easyq' ) );
		}

		$enabled = match ( $post->base_type() ) {
			Post::QUESTION => Options::get_bool( 'viewing', 'voting_on_qs' ),
			Post::ANSWER   => Options::get_bool( 'viewing', 'voting_on_as' ),
			Post::COMMENT  => Options::get_bool( 'viewing', 'voting_on_cs' ),
			default        => false,
		};

		if ( ! $enabled || ( null !== $user_id && $post->user_id === $user_id ) ) {
			return new WP_Error( 'vote_not_allowed', __( 'Voting is not available on this post.', 'easyq' ) );
		}

		return true;
	}

	/**
	 * Returns the vote of a user on a post.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id User ID.
	 * @return int -1, 0 or 1.
	 */
	public function vote_of( int $post_id, int $user_id ): int {
		return $this->row( $post_id, $user_id )['vote'];
	}

	/**
	 * Checks whether a user flagged a post.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public function has_flagged( int $post_id, int $user_id ): bool {
		return 1 === $this->row( $post_id, $user_id )['flag'];
	}

	/**
	 * Loads the votes and flags of a user on several posts with one query.
	 *
	 * @since 0.1.0
	 *
	 * @param int[] $post_ids Post IDs.
	 * @param int   $user_id  User ID.
	 */
	public function prime( array $post_ids, int $user_id ): void {
		$missing = array_values( array_filter( array_unique( array_map( 'intval', $post_ids ) ), static fn ( int $id ): bool => ! isset( self::$rows[ $id . ':' . $user_id ] ) ) );
		if ( array() === $missing ) {
			return;
		}

		foreach ( $missing as $post_id ) {
			self::$rows[ $post_id . ':' . $user_id ] = array(
				'vote' => 0,
				'flag' => 0,
			);
		}

		$wpdb = DB::wpdb();
		$rows = DB::rows(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
			$wpdb->get_results(
				$wpdb->prepare(
					'SELECT post_id, vote, flag FROM %i WHERE user_id = %d AND post_id IN (' . implode( ',', array_fill( 0, count( $missing ), '%d' ) ) . ')',
					array_merge( array( Schema::table( 'uservotes' ), $user_id ), $missing )
				)
			)
		);
		foreach ( $rows as $row ) {
			self::$rows[ Row::int( $row, 'post_id' ) . ':' . $user_id ] = array(
				'vote' => Row::int( $row, 'vote' ),
				'flag' => Row::int( $row, 'flag' ),
			);
		}
	}

	/**
	 * Clears the votes and flags loaded during the current request.
	 *
	 * Call it after changing the uservotes table.
	 *
	 * @since 0.1.0
	 */
	public static function forget(): void {
		self::$rows = array();
	}

	/**
	 * Returns the vote and flag of a user on a post.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id User ID.
	 * @return array{vote: int, flag: int}
	 */
	private function row( int $post_id, int $user_id ): array {
		$key = $post_id . ':' . $user_id;
		if ( ! isset( self::$rows[ $key ] ) ) {
			$this->prime( array( $post_id ), $user_id );
		}

		return self::$rows[ $key ];
	}

	/**
	 * Sets the vote of a user on a post.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $actor Voting user.
	 * @param Post  $post  Post.
	 * @param int   $vote  1 for up, -1 for down, 0 to remove the vote.
	 * @return true|WP_Error
	 */
	public function vote( Actor $actor, Post $post, int $vote ): bool|WP_Error {
		$wpdb = DB::wpdb();
		if ( null === $actor->user_id ) {
			return new WP_Error( 'login_required', __( 'Please log in to vote.', 'easyq' ) );
		}

		$allowed = $this->can_vote( $post, $actor->user_id );
		if ( true !== $allowed ) {
			return $allowed;
		}

		$vote     = max( -1, min( 1, $vote ) );
		$old_vote = $this->vote_of( $post->id, $actor->user_id );
		if ( $old_vote === $vote ) {
			return true;
		}

		$now = DB::now();
		$sql = $wpdb->prepare(
			'INSERT INTO %i (post_id, user_id, vote, flag, vote_created) VALUES (%d, %d, %d, 0, %s) ON DUPLICATE KEY UPDATE vote = %d, vote_updated = %s',
			Schema::table( 'uservotes' ),
			$post->id,
			$actor->user_id,
			$vote,
			$now,
			$vote,
			$now
		);
		if ( null !== $sql ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom tables without a WordPress API.
			$wpdb->query( $sql );
		}
		self::forget();

		$this->posts->recount_votes( $post->id );

		$prefix = strtolower( $post->base_type() );

		if ( Post::ANSWER === $post->type && null !== $post->parent_id ) {
			$this->posts->recount_answers( $post->parent_id );
			$this->posts->update_hotness( $post->parent_id );
			Counts::invalidate( Counts::UNUPVOTED );
		}

		$columns = array();
		if ( $vote > 0 || $old_vote > 0 ) {
			$columns[] = $this->vote_column( $prefix, 'up' );
		}
		if ( $vote < 0 || $old_vote < 0 ) {
			$columns[] = $this->vote_column( $prefix, 'down' );
		}

		$this->points->update( $actor->user_id, $columns );
		$this->points->update( $post->user_id, array( $this->voted_column( $prefix ), Points::UPVOTES_RECEIVED, Points::DOWNVOTES_RECEIVED ) );

		if ( 'q' === $prefix ) {
			$this->posts->update_hotness( $post->id );
		}

		$event = $prefix . match ( true ) {
			$vote > 0 => '_vote_up',
			$vote < 0 => '_vote_down',
			default   => '_vote_nil',
		};

		Events::dispatch(
			$event,
			array(
				'post_id'  => $post->id,
				'user_id'  => $post->user_id,
				'vote'     => $vote,
				'old_vote' => $old_vote,
			),
			$actor
		);

		return true;
	}

	/**
	 * Flags a post as inappropriate. When enough users flagged it, the post is hidden.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $actor    Flagging user.
	 * @param Post  $post     Post.
	 * @param Post  $question Question the post belongs to.
	 * @return bool|WP_Error Whether the post was hidden because of the flag.
	 */
	public function flag( Actor $actor, Post $post, Post $question ): bool|WP_Error {
		if ( null === $actor->user_id ) {
			return new WP_Error( 'login_required', __( 'Please log in to flag posts.', 'easyq' ) );
		}

		if ( ! Options::get_bool( 'spam', 'flagging_of_posts' ) || $post->user_id === $actor->user_id ) {
			return new WP_Error( 'flag_not_allowed', __( 'Flagging is not available on this post.', 'easyq' ) );
		}

		if ( $this->has_flagged( $post->id, $actor->user_id ) ) {
			return false;
		}

		$this->set_flag( $post->id, $actor->user_id, flag: true );
		$this->posts->recount_flags( $post->id );
		Counts::invalidate( Counts::FLAGGED );

		$flagged = $this->posts->find( $post->id ) ?? $post;

		Events::dispatch(
			strtolower( $post->base_type() ) . '_flag',
			array(
				'post_id'     => $post->id,
				'old'         => $post,
				'flag_count'  => $flagged->flag_count,
				'question_id' => $question->id,
			),
			$actor
		);

		if ( $flagged->flag_count < Options::get_int( 'spam', 'flagging_hide_after' ) || $flagged->is_hidden() ) {
			return false;
		}

		$this->hide( $flagged, $question );

		return true;
	}

	/**
	 * Removes the flag of a user from a post.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $actor Flagging user.
	 * @param Post  $post  Post.
	 */
	public function unflag( Actor $actor, Post $post ): void {
		if ( null === $actor->user_id || ! $this->has_flagged( $post->id, $actor->user_id ) ) {
			return;
		}

		$this->set_flag( $post->id, $actor->user_id, flag: false );
		$this->posts->recount_flags( $post->id );
		Counts::invalidate( Counts::FLAGGED );

		Events::dispatch(
			strtolower( $post->base_type() ) . '_unflag',
			array(
				'post_id' => $post->id,
				'old'     => $post,
			),
			$actor
		);
	}

	/**
	 * Removes all flags from a post, e.g. after a moderator checked it.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $actor Moderator.
	 * @param Post  $post  Post.
	 */
	public function clear_flags( Actor $actor, Post $post ): void {
		$wpdb = DB::wpdb();
		$sql  = $wpdb->prepare( 'UPDATE %i SET flag = 0 WHERE post_id = %d', Schema::table( 'uservotes' ), $post->id );
		if ( null !== $sql ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom tables without a WordPress API.
			$wpdb->query( $sql );
		}
		self::forget();
		$this->posts->recount_flags( $post->id );
		Counts::invalidate( Counts::FLAGGED );

		Events::dispatch(
			strtolower( $post->base_type() ) . '_clearflags',
			array(
				'post_id' => $post->id,
				'old'     => $post,
			),
			$actor
		);
	}

	/**
	 * Stores or removes a flag.
	 *
	 * @since 0.1.0
	 *
	 * @param int  $post_id Post ID.
	 * @param int  $user_id User ID.
	 * @param bool $flag    Whether the post is flagged.
	 */
	private function set_flag( int $post_id, int $user_id, bool $flag ): void {
		$wpdb = DB::wpdb();
		$sql  = $wpdb->prepare(
			'INSERT INTO %i (post_id, user_id, vote, flag) VALUES (%d, %d, 0, %d) ON DUPLICATE KEY UPDATE flag = %d',
			Schema::table( 'uservotes' ),
			$post_id,
			$user_id,
			(int) $flag,
			(int) $flag
		);
		if ( null !== $sql ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom tables without a WordPress API.
			$wpdb->query( $sql );
		}
		self::forget();
	}

	/**
	 * Hides a post that received too many flags.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $post     Post.
	 * @param Post $question Question the post belongs to.
	 */
	private function hide( Post $post, Post $question ): void {
		$system = Actor::system();

		match ( $post->base_type() ) {
			Post::QUESTION => $this->post_service->set_question_status( $system, $post, Post::STATUS_HIDDEN ),
			Post::ANSWER   => $this->post_service->set_answer_status( $system, $post, $question, Post::STATUS_HIDDEN ),
			default        => $this->post_service->set_comment_status( $system, $post, $question, $this->posts->find( (int) $post->parent_id ) ?? $question, Post::STATUS_HIDDEN ),
		};
	}

	/**
	 * Returns the statistic column for votes a user gave.
	 *
	 * @since 0.1.0
	 *
	 * @param string $prefix    Post type prefix: q, a or c.
	 * @param string $direction up or down.
	 * @return string
	 */
	private function vote_column( string $prefix, string $direction ): string {
		$type = match ( $prefix ) {
			'q'     => 'question',
			'a'     => 'answer',
			default => 'comment',
		};

		return "{$type}_{$direction}votes";
	}

	/**
	 * Returns the statistic column for vote points a user received.
	 *
	 * @since 0.1.0
	 *
	 * @param string $prefix Post type prefix: q, a or c.
	 * @return string
	 */
	private function voted_column( string $prefix ): string {
		return match ( $prefix ) {
			'q'     => Points::QUESTION_VOTE_POINTS,
			'a'     => Points::ANSWER_VOTE_POINTS,
			default => Points::COMMENT_VOTE_POINTS,
		};
	}
}
