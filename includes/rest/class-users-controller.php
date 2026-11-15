<?php
/**
 * REST endpoints for Q&A user profiles.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\REST;

use EasyQ\Events\Events;
use EasyQ\Frontend\URLs;
use EasyQ\Limits\Limits;
use EasyQ\Mail\Notifications;
use EasyQ\Messages\Messages;
use EasyQ\Newsletter\Newsletter;
use EasyQ\Points\Points;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Users\Avatars;
use EasyQ\Users\Levels;
use EasyQ\Users\User_Query;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Lists users and returns and changes Q&A profiles.
 *
 * Routes:
 *
 * - `GET /easyq/v1/users`: users by points, newest users or users with special levels
 * - `GET /easyq/v1/users/{id}`
 * - `GET|POST /easyq/v1/users/me`: the profile of the current user; POST changes
 *   profile fields and the avatar (multipart upload in the field `avatar`)
 *
 * Profiles contain only public information: name, avatar, points, level,
 * activity counts and the profile fields the viewer may see.
 *
 * @since 0.1.0
 */
final class Users_Controller extends Controller {

	/**
	 * Route base.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	protected $rest_base = 'users';

	/**
	 * Whether the items can be read one by one, so that responses link to them.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	protected bool $item_route = true;

	/**
	 * Registers the routes.
	 *
	 * @since 0.1.0
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/users',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'sort' => array(
							'description' => __( 'Users by points, the newest users, users with special levels, or the favorites of the current user.', 'easyq' ),
							'type'        => 'string',
							'enum'        => array_merge( array_keys( User_Query::SORTS ), array( 'favorites' ) ),
							'default'     => 'points',
						),
					// Lists show users as they are embedded, as core embeds authors: without profile fields and counts.
					) + $this->pagination_args( Options::get_int( 'lists', 'page_size_users' ) ) + $this->read_args( 'embed' ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/users/me',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_current_item' ),
					'permission_callback' => array( $this, 'current_item_permissions_check' ),
					'args'                => $this->read_args(),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_current_item' ),
					'permission_callback' => array( $this, 'update_current_item_permissions_check' ),
					'args'                => array(
						'fields'        => array(
							'description'          => __( 'Values of the profile fields, keyed by field name.', 'easyq' ),
							'type'                 => 'object',
							'additionalProperties' => array( 'type' => 'string' ),
						),
						'remove_avatar' => array(
							'description' => __( 'Whether to remove the uploaded avatar.', 'easyq' ),
							'type'        => 'boolean',
							'default'     => false,
						),
						'notifications' => array(
							'description' => __( 'Whether the user gets emails about their posts.', 'easyq' ),
							'type'        => 'boolean',
						),
						'messages'      => array(
							'description' => __( 'Whether others may send the user private messages.', 'easyq' ),
							'type'        => 'boolean',
						),
						'wall'          => array(
							'description' => __( 'Whether others may post on the wall of the user.', 'easyq' ),
							'type'        => 'boolean',
						),
						'newsletter'    => array(
							'description' => __( 'Whether the user wants the newsletter; turning it on sends an email to confirm.', 'easyq' ),
							'type'        => 'boolean',
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/users/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => $this->read_args(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Checks whether the request may see the requested user list.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public function get_items_permissions_check( $request ): bool|WP_Error {
		$sort   = (string) self::string_param( $request, 'sort' );
		$option = User_Query::SORTS[ $sort ] ?? null;

		// As core: the edit context of the list for those who may list users.
		if ( 'edit' === self::context( $request ) && ! current_user_can( 'list_users' ) ) {
			return new WP_Error( 'rest_forbidden_context', __( 'Sorry, you are not allowed to list users.', 'easyq' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return $this->check_view( $option ?? 'permit_view_q_page', $this->actor(), null, true );
	}

	/**
	 * Lists users.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ): WP_REST_Response|WP_Error {
		$per_page = (int) self::int_param( $request, 'per_page' );
		$page     = max( 1, (int) self::int_param( $request, 'page' ) );
		$sort     = (string) self::string_param( $request, 'sort' );

		if ( 'favorites' === $sort ) {
			// The users the viewer follows, as the favorites page lists them; nothing for visitors.
			$ids   = Services::favorites()->shown_users( get_current_user_id() );
			$found = array(
				'ids'   => array_slice( $ids, ( $page - 1 ) * $per_page, $per_page ),
				'total' => count( $ids ),
			);
		} else {
			$found = Services::user_query()->find( $sort, $page, $per_page );
		}

		Services::user_query()->prime( $found['ids'] );

		$users = array_filter( array_map( 'get_userdata', $found['ids'] ) );

		return $this->paginated( $this->collection( $users, $request ), $found['total'], $per_page, $request );
	}

	/**
	 * Checks whether the request may see the Q&A area.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public function get_item_permissions_check( $request ): bool|WP_Error {
		return $this->check_view( 'permit_view_q_page', $this->actor(), null, true );
	}

	/**
	 * Returns a profile.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ): WP_REST_Response|WP_Error {
		$user = get_userdata( (int) self::int_param( $request, 'id' ) );
		if ( false === $user || ! Services::user_query()->is_shown( $user, $this->actor()->user_id ) ) {
			return REST_Errors::not_found();
		}

		// As core: the edit context, which holds the date of the registration, for the user and
		// those who may list or edit users.
		if ( 'edit' === self::context( $request ) && ! Services::user_query()->shows_registration( $user, $this->actor()->user_id ) ) {
			return new WP_Error( 'rest_forbidden_context', __( 'Sorry, you are not allowed to edit this user.', 'easyq' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return $this->prepare_item_for_response( $user, $request );
	}

	/**
	 * Checks whether the request comes from a logged-in user.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public function current_item_permissions_check( $request ): bool|WP_Error {
		return null === $this->actor()->user_id ? REST_Errors::permission( 'login' ) : true;
	}

	/**
	 * Returns the profile of the current user, with all profile fields.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_current_item( $request ): WP_REST_Response|WP_Error {
		$user = get_userdata( (int) $this->actor()->user_id );
		if ( false === $user ) {
			return REST_Errors::not_found();
		}

		return $this->respond_with( $this->format( $user, ! self::embedded( $request ), all_fields: true ), $request );
	}

	/**
	 * Checks whether the current user may change their profile.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public function update_current_item_permissions_check( $request ): bool|WP_Error {
		$actor = $this->actor();
		if ( null === $actor->user_id ) {
			return REST_Errors::permission( 'login' );
		}

		return $this->check( null, $actor, null, null === $this->avatar_file( $request ) ? null : Limits::UPLOADS );
	}

	/**
	 * Changes the profile fields and the avatar of the current user.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_current_item( $request ): WP_REST_Response|WP_Error {
		$actor   = $this->actor();
		$user_id = (int) $actor->user_id;
		$user    = get_userdata( $user_id );
		if ( false === $user ) {
			return REST_Errors::not_found();
		}

		$file = $this->avatar_file( $request );
		if ( null !== $file && ! Options::get_bool( 'users', 'avatar_allow_upload' ) ) {
			return REST_Errors::permission( 'avatar' );
		}

		// Refusals come before anything is saved, as core validates before `wp_update_user()`.
		$subscribe = $request->has_param( 'newsletter' ) && self::bool_param( $request, 'newsletter' ) && '' === Newsletter::state( $user_id );
		if ( $subscribe ) {
			$allowed = Services::newsletter()->can_subscribe( $user );
			if ( $allowed instanceof WP_Error ) {
				return $allowed;
			}
		}

		if ( null !== $file ) {
			// Every attempt counts, also one that is refused, as `qa_limits_increment()` comes before
			// the checks of the image in Question2Answer: decoding an image costs the server either way.
			Services::limits()->increment( Limits::UPLOADS, $actor );
			$uploaded = Avatars::upload( $user_id, $file );
			if ( $uploaded instanceof WP_Error ) {
				$errors = new WP_Error();
				$errors->add( 'avatar', $uploaded->get_error_message() );

				return REST_Errors::invalid( $errors );
			}
		} elseif ( self::bool_param( $request, 'remove_avatar' ) ) {
			Avatars::remove( $user_id );
		}

		$values = $request->get_param( 'fields' );
		if ( is_array( $values ) ) {
			foreach ( Services::user_fields()->all() as $field ) {
				if ( isset( $values[ $field->name ] ) && is_string( $values[ $field->name ] ) ) {
					Services::user_fields()->set_value( $user_id, $field, $values[ $field->name ] );
				}
			}
		}

		if ( $request->has_param( 'newsletter' ) && ! self::bool_param( $request, 'newsletter' ) ) {
			Newsletter::unsubscribe( $user_id );
		}

		Messages::set_preferences(
			$user_id,
			$request->has_param( 'messages' ) ? self::bool_param( $request, 'messages' ) : null,
			$request->has_param( 'wall' ) ? self::bool_param( $request, 'wall' ) : null
		);
		if ( $request->has_param( 'notifications' ) ) {
			Notifications::set_enabled( $user_id, self::bool_param( $request, 'notifications' ) );
		}

		Events::dispatch( 'u_save', array( 'user_id' => $user_id ), $actor );

		// The confirmation email goes last: only a failed `wp_mail()` can still go wrong here, and then the rest is saved.
		if ( $subscribe ) {
			$subscribed = Services::newsletter()->subscribe( $user );
			if ( $subscribed instanceof WP_Error ) {
				return $subscribed;
			}
		}

		return $this->respond_with( $this->format( $user, ! self::embedded( $request ), all_fields: true ), $request );
	}

	/**
	 * Returns a profile.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_User $user       User.
	 * @param bool    $details    Whether to include profile fields, rank and activity counts.
	 * @param bool    $all_fields Whether to include all profile fields, also those hidden from the viewer.
	 * @return array<string, mixed>
	 */
	public function format( WP_User $user, bool $details, bool $all_fields = false ): array {
		$level   = Services::user_levels()->level( $user->ID ) ?? Levels::BASIC;
		$profile = array(
			'id'     => $user->ID,
			'name'   => $user->display_name,
			'link'   => URLs::user( $user ),
			'avatar' => get_avatar_url( $user->ID, array( 'size' => 192 ) ),
			'level'  => Levels::labels()[ $level ] ?? '',
			'title'  => Services::user_titles()->title( $user->ID ),
			'points' => Services::points()->get( $user->ID ),
		);

		if ( ! $details ) {
			return $profile;
		}

		$viewer = $this->actor();
		$fields = array();
		foreach ( Services::user_fields()->all() as $field ) {
			$value = Services::user_fields()->value( $user->ID, $field );
			if ( $all_fields || ( '' !== $value && ( null === $field->permit || Services::permissions()->meets( $field->permit, $viewer ) ) ) ) {
				$fields[] = array(
					'name'  => $field->name,
					'label' => $field->display_label(),
					'value' => $value,
					'link'  => $field->is_link(),
				);
			}
		}

		$stats = Services::points()->stats( $user->ID );

		return $profile + array(
			'url'         => $user->user_url,
			'description' => $user->description,
			'registered'  => mysql_to_rfc3339( $user->user_registered ),
			'rank'        => Services::points()->rank( $user->ID ),
			'counts'      => array(
				'questions'          => $stats[ Points::QUESTIONS ],
				'answers'            => $stats[ Points::ANSWERS ],
				'comments'           => $stats[ Points::COMMENTS ],
				'answers_selected'   => $stats[ Points::ANSWERS_SELECTED ],
				'upvotes_received'   => $stats[ Points::UPVOTES_RECEIVED ],
				'downvotes_received' => $stats[ Points::DOWNVOTES_RECEIVED ],
			),
			'fields'      => $fields,
		) + ( $all_fields ? array(
			'notifications' => Notifications::enabled( $user->ID ),
			'messages'      => Messages::accepts_messages( $user->ID ),
			'wall'          => Messages::accepts_wall_posts( $user->ID ),
			'newsletter'    => Newsletter::state( $user->ID ),
		) : array() );
	}

	/**
	 * Returns the uploaded avatar of a request.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return array|null The file entry, or null if no file was sent.
	 * @phpstan-return array{name: string, type: string, tmp_name: string, size: int<0, max>, error: int<0, 8>}|null
	 */
	private function avatar_file( WP_REST_Request $request ): ?array {
		return Avatars::file_entry( $request->get_file_params()['avatar'] ?? null );
	}

	/**
	 * Prepares a user for the response; the `embed` context leaves out profile fields and counts.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed           $item    User.
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function prepare_item_for_response( $item, $request ): WP_REST_Response|WP_Error {
		if ( ! $item instanceof WP_User ) {
			return new WP_Error( 'easyq_invalid_item', __( 'The item cannot be shown.', 'easyq' ), array( 'status' => 500 ) );
		}

		return $this->respond_with( $this->format( $item, ! self::embedded( $request ) ), $request, $item );
	}

	/**
	 * Builds the schema of a user.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	protected function item_schema(): array {
		return Schemas::user();
	}
}
