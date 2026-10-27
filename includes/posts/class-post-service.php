<?php
/**
 * Creating and changing posts.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Posts;

use EasyQ\Actor;
use EasyQ\Categories\Categories;
use EasyQ\Database\DB;
use EasyQ\Database\Row;
use EasyQ\Database\Schema;
use EasyQ\Events\Events;
use EasyQ\Points\Points;
use EasyQ\Search\Search;
use EasyQ\Settings\Options;
use EasyQ\Stats\Counts;
use EasyQ\Util\Text;
use WP_Error;
use EasyQ\Votes\Votes;

defined( 'ABSPATH' ) || exit;

/**
 * Creates, edits, moderates, moves and deletes questions, answers and comments.
 *
 * Every operation keeps the search index, cached counts, user points and
 * category counts up to date and reports the Question2Answer events.
 * Permissions and input validation are checked by the callers, see
 * Post_Validator.
 *
 * Ported from Question2Answer `qa-include/app/post-create.php` and `app/post-update.php`.
 *
 * @since 0.1.0
 */
final class Post_Service {

	/**
	 * Post meta key of the extra question field.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const EXTRA_META = 'easyq_extra';

	/**
	 * Sets up the service.
	 *
	 * @since 0.1.0
	 *
	 * @param Post_Repository $posts      Post storage.
	 * @param Search          $search     Search index.
	 * @param Points          $points     User points.
	 * @param Categories      $categories Categories.
	 */
	public function __construct(
		private readonly Post_Repository $posts,
		private readonly Search $search,
		private readonly Points $points,
		private readonly Categories $categories
	) {
	}

	/**
	 * Returns the value stored in the notify column.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor       $actor  Author.
	 * @param bool        $notify Whether the author wants notifications.
	 * @param string|null $email  Email address entered by an anonymous author.
	 * @return string|null An address, `@` for the address of the author's account, or null.
	 */
	public static function notify_value( Actor $actor, bool $notify, ?string $email ): ?string {
		if ( ! $notify ) {
			return null;
		}

		if ( null !== $email && '' !== $email ) {
			return $email;
		}

		return null === $actor->user_id ? null : '@';
	}

	/**
	 * Checks whether a question counts as closed.
	 *
	 * A question is closed by a duplicate or a note, or, if the site closes
	 * questions with a selected answer, by selecting an answer.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $question Question.
	 * @return bool
	 */
	public static function is_closed( Post $question ): bool {
		return $question->is_closed() || ( null !== $question->selected_child_id && Options::get_bool( 'posting', 'do_close_on_select' ) );
	}

	/**
	 * Creates a question.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor       $actor         Author.
	 * @param string      $title         Title.
	 * @param string      $content       Content.
	 * @param string      $format        Content format: '' or 'html'.
	 * @param string[]    $tags          Tags.
	 * @param int|null    $category_id   Category ID.
	 * @param string|null $notify        Notification value, see notify_value().
	 * @param string|null $name          Name of an anonymous author.
	 * @param Post|null   $follow_answer Answer the question follows on from.
	 * @param bool        $queued        Whether the question waits for moderation.
	 * @param string|null $extra         Value of the extra question field.
	 * @return int The new question ID, 0 on failure.
	 */
	public function create_question( Actor $actor, string $title, string $content, string $format = '', array $tags = array(), ?int $category_id = null, ?string $notify = null, ?string $name = null, ?Post $follow_answer = null, bool $queued = false, ?string $extra = null ): int {
		$tag_string = Text::tags_to_string( $tags );
		$id         = $this->posts->insert( $queued ? 'Q_QUEUED' : 'Q', $follow_answer?->id, $category_id, $actor, $title, $content, $format, $tag_string, $notify, $name );

		if ( 0 === $id ) {
			return 0;
		}

		if ( null !== $extra ) {
			update_metadata( 'easyq_post', $id, self::EXTRA_META, $extra );
		}

		$this->posts->update_hotness( $id );
		$question = $this->require_post( $id );

		if ( $queued ) {
			Counts::invalidate( Counts::QUEUED );
		} else {
			$this->search->index( $question, $id, $category_id );
			$this->question_counts_changed( $question );
			$this->points->update( $actor->user_id, array( Points::QUESTIONS ) );
		}

		Events::dispatch(
			$queued ? 'q_queue' : 'q_post',
			array(
				'post_id'     => $id,
				'parent_id'   => $follow_answer?->id,
				'title'       => $title,
				'content'     => $content,
				'format'      => $format,
				'text'        => $question->text(),
				'tags'        => $tag_string,
				'category_id' => $category_id,
				'extra'       => $extra,
				'name'        => $name,
				'notify'      => $notify,
			),
			$actor
		);

		return $id;
	}

	/**
	 * Creates an answer.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor       $actor    Author.
	 * @param Post        $question Question.
	 * @param string      $content  Content.
	 * @param string      $format   Content format.
	 * @param string|null $notify   Notification value, see notify_value().
	 * @param string|null $name     Name of an anonymous author.
	 * @param bool        $queued   Whether the answer waits for moderation.
	 * @return int The new answer ID, 0 on failure.
	 */
	public function create_answer( Actor $actor, Post $question, string $content, string $format = '', ?string $notify = null, ?string $name = null, bool $queued = false ): int {
		$id = $this->posts->insert( $queued ? 'A_QUEUED' : 'A', $question->id, $question->category_id, $actor, null, $content, $format, null, $notify, $name );

		if ( 0 === $id ) {
			return 0;
		}

		$answer = $this->require_post( $id );

		if ( $queued ) {
			Counts::invalidate( Counts::QUEUED );
		} else {
			// Answers of hidden or queued questions are indexed once the question becomes visible.
			if ( Post::QUESTION === $question->type ) {
				$this->search->index( $answer, $question->id, $question->category_id );
			}

			$this->answers_changed( $question );
			$this->points->update( $actor->user_id, array( Points::ANSWERS ) );
		}

		Events::dispatch(
			$queued ? 'a_queue' : 'a_post',
			array(
				'post_id'     => $id,
				'parent_id'   => $question->id,
				'content'     => $content,
				'format'      => $format,
				'text'        => $answer->text(),
				'category_id' => $question->category_id,
				'name'        => $name,
				'notify'      => $notify,
			),
			$actor
		);

		return $id;
	}

