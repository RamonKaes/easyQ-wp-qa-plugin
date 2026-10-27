<?php
/**
 * Post model.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Posts;

use EasyQ\Database\Row;
use EasyQ\Util\Text;

defined( 'ABSPATH' ) || exit;

/**
 * A question, answer, comment or close note, as stored in the posts table.
 *
 * Instances are immutable snapshots; services reload posts after changing them.
 *
 * @since 0.1.0
 */
final class Post {

	/**
	 * Type of questions.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const QUESTION = 'Q';

	/**
	 * Type of answers.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ANSWER = 'A';

	/**
	 * Type of comments.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const COMMENT = 'C';

	/**
	 * Type of notes, e.g. the reason a question was closed.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NOTE = 'NOTE';

	/**
	 * Status of visible posts.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const STATUS_NORMAL = 'normal';

	/**
	 * Status of hidden posts.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const STATUS_HIDDEN = 'hidden';

	/**
	 * Status of posts waiting for approval.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const STATUS_QUEUED = 'queued';

	/**
	 * Update type of a changed category. Update types are stored in the `update_type`
	 * column, as in Question2Answer.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const UPDATE_CATEGORY = 'A';

	/**
	 * Update type of closing or reopening a question.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const UPDATE_CLOSED = 'C';

	/**
	 * Update type of edited content.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const UPDATE_CONTENT = 'E';

	/**
	 * Update type of a post moved to another parent.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const UPDATE_PARENT = 'M';

	/**
	 * Update type of selecting the best answer.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const UPDATE_SELECTED = 'S';

	/**
	 * Update type of changed tags.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const UPDATE_TAGS = 'T';

	/**
	 * Update type of a post converted to another type, e.g. an answer into a comment.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const UPDATE_TYPE = 'Y';

	/**
	 * Update type of hiding or showing a post again.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const UPDATE_VISIBLE = 'H';

	/**
	 * Sets up the post.
	 *
	 * @since 0.1.0
	 *
	 * @param int                  $id                Post ID.
	 * @param string               $type              Stored type, e.g. `Q`, `A_HIDDEN` or `C_QUEUED`.
	 * @param int|null             $parent_id         Parent post: the question of an answer, the question or answer of a comment, the answer of a follow-on question.
	 * @param int|null             $category_id       Category ID.
	 * @param array<int, int|null> $category_path IDs of the category's ancestors, from the top level down.
	 * @param int                  $answer_count      Visible answers (questions only).
	 * @param int                  $answer_max_votes  Highest net votes of a visible answer (questions only).
	 * @param int|null             $selected_child_id Selected answer (questions only).
	 * @param int|null             $closed_by_id      Duplicate question or close note; set if the question is closed.
	 * @param int|null             $user_id           Author.
	 * @param int|null             $cookie_id         Cookie of an anonymous author.
	 * @param string|null          $created_ip        Binary IP address of the author.
	 * @param int|null             $last_user_id      User who last changed the post.
	 * @param string|null          $last_ip           Binary IP address of the last change.
	 * @param int                  $upvotes           Upvotes.
	 * @param int                  $downvotes         Downvotes.
	 * @param int                  $net_votes         Upvotes minus downvotes.
	 * @param int                  $views             Views (questions only).
	 * @param float|null           $hotness           Hotness score (questions only).
	 * @param int                  $flag_count        Number of flags.
	 * @param string               $format            Content format: '' or 'html'.
	 * @param string               $created           Creation time, MySQL format in UTC.
	 * @param string|null          $updated           Time of the last change, MySQL format in UTC.
	 * @param string|null          $update_type       Kind of the last change, see the UPDATE_* constants.
	 * @param string|null          $title             Title (questions only).
	 * @param string|null          $content           Content.
	 * @param string|null          $tags              Comma-separated tags (questions only).
	 * @param string|null          $author_name       Name of an anonymous author.
	 * @param string|null          $notify            Email address for notifications, `@` for the author's account address, or null.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $type,
		public readonly ?int $parent_id,
		public readonly ?int $category_id,
		public readonly array $category_path,
		public readonly int $answer_count,
		public readonly int $answer_max_votes,
		public readonly ?int $selected_child_id,
		public readonly ?int $closed_by_id,
		public readonly ?int $user_id,
		public readonly ?int $cookie_id,
		public readonly ?string $created_ip,
		public readonly ?int $last_user_id,
		public readonly ?string $last_ip,
		public readonly int $upvotes,
		public readonly int $downvotes,
		public readonly int $net_votes,
		public readonly int $views,
		public readonly ?float $hotness,
		public readonly int $flag_count,
		public readonly string $format,
		public readonly string $created,
		public readonly ?string $updated,
		public readonly ?string $update_type,
		public readonly ?string $title,
		public readonly ?string $content,
		public readonly ?string $tags,
		public readonly ?string $author_name,
		public readonly ?string $notify
	) {
	}

	/**
	 * Creates a post from a database row.
	 *
	 * @since 0.1.0
	 *
	 * @param object $row Row of the posts table.
	 * @return self
	 */
	public static function from_row( object $row ): self {
		return new self(
			Row::int( $row, 'id' ),
			Row::string( $row, 'type' ),
			Row::nullable_int( $row, 'parent_id' ),
			Row::nullable_int( $row, 'category_id' ),
			array(
				Row::nullable_int( $row, 'category_path_1' ),
				Row::nullable_int( $row, 'category_path_2' ),
				Row::nullable_int( $row, 'category_path_3' ),
			),
			Row::int( $row, 'answer_count' ),
			Row::int( $row, 'answer_max_votes' ),
			Row::nullable_int( $row, 'selected_child_id' ),
			Row::nullable_int( $row, 'closed_by_id' ),
			Row::nullable_int( $row, 'user_id' ),
			Row::nullable_int( $row, 'cookie_id' ),
			Row::nullable_string( $row, 'created_ip' ),
			Row::nullable_int( $row, 'last_user_id' ),
			Row::nullable_string( $row, 'last_ip' ),
			Row::int( $row, 'upvotes' ),
			Row::int( $row, 'downvotes' ),
			Row::int( $row, 'net_votes' ),
			Row::int( $row, 'views' ),
			Row::nullable_float( $row, 'hotness' ),
			Row::int( $row, 'flag_count' ),
			Row::string( $row, 'format' ),
			Row::string( $row, 'created' ),
			Row::nullable_string( $row, 'updated' ),
			Row::nullable_string( $row, 'update_type' ),
			Row::nullable_string( $row, 'title' ),
			Row::nullable_string( $row, 'content' ),
			Row::nullable_string( $row, 'tags' ),
			Row::nullable_string( $row, 'author_name' ),
			Row::nullable_string( $row, 'notify' )
		);
	}

