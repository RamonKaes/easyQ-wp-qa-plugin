<?php
/**
 * REST endpoints for user profile fields.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\REST;

use EasyQ\Capabilities;
use EasyQ\Services;
use EasyQ\Users\Permits;
use EasyQ\Users\User_Field;
use EasyQ\Users\User_Fields;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Lists, adds, changes and deletes the profile fields, for users who manage easyQ.
 *
 * Routes:
 *
 * - `GET` and `POST /easyq/v1/user-fields`
 * - `GET`, `PUT`/`PATCH` and `DELETE /easyq/v1/user-fields/{id}`
 *
 * The values users enter are part of `/easyq/v1/users/{id}` and `/easyq/v1/users/me`.
 *
 * @since 0.1.0
 */
final class User_Fields_Controller extends Controller {

	/**
	 * Field types with their flags.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, int>
	 */
	public const TYPES = array(
		'text'       => 0,
		'multi_line' => User_Field::FLAG_MULTI_LINE,
		'link'       => User_Field::FLAG_LINK,
	);

	/**
	 * Registers the routes.
	 *
	 * @since 0.1.0
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/user-fields',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_fields' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_field' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
					'args'                => $this->field_args( true ),
				),
				'schema' => array( Schemas::class, 'user_field' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/user-fields/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_field' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_field' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
					'args'                => $this->field_args( false ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_field' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
				),
				'schema' => array( Schemas::class, 'user_field' ),
			)
		);
	}

	/**
	 * Checks whether the current user may manage the fields.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public function manage_permissions_check( $request ): bool|WP_Error {
		if ( current_user_can( Capabilities::MANAGE ) ) {
			return true;
		}

		return REST_Errors::permission( is_user_logged_in() ? 'level' : 'login' );
	}

	/**
	 * Lists the fields in order.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function get_fields( $request ): WP_REST_Response {
		return new WP_REST_Response( array_map( array( self::class, 'format' ), array_values( Services::user_fields()->all() ) ) );
	}

	/**
	 * Returns a field.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_field( $request ): WP_REST_Response|WP_Error {
		$field = Services::user_fields()->find( (int) self::int_param( $request, 'id' ) );

		return null === $field ? REST_Errors::not_found() : new WP_REST_Response( self::format( $field ) );
	}

	/**
	 * Adds a field.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_field( $request ): WP_REST_Response|WP_Error {
		$fields = Services::user_fields();
		$result = $fields->create(
			(string) self::string_param( $request, 'name' ),
			sanitize_text_field( (string) self::string_param( $request, 'label' ) ),
			self::flags( (string) ( self::string_param( $request, 'type' ) ?? 'text' ), self::bool_param( $request, 'on_register' ) ),
			self::permit( self::int_param( $request, 'permit' ) )
		);
		if ( $result instanceof WP_Error ) {
			return REST_Errors::invalid( $result );
		}

		$position = self::int_param( $request, 'position' );
		if ( null !== $position ) {
			$fields->move( $result, $position );
		}

		$field = $fields->find( $result );
		if ( null === $field ) {
			return REST_Errors::not_found();
		}

		$response = new WP_REST_Response( self::format( $field ), 201 );
		$response->header( 'Location', rest_url( self::NAMESPACE . '/user-fields/' . $result ) );

		return $response;
	}

	/**
	 * Changes a field. Parameters that are not passed keep their value; the name cannot change.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_field( $request ): WP_REST_Response|WP_Error {
		$fields = Services::user_fields();
		$field  = $fields->find( (int) self::int_param( $request, 'id' ) );
		if ( null === $field ) {
			return REST_Errors::not_found();
		}

		$type   = $request->has_param( 'type' ) ? (string) self::string_param( $request, 'type' ) : self::type( $field );
		$result = $fields->update(
			$field->id,
			$request->has_param( 'label' ) ? sanitize_text_field( (string) self::string_param( $request, 'label' ) ) : $field->label,
			self::flags( $type, $request->has_param( 'on_register' ) ? self::bool_param( $request, 'on_register' ) : $field->on_register() ),
			$request->has_param( 'permit' ) ? self::permit( self::int_param( $request, 'permit' ) ) : $field->permit
		);
		if ( $result instanceof WP_Error ) {
			return REST_Errors::invalid( $result );
		}

		$position = self::int_param( $request, 'position' );
		if ( null !== $position ) {
			$fields->move( $field->id, $position );
		}

		$updated = $fields->find( $field->id );

		return null === $updated ? REST_Errors::not_found() : new WP_REST_Response( self::format( $updated ) );
	}

	/**
	 * Deletes a field and the values users entered.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_field( $request ): WP_REST_Response|WP_Error {
		$field = Services::user_fields()->find( (int) self::int_param( $request, 'id' ) );
		if ( null === $field ) {
			return REST_Errors::not_found();
		}

		Services::user_fields()->delete( $field->id );

		return new WP_REST_Response(
			array(
				'deleted'  => true,
				'previous' => self::format( $field ),
			)
		);
	}

	/**
	 * Describes a field.
	 *
	 * @since 0.1.0
	 *
	 * @param User_Field $field Field.
	 * @return array<string, mixed>
	 */
	public static function format( User_Field $field ): array {
		return array(
			'id'          => $field->id,
			'name'        => $field->name,
			'label'       => $field->label,
			'type'        => self::type( $field ),
			'permit'      => $field->permit ?? Permits::ALL,
			'on_register' => $field->on_register(),
			'position'    => $field->position,
		);
	}

