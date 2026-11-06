<?php
/**
 * REST endpoints for wall posts and private messages.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\REST;

use EasyQ\Frontend\URLs;
use EasyQ\Messages\Message;
use EasyQ\Messages\Messages;
use EasyQ\Services;
use EasyQ\Settings\Options;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Walls of users and private messages.
 *
 * Routes:
 *
 * - `GET`, `POST /easyq/v1/users/{id}/wall`
 * - `DELETE /easyq/v1/users/{id}/wall/{message}`
 * - `GET`, `POST /easyq/v1/messages`
 * - `DELETE /easyq/v1/messages/{id}`
 *
 * @since 0.1.0
 */
final class Messages_Controller extends Controller {

	/**
	 * Route base.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	protected $rest_base = 'messages';

	/**
	 * Registers the routes.
	 *
	 * @since 0.1.0
	 */
	public function register_routes(): void {
		$content = array(
			'content' => array(
				'description' => __( 'Text of the message.', 'easyq' ),
				'type'        => 'string',
				'required'    => true,
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/users/(?P<id>\d+)/wall',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_wall' ),
					'permission_callback' => '__return_true',
					'args'                => $this->read_args() + $this->pagination_args( 10 ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'post_on_wall' ),
					'permission_callback' => array( $this, 'post_on_wall_permissions_check' ),
					'args'                => $content,
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/users/(?P<id>\d+)/wall/(?P<message>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_wall_post' ),
					'permission_callback' => array( $this, 'delete_wall_post_permissions_check' ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'logged_in' ),
					'args'                => $this->read_args() + array(
						'box'  => array(
							'description' => __( 'Received or sent messages.', 'easyq' ),
							'type'        => 'string',
							'enum'        => array( 'inbox', 'sent' ),
							'default'     => 'inbox',
						),
						'with' => array(
							'description' => __( 'The latest messages with this user instead, oldest first.', 'easyq' ),
							'type'        => 'integer',
						),
					) + $this->pagination_args( 10 ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'logged_in' ),
					'args'                => $content + array(
						'to' => array(
							'description' => __( 'Recipient.', 'easyq' ),
							'type'        => 'integer',
							'required'    => true,
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'logged_in' ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Checks that the request comes from a logged in user.
	 *
	 * @since 0.1.0
	 *
	 * @return true|WP_Error
	 */
	public function logged_in(): bool|WP_Error {
		return is_user_logged_in() ? true : REST_Errors::permission( 'login' );
	}

	/**
	 * Lists the posts on a wall.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_wall( $request ): WP_REST_Response|WP_Error {
		$owner = $this->owner( $request );
		if ( $owner instanceof WP_Error ) {
			return $owner;
		}

		$per_page = max( 1, (int) self::int_param( $request, 'per_page' ) );
		$found    = Services::messages()->wall( $owner->ID, max( 1, (int) self::int_param( $request, 'page' ) ), $per_page );

		return $this->paginated( $this->collection( $found['messages'], $request ), $found['total'], $per_page );
	}

	/**
	 * Posts on a wall.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function post_on_wall( $request ): WP_REST_Response|WP_Error {
		$owner = $this->owner( $request );
		if ( $owner instanceof WP_Error ) {
			return $owner;
		}

		$id = Services::messages()->post_on_wall( $this->actor(), $owner, (string) self::string_param( $request, 'content' ) );
		if ( $id instanceof WP_Error ) {
			return $id;
		}

		$response = $this->prepare_item_for_response( Services::messages()->find( $id ), $request );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Checks whether the actor may post on the wall: the site is writable, the wall exists and
	 * accepts posts from them.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public function post_on_wall_permissions_check( $request ): bool|WP_Error {
		$writable = $this->check_writable( $this->actor() );
		if ( true !== $writable ) {
			return $writable;
		}

		$owner = $this->owner( $request );
		if ( $owner instanceof WP_Error ) {
			return $owner;
		}

		$reason = Services::messages()->wall_error( $this->actor(), $owner );

		return null === $reason ? true : Messages::refused( $reason );
	}

	/**
	 * Checks whether the actor may delete a wall post: the post is on this wall and theirs to delete.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public function delete_wall_post_permissions_check( $request ): bool|WP_Error {
		$post = Services::messages()->find( (int) self::int_param( $request, 'message' ) );
		if ( null === $post || Messages::WALL !== $post->type || (int) self::int_param( $request, 'id' ) !== $post->to_user_id ) {
			return REST_Errors::not_found();
		}

		return Services::messages()->can_delete_wall_post( $this->actor(), $post )
			? true
			: new WP_Error( 'easyq_permission', __( 'You cannot delete this post.', 'easyq' ), array( 'status' => 403 ) );
	}

	/**
	 * Deletes a wall post.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_wall_post( $request ): WP_REST_Response|WP_Error {
		$id   = (int) self::int_param( $request, 'message' );
		$post = Services::messages()->find( $id );
		if ( null === $post || (int) self::int_param( $request, 'id' ) !== $post->to_user_id ) {
			return REST_Errors::not_found();
		}

		$previous = $this->prepare_item_for_response( $post, $request )->get_data();
		$deleted  = Services::messages()->delete_wall_post( $this->actor(), $id );
		if ( $deleted instanceof WP_Error ) {
			return $deleted;
		}

		return new WP_REST_Response(
			array(
				'deleted'  => true,
				'previous' => $previous,
			)
		);
	}

	/**
	 * Lists the received or sent private messages, or the conversation with a user.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ): WP_REST_Response|WP_Error {
		if ( ! Options::get_bool( 'users', 'allow_private_messages' ) || ! Options::get_bool( 'users', 'show_message_history' ) ) {
			return REST_Errors::not_found();
		}

		$user_id = get_current_user_id();
		$with    = self::int_param( $request, 'with' );
		if ( null !== $with ) {
			$messages = Services::messages()->conversation( $user_id, $with );

			return new WP_REST_Response( $this->collection( $messages, $request ) );
		}

		$per_page = max( 1, (int) self::int_param( $request, 'per_page' ) );
		$found    = Services::messages()->box( $user_id, (string) self::string_param( $request, 'box' ), max( 1, (int) self::int_param( $request, 'page' ) ), $per_page );

		return $this->paginated( $this->collection( $found['messages'], $request ), $found['total'], $per_page );
	}

	/**
	 * Sends a private message.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ): WP_REST_Response|WP_Error {
		$writable = $this->check_writable( $this->actor() );
		if ( true !== $writable ) {
			return $writable;
		}

		$to = get_userdata( (int) self::int_param( $request, 'to' ) );
		if ( false === $to || ! Services::user_query()->is_member( $to ) ) {
			return REST_Errors::not_found();
		}

		$id = Services::messages()->send( $this->actor(), $to, (string) self::string_param( $request, 'content' ) );
		if ( $id instanceof WP_Error ) {
			return $id;
		}

		$message  = null === $id ? null : Services::messages()->find( $id );
		$response = null === $message
			? new WP_REST_Response(
				array(
					'sent'   => true,
					'stored' => false,
				)
			)
			: $this->prepare_item_for_response( $message, $request );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Deletes a private message from the box of the current user.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ): WP_REST_Response|WP_Error {
		$message = Services::messages()->delete_private( $this->actor(), (int) self::int_param( $request, 'id' ) );
		if ( $message instanceof WP_Error ) {
			return $message;
		}

		return new WP_REST_Response(
			array(
				'deleted'  => true,
				'previous' => $this->prepare_item_for_response( $message, $request )->get_data(),
			)
		);
	}

	/**
	 * Prepares a message for a response.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed           $item    Message.
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function prepare_item_for_response( $item, $request ): WP_REST_Response {
		if ( ! $item instanceof Message ) {
			return new WP_REST_Response( array() );
		}

		$actor = $this->actor();

		return $this->respond_with(
			array(
				'id'       => $item->id,
				'type'     => Messages::WALL === $item->type ? 'wall' : 'private',
				'from'     => self::user_data( $item->from_user_id ),
				'to'       => self::user_data( $item->to_user_id ),
				'content'  => array( 'rendered' => $item->html() ),
				'date_gmt' => mysql_to_rfc3339( $item->created ),
				'can'      => array(
					'delete' => Messages::WALL === $item->type
						? Services::messages()->can_delete_wall_post( $actor, $item )
						: null !== $actor->user_id && in_array( $actor->user_id, array( $item->from_user_id, $item->to_user_id ), true ),
				),
			),
			$request
		);
	}

	/**
	 * Returns the owner of the wall of a request, if walls are on.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_User|WP_Error
	 */
	private function owner( WP_REST_Request $request ): WP_User|WP_Error {
		$owner = get_userdata( (int) self::int_param( $request, 'id' ) );
		if ( ! Options::get_bool( 'users', 'allow_user_walls' ) || false === $owner || ! Services::user_query()->is_member( $owner ) ) {
			return REST_Errors::not_found();
		}

		return $owner;
	}

	/**
	 * Returns the public data of a user, or null for a deleted one.
	 *
	 * @since 0.1.0
	 *
	 * @param int|null $user_id User ID.
	 * @return array{id: int, name: string, link: string}|null
	 */
	private static function user_data( ?int $user_id ): ?array {
		$user = null === $user_id ? false : get_userdata( $user_id );

		return false === $user ? null : array(
			'id'   => $user->ID,
			'name' => $user->display_name,
			'link' => URLs::user( $user ),
		);
	}

	/**
	 * Builds the schema of the response.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	protected function item_schema(): array {
		return Schemas::message();
	}
}