	/**
	 * Creates a comment on a question or an answer.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor       $actor    Author.
	 * @param Post        $question Question the comment belongs to.
	 * @param Post        $parent_post   Question or answer the comment is on.
	 * @param string      $content  Content.
	 * @param string      $format   Content format.
	 * @param string|null $notify   Notification value, see notify_value().
	 * @param string|null $name     Name of an anonymous author.
	 * @param bool        $queued   Whether the comment waits for moderation.
	 * @return int The new comment ID, 0 on failure.
	 */
	public function create_comment( Actor $actor, Post $question, Post $parent_post, string $content, string $format = '', ?string $notify = null, ?string $name = null, bool $queued = false ): int {
		$id = $this->posts->insert( $queued ? 'C_QUEUED' : 'C', $parent_post->id, $question->category_id, $actor, null, $content, $format, null, $notify, $name );

		if ( 0 === $id ) {
			return 0;
		}

		$comment = $this->require_post( $id );

		if ( $queued ) {
			Counts::invalidate( Counts::QUEUED );
		} else {
			if ( $this->comment_indexable( $question, $parent_post ) ) {
				$this->search->index( $comment, $question->id, $question->category_id );
			}

			Counts::invalidate( Counts::COMMENTS );
			$this->points->update( $actor->user_id, array( Points::COMMENTS ) );
		}

		Events::dispatch(
			$queued ? 'c_queue' : 'c_post',
			array(
				'post_id'     => $id,
				'parent_id'   => $parent_post->id,
				'parent_type' => $parent_post->base_type(),
				'question_id' => $question->id,
				'thread'      => $this->thread_ids( $parent_post, $id ),
				'content'     => $content,
				'format'      => $format,
				'text'        => $comment->text(),
				'category_id' => $question->category_id,
				'name'        => $name,
				'notify'      => $notify,
			),
			$actor
		);

		return $id;
	}

	/**
	 * Changes title, content and tags of a question.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor       $actor      Editor.
	 * @param Post        $question   Question before the change.
	 * @param string      $title      Title.
	 * @param string      $content    Content.
	 * @param string      $format     Content format.
	 * @param string[]    $tags       Tags.
	 * @param string|null $notify     Notification value.
	 * @param string|null $name       Name of an anonymous author, or null to keep it.
	 * @param bool        $remoderate Whether a changed question goes back to moderation.
	 * @param bool        $silent     Whether to skip recording the edit.
	 * @param string|null $extra      Value of the extra question field, or null to keep it.
	 */
	public function edit_question( Actor $actor, Post $question, string $title, string $content, string $format, array $tags, ?string $notify, ?string $name = null, bool $remoderate = false, bool $silent = false, ?string $extra = null ): void {
		$tag_string = Text::tags_to_string( $tags );

		$this->search->unindex( $question->id );

		$was_queued      = $question->is_queued();
		$title_changed   = (string) $question->title !== $title;
		$content_changed = (string) $question->content !== $content || $question->format !== $format;
		$tags_changed    = (string) $question->tags !== $tag_string;
		$record_edit     = ( $title_changed || $content_changed || $tags_changed ) && ! $was_queued && ! $silent;

		$columns = array(
			'title'   => $title,
			'content' => $content,
			'format'  => $format,
			'tags'    => $tag_string,
			'notify'  => $notify,
		);
		if ( null !== $name ) {
			$columns['author_name'] = $name;
		}

		$this->posts->update(
			$question->id,
			$columns,
			$record_edit ? $actor : null,
			( $title_changed || $content_changed ) ? Post::UPDATE_CONTENT : Post::UPDATE_TAGS
		);

		if ( null !== $extra ) {
			update_metadata( 'easyq_post', $question->id, self::EXTRA_META, $extra );
		}

		$requeue = $record_edit && $remoderate;
		$updated = $this->require_post( $question->id );

		if ( $requeue ) {
			$this->unindex_question_tree( $question );
			$this->posts->update( $question->id, array( 'type' => 'Q_QUEUED' ) );
			$this->question_counts_changed( $question );
			Counts::invalidate( Counts::QUEUED, Counts::FLAGGED );
			$this->points->update( $question->user_id, array( Points::QUESTIONS, Points::SELECTIONS ) );
		} elseif ( Post::QUESTION === $question->type ) {
			$this->search->index( $updated, $question->id, $question->category_id );
			if ( $tags_changed ) {
				Counts::invalidate( Counts::TAGS );
			}
		}

		$params = array(
			'post_id' => $question->id,
			'title'   => $title,
			'content' => $content,
			'format'  => $format,
			'text'    => $updated->text(),
			'tags'    => $tag_string,
			'extra'   => $extra,
			'name'    => $name,
			'old'     => $question,
		);

		Events::dispatch(
			'q_edit',
			$params + array(
				'silent'          => $silent,
				'title_changed'   => $title_changed,
				'content_changed' => $content_changed,
				'tags_changed'    => $tags_changed,
			),
			$actor
		);

		if ( $requeue ) {
			Events::dispatch( 'q_requeue', $params, $actor );
		}
	}

