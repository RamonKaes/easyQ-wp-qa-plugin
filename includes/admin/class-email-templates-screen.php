<?php
/**
 * Email templates screen.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Admin;

use EasyQ\Capabilities;
use EasyQ\Mail\Mailer;
use EasyQ\Mail\Recipient;
use EasyQ\Mail\Templates;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the emails and edits their texts through the Settings API, with the
 * placeholders, a preview, a test email and going back to the default.
 *
 * @since 0.1.0
 */
final class Email_Templates_Screen {

	/**
	 * Page slug of the screen.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PAGE = 'easyq-email-templates';

	/**
	 * Nonce action of the forms to reset a template and to send a test email.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const NONCE = 'easyq-email-templates';

	/**
	 * Hooks the screen into WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 13 );
	}

	/**
	 * Adds the screen to the easyQ menu.
	 *
	 * @since 0.1.0
	 */
	public function add_menu(): void {
		$hook = add_submenu_page( Dashboard_Screen::PAGE, __( 'Email Templates', 'easyq' ), __( 'Email Templates', 'easyq' ), Capabilities::MANAGE, self::PAGE, array( $this, 'render' ) );
		if ( false !== $hook ) {
			add_action( 'load-' . $hook, array( $this, 'load' ) );
		}
	}

	/**
	 * Returns the address of the screen.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $args Query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array( 'page' => self::PAGE ) + $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Returns the address of the page that edits a template.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Template name.
	 * @return string
	 */
	public static function edit_url( string $name ): string {
		return self::url( array( 'template' => $name ) );
	}

	/**
	 * Handles a submitted form before the page starts.
	 *
	 * @since 0.1.0
	 */
	public function load(): void {
		if ( ! isset( $_POST['easyq_action'] ) ) {
			return;
		}

		check_admin_referer( self::NONCE );

		wp_safe_redirect( $this->handle( wp_unslash( $_POST ) ) );
		exit;
	}

	/**
	 * Carries out a submitted form.
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed> $post Unslashed form data; the nonce has been checked.
	 * @return string Address to go to.
	 */
	public function handle( array $post ): string {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage the email templates.', 'easyq' ), 403 );
		}

		$name = is_string( $post['template'] ?? null ) ? sanitize_key( $post['template'] ) : '';
		if ( null === Templates::get( $name ) ) {
			return self::url();
		}

		$action = is_string( $post['easyq_action'] ?? null ) ? $post['easyq_action'] : '';
		if ( 'reset' === $action ) {
			Templates::reset( $name );

			return add_query_arg( 'message', 'reset', self::edit_url( $name ) );
		}

