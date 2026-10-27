<?php
/**
 * The person or process performing an action.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ;

use EasyQ\Settings\Options;
use EasyQ\Util\IP;

defined( 'ABSPATH' ) || exit;

/**
 * Identifies who performs an action: a user, an anonymous visitor with a cookie, or the system.
 *
 * @since 0.1.0
 */
final class Actor {

	/**
	 * Sets up the actor.
	 *
	 * @since 0.1.0
	 *
	 * @param int|null    $user_id   WordPress user ID, or null for visitors and the system.
	 * @param int|null    $cookie_id Visitor cookie ID, or null.
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
	 * The cookie is kept for logged-in users too, so they can claim posts they wrote before registering.
	 *
	 * @since 0.1.0
	 *
	 * @param int|null $cookie_id Anonymous visitor cookie ID.
	 * @return self
	 */
	public static function current( ?int $cookie_id = null ): self {
		$user_id = get_current_user_id();

		return new self( $user_id > 0 ? $user_id : null, $cookie_id, IP::current() );
	}

	/**
	 * Returns an actor for actions performed by the system, e.g. scheduled tasks.
	 *
	 * @since 0.1.0
	 *
	 * @return self
	 */
	public static function system(): self {
		return new self();
	}

	/**
	 * Returns an actor for a user without request context.
	 *
	 * @since 0.1.0
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
	 * @since 0.1.0
	 *
	 * @return string|null
	 */
	public function stored_ip(): ?string {
		if ( null === $this->ip ) {
			return null;
		}

		$ip = Options::get_bool( 'spam', 'ip_anonymize' ) ? IP::anonymize( $this->ip ) : $this->ip;

		return IP::to_binary( $ip );
	}
}
