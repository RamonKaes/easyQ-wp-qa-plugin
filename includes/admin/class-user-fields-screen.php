<?php
/**
 * User fields screen.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Admin;

use EasyQ\Capabilities;
use EasyQ\REST\User_Fields_Controller;
use EasyQ\Services;
use EasyQ\Users\Permits;
use EasyQ\Users\User_Field;
use EasyQ\Users\User_Fields;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Adds, changes, orders and deletes the profile fields, like the Question2Answer
 * page `admin/userfields`, laid out like the core category screen.
 *
 * @since 0.1.0
 */
final class User_Fields_Screen {

	/**
	 * Page slug of the screen.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PAGE = 'easyq-user-fields';

	/**
	 * Nonce action of the forms.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const NONCE = 'easyq-user-fields';

	/**
	 * Errors of the last submitted form, keyed by field.
	 *
	 * @since 0.1.0
	 *
	 * @var WP_Error|null
	 */
	private ?WP_Error $errors = null;

	/**
	 * Values of the last submitted form, shown again with its errors.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private array $input = array();

	/**
	 * Hooks the screen into WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 11 );
	}

	/**
	 * Adds the screen to the easyQ menu.
	 *
	 * @since 0.1.0
	 */
	public function add_menu(): void {
		$hook = add_submenu_page( Settings_Screen::PAGE, __( 'User Fields', 'easyq' ), __( 'User Fields', 'easyq' ), Capabilities::MANAGE, self::PAGE, array( $this, 'render' ) );
		if ( false !== $hook ) {
			add_action( 'load-' . $hook, array( $this, 'load' ) );
		}
	}

	/**
	 * Returns the address of the screen.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, int|string> $args Query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array( 'page' => self::PAGE ) + $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Returns the address of the page that edits a field.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id Field ID.
	 * @return string
	 */
	public static function edit_url( int $id ): string {
		return self::url(
			array(
				'action' => 'edit',
				'field'  => $id,
			)
		);
	}

	/**
	 * Handles a submitted form before the screen is shown; redirects after a change.
	 *
	 * @since 0.1.0
	 */
	public function load(): void {
		if ( ! isset( $_POST['easyq_action'] ) ) {
			return;
		}

		check_admin_referer( self::NONCE );

		$redirect = $this->handle( wp_unslash( $_POST ) );
		if ( null !== $redirect ) {
			wp_safe_redirect( $redirect );
			exit;
		}
	}

	/**
	 * Carries out a submitted form.
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed> $post Unslashed form data; the nonce has been checked.
	 * @return string|null Address to go to after a change, or null to show the form again with its errors.
	 */
	public function handle( array $post ): ?string {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage the user fields.', 'easyq' ), 403 );
		}

		foreach ( array( 'easyq_action', 'field', 'name', 'label', 'type', 'permit', 'position', 'on_register' ) as $key ) {
			$this->input[ $key ] = isset( $post[ $key ] ) && is_scalar( $post[ $key ] ) ? (string) $post[ $key ] : '';
		}

		$fields   = Services::user_fields();
		$label    = sanitize_text_field( $this->input['label'] );
		$flags    = User_Fields_Controller::flags( $this->input['type'], '' !== $this->input['on_register'] );
		$permit   = User_Fields_Controller::permit( is_numeric( $this->input['permit'] ) ? (int) $this->input['permit'] : null );
		$field    = $fields->find( (int) $this->input['field'] );
		$position = is_numeric( $this->input['position'] ) ? max( 1, (int) $this->input['position'] ) : null;