	/**
	 * Changes the content of an answer.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor       $actor      Editor.
	 * @param Post        $answer     Answer before the change.
	 * @param Post        $question   Question of the answer.
	 * @param string      $content    Content.
	 * @param string      $format     Content format.
	 * @param string|null $notify     Notification value.
	 * @param string|null $name       Name of an anonymous author, or null to keep it.
	 * @param bool        $remoderate Whether a changed answer goes back to moderation.
	 * @param bool        $silent     Whether to skip recording the edit.
	 */
	public function edit_answer( Actor $actor, Post $answer, Post $question, string $content, string $format, ?string $notify, ?string $name = null, bool $remoderate = false, bool $silent = false ): void {
		$this->search->unindex( $answer->id );

		$content_changed = (string) $answer->content !== $content || $answer->format !== $format;
		$record_edit     = $content_changed && ! $answer->is_queued() && ! $silent;
		$requeue         = $record_edit && $remoderate;

		$this->update_content( $actor, $answer, $content, $format, $notify, $name, $record_edit );

		if ( $requeue ) {
			foreach ( $this->comments_of( $question, $answer->id ) as $comment ) {
				$this->search->unindex( $comment->id );
			}

			$this->posts->update( $answer->id, array( 'type' => 'A_QUEUED' ) );
			$this->answers_changed( $question );
			Counts::invalidate( Counts::QUEUED, Counts::FLAGGED );
			$this->points->update( $answer->user_id, array( Points::ANSWERS, Points::ANSWERS_SELECTED ) );
		} elseif ( Post::ANSWER === $answer->type && Post::QUESTION === $question->type ) {
			$this->search->index( $this->require_post( $answer->id ), $question->id, $answer->category_id );
		}

		$params = array(
			'post_id'   => $answer->id,
			'parent_id' => $answer->parent_id,
			'content'   => $content,
			'format'    => $format,
			'text'      => Text::content_to_text( $content, $format ),
			'name'      => $name,
			'old'       => $answer,
		);

		Events::dispatch(
			'a_edit',
			$params + array(
				'silent'          => $silent,
				'content_changed' => $content_changed,
			),
			$actor
		);

		if ( $requeue ) {
			Events::dispatch( 'a_requeue', $params, $actor );
		}
	}

	/**
	 * Changes the content of a comment.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor       $actor      Editor.
	 * @param Post        $comment    Comment before the change.
	 * @param Post        $question   Question the comment belongs to.
	 * @param Post        $parent_post     Question or answer the comment is on.
	 * @param string      $content    Content.
	 * @param string      $format     Content format.
	 * @param string|null $notify     Notification value.
	 * @param string|null $name       Name of an anonymous author, or null to keep it.
	 * @param bool        $remoderate Whether a changed comment goes back to moderation.
	 * @param bool        $silent     Whether to skip recording the edit.
	 */
	public function edit_comment( Actor $actor, Post $comment, Post $question, Post $parent_post, string $content, string $format, ?string $notify, ?string $name = null, bool $remoderate = false, bool $silent = false ): void {
		$this->search->unindex( $comment->id );

		$content_changed = (string) $comment->content !== $content || $comment->format !== $format;
		$record_edit     = $content_changed && ! $comment->is_queued() && ! $silent;
		$requeue         = $record_edit && $remoderate;

		$this->update_content( $actor, $comment, $content, $format, $notify, $name, $record_edit );

		if ( $requeue ) {
			$this->posts->update( $comment->id, array( 'type' => 'C_QUEUED' ) );
			Counts::invalidate( Counts::COMMENTS, Counts::QUEUED, Counts::FLAGGED );
			$this->points->update( $comment->user_id, array( Points::COMMENTS ) );
		} elseif ( Post::COMMENT === $comment->type && $this->comment_indexable( $question, $parent_post ) ) {
			$this->search->index( $this->require_post( $comment->id ), $question->id, $comment->category_id );
		}

		$params = array(
			'post_id'     => $comment->id,
			'parent_id'   => $comment->parent_id,
			'parent_type' => $parent_post->base_type(),
			'question_id' => $question->id,
			'content'     => $content,
			'format'      => $format,
			'text'        => Text::content_to_text( $content, $format ),
			'name'        => $name,
			'old'         => $comment,
		);

		Events::dispatch(
			'c_edit',
			$params + array(
				'silent'          => $silent,
				'content_changed' => $content_changed,
			),
			$actor
		);

		if ( $requeue ) {
			Events::dispatch( 'c_requeue', $params, $actor );
		}
	}

	/**
	 * Changes the status of a question, which also affects the visibility of its answers and comments.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor  $actor    Who changes the status.
	 * @param Post   $question Question before the change.
	 * @param string $status   One of the Post::STATUS_* constants.
	 */
	public function set_question_status( Actor $actor, Post $question, string $status ): void {
		$was_hidden   = $question->is_hidden();
		$was_queued   = $question->is_queued();
		$was_requeued = $was_queued && null !== $question->updated;

		$this->search->unindex( $question->id );
		$this->unindex_question_tree( $question );

		[ $event, $record ] = $this->status_change( 'q', $status, $was_hidden, $was_queued );

		$this->posts->update( $question->id, array( 'type' => Post::type_for( Post::QUESTION, $status ) ), $record ? $actor : null, Post::UPDATE_VISIBLE );

		if ( $was_queued && Post::STATUS_NORMAL === $status && Options::get_bool( 'spam', 'moderate_update_time' ) ) {
			// Approving: the post appears as new, or as edited now.
			$this->posts->update( $question->id, array( $was_requeued ? 'updated' : 'created' => DB::now() ) );
			if ( ! $was_requeued ) {
				$this->posts->update_hotness( $question->id );
			}
		}

		$this->question_counts_changed( $question );
		Counts::invalidate( Counts::QUEUED, Counts::FLAGGED );
		$this->points->update( $question->user_id, array( Points::QUESTIONS, Points::SELECTIONS ) );

		$updated = $this->require_post( $question->id );
		if ( Post::STATUS_NORMAL === $status ) {
			$this->index_question_tree( $updated );
		}

		$params = $this->post_params( $updated );

		if ( null !== $event ) {
			Events::dispatch( $event, $params + array( 'old' => $question ), $actor );
		}

		if ( $was_queued && Post::STATUS_NORMAL === $status && ! $was_requeued ) {
			Events::dispatch( 'q_post', $params + $this->delayed_params( $question ), $this->author_of( $question ) );
		}
	}

