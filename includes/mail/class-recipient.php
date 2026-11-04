<?php
/**
 * Recipient of an email.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Mail;

use EasyQ\Posts\Post;

defined( 'ABSPATH' ) || exit;

/**
 * Describes who receives an email.
 *
 * @since 0.1.0
 */
final class Recipient {

	/**
	 * Sets up the recipient.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $email   Email address.
	 * @param int|null    $user_id User, for the language of the email and the link to the account page.
	 * @param string|null $name    Name for the salutation; null for none.
	 * @param Post|null   $post    Post the email is about because its author asked for it; adds the links to stop the emails.
	 */
	public function __construct(
		public readonly string $email,
		public readonly ?int $user_id = null,
		public readonly ?string $name = null,
		public readonly ?Post $post = null
	) {
	}
}