	/**
	 * Returns the base type without status: Q, A, C or NOTE.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function base_type(): string {
		return self::NOTE === $this->type ? self::NOTE : substr( $this->type, 0, 1 );
	}

	/**
	 * Returns the status: normal, hidden or queued.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function status(): string {
		return match ( true ) {
			str_ends_with( $this->type, '_HIDDEN' ) => self::STATUS_HIDDEN,
			str_ends_with( $this->type, '_QUEUED' ) => self::STATUS_QUEUED,
			default                                 => self::STATUS_NORMAL,
		};
	}

	/**
	 * Builds the stored type for a base type and status.
	 *
	 * @since 0.1.0
	 *
	 * @param string $base_type Q, A or C.
	 * @param string $status    One of the STATUS_* constants.
	 * @return string
	 */
	public static function type_for( string $base_type, string $status ): string {
		return match ( $status ) {
			self::STATUS_HIDDEN => $base_type . '_HIDDEN',
			self::STATUS_QUEUED => $base_type . '_QUEUED',
			default             => $base_type,
		};
	}

	/**
	 * Checks whether the post is visible to everyone.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_visible(): bool {
		return self::STATUS_NORMAL === $this->status();
	}

	/**
	 * Checks whether the post is hidden.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_hidden(): bool {
		return self::STATUS_HIDDEN === $this->status();
	}

	/**
	 * Checks whether the post waits for moderation.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_queued(): bool {
		return self::STATUS_QUEUED === $this->status();
	}

	/**
	 * Checks whether the question is closed.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_closed(): bool {
		return null !== $this->closed_by_id;
	}

	/**
	 * Returns the tags of a question.
	 *
	 * @since 0.1.0
	 *
	 * @return string[]
	 */
	public function tag_list(): array {
		return Text::string_to_tags( $this->tags );
	}

	/**
	 * Returns the plain text of the content.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function text(): string {
		return Text::content_to_text( (string) $this->content, $this->format );
	}
}
