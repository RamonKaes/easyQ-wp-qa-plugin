<?php
/**
 * The person or process performing an action.
 *
 * @package easyQ
 */

namespace EasyQ;

use EasyQ\Settings\Options;
use EasyQ\Util\Ip;

defined( 'ABSPATH' ) || exit;

/**
 * Identifies who performs an action: a user, an anonymous visitor with a cookie, or the system.
 */
final class Actor {

	/**
	 * Sets up the actor.
	 *
	 * @param int|null    $user_id   WordPress user ID, or null for visitors and the system.
	 * @param int|null    $cookie_id Anonymous visitor cookie ID, or null.
	 * @param string|null $ip        IP address of the request, or null.
	 */
	public function __construct(
		public readonly ?int $user_id = null,
		public readonly ?int $cookie_id = null,
		public readonly ?string $ip = null
	) {
	}

	/**
	 * Returns the actor of the current request.
	 *
	 * @param int|null $cookie_id Anonymous visitor cookie ID, used only if no user is logged in.
	 * @return self
	 */
	public static function current( ?int $cookie_id = null ): self {
		$user_id = get_current_user_id();

		return new self( $user_id > 0 ? $user_id : null, $user_id > 0 ? null : $cookie_id, Ip::current() );
	}

	/**
	 * Returns an actor for actions performed by the system, e.g. scheduled tasks.
	 *
	 * @return self
	 */
	public static function system(): self {
		return new self();
	}

	/**
	 * Returns an actor for a user without request context.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return self
	 */
	public static function user( int $user_id ): self {
		return new self( $user_id );
	}

	/**
	 * Returns the IP address in the binary form stored in the database, anonymized if the site asks for it.
	 *
	 * @return string|null
	 */
	public function stored_ip(): ?string {
		if ( null === $this->ip ) {
			return null;
		}

		$ip = Options::get_bool( 'spam', 'ip_anonymize' ) ? Ip::anonymize( $this->ip ) : $this->ip;

		return Ip::to_binary( $ip );
	}
}