		return add_query_arg( 'message', self::send_test( $name ) ? 'sent' : 'failed', self::edit_url( $name ) );
	}

	/**
	 * Sends a template with sample values to the current user.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Template name.
	 * @return bool Whether `wp_mail()` accepted the email.
	 */
	public static function send_test( string $name ): bool {
		$user = wp_get_current_user();

		return 1 === ( new Mailer() )->send(
			$name,
			array( new Recipient( $user->user_email, $user->ID, $user->display_name ) ),
			array( Templates::class, 'samples' )
		);
	}

	/**
	 * Renders the screen.
	 *
	 * @since 0.1.0
	 */
	public function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only selects what to show; changes are posted with a nonce.
		$name    = isset( $_GET['template'] ) && is_string( $_GET['template'] ) ? sanitize_key( $_GET['template'] ) : '';
		$message = isset( $_GET['message'] ) && is_string( $_GET['message'] ) ? sanitize_key( $_GET['message'] ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap">';
		if ( null === Templates::get( $name ) ) {
			$this->render_list();
		} else {
			$this->render_edit( $name, $message );
		}
		echo '</div>';
	}

	/**
	 * Renders the list of emails.
	 *
	 * @since 0.1.0
	 */
	private function render_list(): void {
		printf( '<h1>%s</h1>', esc_html__( 'Email Templates', 'easyq' ) );
		printf( '<p>%s</p>', esc_html__( 'The texts of the emails easyQ sends. Edited texts are used in every language, unless a translation plugin provides others.', 'easyq' ) );

		$table = new Email_Templates_List_Table();
		$table->prepare_items();
		$table->display();
	}

	/**
	 * Renders the form to edit a template, the preview and the further actions.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name    Template name.
	 * @param string $message Message key from the last action.
	 */
	private function render_edit( string $name, string $message ): void {
		$template = Templates::get( $name ) ?? array(
			'subject' => '',
			'body'    => '',
		);
		$option   = Templates::OPTION . '[' . $name . ']';

		printf( '<h1>%s</h1>', esc_html( Templates::info()[ $name ]['label'] ?? '' ) );
		settings_errors();
		$this->render_notice( $message );
		printf( '<p><a href="%s">%s</a></p>', esc_url( self::url() ), esc_html__( 'Back to all email templates', 'easyq' ) );

		echo '<form method="post" action="options.php">';
		settings_fields( Templates::OPTION );
		echo '<table class="form-table" role="presentation"><tbody>';
		printf(
			'<tr><th scope="row"><label for="easyq-template-subject">%1$s</label></th><td><input type="text" class="large-text" id="easyq-template-subject" name="%2$s[subject]" value="%3$s" required></td></tr>',
			esc_html__( 'Subject', 'easyq' ),
			esc_attr( $option ),
			esc_attr( $template['subject'] )
		);
		printf(
			'<tr><th scope="row"><label for="easyq-template-body">%1$s</label></th><td><textarea class="large-text" rows="16" id="easyq-template-body" name="%2$s[body]" required aria-describedby="easyq-template-placeholders">%3$s</textarea></td></tr>',
			esc_html__( 'Text', 'easyq' ),
			esc_attr( $option ),
			esc_textarea( $template['body'] )
		);
		printf( '<tr><th scope="row">%s</th><td>', esc_html__( 'Placeholders', 'easyq' ) );
		$this->render_placeholders( $name );
		echo '</td></tr></tbody></table>';
		submit_button();
		echo '</form>';

		$preview = Mailer::content(
			$name,
			Templates::samples() + array(
				'###USERNAME###' => wp_get_current_user()->display_name,
				'###EMAIL###'    => wp_get_current_user()->user_email,
			)
		);
		printf( '<h2>%s</h2>', esc_html__( 'Preview', 'easyq' ) );
		printf( '<p>%s</p>', esc_html__( 'The saved text with sample values. Emails about a post also get links to stop them, which cannot be edited.', 'easyq' ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		printf(
			'<tr><th scope="row"><label for="easyq-preview-subject">%1$s</label></th><td><input type="text" class="large-text" id="easyq-preview-subject" value="%2$s" readonly></td></tr>',
			esc_html__( 'Subject', 'easyq' ),
			esc_attr( $preview['subject'] )
		);
		printf(
			'<tr><th scope="row"><label for="easyq-preview-body">%1$s</label></th><td><textarea class="large-text" rows="16" id="easyq-preview-body" readonly>%2$s</textarea></td></tr>',
			esc_html__( 'Text', 'easyq' ),
			esc_textarea( $preview['message'] )
		);
		echo '</tbody></table>';

		$this->render_action( $name, 'test', sprintf( /* translators: %s: email address. */ __( 'Send a test email to %s', 'easyq' ), wp_get_current_user()->user_email ), 'secondary' );
		if ( Templates::is_edited( $name ) ) {
			$this->render_action( $name, 'reset', __( 'Reset to the default text', 'easyq' ), 'delete' );
		}
	}

	/**
	 * Renders the list of placeholders of a template.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Template name.
	 */
	private function render_placeholders( string $name ): void {
		$descriptions = Templates::placeholder_descriptions();

		echo '<ul id="easyq-template-placeholders">';
		foreach ( Templates::placeholders( $name ) as $placeholder ) {
			printf( '<li><code>%1$s</code> %2$s</li>', esc_html( $placeholder ), esc_html( $descriptions[ $placeholder ] ?? '' ) );
		}
		echo '</ul>';
	}

	/**
	 * Renders a form with one button for an action on a template.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name   Template name.
	 * @param string $action Action.
	 * @param string $label  Button text.
	 * @param string $type   Button type.
	 */
	private function render_action( string $name, string $action, string $label, string $type ): void {
		echo '<form method="post" action="' . esc_url( self::edit_url( $name ) ) . '">';
		wp_nonce_field( self::NONCE );
		printf(
			'<input type="hidden" name="easyq_action" value="%1$s"><input type="hidden" name="template" value="%2$s">',
			esc_attr( $action ),
			esc_attr( $name )
		);
		submit_button( $label, $type, 'submit-' . $action, false );
		echo '</form><p></p>';
	}

	/**
	 * Renders the notice about the last action.
	 *
	 * @since 0.1.0
	 *
	 * @param string $message Message key.
	 */
	private function render_notice( string $message ): void {
		$text = match ( $message ) {
			'reset'  => __( 'The default text is back.', 'easyq' ),
			'sent'   => __( 'The test email has been sent.', 'easyq' ),
			'failed' => __( 'The test email could not be sent.', 'easyq' ),
			default  => '',
		};
		if ( '' !== $text ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', 'failed' === $message ? 'error' : 'success', esc_html( $text ) );
		}
	}
}