	/**
	 * Returns the type of a field.
	 *
	 * @since 0.1.0
	 *
	 * @param User_Field $field Field.
	 * @return string A key of TYPES.
	 */
	public static function type( User_Field $field ): string {
		return match ( true ) {
			$field->is_link()       => 'link',
			$field->is_multi_line() => 'multi_line',
			default                 => 'text',
		};
	}

	/**
	 * Returns the flags of a field type.
	 *
	 * @since 0.1.0
	 *
	 * @param string $type        A key of TYPES; others count as `text`.
	 * @param bool   $on_register Whether the field is on the registration form.
	 * @return int
	 */
	public static function flags( string $type, bool $on_register ): int {
		return ( self::TYPES[ $type ] ?? 0 ) | ( $on_register ? User_Field::FLAG_ON_REGISTER : 0 );
	}

	/**
	 * Returns the stored visibility of a threshold: null for anybody.
	 *
	 * @since 0.1.0
	 *
	 * @param int|null $permit Threshold, see Permits.
	 * @return int|null
	 */
	public static function permit( ?int $permit ): ?int {
		return null === $permit || Permits::ALL === $permit ? null : $permit;
	}

	/**
	 * Returns the arguments for adding or changing a field.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $create Whether the field is added.
	 * @return array<string, array<string, mixed>>
	 */
	private function field_args( bool $create ): array {
		$args = array(
			'label'       => array(
				'description' => __( 'Label shown to users.', 'easyq' ),
				'type'        => 'string',
				'required'    => $create,
			),
			'type'        => array(
				'description' => __( 'One line of text, several lines, or a web address.', 'easyq' ),
				'type'        => 'string',
				'enum'        => array_keys( self::TYPES ),
			),
			'permit'      => array(
				'description' => __( 'Who may see the field.', 'easyq' ),
				'type'        => 'integer',
				'enum'        => array_keys( User_Fields::permit_choices() ),
			),
			'on_register' => array(
				'description' => __( 'Whether the field is on the registration form.', 'easyq' ),
				'type'        => 'boolean',
			),
			'position'    => array(
				'description' => __( 'Position, starting at 1.', 'easyq' ),
				'type'        => 'integer',
				'minimum'     => 1,
			),
		);

		if ( $create ) {
			$args['name'] = array(
				'description' => __( 'Internal name, used in the user meta key; derived from the label if omitted. It cannot change later.', 'easyq' ),
				'type'        => 'string',
				'pattern'     => '^[a-z0-9_\-]*$',
			);
		}

		return $args;
	}
}
