<?php
/**
 * A private message or wall post.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Messages;

use EasyQ\Database\Row;

defined( 'ABSPATH' ) || exit;

/**
 * A row of the messages table.
 *
 * @since 0.1.0
 */
final class Message {

	/**
	 * Sets up the message.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $id           ID.
	 * @param string   $type         `public` for wall posts, `private` for private messages.
	 * @param int|null $from_user_id Author.
	 * @param int|null $to_user_id   Recipient, or owner of the wall.
	 * @param bool     $from_hidden  Whether the author deleted it from their sent messages.
	 * @param bool     $to_hidden    Whether the recipient deleted it from their inbox.
	 * @param string   $content      Text.
	 * @param string   $format       Format; empty for plain text.
	 * @param string   $created      Time of sending, MySQL format in UTC.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $type,
		public readonly ?int $from_user_id,
		public readonly ?int $to_user_id,
		public readonly bool $from_hidden,
		public readonly bool $to_hidden,
		public readonly string $content,
		public readonly string $format,
		public readonly string $created
	) {
	}

	/**
	 * Creates a message from a database row.
	 *
	 * @since 0.1.0
	 *
	 * @param object $row Row.
	 * @return self
	 */
	public static function from_row( object $row ): self {
		return new self(
			Row::int( $row, 'id' ),
			Row::string( $row, 'type' ),
			Row::nullable_int( $row, 'from_user_id' ),
			Row::nullable_int( $row, 'to_user_id' ),
			1 === Row::int( $row, 'from_hidden' ),
			1 === Row::int( $row, 'to_hidden' ),
			Row::string( $row, 'content' ),
			Row::string( $row, 'format' ),
			Row::string( $row, 'created' )
		);
	}

	/**
	 * Returns the text as HTML: paragraphs and line breaks of the plain text.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function html(): string {
		return wpautop( esc_html( $this->content ) );
	}
}
