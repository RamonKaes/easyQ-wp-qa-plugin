<?php
/**
 * Tools screen.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Admin;

use EasyQ\Actor;
use EasyQ\Capabilities;
use EasyQ\Tools\Tools;
use EasyQ\Util\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the maintenance tools, like the database clean-up of the Question2Answer
 * page `admin/stats`.
 *
 * With JavaScript, a tool runs to the end through the REST API with a progress bar.
 * Without it, each click runs one batch and the screen offers to continue.
 *
 * @since 0.1.0
 */
final class Tools_Screen {

	/**
	 * Page slug of the screen.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PAGE = 'easyq-tools';

	/**
	 * Nonce action of the forms.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const NONCE = 'easyq-tools';

	/**
	 * Tools that need the user to confirm, because they cannot be undone.
	 *
	 * @since 0.1.0
	 *
	 * @var string[]
	 */
	private const CONFIRM = array( 'delete_hidden' );

	/**
	 * Where the tool run without JavaScript stopped.
	 *
	 * @since 0.1.0
	 *
	 * @var array{tool: string, step: string, step_number: int, steps: int, after: int, done: int, total: int, complete: bool}|null
	 */
	private ?array $state = null;

	/**
	 * Whether the last submitted tool needed a confirmation that was missing.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $unconfirmed = '';

	/**
	 * Hooks the screen into WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 15 );
	}

	/**
	 * Adds the screen to the easyQ menu.
	 *
	 * @since 0.1.0
	 */
	public function add_menu(): void {
		$hook = add_submenu_page( Dashboard_Screen::PAGE, __( 'Q&A Tools', 'easyq' ), __( 'Tools', 'easyq' ), Capabilities::MANAGE, self::PAGE, array( $this, 'render' ) );
		if ( false !== $hook ) {
			add_action( 'load-' . $hook, array( $this, 'load' ) );
		}
	}