	/**
	 * Changes the status of an answer, which also affects the visibility of its comments.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor  $actor    Who changes the status.
	 * @param Post   $answer   Answer before the change.
	 * @param Post   $question Question of the answer.
	 * @param string $status   One of the Post::STATUS_* constants.
	 */
	public function set_answer_status( Actor $actor, Post $answer, Post $question, string $status ): void {
		$was_hidden   = $answer->is_hidden();
		$was_queued   = $answer->is_queued();
		$was_requeued = $was_queued && null !== $answer->updated;
		$comments     = $this->comments_of( $question, $answer->id );

		$this->search->unindex( $answer->id );
		foreach ( $comments as $comment ) {
			$this->search->unindex( $comment->id );
		}

		[ $event, $record ] = $this->status_change( 'a', $status, $was_hidden, $was_queued );

		if ( Post::STATUS_HIDDEN === $status && $question->selected_child_id === $answer->id ) {
			$this->select_answer( Actor::system(), $question, null );
			$question = $this->require_post( $question->id );
		}

		$this->posts->update( $answer->id, array( 'type' => Post::type_for( Post::ANSWER, $status ) ), $record ? $actor : null, Post::UPDATE_VISIBLE );

		if ( $was_queued && Post::STATUS_NORMAL === $status && Options::get_bool( 'spam', 'moderate_update_time' ) ) {
			$this->posts->update( $answer->id, array( $was_requeued ? 'updated' : 'created' => DB::now() ) );
		}

		$this->answers_changed( $question );
		Counts::invalidate( Counts::QUEUED, Counts::FLAGGED );
		$this->points->update( $answer->user_id, array( Points::ANSWERS, Points::ANSWERS_SELECTED ) );

		$updated = $this->require_post( $answer->id );
		if ( Post::QUESTION === $question->type && Post::STATUS_NORMAL === $status ) {
			$this->search->index( $updated, $question->id, $updated->category_id );
			foreach ( $comments as $comment ) {
				if ( Post::COMMENT === $comment->type ) {
					$this->search->index( $comment, $question->id, $comment->category_id );
				}
			}
		}

		$params = $this->post_params( $updated );

		if ( null !== $event ) {
			Events::dispatch( $event, $params + array( 'old' => $answer ), $actor );
		}

		if ( $was_queued && Post::STATUS_NORMAL === $status && ! $was_requeued ) {
			Events::dispatch( 'a_post', $params + $this->delayed_params( $answer ), $this->author_of( $answer ) );
		}
	}

	/**
	 * Changes the status of a comment.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor  $actor    Who changes the status.
	 * @param Post   $comment  Comment before the change.
	 * @param Post   $question Question the comment belongs to.
	 * @param Post   $parent_post   Question or answer the comment is on.
	 * @param string $status   One of the Post::STATUS_* constants.
	 */
	public function set_comment_status( Actor $actor, Post $comment, Post $question, Post $parent_post, string $status ): void {
		$was_hidden   = $comment->is_hidden();
		$was_queued   = $comment->is_queued();
		$was_requeued = $was_queued && null !== $comment->updated;

		$this->search->unindex( $comment->id );

		[ $event, $record ] = $this->status_change( 'c', $status, $was_hidden, $was_queued );

		$this->posts->update( $comment->id, array( 'type' => Post::type_for( Post::COMMENT, $status ) ), $record ? $actor : null, Post::UPDATE_VISIBLE );

		if ( $was_queued && Post::STATUS_NORMAL === $status && Options::get_bool( 'spam', 'moderate_update_time' ) ) {
			$this->posts->update( $comment->id, array( $was_requeued ? 'updated' : 'created' => DB::now() ) );
		}

		Counts::invalidate( Counts::COMMENTS, Counts::QUEUED, Counts::FLAGGED );
		$this->points->update( $comment->user_id, array( Points::COMMENTS ) );

		$updated = $this->require_post( $comment->id );
		if ( Post::STATUS_NORMAL === $status && $this->comment_indexable( $question, $parent_post ) ) {
			$this->search->index( $updated, $question->id, $updated->category_id );
		}

		$params = $this->post_params( $updated ) + array(
			'parent_type' => $parent_post->base_type(),
			'question_id' => $question->id,
		);

		if ( null !== $event ) {
			Events::dispatch( $event, $params + array( 'old' => $comment ), $actor );
		}

		if ( $was_queued && Post::STATUS_NORMAL === $status && ! $was_requeued ) {
			Events::dispatch(
				'c_post',
				$params + array( 'thread' => $this->thread_ids( $parent_post, $comment->id ) ) + $this->delayed_params( $comment ),
				$this->author_of( $comment )
			);
		}
	}

	/**
	 * Permanently deletes a hidden question without answers or comments.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $actor    Who deletes the question.
	 * @param Post  $question Question.
	 * @return true|WP_Error
	 */
	public function delete_question( Actor $actor, Post $question ): bool|WP_Error {
		if ( Post::QUESTION . '_HIDDEN' !== $question->type ) {
			return new WP_Error( 'not_hidden', __( 'Only hidden questions can be deleted.', 'easyq' ) );
		}

		$note = $this->posts->close_note_of( $question );
		if ( $this->has_dependents( $question->id, $note?->id ) ) {
			return new WP_Error( 'has_dependents', __( 'Questions with answers or comments cannot be deleted.', 'easyq' ) );
		}

		$params = array(
			'post_id' => $question->id,
			'old'     => $question,
		);
		Events::dispatch( 'q_delete_before', $params, $actor );

		if ( null !== $note ) {
			$this->posts->update( $question->id, array( 'closed_by_id' => null ) );
			$this->search->unindex( $note->id );
			$this->posts->delete( $note->id );
		}

		$voters       = $this->voters( $question->id );
		$category_ids = $this->posts->category_ids_of( $question->id );

		$this->search->unindex( $question->id );
		$this->posts->delete( $question->id );
		$this->categories->recount( $category_ids );

		$this->points->update( $question->user_id, array( Points::QUESTIONS, Points::SELECTIONS, Points::QUESTION_VOTE_POINTS, Points::UPVOTES_RECEIVED, Points::DOWNVOTES_RECEIVED ) );
		foreach ( $voters as $voter_id => $vote ) {
			$this->points->update( $voter_id, array( $vote > 0 ? Points::QUESTION_UPVOTES : Points::QUESTION_DOWNVOTES ) );
		}

		Events::dispatch( 'q_delete', $params, $actor );

		return true;
	}