		switch ( $this->input['easyq_action'] ) {
			case 'add':
				$result = $fields->create( sanitize_key( $this->input['name'] ), $label, $flags, $permit );
				if ( is_int( $result ) ) {
					return self::url( array( 'message' => 'added' ) );
				}
				break;

			case 'save':
				if ( null === $field ) {
					return self::url( array( 'message' => 'missing' ) );
				}

				$result = $fields->update( $field->id, $label, $flags, $permit );
				if ( true === $result ) {
					if ( null !== $position ) {
						$fields->move( $field->id, $position );
					}

					return add_query_arg( 'message', 'saved', self::edit_url( $field->id ) );
				}
				break;

			case 'delete':
				if ( null !== $field ) {
					$fields->delete( $field->id );
				}

				return self::url( array( 'message' => null === $field ? 'missing' : 'deleted' ) );

			default:
				$result = new WP_Error( 'action', __( 'Unknown action.', 'easyq' ) );
		}

		$this->errors = $result instanceof WP_Error ? $result : null;

		return null;
	}

	/**
	 * Renders the screen.
	 *
	 * @since 0.1.0
	 */
	public function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only selects what to show; changes are posted with a nonce.
		$action  = isset( $_GET['action'] ) && is_string( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		$id      = isset( $_GET['field'] ) && is_numeric( $_GET['field'] ) ? (int) $_GET['field'] : 0;
		$message = isset( $_GET['message'] ) && is_string( $_GET['message'] ) ? sanitize_key( $_GET['message'] ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$field = Services::user_fields()->find( $id );

		echo '<div class="wrap">';
		if ( 'edit' === $action && null !== $field ) {
			$this->render_edit( $field, $message );
		} else {
			$this->render_list( $message );
		}
		echo '</div>';
	}

	/**
	 * Renders the list with the form to add a field.
	 *
	 * @since 0.1.0
	 *
	 * @param string $message Message key from the last change.
	 */
	private function render_list( string $message ): void {
		$adding = 'add' === ( $this->input['easyq_action'] ?? '' );

		printf( '<h1>%s</h1>', esc_html__( 'User Fields', 'easyq' ) );
		$this->render_notices( $message );
		printf( '<p>%s</p>', esc_html__( 'Users fill in these fields in their profile. Name, email address, website and biography come from the WordPress profile.', 'easyq' ) );

		echo '<div id="col-container" class="wp-clearfix"><div id="col-left"><div class="col-wrap"><div class="form-wrap">';
		printf( '<h2>%s</h2>', esc_html__( 'Add User Field', 'easyq' ) );
		echo '<form method="post" action="' . esc_url( self::url() ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="easyq_action" value="add">';

		echo '<div class="form-field form-required">';
		$this->render_label( 'label', __( 'Label', 'easyq' ) );
		$this->render_text( 'label', $adding ? $this->input['label'] : '', true );
		echo '</div><div class="form-field">';
		$this->render_label( 'name', __( 'Name', 'easyq' ) );
		$this->render_text( 'name', $adding ? $this->input['name'] : '', false, __( 'Lowercase letters, digits, hyphens and underscores; derived from the label if left empty. It cannot change later.', 'easyq' ) );
		echo '</div><div class="form-field">';
		$this->render_label( 'type', __( 'Type', 'easyq' ) );
		$this->render_select( 'type', User_Fields_List_Table::type_labels(), $adding ? $this->input['type'] : 'text' );
		echo '</div><div class="form-field">';
		$this->render_label( 'permit', __( 'Visible to', 'easyq' ) );
		$this->render_select( 'permit', User_Fields::permit_choices(), $adding ? $this->input['permit'] : (string) Permits::ALL );
		echo '</div><div class="form-field">';
		$this->render_checkbox( $adding && '' !== $this->input['on_register'] );
		echo '</div>';
		submit_button( __( 'Add User Field', 'easyq' ) );
		echo '</form></div></div></div>';

		echo '<div id="col-right"><div class="col-wrap">';
		$table = new User_Fields_List_Table();
		$table->prepare_items();
		$table->display();
		echo '</div></div></div>';
	}

	/**
	 * Renders the form to edit a field, and the form to delete it.
	 *
	 * @since 0.1.0
	 *
	 * @param User_Field $field   Field.
	 * @param string     $message Message key from the last change.
	 */
	private function render_edit( User_Field $field, string $message ): void {
		$saving = 'save' === ( $this->input['easyq_action'] ?? '' );

		printf( '<h1>%s</h1>', esc_html__( 'Edit User Field', 'easyq' ) );
		$this->render_notices( $message );
		printf( '<p><a href="%s">%s</a></p>', esc_url( self::url() ), esc_html__( '← Back to User Fields', 'easyq' ) );

		echo '<form method="post" action="' . esc_url( self::edit_url( $field->id ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="easyq_action" value="save">';
		printf( '<input type="hidden" name="field" value="%d">', (int) $field->id );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">';
		$this->render_label( 'label', __( 'Label', 'easyq' ) );
		echo '</th><td>';
		$this->render_text( 'label', $saving ? $this->input['label'] : $field->display_label(), true );
		echo '</td></tr>';

		printf( '<tr><th scope="row">%1$s</th><td><code>%2$s</code></td></tr>', esc_html__( 'Name', 'easyq' ), esc_html( $field->name ) );

		echo '<tr><th scope="row">';
		$this->render_label( 'type', __( 'Type', 'easyq' ) );
		echo '</th><td>';
		$this->render_select( 'type', User_Fields_List_Table::type_labels(), $saving ? $this->input['type'] : User_Fields_Controller::type( $field ) );
		echo '</td></tr><tr><th scope="row">';
		$this->render_label( 'permit', __( 'Visible to', 'easyq' ) );
		echo '</th><td>';
		$this->render_select( 'permit', User_Fields::permit_choices(), $saving ? $this->input['permit'] : (string) ( $field->permit ?? Permits::ALL ) );
		echo '</td></tr><tr><th scope="row">';
		$this->render_label( 'position', __( 'Position', 'easyq' ) );
		echo '</th><td>';
		$this->render_select( 'position', $this->position_choices( $field ), (string) $field->position );
		echo '</td></tr><tr><th scope="row">' . esc_html__( 'Registration form', 'easyq' ) . '</th><td>';
		$this->render_checkbox( $saving ? '' !== $this->input['on_register'] : $field->on_register() );
		echo '</td></tr></tbody></table>';
		submit_button( __( 'Update', 'easyq' ) );
		echo '</form>';

		printf( '<h2>%s</h2>', esc_html__( 'Delete User Field', 'easyq' ) );
		printf( '<p>%s</p>', esc_html__( 'Deleting the field also deletes what users entered in it.', 'easyq' ) );
		echo '<form method="post" action="' . esc_url( self::edit_url( $field->id ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="easyq_action" value="delete">';
		printf( '<input type="hidden" name="field" value="%d">', (int) $field->id );
		submit_button( __( 'Delete User Field', 'easyq' ), 'delete', 'submit', false );
		echo '</form>';
	}

	/**
	 * Returns the positions a field can take: first, or after one of the others.
	 *
	 * @since 0.1.0
	 *
	 * @param User_Field $field Field.
	 * @return array<int, string>
	 */
	private function position_choices( User_Field $field ): array {
		$choices = array( 1 => __( 'First', 'easyq' ) );
		$others  = array_values( array_filter( Services::user_fields()->all(), static fn ( User_Field $other ): bool => $other->id !== $field->id ) );
		foreach ( $others as $index => $other ) {
			/* translators: %s: field label. */
			$choices[ $index + 2 ] = sprintf( __( 'After “%s”', 'easyq' ), $other->display_label() );
		}

		return $choices;
	}

	/**
	 * Renders the message of the last change and the errors of the submitted form.
	 *
	 * @since 0.1.0
	 *
	 * @param string $message Message key from the last change.
	 */
	private function render_notices( string $message ): void {
		$text = match ( $message ) {
			'added'   => __( 'User field added.', 'easyq' ),
			'saved'   => __( 'User field saved.', 'easyq' ),
			'deleted' => __( 'User field deleted.', 'easyq' ),
			'missing' => __( 'The field does not exist.', 'easyq' ),
			default   => '',
		};
		if ( '' !== $text ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', 'missing' === $message ? 'error' : 'success', esc_html( $text ) );
		}

		if ( null !== $this->errors && $this->errors->has_errors() ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'The field could not be saved. Please correct the marked fields.', 'easyq' ) );
		}
	}

	/**
	 * Renders the label of a field.
	 *
	 * @since 0.1.0
	 *
	 * @param string $field Field name.
	 * @param string $label Label.
	 */
	private function render_label( string $field, string $label ): void {
		printf( '<label for="easyq-user-field-%s">%s</label>', esc_attr( $field ), esc_html( $label ) );
	}

	/**
	 * Renders a text input with its error and description.
	 *
	 * @since 0.1.0
	 *
	 * @param string $field       Field name.
	 * @param string $value       Value.
	 * @param bool   $required    Whether the field is required.
	 * @param string $description Description.
	 */
	private function render_text( string $field, string $value, bool $required, string $description = '' ): void {
		$id      = 'easyq-user-field-' . $field;
		$message = $this->error( $field );
		$ids     = array_filter( array( '' === $message ? '' : $id . '-error', '' === $description ? '' : $id . '-description' ) );

		printf(
			'<input type="text" class="regular-text" id="%1$s" name="%2$s" value="%3$s" maxlength="%4$d"%5$s%6$s%7$s>',
			esc_attr( $id ),
			esc_attr( $field ),
			esc_attr( $value ),
			(int) User_Fields::MAX_NAME_LENGTH,
			$required ? ' required' : '',
			'' === $message ? '' : ' aria-invalid="true"',
			array() === $ids ? '' : ' aria-describedby="' . esc_attr( implode( ' ', $ids ) ) . '"'
		);
		if ( '' !== $message ) {
			printf( '<p class="notice-error" id="%1$s-error"><strong>%2$s</strong></p>', esc_attr( $id ), esc_html( $message ) );
		}
		if ( '' !== $description ) {
			printf( '<p id="%1$s-description">%2$s</p>', esc_attr( $id ), esc_html( $description ) );
		}
	}

	/**
	 * Renders a selection.
	 *
	 * @since 0.1.0
	 *
	 * @param string                    $field    Field name.
	 * @param array<int|string, string> $choices  Values with their names.
	 * @param string                    $selected Selected value.
	 */
	private function render_select( string $field, array $choices, string $selected ): void {
		$message = $this->error( $field );

		printf(
			'<select id="easyq-user-field-%1$s" name="%1$s"%2$s>',
			esc_attr( $field ),
			'' === $message ? '' : ' aria-invalid="true" aria-describedby="easyq-user-field-' . esc_attr( $field ) . '-error"'
		);
		foreach ( $choices as $value => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $value ), selected( $selected, (string) $value, false ), esc_html( $label ) );
		}
		echo '</select>';
		if ( '' !== $message ) {
			printf( '<p class="notice-error" id="easyq-user-field-%1$s-error"><strong>%2$s</strong></p>', esc_attr( $field ), esc_html( $message ) );
		}
	}

	/**
	 * Renders the checkbox that puts the field on the registration form.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $checked Whether the box is checked.
	 */
	private function render_checkbox( bool $checked ): void {
		printf(
			'<label for="easyq-user-field-on-register"><input type="checkbox" id="easyq-user-field-on-register" name="on_register" value="1"%1$s> %2$s</label>',
			checked( $checked, true, false ),
			esc_html__( 'Show on the registration form', 'easyq' )
		);
	}

	/**
	 * Returns the error message of a field from the last submitted form.
	 *
	 * @since 0.1.0
	 *
	 * @param string $field Field name.
	 * @return string Empty if the field has no error.
	 */
	private function error( string $field ): string {
		return null === $this->errors ? '' : implode( ' ', $this->errors->get_error_messages( $field ) );
	}
}
