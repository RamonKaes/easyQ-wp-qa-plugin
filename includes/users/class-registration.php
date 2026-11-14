<?php
/**
 * Q&A parts of the registration form.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Users;

use EasyQ\Services;
use EasyQ\Settings\Options;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the registration settings of Question2Answer (`pages/register.php`) to the WordPress
 * registration form: a text above the form, the profile fields marked for registration,
 * the terms to accept and the newsletter. Blocked usernames are checked by Blocked_Names.
 *
 * @since 0.1.0
 */
final class Registration {

	/**
	 * User meta: time and text of the terms a user accepted on registration, as proof.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const TERMS_META = 'easyq_terms_consent';

	/**
	 * Nonce action and field name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const NONCE = 'easyq_register';

	/**
	 * Hooks the parts into the registration form.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'login_form_register', array( $this, 'add_message' ) );
		add_action( 'register_form', array( $this, 'render' ) );
		add_filter( 'registration_errors', array( $this, 'validate' ) );
		add_action( 'user_register', array( $this, 'save' ) );
	}

	/**
	 * Adds the text above the form, where Question2Answer shows it, on the registration screen only.
	 *
	 * @since 0.1.0
	 */
	public function add_message(): void {
		add_filter( 'login_message', array( $this, 'message' ) );
	}

	/**
	 * Appends the text above the form to the message of the registration screen.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $message Message above the form.
	 * @return string
	 */
	public function message( mixed $message ): string {
		$message = is_string( $message ) ? $message : '';
		$text    = Options::get_bool( 'users', 'show_custom_register' ) ? trim( Options::get_string( 'users', 'custom_register' ) ) : '';
		if ( '' === $text ) {
			return $message;
		}

		return $message . wp_get_admin_notice(
			wp_kses_post( wpautop( $text ) ),
			array(
				'type'               => 'info',
				'additional_classes' => array( 'message' ),
				'paragraph_wrap'     => false,
			)
		);
	}

	/**
	 * Outputs the profile fields, the terms and the newsletter.
	 *
	 * @since 0.1.0
	 */
	public function render(): void {
		$fields     = $this->fields();
		$terms      = self::asks_for_terms();
		$newsletter = Options::get_bool( 'newsletter', 'mailing_enabled' );
		if ( array() === $fields && ! $terms && ! $newsletter ) {
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

		if ( $newsletter ) {
			?>
			<p>
				<input type="checkbox" name="easyq_newsletter" id="easyq-register-newsletter" value="1" />
				<label for="easyq-register-newsletter"><?php esc_html_e( 'Send me the newsletter. I will confirm it with a link sent by email.', 'easyq' ); ?></label>
			</p>
			<?php
		}

		if ( $terms ) {
			?>
			<p>
				<input type="checkbox" name="easyq_terms" id="easyq-register-terms" value="1" required="required" />
				<label for="easyq-register-terms"><?php echo wp_kses_post( self::terms_label() ); ?></label>
			</p>
			<?php
		}
	}

	/**
	 * Refuses registrations without accepted terms.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Error $errors Errors of the registration.
	 * @return WP_Error
	 */
	public function validate( WP_Error $errors ): WP_Error {
		if ( self::asks_for_terms() && ! $this->ticked( 'easyq_terms' ) ) {
			$errors->add( 'easyq_terms', __( '<strong>Error:</strong> Please accept the terms.', 'easyq' ) );
		}

		return $errors;
	}

	/**
	 * Stores the profile fields, the accepted terms and the newsletter request of a new user.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 */
	public function save( int $user_id ): void {
		if ( ! $this->has_valid_nonce() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in has_valid_nonce().
		if ( isset( $_POST['easyq_fields'] ) && is_array( $_POST['easyq_fields'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in has_valid_nonce().
			$values = map_deep( wp_unslash( $_POST['easyq_fields'] ), 'sanitize_textarea_field' );

			foreach ( $this->fields() as $field ) {
				if ( isset( $values[ $field->id ] ) && is_string( $values[ $field->id ] ) ) {
					Services::user_fields()->set_value( $user_id, $field, $values[ $field->id ] );
				}
			}
		}

		if ( self::asks_for_terms() && $this->ticked( 'easyq_terms' ) ) {
			Site_Meta::update(
				$user_id,
				self::TERMS_META,
				array(
					'time' => time(),
					'text' => wp_strip_all_tags( self::terms_label() ),
				)
			);
		}

		$user = get_userdata( $user_id );
		if ( false !== $user && $this->ticked( 'easyq_newsletter' ) ) {
			// Double opt-in: the subscription starts once the user confirms the email. Without a newsletter, subscribe() refuses.
			Services::newsletter()->subscribe( $user );
		}
	}

	/**
	 * Whether the registration form asks new users to accept the terms.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public static function asks_for_terms(): bool {
		return Options::get_bool( 'users', 'show_register_terms' );
	}

	/**
	 * Returns the label of the terms checkbox: the text of the site owner, or a sentence that
	 * links the privacy policy page of WordPress.
	 *
	 * @since 0.1.0
	 *
	 * @return string HTML.
	 */
	public static function terms_label(): string {
		$text = trim( Options::get_string( 'users', 'register_terms' ) );
		if ( '' !== $text ) {
			return $text;
		}

		$url = get_privacy_policy_url();
		if ( '' === $url ) {
			return esc_html__( 'I accept the terms of this site.', 'easyq' );
		}

		/* translators: %s: address of the privacy policy page. */
		return sprintf( __( 'I accept the terms and the <a href="%s">privacy policy</a> of this site.', 'easyq' ), esc_url( $url ) );
	}

	/**
	 * Returns the terms a user accepted on registration.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 * @return array{time: int, text: string}|null
	 */
	public static function accepted_terms( int $user_id ): ?array {
		$consent = Site_Meta::get( $user_id, self::TERMS_META );
		if ( ! is_array( $consent ) || ! is_int( $consent['time'] ?? null ) ) {
			return null;
		}

		return array(
			'time' => $consent['time'],
			'text' => is_string( $consent['text'] ?? null ) ? $consent['text'] : '',
		);
	}

	/**
	 * Whether a checkbox of the form was ticked.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Field name.
	 * @return bool
	 */
	private function ticked( string $name ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in has_valid_nonce().
		return $this->has_valid_nonce() && isset( $_POST[ $name ] ) && '1' === $_POST[ $name ];
	}

	/**
	 * Whether the form came with a valid nonce.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	private function has_valid_nonce(): bool {
		$nonce = isset( $_POST[ self::NONCE ] ) && is_string( $_POST[ self::NONCE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ) : '';

		return false !== wp_verify_nonce( $nonce, self::NONCE );
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