	/**
	 * Permanently deletes a hidden answer without comments or follow-on questions.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $actor    Who deletes the answer.
	 * @param Post  $answer   Answer.
	 * @param Post  $question Question of the answer.
	 * @return true|WP_Error
	 */
	public function delete_answer( Actor $actor, Post $answer, Post $question ): bool|WP_Error {
		if ( Post::ANSWER . '_HIDDEN' !== $answer->type ) {
			return new WP_Error( 'not_hidden', __( 'Only hidden answers can be deleted.', 'easyq' ) );
		}

		if ( $this->has_dependents( $answer->id ) ) {
			return new WP_Error( 'has_dependents', __( 'Answers with comments or follow-on questions cannot be deleted.', 'easyq' ) );
		}

		$params = array(
			'post_id'   => $answer->id,
			'parent_id' => $answer->parent_id,
			'old'       => $answer,
		);
		Events::dispatch( 'a_delete_before', $params, $actor );

		$voters = $this->voters( $answer->id );

		$this->search->unindex( $answer->id );
		$this->posts->delete( $answer->id );

		if ( $question->selected_child_id === $answer->id ) {
			$this->posts->update( $question->id, array( 'selected_child_id' => null ) );
			$this->points->update( $question->user_id, array( Points::SELECTIONS ) );
			Counts::invalidate( Counts::UNSELECTED );
		}

		$this->points->update( $answer->user_id, array( Points::ANSWERS, Points::ANSWERS_SELECTED, Points::ANSWER_VOTE_POINTS, Points::UPVOTES_RECEIVED, Points::DOWNVOTES_RECEIVED ) );
		foreach ( $voters as $voter_id => $vote ) {
			$this->points->update( $voter_id, array( $vote > 0 ? Points::ANSWER_UPVOTES : Points::ANSWER_DOWNVOTES ) );
		}

		Events::dispatch( 'a_delete', $params, $actor );

		return true;
	}

	/**
	 * Permanently deletes a hidden comment.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $actor    Who deletes the comment.
	 * @param Post  $comment  Comment.
	 * @param Post  $question Question the comment belongs to.
	 * @param Post  $parent_post   Question or answer the comment is on.
	 * @return true|WP_Error
	 */
	public function delete_comment( Actor $actor, Post $comment, Post $question, Post $parent_post ): bool|WP_Error {
		if ( Post::COMMENT . '_HIDDEN' !== $comment->type ) {
			return new WP_Error( 'not_hidden', __( 'Only hidden comments can be deleted.', 'easyq' ) );
		}

		$params = array(
			'post_id'     => $comment->id,
			'parent_id'   => $comment->parent_id,
			'parent_type' => $parent_post->base_type(),
			'question_id' => $question->id,
			'old'         => $comment,
		);
		Events::dispatch( 'c_delete_before', $params, $actor );

		$voters = $this->voters( $comment->id );

		$this->search->unindex( $comment->id );
		$this->posts->delete( $comment->id );

		$this->points->update( $comment->user_id, array( Points::COMMENTS, Points::COMMENT_VOTE_POINTS, Points::UPVOTES_RECEIVED, Points::DOWNVOTES_RECEIVED ) );
		foreach ( $voters as $voter_id => $vote ) {
			$this->points->update( $voter_id, array( $vote > 0 ? Points::COMMENT_UPVOTES : Points::COMMENT_DOWNVOTES ) );
		}

		Events::dispatch( 'c_delete', $params, $actor );

		return true;
	}

	/**
	 * Selects the best answer of a question, or clears the selection.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor    $actor     Who selects the answer.
	 * @param Post     $question  Question.
	 * @param int|null $answer_id Answer ID, or null to clear the selection.
	 */
	public function select_answer( Actor $actor, Post $question, ?int $answer_id ): void {
		$answers     = $this->posts->answers_of( $question->id );
		$previous_id = $question->selected_child_id;
		$was_closed  = self::is_closed( $question );

		$this->posts->set_selected_answer( $question, $answer_id, $actor );
		$this->points->update( $question->user_id, array( Points::SELECTIONS ) );
		Counts::invalidate( Counts::UNSELECTED );

		if ( null !== $previous_id && isset( $answers[ $previous_id ] ) ) {
			$this->points->update( $answers[ $previous_id ]->user_id, array( Points::ANSWERS_SELECTED ) );

			Events::dispatch(
				'a_unselect',
				array(
					'parent_id' => $question->id,
					'post_id'   => $previous_id,
				),
				$actor
			);

			if ( $was_closed && null === $question->closed_by_id ) {
				$this->posts->update( $question->id, array(), $actor, Post::UPDATE_CLOSED );

				Events::dispatch(
					'q_reopen',
					array(
						'post_id' => $question->id,
						'old'     => $question,
					),
					$actor
				);
			}
		}

		if ( null !== $answer_id && isset( $answers[ $answer_id ] ) ) {
			$this->points->update( $answers[ $answer_id ]->user_id, array( Points::ANSWERS_SELECTED ) );

			Events::dispatch(
				'a_select',
				array(
					'parent_id' => $question->id,
					'post_id'   => $answer_id,
				),
				$actor
			);

			if ( ! $question->is_closed() && Options::get_bool( 'posting', 'do_close_on_select' ) ) {
				$this->posts->update( $question->id, array(), $actor, Post::UPDATE_CLOSED );

				Events::dispatch(
					'q_close',
					array(
						'post_id'     => $question->id,
						'old'         => $question,
						'reason'      => 'answer-selected',
						'original_id' => $answer_id,
					),
					$actor
				);
			}
		}
	}

	/**
	 * Closes a question as a duplicate of another question.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $actor       Who closes the question.
	 * @param Post  $question    Question.
	 * @param int   $original_id ID of the original question.
	 */
	public function close_as_duplicate( Actor $actor, Post $question, int $original_id ): void {
		$question = $this->reopen( $actor, $question );

		$this->posts->update( $question->id, array( 'closed_by_id' => $original_id ), $actor, Post::UPDATE_CLOSED );
		$this->question_counts_changed( $question );

		Events::dispatch(
			'q_close',
			array(
				'post_id'     => $question->id,
				'old'         => $question,
				'reason'      => 'duplicate',
				'original_id' => $original_id,
			),
			$actor
		);
	}

