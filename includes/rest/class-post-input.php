<?php
/**
 * Post input of the REST API.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\REST;

use EasyQ\Actor;
use EasyQ\Posts\Post_Service;
use EasyQ\Settings\Options;
use EasyQ\Users\Visitor_Cookie;
use EasyQ\Util\Text;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and sanitizes the fields of new and edited posts.
 *
 * @since 0.1.0
 */
final class Post_Input {

	/**
	 * Returns the argument definitions shared by all post types.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $required Whether the content is required.
	 * @return array<string, array<string, mixed>>
	 */
	public static function content_args( bool $required ): array {
		return array(
			'content' => array(
				'description' => __( 'Content of the post.', 'easyq' ),
				'type'        => 'string',
				'required'    => $required,
			),
			'format'  => array(
				'description' => __( 'Content format: empty for plain text, or html.', 'easyq' ),
				'type'        => 'string',
				'enum'        => array( '', 'html' ),
				'default'     => '',
			),
			'notify'  => array(
				'description' => __( 'Whether the author wants to be notified about responses.', 'easyq' ),
				'type'        => 'boolean',
			),
			'email'   => array(
				'description' => __( 'Email address for notifications of anonymous authors.', 'easyq' ),
				'type'        => 'string',
				'format'      => 'email',
			),
			'name'    => array(
				'description' => __( 'Name of an anonymous author.', 'easyq' ),
				'type'        => 'string',
			),
		);
	}

	/**
	 * Returns the content of a request, filtered for its format.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return array{content: string, format: string}
	 */
	public static function content( WP_REST_Request $request ): array {
		$format  = 'html' === $request->get_param( 'format' ) ? 'html' : '';
		$content = $request->get_param( 'content' );
		$content = is_string( $content ) ? wp_check_invalid_utf8( $content ) : '';

		return array(
			'content' => 'html' === $format ? wp_kses_post( $content ) : $content,
			'format'  => $format,
		);
	}

	/**
	 * Returns the tags of a request, as an array or as text entered by a user.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Tags parameter.
	 * @return string[]
	 */
	public static function tags( mixed $value ): array {
		if ( is_array( $value ) ) {
			return Text::normalize_tags( array_map( static fn ( $tag ): string => is_scalar( $tag ) ? (string) $tag : '', $value ) );
		}

		return is_string( $value ) ? Text::parse_tags( $value, Options::get_bool( 'posting', 'tag_separator_comma' ) ) : array();
	}

	/**
	 * Checks whether the site uses tags.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public static function uses_tags(): bool {
		return str_contains( Options::get_string( 'general', 'tags_or_categories' ), 't' );
	}

	/**
	 * Checks whether the site uses categories.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public static function uses_categories(): bool {
		return str_contains( Options::get_string( 'general', 'tags_or_categories' ), 'c' );
	}

	/**
	 * Describes the author of a new post.
	 *
	 * Anonymous authors get a visitor cookie, so they can edit and claim their post later.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor           $actor   The actor.
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return array{actor: Actor, name: string|null, notify: string|null}
	 */
	public static function author( Actor $actor, WP_REST_Request $request ): array {
		$email  = $request->get_param( 'email' );
		$email  = is_string( $email ) ? sanitize_email( $email ) : null;
		$notify = $request->get_param( 'notify' );
		$notify = is_bool( $notify ) || is_int( $notify ) || is_string( $notify ) ? rest_sanitize_boolean( $notify ) : Options::get_bool( 'posting', 'notify_users_default' );

		if ( null !== $actor->user_id ) {
			return array(
				'actor'  => $actor,
				'name'   => null,
				'notify' => Post_Service::notify_value( $actor, $notify, null ),
			);
		}

		$actor = new Actor( null, Visitor_Cookie::ensure( $actor ), $actor->ip );
		$name  = $request->get_param( 'name' );
		$name  = is_string( $name ) && Options::get_bool( 'posting', 'allow_anonymous_naming' ) ? sanitize_text_field( $name ) : '';

		return array(
			'actor'  => $actor,
			'name'   => '' === $name ? null : $name,
			'notify' => Post_Service::notify_value( $actor, $notify, $email ),
		);
	}

	/**
	 * Returns the fields for the validator.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request      $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @param array<string, mixed> $fields  Fields read so far.
	 * @return array<string, mixed>
	 */
	public static function validation_fields( WP_REST_Request $request, array $fields ): array {
		foreach ( array( 'notify', 'email', 'name' ) as $key ) {
			if ( $request->has_param( $key ) ) {
				$fields[ $key ] = $request->get_param( $key );
			}
		}

		return $fields;
	}
}
