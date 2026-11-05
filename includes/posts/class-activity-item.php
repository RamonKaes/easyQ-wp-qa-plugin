<?php
/**
 * Activity item.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Posts;

use EasyQ\Updates\Updates;

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
	 * @param bool        $personal    Whether it is about the post of the user who reads it.
	 */
	public function __construct(
		public readonly int $question_id,
		public readonly int $post_id,
		public readonly string $base_type,
		public readonly string $time,
		public readonly ?string $update_type,
		public readonly ?int $user_id,
		public readonly bool $personal = false
	) {
	}

	/**
	 * Returns the item as block context.
	 *
	 * @since 0.1.0
	 *
	 * @return array{postId: int, baseType: string, time: string, updateType: string|null, userId: int|null, personal: bool}
	 */
	public function to_context(): array {
		return array(
			'postId'     => $this->post_id,
			'baseType'   => $this->base_type,
			'time'       => $this->time,
			'updateType' => $this->update_type,
			'userId'     => $this->user_id,
			'personal'   => $this->personal,
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
			is_numeric( $context['userId'] ?? null ) ? (int) $context['userId'] : null,
			! empty( $context['personal'] )
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
		if ( $this->personal ) {
			return $this->personal_label( $post );
		}

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
			'Q-' . Updates::FOLLOWS        => __( 'asked related question', 'easyq' ),
			'C-', 'C-' . Updates::FOLLOWS, 'C-' . Updates::COMMENT_ON_QUESTION, 'C-' . Updates::COMMENT_ON_ANSWER => __( 'commented', 'easyq' ),
			'C-' . Post::UPDATE_TYPE, 'C-' . Post::UPDATE_PARENT => __( 'comment moved', 'easyq' ),
			'C-' . Post::UPDATE_VISIBLE    => __( 'comment reshown', 'easyq' ),
			'C-' . Post::UPDATE_CONTENT    => __( 'comment edited', 'easyq' ),
			default                        => __( 'edited', 'easyq' ),
		};
	}

	/**
	 * Returns the description for the user whose post it is, as the Q2A texts `your_*`.
	 *
	 * @since 0.1.0
	 *
	 * @param Post|null $post The post the activity is about.
	 * @return string
	 */
	private function personal_label( ?Post $post ): string {
		$hidden = null !== $post && $post->is_hidden();

		return match ( $this->base_type . '-' . (string) $this->update_type ) {
			'Q-' . Post::UPDATE_VISIBLE    => $hidden ? __( 'your question hidden', 'easyq' ) : __( 'your question reshown', 'easyq' ),
			'Q-' . Post::UPDATE_CLOSED     => null !== $post && $post->is_closed() ? __( 'your question closed', 'easyq' ) : __( 'your question reopened', 'easyq' ),
			'Q-' . Post::UPDATE_TAGS       => __( 'your question retagged', 'easyq' ),
			'Q-' . Post::UPDATE_CATEGORY   => __( 'your question recategorized', 'easyq' ),
			'Q-' . Updates::FOLLOWS        => __( 'asked on your answer', 'easyq' ),
			'A-'                           => __( 'your question answered', 'easyq' ),
			'A-' . Post::UPDATE_SELECTED   => __( 'your answer selected', 'easyq' ),
			'A-' . Post::UPDATE_VISIBLE    => $hidden ? __( 'your answer hidden', 'easyq' ) : __( 'your answer reshown', 'easyq' ),
			'A-' . Post::UPDATE_CONTENT    => __( 'your answer edited', 'easyq' ),
			'C-' . Updates::COMMENT_ON_QUESTION => __( 'your question commented', 'easyq' ),
			'C-' . Updates::COMMENT_ON_ANSWER => __( 'your answer commented', 'easyq' ),
			'C-' . Updates::FOLLOWS        => __( 'your comment followed', 'easyq' ),
			'C-' . Post::UPDATE_TYPE, 'C-' . Post::UPDATE_PARENT => __( 'your comment moved', 'easyq' ),
			'C-' . Post::UPDATE_VISIBLE    => $hidden ? __( 'your comment hidden', 'easyq' ) : __( 'your comment reshown', 'easyq' ),
			'C-' . Post::UPDATE_CONTENT    => __( 'your comment edited', 'easyq' ),
			default                        => __( 'your question edited', 'easyq' ),
		};
	}
}