	/**
	 * Closes a question with a note explaining why.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor  $actor    Who closes the question.
	 * @param Post   $question Question.
	 * @param string $note     Reason.
	 */
	public function close_with_note( Actor $actor, Post $question, string $note ): void {
		$question = $this->reopen( $actor, $question );

		$note_id = $this->posts->insert( Post::NOTE, $question->id, $question->category_id, $actor, null, $note, '', null, null, null );
		if ( 0 === $note_id ) {
			return;
		}

		if ( Post::QUESTION === $question->type ) {
			$this->search->index( $this->require_post( $note_id ), $question->id, $question->category_id );
		}

		$this->posts->update( $question->id, array( 'closed_by_id' => $note_id ), $actor, Post::UPDATE_CLOSED );
		$this->question_counts_changed( $question );

		Events::dispatch(
			'q_close',
			array(
				'post_id' => $question->id,
				'old'     => $question,
				'reason'  => 'other',
				'note'    => $note,
			),
			$actor
		);
	}

	/**
	 * Reopens a closed question; a close note is deleted.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $actor    Who reopens the question.
	 * @param Post  $question Question.
	 * @return Post The question after the change.
	 */
	public function reopen( Actor $actor, Post $question ): Post {
		if ( null === $question->closed_by_id ) {
			return $question;
		}

		$note = $this->posts->close_note_of( $question );

		$this->posts->update( $question->id, array( 'closed_by_id' => null ), $actor, Post::UPDATE_CLOSED );

		if ( null !== $note ) {
			$this->search->unindex( $note->id );
			$this->posts->delete( $note->id );
		}

		$this->question_counts_changed( $question );

		Events::dispatch(
			'q_reopen',
			array(
				'post_id' => $question->id,
				'old'     => $question,
			),
			$actor
		);

		return $this->require_post( $question->id );
	}

	/**
	 * Moves a question with its answers and comments to another category.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor    $actor       Who moves the question.
	 * @param Post     $question    Question.
	 * @param int|null $category_id New category ID, or null for none.
	 * @param bool     $silent      Whether to skip recording the change.
	 */
	public function set_category( Actor $actor, Post $question, ?int $category_id, bool $silent = false ): void {
		$old_categories = $this->posts->category_ids_of( $question->id );

		$this->posts->update( $question->id, array( 'category_id' => $category_id ), $silent ? null : $actor, Post::UPDATE_CATEGORY );
		$this->posts->calc_category_path( $question->id );

		$updated = $this->require_post( $question->id );
		$this->categories->recount( array_merge( $old_categories, $this->posts->category_ids_of( $question->id ) ) );

		$other_ids = array_merge(
			array_keys( $this->posts->answers_of( $question->id ) ),
			array_map( static fn ( Post $comment ): int => $comment->id, $this->comments_of( $question ) )
		);

		$note = $this->posts->close_note_of( $question );
		if ( null !== $note ) {
			$other_ids[] = $note->id;
		}

		$this->posts->copy_category( $other_ids, $updated );
		$this->search->move( array_merge( array( $question->id ), $other_ids ), $category_id );

		Events::dispatch(
			'q_move',
			array(
				'post_id'         => $question->id,
				'old'             => $question,
				'category_id'     => $category_id,
				'old_category_id' => $question->category_id,
			),
			$actor
		);
	}

	/**
	 * Converts an answer into a comment on the question or another answer.
	 *
	 * Comments and follow-on questions of the answer move to the new parent.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor       $actor      Who converts the answer.
	 * @param Post        $answer     Answer before the change.
	 * @param Post        $question   Question of the answer.
	 * @param int         $parent_id  New parent: the question or one of its other answers.
	 * @param string      $content    Content.
	 * @param string      $format     Content format.
	 * @param string|null $notify     Notification value.
	 * @param string|null $name       Name of an anonymous author, or null to keep it.
	 * @param bool        $remoderate Whether a changed comment goes back to moderation.
	 * @param bool        $silent     Whether to skip recording the change.
	 */
	public function answer_to_comment( Actor $actor, Post $answer, Post $question, int $parent_id, string $content, string $format, ?string $notify, ?string $name = null, bool $remoderate = false, bool $silent = false ): void {
		$answers     = $this->posts->answers_of( $question->id );
		$parent_post = $answers[ $parent_id ] ?? $question;

		$this->search->unindex( $answer->id );

		$was_queued      = $answer->is_queued();
		$content_changed = (string) $answer->content !== $content || $answer->format !== $format;
		$record_edit     = $content_changed && ! $was_queued && ! $silent;
		$requeue         = $record_edit && $remoderate;
		$new_type        = $requeue ? 'C_QUEUED' : 'C' . substr( $answer->type, 1 );

		$this->posts->update( $answer->id, array( 'type' => $new_type ), ( $was_queued || $silent ) ? null : $actor, Post::UPDATE_TYPE );
		$this->posts->update( $answer->id, array( 'parent_id' => $parent_post->id ) );
		$this->update_content( $actor, $answer, $content, $format, $notify, $name, $record_edit );

		foreach ( $this->posts->children( $answer->id ) as $child ) {
			$this->posts->update( $child->id, array( 'parent_id' => $parent_post->id ) );
		}

		if ( $question->selected_child_id === $answer->id ) {
			$this->select_answer( Actor::system(), $question, null );
		}

		$this->answers_changed( $question );
		Counts::invalidate( Counts::COMMENTS, Counts::QUEUED, Counts::FLAGGED );

		$this->points->update( $answer->user_id, array( Points::ANSWERS, Points::ANSWERS_SELECTED, Points::COMMENTS, Points::ANSWER_VOTE_POINTS, Points::COMMENT_VOTE_POINTS ) );
		foreach ( $this->voters( $answer->id ) as $voter_id => $vote ) {
			$this->points->update(
				$voter_id,
				$vote > 0 ? array( Points::ANSWER_UPVOTES, Points::COMMENT_UPVOTES ) : array( Points::ANSWER_DOWNVOTES, Points::COMMENT_DOWNVOTES )
			);
		}

		if ( ! $requeue && Post::ANSWER === $answer->type && $this->comment_indexable( $question, $parent_post ) ) {
			$this->search->index( $this->require_post( $answer->id ), $question->id, $answer->category_id );
		}

		$params = array(
			'post_id'     => $answer->id,
			'parent_id'   => $parent_post->id,
			'parent_type' => $parent_post->base_type(),
			'question_id' => $question->id,
			'content'     => $content,
			'format'      => $format,
			'text'        => Text::content_to_text( $content, $format ),
			'name'        => $name,
			'old'         => $answer,
		);

		Events::dispatch(
			'a_to_c',
			$params + array(
				'silent'          => $silent,
				'content_changed' => $content_changed,
			),
			$actor
		);

		if ( $requeue ) {
			Events::dispatch( 'c_requeue', $params, $actor );
		}
	}

