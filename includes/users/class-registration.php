<?php
/**
 * Q&A fields on the registration form.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Users;

use EasyQ\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the profile fields marked for registration on the WordPress registration form.
 *
 * @since 0.1.0
 */
final class Registration {

	/**
	 * Nonce action and field name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const NONCE = 'easyq_register';

	/**
	 * Hooks the fields into the registration form.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'register_form', array( $this, 'render' ) );
		add_action( 'user_register', array( $this, 'save' ) );
	}

	/**
	 * Outputs the registration fields.
	 *
	 * @since 0.1.0
	 */
	public function render(): void {
		$fields = $this->fields();
		if ( array() === $fields ) {
			return;
		}

		wp_nonce_field( self::NONCE, self::NONCE );

		foreach ( $fields as $field ) {
			$id = 'easyq-register-' . $field->id;
			?>
			<p>
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field->display_label() ); ?></label>
				<?php if ( $field->is_multi_line() ) : ?>
					<textarea name="easyq_fields[<?php echo esc_attr( (string) $field->id ); ?>]" id="<?php echo esc_attr( $id ); ?>" class="input" rows="3"></textarea>
				<?php else : ?>
					<input type="<?php echo $field->is_link() ? 'url' : 'text'; ?>" name="easyq_fields[<?php echo esc_attr( (string) $field->id ); ?>]" id="<?php echo esc_attr( $id ); ?>" class="input" size="25" />
				<?php endif; ?>
			</p>
			<?php
		}
	}

	/**
	 * Stores the registration fields of a new user.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 */
	public function save( int $user_id ): void {
		$nonce = isset( $_POST[ self::NONCE ] ) && is_string( $_POST[ self::NONCE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) || ! isset( $_POST['easyq_fields'] ) || ! is_array( $_POST['easyq_fields'] ) ) {
			return;
		}

		$values = map_deep( wp_unslash( $_POST['easyq_fields'] ), 'sanitize_textarea_field' );

		foreach ( $this->fields() as $field ) {
			if ( isset( $values[ $field->id ] ) && is_string( $values[ $field->id ] ) ) {
				Services::user_fields()->set_value( $user_id, $field, $values[ $field->id ] );
			}
		}
	}

	/**
	 * Returns the fields shown on registration.
	 *
	 * @since 0.1.0
	 *
	 * @return User_Field[]
	 */
	private function fields(): array {
		return array_values( array_filter( Services::user_fields()->all(), static fn ( User_Field $field ): bool => $field->on_register() ) );
	}
}
