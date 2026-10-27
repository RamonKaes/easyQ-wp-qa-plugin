<?php
/**
 * Activity item.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Posts;

defined( 'ABSPATH' ) || exit;

/**
 * Something that happened to a question: it was asked, answered, commented on or changed.
 *
 * @since 0.1.0
 */
final class Activity_Item {

	/**
	 * Sets up the item.
	 *
	 * @since 0.1.0
	 *
	 * @param int         $question_id Question the activity belongs to.
	 * @param int         $post_id     Post that was created or changed: the question, an answer or a comment.
	 * @param string      $base_type   Base type of that post: Q, A or C.
	 * @param string      $time        Time of the activity, MySQL format in UTC.
	 * @param string|null $update_type Kind of change (see the Post::UPDATE_* constants), or null if the post was created.
	 * @param int|null    $user_id     User who created or changed the post.
	 */
	public function __construct(
		public readonly int $question_id,
		public readonly int $post_id,
		public readonly string $base_type,
		public readonly string $time,
		public readonly ?string $update_type,
		public readonly ?int $user_id
	) {
	}

	/**
	 * Returns the item as block context.
	 *
	 * @since 0.1.0
	 *
	 * @return array{postId: int, baseType: string, time: string, updateType: string|null, userId: int|null}
	 */
	public function to_context(): array {
		return array(
			'postId'     => $this->post_id,
			'baseType'   => $this->base_type,
			'time'       => $this->time,
			'updateType' => $this->update_type,
			'userId'     => $this->user_id,
		);
	}

	/**
	 * Creates an item from block context.
	 *
	 * @since 0.1.0
	 *
	 * @param int   $question_id Question ID.
	 * @param mixed $context     Context created by to_context().
	 * @return self|null
	 */
	public static function from_context( int $question_id, mixed $context ): ?self {
		if ( ! is_array( $context ) || ! is_numeric( $context['postId'] ?? null ) || ! is_string( $context['baseType'] ?? null ) || ! is_string( $context['time'] ?? null ) ) {
			return null;
		}

		return new self(
			$question_id,
			(int) $context['postId'],
			$context['baseType'],
			$context['time'],
			is_string( $context['updateType'] ?? null ) ? $context['updateType'] : null,
			is_numeric( $context['userId'] ?? null ) ? (int) $context['userId'] : null
		);
	}

	/**
	 * Returns the item for the creation of a post.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $post     Question, answer or comment.
	 * @param int  $question Question ID.
	 * @return self
	 */
	public static function created( Post $post, int $question ): self {
		return new self( $question, $post->id, $post->base_type(), $post->created, null, $post->user_id );
	}

	/**
	 * Describes the activity, e.g. "answered" or "retagged".
	 *
	 * Ported from Question2Answer `qa_other_to_q_html_fields()`.
	 *
	 * @since 0.1.0
	 *
	 * @param Post|null $post The created or changed post, to tell closing from reopening.
	 * @return string
	 */
	public function label( ?Post $post = null ): string {
		return match ( $this->base_type . '-' . (string) $this->update_type ) {
			'Q-'                           => __( 'asked', 'easyq' ),
			'Q-' . Post::UPDATE_VISIBLE    => __( 'reshown', 'easyq' ),
			'Q-' . Post::UPDATE_CLOSED     => null !== $post && $post->is_closed() ? __( 'closed', 'easyq' ) : __( 'reopened', 'easyq' ),
			'Q-' . Post::UPDATE_TAGS       => __( 'retagged', 'easyq' ),
			'Q-' . Post::UPDATE_CATEGORY   => __( 'recategorized', 'easyq' ),
			'A-'                           => __( 'answered', 'easyq' ),
			'A-' . Post::UPDATE_SELECTED   => __( 'answer selected', 'easyq' ),
			'A-' . Post::UPDATE_VISIBLE    => __( 'answer reshown', 'easyq' ),
			'A-' . Post::UPDATE_CONTENT    => __( 'answer edited', 'easyq' ),
			'C-'                           => __( 'commented', 'easyq' ),
			'C-' . Post::UPDATE_TYPE, 'C-' . Post::UPDATE_PARENT => __( 'comment moved', 'easyq' ),
			'C-' . Post::UPDATE_VISIBLE    => __( 'comment reshown', 'easyq' ),
			'C-' . Post::UPDATE_CONTENT    => __( 'comment edited', 'easyq' ),
			default                        => __( 'edited', 'easyq' ),
		};
	}
}
