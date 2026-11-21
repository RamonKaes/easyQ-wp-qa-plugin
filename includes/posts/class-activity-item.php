<?php
/**
 * Activity item.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Posts;

use EasyQ\Updates\Updates;
use EasyQ\Util\Number;

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
			Number::id( $context['postId'] ),
			$context['baseType'],
			$context['time'],
			is_string( $context['updateType'] ?? null ) ? $context['updateType'] : null,
			Number::int_in_range( $context['userId'] ?? null, PHP_INT_MIN ),
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
	 * Returns the whole sentence of the activity, as core writes "%1$s at %2$s" and not a phrase
	 * inside another string: the time in `%1$s`, the author in `%2$s`, or the time alone in `%s`
	 * without the author. Translators get one sentence per activity and can make it agree.
	 *
	 * @since 0.1.0
	 *
	 * @param Post|null $post        The created or changed post, see label().
	 * @param bool      $with_author Whether the sentence names who did it.
	 * @return string Unescaped text with the placeholders.
	 */
	public function sentence( ?Post $post, bool $with_author ): string {
		$closed = null !== $post && $post->is_closed();
		$hidden = null !== $post && $post->is_hidden();
		$key    = ( $this->personal ? 'own-' : '' ) . $this->base_type . '-' . (string) $this->update_type;

		$pair = match ( $key ) {
			'Q-' => array(
				/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
				__( 'asked %1$s by %2$s', 'easyq' ),
				/* translators: %s: when it happened, e.g. "5 minutes ago". */
				__( 'asked %s', 'easyq' ),
			),
			'Q-' . Post::UPDATE_VISIBLE => array(
				/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
				__( 'reshown %1$s by %2$s', 'easyq' ),
				/* translators: %s: when it happened, e.g. "5 minutes ago". */
				__( 'reshown %s', 'easyq' ),
			),
			'Q-' . Post::UPDATE_CLOSED => $closed
				? array(
					/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
					__( 'closed %1$s by %2$s', 'easyq' ),
					/* translators: %s: when it happened, e.g. "5 minutes ago". */
					__( 'closed %s', 'easyq' ),
				)
				: array(
					/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
					__( 'reopened %1$s by %2$s', 'easyq' ),
					/* translators: %s: when it happened, e.g. "5 minutes ago". */
					__( 'reopened %s', 'easyq' ),
				),
			'Q-' . Post::UPDATE_TAGS => array(
				/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
				__( 'retagged %1$s by %2$s', 'easyq' ),
				/* translators: %s: when it happened, e.g. "5 minutes ago". */
				__( 'retagged %s', 'easyq' ),
			),
			'Q-' . Post::UPDATE_CATEGORY => array(
				/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
				__( 'recategorized %1$s by %2$s', 'easyq' ),
				/* translators: %s: when it happened, e.g. "5 minutes ago". */
				__( 'recategorized %s', 'easyq' ),
			),
			'A-' => array(
				/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
				__( 'answered %1$s by %2$s', 'easyq' ),
				/* translators: %s: when it happened, e.g. "5 minutes ago". */
				__( 'answered %s', 'easyq' ),
			),
			'A-' . Post::UPDATE_SELECTED => array(
				/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
				__( 'answer selected %1$s by %2$s', 'easyq' ),
				/* translators: %s: when it happened, e.g. "5 minutes ago". */
				__( 'answer selected %s', 'easyq' ),
			),
			'A-' . Post::UPDATE_VISIBLE => array(
				/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
				__( 'answer reshown %1$s by %2$s', 'easyq' ),
				/* translators: %s: when it happened, e.g. "5 minutes ago". */
				__( 'answer reshown %s', 'easyq' ),
			),
			'A-' . Post::UPDATE_CONTENT => array(
				/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
				__( 'answer edited %1$s by %2$s', 'easyq' ),
				/* translators: %s: when it happened, e.g. "5 minutes ago". */
				__( 'answer edited %s', 'easyq' ),
			),
			'Q-' . Updates::FOLLOWS => array(
				/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
				__( 'asked related question %1$s by %2$s', 'easyq' ),
				/* translators: %s: when it happened, e.g. "5 minutes ago". */
				__( 'asked related question %s', 'easyq' ),
			),
			'C-', 'C-' . Updates::FOLLOWS, 'C-' . Updates::COMMENT_ON_QUESTION, 'C-' . Updates::COMMENT_ON_ANSWER => array(
				/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
				__( 'commented %1$s by %2$s', 'easyq' ),
				/* translators: %s: when it happened, e.g. "5 minutes ago". */
				__( 'commented %s', 'easyq' ),
			),
			'C-' . Post::UPDATE_TYPE, 'C-' . Post::UPDATE_PARENT => array(
				/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
				__( 'comment moved %1$s by %2$s', 'easyq' ),
				/* translators: %s: when it happened, e.g. "5 minutes ago". */
				__( 'comment moved %s', 'easyq' ),
			),
			'C-' . Post::UPDATE_VISIBLE => array(
				/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
				__( 'comment reshown %1$s by %2$s', 'easyq' ),
				/* translators: %s: when it happened, e.g. "5 minutes ago". */
				__( 'comment reshown %s', 'easyq' ),
			),
			'C-' . Post::UPDATE_CONTENT => array(
				/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
				__( 'comment edited %1$s by %2$s', 'easyq' ),
				/* translators: %s: when it happened, e.g. "5 minutes ago". */
				__( 'comment edited %s', 'easyq' ),
			),
			'own-Q-' . Post::UPDATE_VISIBLE => $hidden
				? array(
					/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
					__( 'your question hidden %1$s by %2$s', 'easyq' ),
					/* translators: %s: when it happened, e.g. "5 minutes ago". */
					__( 'your question hidden %s', 'easyq' ),
				)
				: array(
					/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
					__( 'your question reshown %1$s by %2$s', 'easyq' ),
					/* translators: %s: when it happened, e.g. "5 minutes ago". */
					__( 'your question reshown %s', 'easyq' ),
				),
			'own-Q-' . Post::UPDATE_CLOSED => $closed
				? array(
					/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
					__( 'your question closed %1$s by %2$s', 'easyq' ),
					/* translators: %s: when it happened, e.g. "5 minutes ago". */
					__( 'your question closed %s', 'easyq' ),
				)
				: array(
					/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
					__( 'your question reopened %1$s by %2$s', 'easyq' ),
					/* translators: %s: when it happened, e.g. "5 minutes ago". */
					__( 'your question reopened %s', 'easyq' ),
				),
			'own-Q-' . Post::UPDATE_TAGS => array(
				/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
				__( 'your question retagged %1$s by %2$s', 'easyq' ),
				/* translators: %s: when it happened, e.g. "5 minutes ago". */
				__( 'your question retagged %s', 'easyq' ),
			),
			'own-Q-' . Post::UPDATE_CATEGORY => array(
				/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
				__( 'your question recategorized %1$s by %2$s', 'easyq' ),
				/* translators: %s: when it happened, e.g. "5 minutes ago". */
				__( 'your question recategorized %s', 'easyq' ),
			),
			'own-Q-' . Updates::FOLLOWS => array(
				/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
				__( 'asked on your answer %1$s by %2$s', 'easyq' ),
				/* translators: %s: when it happened, e.g. "5 minutes ago". */
				__( 'asked on your answer %s', 'easyq' ),
			),
			'own-A-' => array(
				/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
				__( 'your question answered %1$s by %2$s', 'easyq' ),
				/* translators: %s: when it happened, e.g. "5 minutes ago". */
				__( 'your question answered %s', 'easyq' ),
			),
			'own-A-' . Post::UPDATE_SELECTED => array(
				/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
				__( 'your answer selected %1$s by %2$s', 'easyq' ),
				/* translators: %s: when it happened, e.g. "5 minutes ago". */
				__( 'your answer selected %s', 'easyq' ),
			),
			'own-A-' . Post::UPDATE_VISIBLE => $hidden
				? array(
					/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
					__( 'your answer hidden %1$s by %2$s', 'easyq' ),
					/* translators: %s: when it happened, e.g. "5 minutes ago". */
					__( 'your answer hidden %s', 'easyq' ),
				)
				: array(
					/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
					__( 'your answer reshown %1$s by %2$s', 'easyq' ),
					/* translators: %s: when it happened, e.g. "5 minutes ago". */
					__( 'your answer reshown %s', 'easyq' ),
				),
			'own-A-' . Post::UPDATE_CONTENT => array(
				/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
				__( 'your answer edited %1$s by %2$s', 'easyq' ),
				/* translators: %s: when it happened, e.g. "5 minutes ago". */
				__( 'your answer edited %s', 'easyq' ),
			),
			'own-C-' . Updates::COMMENT_ON_QUESTION => array(
				/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
				__( 'your question commented %1$s by %2$s', 'easyq' ),
				/* translators: %s: when it happened, e.g. "5 minutes ago". */
				__( 'your question commented %s', 'easyq' ),
			),
			'own-C-' . Updates::COMMENT_ON_ANSWER => array(
				/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
				__( 'your answer commented %1$s by %2$s', 'easyq' ),
				/* translators: %s: when it happened, e.g. "5 minutes ago". */
				__( 'your answer commented %s', 'easyq' ),
			),
			'own-C-' . Updates::FOLLOWS => array(
				/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
				__( 'your comment followed %1$s by %2$s', 'easyq' ),
				/* translators: %s: when it happened, e.g. "5 minutes ago". */
				__( 'your comment followed %s', 'easyq' ),
			),
			'own-C-' . Post::UPDATE_TYPE, 'own-C-' . Post::UPDATE_PARENT => array(
				/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
				__( 'your comment moved %1$s by %2$s', 'easyq' ),
				/* translators: %s: when it happened, e.g. "5 minutes ago". */
				__( 'your comment moved %s', 'easyq' ),
			),
			'own-C-' . Post::UPDATE_VISIBLE => $hidden
				? array(
					/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
					__( 'your comment hidden %1$s by %2$s', 'easyq' ),
					/* translators: %s: when it happened, e.g. "5 minutes ago". */
					__( 'your comment hidden %s', 'easyq' ),
				)
				: array(
					/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
					__( 'your comment reshown %1$s by %2$s', 'easyq' ),
					/* translators: %s: when it happened, e.g. "5 minutes ago". */
					__( 'your comment reshown %s', 'easyq' ),
				),
			'own-C-' . Post::UPDATE_CONTENT => array(
				/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
				__( 'your comment edited %1$s by %2$s', 'easyq' ),
				/* translators: %s: when it happened, e.g. "5 minutes ago". */
				__( 'your comment edited %s', 'easyq' ),
			),
			default => $this->personal
				? array(
					/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
					__( 'your question edited %1$s by %2$s', 'easyq' ),
					/* translators: %s: when it happened, e.g. "5 minutes ago". */
					__( 'your question edited %s', 'easyq' ),
				)
				: array(
					/* translators: 1: when it happened, e.g. "5 minutes ago", 2: who did it. */
					__( 'edited %1$s by %2$s', 'easyq' ),
					/* translators: %s: when it happened, e.g. "5 minutes ago". */
					__( 'edited %s', 'easyq' ),
				),
		};

		return $with_author ? $pair[0] : $pair[1];
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
