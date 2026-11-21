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
use EasyQ\Util\Text;
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
		// The sign-up of a network (`wp-signup.php`), where `register_form` never runs: core creates
		// the user only when the account is activated, so the input waits in the sign-up meta.
		add_action( 'before_signup_form', array( $this, 'print_signup_message' ) );
		add_action( 'signup_extra_fields', array( $this, 'render' ) );
		add_filter( 'wpmu_validate_user_signup', array( $this, 'validate_signup' ) );
		add_filter( 'add_signup_meta', array( $this, 'add_signup_meta' ) );
		add_action( 'wpmu_activate_user', array( $this, 'activate' ), 10, 3 );
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
	 * Outputs the profile fields, the terms and the newsletter, in the registration form and
	 * in the sign-up form of a network.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $errors Errors of the sign-up form of a network; none for the registration form.
	 */
	public function render( mixed $errors = null ): void {
		$fields     = $this->fields();
		$terms      = self::asks_for_terms();
		$newsletter = Options::get_bool( 'newsletter', 'mailing_enabled' );
		if ( array() === $fields && ! $terms && ! $newsletter ) {
			return;
		}

		wp_nonce_field( self::NONCE, self::NONCE );

		// After core refused the registration, the form shows what was entered, as core keeps the
		// username and the email address.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Only fills the form again; `save()` verifies the nonce.
		$posted     = isset( $_POST['easyq_fields'] ) && is_array( $_POST['easyq_fields'] ) ? wp_unslash( $_POST['easyq_fields'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is escaped where it is printed.
		$newsletter = $newsletter && isset( $_POST['easyq_newsletter'] ) ? 'checked' : ( $newsletter ? 'unchecked' : '' );
		$terms      = $terms && isset( $_POST['easyq_terms'] ) ? 'checked' : ( $terms ? 'unchecked' : '' );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		foreach ( $fields as $field ) {
			$id    = 'easyq-register-' . $field->id;
			$value = is_array( $posted ) && is_string( $posted[ $field->id ] ?? null ) ? $posted[ $field->id ] : '';
			?>
			<p>
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field->display_label() ); ?></label>
				<?php if ( $field->is_multi_line() ) : ?>
					<textarea name="easyq_fields[<?php echo esc_attr( (string) $field->id ); ?>]" id="<?php echo esc_attr( $id ); ?>" class="input" rows="3" maxlength="<?php echo esc_attr( (string) User_Fields::MAX_VALUE_LENGTH ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
				<?php else : ?>
					<input type="<?php echo $field->is_link() ? 'url' : 'text'; ?>" name="easyq_fields[<?php echo esc_attr( (string) $field->id ); ?>]" id="<?php echo esc_attr( $id ); ?>" class="input" size="25" maxlength="<?php echo esc_attr( (string) User_Fields::MAX_VALUE_LENGTH ); ?>" value="<?php echo esc_attr( $value ); ?>" />
				<?php endif; ?>
			</p>
			<?php
		}

		if ( '' !== $newsletter ) {
			?>
			<p>
				<input type="checkbox" name="easyq_newsletter" id="easyq-register-newsletter" value="1"<?php checked( 'checked', $newsletter ); ?> />
				<label for="easyq-register-newsletter"><?php esc_html_e( 'Send me the newsletter. I will confirm it with a link sent by email.', 'easyq' ); ?></label>
			</p>
			<?php
		}

		if ( '' !== $terms ) {
			?>
			<p>
				<input type="checkbox" name="easyq_terms" id="easyq-register-terms" value="1" required="required"<?php checked( 'checked', $terms ); ?> />
				<label for="easyq-register-terms"><?php echo wp_kses_post( self::terms_label() ) . ' ' . wp_required_field_indicator(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The indicator of core is escaped markup. ?></label>
			</p>
			<?php
			// The sign-up of a network shows its errors next to the fields, as core does for its own.
			if ( $errors instanceof WP_Error && '' !== $errors->get_error_message( 'easyq_terms' ) ) {
				printf( '<p class="error" id="easyq-register-terms-error">%s</p>', wp_kses_post( $errors->get_error_message( 'easyq_terms' ) ) );
			}
		}
	}

	/**
	 * Refuses registrations without accepted terms.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $errors Errors of the registration.
	 * @return mixed
	 */
	public function validate( mixed $errors ): mixed {
		// Another filter may hand on any type; it is passed on unchanged, as core does.
		if ( ! $errors instanceof WP_Error ) {
			return $errors;
		}

		// A form that came back too late says so, rather than that the ticked terms were not
		// accepted; it shows again with what was entered, so neither the fields nor the wish for
		// the newsletter get lost without a word.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only tells whether the form sent its nonce; has_valid_nonce() verifies it.
		if ( isset( $_POST[ self::NONCE ] ) && ! $this->has_valid_nonce() ) {
			$errors->add( 'easyq_expired', __( '<strong>Error:</strong> The form has expired. Please try again.', 'easyq' ) );

			return $errors;
		}

		if ( self::asks_for_terms() && ! $this->ticked( 'easyq_terms' ) ) {
			$errors->add( 'easyq_terms', __( '<strong>Error:</strong> Please accept the terms.', 'easyq' ) );
		}

		// A value longer than a field holds is refused with its field, as Question2Answer reports the length.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only the length is read; core checks the request of the form.
		$posted = isset( $_POST['easyq_fields'] ) && is_array( $_POST['easyq_fields'] ) ? wp_unslash( $_POST['easyq_fields'] ) : array();
		foreach ( $this->fields() as $field ) {
			$value = is_array( $posted ) ? ( $posted[ $field->id ] ?? null ) : null;
			if ( is_string( $value ) && Text::length( $value ) > User_Fields::MAX_VALUE_LENGTH ) {
				/* translators: 1: field label, 2: number of characters. */
				$errors->add( 'easyq_field_' . $field->id, sprintf( __( '<strong>Error:</strong> %1$s can have at most %2$s characters.', 'easyq' ), esc_html( $field->display_label() ), number_format_i18n( User_Fields::MAX_VALUE_LENGTH ) ) );
			}
		}

		return $errors;
	}

	/**
	 * Saves the input of the registration form for a new user.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 */
	public function save( int $user_id ): void {
		$input = $this->input();
		if ( null !== $input ) {
			$this->apply( $user_id, $input );
		}
	}

	/**
	 * Prints the text above the sign-up form of a network, as `message()` adds it to the
	 * registration form.
	 *
	 * @since 0.1.0
	 */
	public function print_signup_message(): void {
		echo $this->message( '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- `message()` escapes the text.
	}

	/**
	 * Refuses a sign-up of a network without accepted terms, as `validate()` refuses a
	 * registration.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $result Result of `wpmu_validate_user_signup()`, with `errors`.
	 * @return mixed
	 */
	public function validate_signup( mixed $result ): mixed {
		// Only the form of `wp-signup.php`; network administrators add users without it.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only tells which form was sent.
		$stage = isset( $_POST['stage'] ) && is_string( $_POST['stage'] ) ? sanitize_key( wp_unslash( $_POST['stage'] ) ) : '';
		if ( 'validate-user-signup' !== $stage || ! is_array( $result ) || ! ( $result['errors'] ?? null ) instanceof WP_Error ) {
			return $result;
		}

		$this->validate( $result['errors'] );

		return $result;
	}

	/**
	 * Keeps the input of the sign-up form of a network until the account is activated.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $meta Meta of the sign-up.
	 * @return mixed
	 */
	public function add_signup_meta( mixed $meta ): mixed {
		$input = $this->input();
		if ( ! is_array( $meta ) || null === $input ) {
			return $meta;
		}

		$meta['easyq_registration'] = $input + array( 'site' => get_current_blog_id() );

		return $meta;
	}

	/**
	 * Saves the input of the sign-up form of a network once its account is activated, on the
	 * site it was sent on.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $user_id  ID of the new user.
	 * @param mixed $password Password, unused.
	 * @param mixed $meta     Meta of the sign-up.
	 */
	public function activate( mixed $user_id, mixed $password, mixed $meta ): void {
		unset( $password );
		$input = is_array( $meta ) && is_array( $meta['easyq_registration'] ?? null ) ? $meta['easyq_registration'] : null;
		if ( null === $input || ! is_numeric( $user_id ) ) {
			return;
		}

		$site     = is_numeric( $input['site'] ?? null ) ? (int) $input['site'] : get_current_blog_id();
		$switched = get_current_blog_id() !== $site && switch_to_blog( $site );
		/**
		 * The input as `input()` stored it.
		 *
		 * @var array<string, mixed> $input
		 */
		$this->apply( (int) $user_id, $input );
		if ( $switched ) {
			restore_current_blog();
		}
	}

	/**
	 * Returns the input of the registration or sign-up form, or null without a valid nonce.
	 *
	 * @since 0.1.0
	 *
	 * @return array{fields: array<int, string>, terms: bool, terms_text: string, time: int, newsletter: bool}|null
	 */
	private function input(): ?array {
		if ( ! $this->has_valid_nonce() ) {
			return null;
		}

		$fields = array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Verified in has_valid_nonce(); each value is sanitized below as its field takes it.
		$posted = isset( $_POST['easyq_fields'] ) && is_array( $_POST['easyq_fields'] ) ? wp_unslash( $_POST['easyq_fields'] ) : array();
		foreach ( $this->fields() as $field ) {
			if ( is_array( $posted ) && is_string( $posted[ $field->id ] ?? null ) ) {
				// As the profile stores it: a link keeps its percent encoding, which `sanitize_textarea_field()` removed.
				$fields[ $field->id ] = User_Fields::sanitize_value( $field, $posted[ $field->id ] );
			}
		}

		return array(
			'fields'     => $fields,
			'terms'      => self::asks_for_terms() && $this->ticked( 'easyq_terms' ),
			'terms_text' => wp_strip_all_tags( self::terms_label() ),
			'time'       => time(),
			'newsletter' => $this->ticked( 'easyq_newsletter' ),
		);
	}

	/**
	 * Saves the input of a registration for a user: profile fields, the consent to the terms
	 * with its time and text, and the request for the newsletter.
	 *
	 * @since 0.1.0
	 *
	 * @param int                  $user_id User ID.
	 * @param array<string, mixed> $input   Input, see `input()`.
	 */
	private function apply( int $user_id, array $input ): void {
		$values = is_array( $input['fields'] ?? null ) ? $input['fields'] : array();
		foreach ( $this->fields() as $field ) {
			if ( is_string( $values[ $field->id ] ?? null ) ) {
				Services::user_fields()->set_value( $user_id, $field, $values[ $field->id ] );
			}
		}

		if ( true === ( $input['terms'] ?? false ) ) {
			Site_Meta::update(
				$user_id,
				self::TERMS_META,
				array(
					'time' => is_int( $input['time'] ?? null ) ? $input['time'] : time(),
					'text' => is_string( $input['terms_text'] ?? null ) ? $input['terms_text'] : '',
				)
			);
		}

		$user = get_userdata( $user_id );
		if ( false !== $user && true === ( $input['newsletter'] ?? false ) ) {
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