	/**
	 * Loads the script and runs a submitted batch before the screen is shown.
	 *
	 * @since 0.1.0
	 */
	public function load(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

		if ( ! isset( $_POST['easyq_tool'] ) ) {
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
	 * Loads the script that runs the tools with a progress bar.
	 *
	 * @since 0.1.0
	 */
	public function enqueue(): void {
		$asset = Assets::get( 'tools' );
		wp_enqueue_script(
			'easyq-tools',
			plugins_url( 'build/tools.js', \EasyQ\PLUGIN_FILE ),
			array_values( array_filter( $asset['dependencies'], static fn ( $dependency ): bool => is_string( $dependency ) && '' !== $dependency ) ),
			$asset['version'],
			true
		);
		wp_set_script_translations( 'easyq-tools', 'easyq', \EasyQ\PLUGIN_DIR . '/languages' );
	}

	/**
	 * Runs one batch of a submitted tool.
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed> $post Unslashed form data; the nonce has been checked.
	 * @return string|null Address to go to once the tool is complete, or null to show how far it is.
	 */
	public function handle( array $post ): ?string {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to run the tools.', 'easyq' ), 403 );
		}

		$value = static fn ( string $key ): string => isset( $post[ $key ] ) && is_scalar( $post[ $key ] ) ? (string) $post[ $key ] : '';
		$tool  = sanitize_key( $value( 'easyq_tool' ) );
		if ( in_array( $tool, self::CONFIRM, true ) && '' === $value( 'confirm' ) ) {
			$this->unconfirmed = $tool;

			return null;
		}

		$state = ( new Tools() )->run( Actor::current(), $tool, sanitize_key( $value( 'step' ) ), absint( $value( 'after' ) ) );
		if ( null !== $state && $state['complete'] ) {
			return add_query_arg(
				array(
					'page' => self::PAGE,
					'done' => $tool,
				),
				admin_url( 'admin.php' )
			);
		}

		$this->state = $state;

		return null;
	}

	/**
	 * Renders the screen.
	 *
	 * @since 0.1.0
	 */
	public function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only shows which tool finished.
		$done   = isset( $_GET['done'] ) && is_string( $_GET['done'] ) ? sanitize_key( $_GET['done'] ) : '';
		$labels = Tools::labels();

		echo '<div class="wrap"><h1>' . esc_html( get_admin_page_title() ) . '</h1>';
		if ( isset( $labels[ $done ] ) ) {
			/* translators: %s: name of a tool. */
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( __( 'Finished: %s.', 'easyq' ), $labels[ $done ][0] ) ) );
		}
		printf( '<p>%s</p>', esc_html__( 'These tools recalculate stored values and clean up the database. They can take a while on large sites; keep this page open until they are done.', 'easyq' ) );

		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( $labels as $tool => [ $name, $description ] ) {
			$this->render_tool( $tool, $name, $description );
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Renders the form of a tool.
	 *
	 * @since 0.1.0
	 *
	 * @param string $tool        Tool.
	 * @param string $name        Name.
	 * @param string $description Description.
	 */
	private function render_tool( string $tool, string $name, string $description ): void {
		$state   = null !== $this->state && $tool === $this->state['tool'] ? $this->state : null;
		$id      = 'easyq-tool-' . str_replace( '_', '-', $tool );
		$confirm = in_array( $tool, self::CONFIRM, true );

		printf( '<tr><th scope="row" id="%1$s-name">%2$s</th><td>', esc_attr( $id ), esc_html( $name ) );
		printf(
			'<form method="post" action="%1$s" data-easyq-tool="%2$s" aria-labelledby="%3$s-name">',
			esc_url( add_query_arg( 'page', self::PAGE, admin_url( 'admin.php' ) ) ),
			esc_attr( $tool ),
			esc_attr( $id )
		);
		wp_nonce_field( self::NONCE );
		printf( '<input type="hidden" name="easyq_tool" value="%s">', esc_attr( $tool ) );
		printf( '<input type="hidden" name="step" value="%s">', esc_attr( $state['step'] ?? '' ) );
		printf( '<input type="hidden" name="after" value="%d">', (int) ( $state['after'] ?? 0 ) );
		printf( '<p class="description" id="%1$s-description">%2$s</p>', esc_attr( $id ), esc_html( $description ) );

		if ( $confirm ) {
			printf(
				'<p><label for="%1$s-confirm"><input type="checkbox" id="%1$s-confirm" name="confirm" value="1" required%2$s> %3$s</label></p>',
				esc_attr( $id ),
				$this->unconfirmed === $tool ? ' aria-invalid="true" aria-describedby="' . esc_attr( $id ) . '-error"' : '',
				esc_html__( 'I understand that the posts are deleted for good.', 'easyq' )
			);
			if ( $this->unconfirmed === $tool ) {
				printf( '<p class="notice-error" id="%1$s-error"><strong>%2$s</strong></p>', esc_attr( $id ), esc_html__( 'Please confirm first.', 'easyq' ) );
			}
		}

		printf(
			'<p><button type="submit" class="button" aria-describedby="%1$s-description">%2$s</button></p>',
			esc_attr( $id ),
			null === $state ? esc_html__( 'Run', 'easyq' ) : esc_html__( 'Continue', 'easyq' )
		);

		printf(
			'<progress%1$s max="%2$d" value="%3$d" aria-labelledby="%4$s-name"></progress>',
			null === $state ? ' hidden' : '',
			(int) max( 1, (int) ( $state['total'] ?? 0 ) ),
			(int) ( $state['done'] ?? 0 ),
			esc_attr( $id )
		);
		printf(
			'<p data-easyq-status role="status">%s</p>',
			null === $state
				? ''
				: esc_html(
					sprintf(
						/* translators: 1: number of the step, 2: number of steps, 3: items done, 4: items in the step. */
						__( 'Step %1$d of %2$d: %3$s of %4$s done.', 'easyq' ),
						$state['step_number'],
						$state['steps'],
						number_format_i18n( $state['done'] ),
						number_format_i18n( $state['total'] )
					)
				)
		);
		echo '</form></td></tr>';
	}
}