	/**
	 * Assigns a post of an anonymous author to a user, e.g. after the visitor registered.
	 *
	 * Votes of the new author on the post are removed, since users cannot vote on their own posts.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $actor   The user claiming the post.
	 * @param Post  $post    Post.
	 */
	public function claim( Actor $actor, Post $post ): void {
		if ( null === $actor->user_id ) {
			return;
		}

		$wpdb = DB::wpdb();

		$sql = $wpdb->prepare(
			'UPDATE %i SET user_id = %d, last_user_id = IF(updated IS NULL, last_user_id, COALESCE(last_user_id, %d)) WHERE id = %d',
			Schema::table( 'posts' ),
			$actor->user_id,
			$actor->user_id,
			$post->id
		);
		if ( null !== $sql ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom tables without a WordPress API.
			$wpdb->query( $sql );
		}
		$sql = $wpdb->prepare( 'DELETE FROM %i WHERE post_id = %d AND user_id = %d', Schema::table( 'uservotes' ), $post->id, $actor->user_id );
		if ( null !== $sql ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom tables without a WordPress API.
			$wpdb->query( $sql );
		}
		Votes::forget();

		$this->posts->forget( $post->id );
		$this->posts->recount_votes( $post->id );

		$prefix  = strtolower( $post->base_type() );
		$columns = match ( $prefix ) {
			'q'     => array( Points::QUESTIONS, Points::SELECTIONS, Points::QUESTION_VOTE_POINTS, Points::QUESTION_UPVOTES, Points::QUESTION_DOWNVOTES, Points::UPVOTES_RECEIVED, Points::DOWNVOTES_RECEIVED ),
			'a'     => array( Points::ANSWERS, Points::ANSWERS_SELECTED, Points::ANSWER_VOTE_POINTS, Points::ANSWER_UPVOTES, Points::ANSWER_DOWNVOTES, Points::UPVOTES_RECEIVED, Points::DOWNVOTES_RECEIVED ),
			default => array( Points::COMMENTS, Points::COMMENT_VOTE_POINTS, Points::COMMENT_UPVOTES, Points::COMMENT_DOWNVOTES, Points::UPVOTES_RECEIVED, Points::DOWNVOTES_RECEIVED ),
		};

		$this->points->update( $post->user_id, $columns );
		$this->points->update( $actor->user_id, $columns );

		Events::dispatch(
			$prefix . '_claim',
			array(
				'post_id'   => $post->id,
				'parent_id' => $post->parent_id,
				'old'       => $post,
			),
			$actor
		);
	}

	/**
	 * Counts a view of a question and updates its hotness if the site wants that.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $question Question.
	 */
	public function record_view( Post $question ): void {
		if ( ! Options::get_bool( 'viewing', 'do_count_q_views' ) ) {
			return;
		}

		$this->posts->increment_views( $question->id );

		if ( Options::get_bool( 'viewing', 'recalc_hotness_q_view' ) ) {
			$this->posts->update_hotness( $question->id );
		}
	}

	/**
	 * Returns the question a post belongs to.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $post Question, answer, comment or note.
	 * @return Post|null
	 */
	public function question_of( Post $post ): ?Post {
		if ( Post::QUESTION === $post->base_type() ) {
			return $post;
		}

		$parent_post = null === $post->parent_id ? null : $this->posts->find( $post->parent_id );
		if ( null === $parent_post ) {
			return null;
		}

		if ( Post::QUESTION === $parent_post->base_type() ) {
			return $parent_post;
		}

		return null === $parent_post->parent_id ? null : $this->posts->find( $parent_post->parent_id );
	}

	/**
	 * Determines the event and whether to record the change for a status change.
	 *
	 * @since 0.1.0
	 *
	 * @param string $prefix     Event prefix: q, a or c.
	 * @param string $status     New status.
	 * @param bool   $was_hidden Whether the post was hidden.
	 * @param bool   $was_queued Whether the post was queued.
	 * @return array{0: string|null, 1: bool} Event name or null, and whether to record who changed the post.
	 */
	private function status_change( string $prefix, string $status, bool $was_hidden, bool $was_queued ): array {
		return match ( $status ) {
			Post::STATUS_QUEUED => array( $was_queued ? null : $prefix . '_requeue', false ),
			Post::STATUS_HIDDEN => $was_hidden ? array( null, false ) : array( $prefix . ( $was_queued ? '_reject' : '_hide' ), ! $was_queued ),
			default             => match ( true ) {
				$was_queued => array( $prefix . '_approve', false ),
				$was_hidden => array( $prefix . '_reshow', true ),
				default     => array( null, false ),
			},
		};
	}

	/**
	 * Updates content columns of an answer or comment.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor       $actor       Editor.
	 * @param Post        $post        Post.
	 * @param string      $content     Content.
	 * @param string      $format      Content format.
	 * @param string|null $notify      Notification value.
	 * @param string|null $name        Name of an anonymous author, or null to keep it.
	 * @param bool        $record_edit Whether to record the edit.
	 */
	private function update_content( Actor $actor, Post $post, string $content, string $format, ?string $notify, ?string $name, bool $record_edit ): void {
		$columns = array(
			'content' => $content,
			'format'  => $format,
			'notify'  => $notify,
		);
		if ( null !== $name ) {
			$columns['author_name'] = $name;
		}

		$this->posts->update( $post->id, $columns, $record_edit ? $actor : null, Post::UPDATE_CONTENT );
	}

	/**
	 * Updates the cached values that depend on a question's visibility.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $question Question.
	 */
	private function question_counts_changed( Post $question ): void {
		$this->categories->recount( $this->posts->category_ids_of( $question->id ) );
		Counts::invalidate( ...Counts::QUESTION_COUNTS );
	}

	/**
	 * Updates the cached values that depend on the visible answers of a question.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $question Question.
	 */
	private function answers_changed( Post $question ): void {
		$this->posts->recount_answers( $question->id );
		$this->posts->update_hotness( $question->id );
		Counts::invalidate( Counts::ANSWERS, Counts::UNANSWERED, Counts::UNUPVOTED );
	}

	/**
	 * Removes the answers, comments and close note of a question from the index.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $question Question.
	 */
	private function unindex_question_tree( Post $question ): void {
		foreach ( $this->posts->answers_of( $question->id ) as $answer ) {
			$this->search->unindex( $answer->id );
		}

		foreach ( $this->comments_of( $question ) as $comment ) {
			$this->search->unindex( $comment->id );
		}

		$note = $this->posts->close_note_of( $question );
		if ( null !== $note ) {
			$this->search->unindex( $note->id );
		}
	}

	/**
	 * Indexes a visible question with its visible answers, comments and close note.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $question Question.
	 */
	private function index_question_tree( Post $question ): void {
		$this->search->index( $question, $question->id, $question->category_id );

		$answers = $this->posts->answers_of( $question->id );
		foreach ( $answers as $answer ) {
			if ( Post::ANSWER === $answer->type ) {
				$this->search->index( $answer, $question->id, $answer->category_id );
			}
		}

		foreach ( $this->comments_of( $question ) as $comment ) {
			$parent_post = $answers[ (int) $comment->parent_id ] ?? null;
			if ( Post::COMMENT === $comment->type && ( null === $parent_post || Post::ANSWER === $parent_post->type ) ) {
				$this->search->index( $comment, $question->id, $comment->category_id );
			}
		}

		$note = $this->posts->close_note_of( $question );
		if ( null !== $note ) {
			$this->search->index( $note, $question->id, $note->category_id );
		}
	}

	/**
	 * Returns the comments of a question and its answers, optionally only those on one post.
	 *
	 * @since 0.1.0
	 *
	 * @param Post     $question  Question.
	 * @param int|null $parent_id Only comments on this post.
	 * @return Post[]
	 */
	private function comments_of( Post $question, ?int $parent_id = null ): array {
		return array_values(
			array_filter(
				$this->posts->comments_and_follows_of( $question->id ),
				static fn ( Post $post ): bool => Post::COMMENT === $post->base_type() && ( null === $parent_id || $post->parent_id === $parent_id )
			)
		);
	}

	/**
	 * Checks whether a comment can be indexed: its question and parent must be visible.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $question Question.
	 * @param Post $parent_post   Question or answer the comment is on.
	 * @return bool
	 */
	private function comment_indexable( Post $question, Post $parent_post ): bool {
		return Post::QUESTION === $question->type && in_array( $parent_post->type, array( Post::QUESTION, Post::ANSWER ), true );
	}

	/**
	 * Returns the IDs of the visible comments on a post, without one comment.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $parent_post     Question or answer.
	 * @param int  $comment_id Comment to leave out.
	 * @return int[]
	 */
	private function thread_ids( Post $parent_post, int $comment_id ): array {
		$ids = array();
		foreach ( $this->posts->children( $parent_post->id ) as $child ) {
			if ( Post::COMMENT === $child->type && $child->id !== $comment_id ) {
				$ids[] = $child->id;
			}
		}

		return $ids;
	}

	/**
	 * Checks whether a post has children other than the given one.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $id        Post ID.
	 * @param int|null $except_id Child to ignore, e.g. a close note.
	 * @return bool
	 */
	private function has_dependents( int $id, ?int $except_id = null ): bool {
		foreach ( $this->posts->children( $id ) as $child ) {
			if ( $child->id !== $except_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the non-zero votes on a post.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, int> Votes keyed by user ID.
	 */
	private function voters( int $post_id ): array {
		$wpdb = DB::wpdb();
		$rows = DB::rows(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
			$wpdb->get_results(
				$wpdb->prepare( 'SELECT user_id, vote FROM %i WHERE post_id = %d AND vote != 0', Schema::table( 'uservotes' ), $post_id )
			)
		);

		$votes = array();
		foreach ( $rows as $row ) {
			$votes[ Row::int( $row, 'user_id' ) ] = Row::int( $row, 'vote' );
		}

		return $votes;
	}

	/**
	 * Returns the event parameters describing a post.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $post Post.
	 * @return array<string, mixed>
	 */
	private function post_params( Post $post ): array {
		return array(
			'post_id'     => $post->id,
			'parent_id'   => $post->parent_id,
			'title'       => $post->title,
			'content'     => $post->content,
			'format'      => $post->format,
			'text'        => $post->text(),
			'tags'        => $post->tags,
			'category_id' => $post->category_id,
			'name'        => $post->author_name,
		);
	}

	/**
	 * Returns the extra parameters of a creation event reported after approval.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $post Post as it was queued.
	 * @return array<string, mixed>
	 */
	private function delayed_params( Post $post ): array {
		return array(
			'notify'  => $post->notify,
			'delayed' => $post->created,
		);
	}

	/**
	 * Returns the author of a post as an actor.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $post Post.
	 * @return Actor
	 */
	private function author_of( Post $post ): Actor {
		return new Actor( $post->user_id, $post->cookie_id );
	}

	/**
	 * Loads a post that must exist.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id Post ID.
	 * @return Post
	 * @throws \RuntimeException If the post does not exist.
	 */
	private function require_post( int $id ): Post {
		$post = $this->posts->find( $id );
		if ( null === $post ) {
			throw new \RuntimeException( esc_html( sprintf( 'Post %d does not exist.', $id ) ) );
		}

		return $post;
	}
}
