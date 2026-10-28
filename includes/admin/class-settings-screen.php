<?php
/**
 * Settings screen.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Admin;

use EasyQ\Capabilities;
use EasyQ\Settings\Fields;
use EasyQ\Settings\Options;
use EasyQ\Settings\Registry;
use EasyQ\Users\Levels;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the easyQ admin menu and its settings page: one tab per settings section,
 * built with the Settings API and core admin markup.
 *
 * @since 0.1.0
 *
 * @phpstan-import-type Field from Fields
 */
final class Settings_Screen {

	/**
	 * Page slug of the settings screen.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PAGE = 'easyq-settings';

	/**
	 * Hooks the screen into WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'add_fields' ) );
	}

	/**
	 * Adds the easyQ menu with the settings page.
	 *
	 * @since 0.1.0
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'Q&A Settings', 'easyq' ),
			'easyQ',
			Capabilities::MANAGE,
			self::PAGE,
			array( $this, 'render' ),
			'dashicons-format-chat',
			26
		);
		add_submenu_page( self::PAGE, __( 'Q&A Settings', 'easyq' ), __( 'Settings', 'easyq' ), Capabilities::MANAGE, self::PAGE, array( $this, 'render' ) );
	}

	/**
	 * Returns the Settings API page of a section.
	 *
	 * @since 0.1.0
	 *
	 * @param string $section Section name.
	 * @return string
	 */
	public static function page( string $section ): string {
		return self::PAGE . '-' . $section;
	}

	/**
	 * Adds the fields of all sections to the Settings API.
	 *
	 * @since 0.1.0
	 */
	public function add_fields(): void {
		foreach ( array_keys( Fields::tabs() ) as $section ) {
			add_settings_section( Registry::group( $section ), '', '__return_empty_string', self::page( $section ) );

			foreach ( Fields::section( $section ) as $key => $field ) {
				// A points minimum is shown with its permission; fields of the site owner only to the site owner.
				if ( isset( $field['parent'] ) || ! Fields::may_change( $field ) ) {
					continue;
				}

				$args = array(
					'section' => $section,
					'key'     => $key,
				);
				if ( 'role_levels' !== $field['type'] ) {
					$args['label_for'] = self::id( $section, $key );
				}

				add_settings_field( $key, esc_html( $field['label'] ), array( $this, 'render_field' ), self::page( $section ), Registry::group( $section ), $args );
			}
		}
	}

	/**
	 * Renders the settings page.
	 *
	 * @since 0.1.0
	 */
	public function render(): void {
		$tab = $this->current_tab();

		echo '<div class="wrap"><h1>' . esc_html( get_admin_page_title() ) . '</h1>';
		settings_errors();

		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Settings sections', 'easyq' ) . '">';
		foreach ( Fields::tabs() as $section => $title ) {
			$url = add_query_arg(
				array(
					'page' => self::PAGE,
					'tab'  => $section,
				),
				admin_url( 'admin.php' )
			);
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
				esc_url( $url ),
				$section === $tab ? ' nav-tab-active' : '',
				$section === $tab ? ' aria-current="page"' : '',
				esc_html( $title )
			);
		}
		echo '</nav>';

		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( Registry::group( $tab ) );
		do_settings_sections( self::page( $tab ) );
		submit_button();
		echo '</form></div>';
	}

	/**
	 * Renders the control of a field.
	 *
	 * @since 0.1.0
	 *
	 * @param array{section: string, key: string} $args Section and setting name.
	 */
	public function render_field( array $args ): void {
		$section = $args['section'];
		$key     = $args['key'];
		$fields  = Fields::section( $section );
		$field   = $fields[ $key ] ?? null;
		if ( null === $field ) {
			return;
		}

		$value       = Options::get( $section, $key );
		$id          = self::id( $section, $key );
		$name        = Options::option_name( $section ) . '[' . $key . ']';
		$description = $field['description'] ?? '';
		$described   = '' === $description ? '' : ' aria-describedby="' . esc_attr( $id . '-description' ) . '"';

		switch ( $field['type'] ) {
			case 'bool':
				// The hidden field submits "off" for an unchecked box.
				printf(
					'<input type="hidden" name="%1$s" value="0"><input type="checkbox" id="%2$s" name="%1$s" value="1"%3$s%4$s>',
					esc_attr( $name ),
					esc_attr( $id ),
					checked( (bool) $value, true, false ),
					$described // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				);
				break;

			case 'int':
				printf(
					'<input type="number" class="small-text" id="%1$s" name="%2$s" value="%3$s" min="%4$s"%5$s%6$s>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( is_numeric( $value ) ? (string) (int) $value : '' ),
					esc_attr( (string) ( $field['min'] ?? 0 ) ),
					isset( $field['max'] ) ? ' max="' . esc_attr( (string) $field['max'] ) . '"' : '',
					$described // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				);
				break;

			case 'choice':
				$this->render_select( $id, $name, $field['choices'] ?? array(), $value, $described );
				$points = $fields[ $key . '_points' ] ?? null;
				if ( null !== $points ) {
					$this->render_points( $section, $key . '_points' );
				}
				break;

			case 'lines':
				printf(
					'<textarea class="large-text" rows="4" id="%1$s" name="%2$s"%3$s>%4$s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					$described, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
					esc_textarea( is_string( $value ) ? $value : '' )
				);
				break;

			case 'role_levels':
				$this->render_role_levels( $id, $name, $field['label'], $value );
				break;

			default:
				printf(
					'<input type="text" class="regular-text" id="%1$s" name="%2$s" value="%3$s"%4$s>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( is_scalar( $value ) ? (string) $value : '' ),
					$described // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				);
		}

		if ( '' !== $description ) {
			printf( '<p class="description" id="%1$s">%2$s</p>', esc_attr( $id . '-description' ), esc_html( $description ) );
		}
	}

	/**
	 * Renders a select box.
	 *
	 * @since 0.1.0
	 *
	 * @param string                    $id        Element ID.
	 * @param string                    $name      Field name.
	 * @param array<int|string, string> $choices   Values with their names.
	 * @param mixed                     $value     Current value.
	 * @param string                    $described Escaped aria-describedby attribute, or an empty string.
	 */
	private function render_select( string $id, string $name, array $choices, mixed $value, string $described = '' ): void {
		printf(
			'<select id="%1$s" name="%2$s"%3$s>',
			esc_attr( $id ),
			esc_attr( $name ),
			$described // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller.
		);
		foreach ( $choices as $choice => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $choice ),
				selected( is_scalar( $value ) && (string) $choice === (string) $value, true, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Renders the points minimum of a permission.
	 *
	 * @since 0.1.0
	 *
	 * @param string $section Section name.
	 * @param string $key     Setting name of the points minimum.
	 */
	private function render_points( string $section, string $key ): void {
		$id = self::id( $section, $key );

		printf(
			' <label for="%1$s">%2$s</label> <input type="number" class="small-text" id="%1$s" name="%3$s" value="%4$s" min="0">',
			esc_attr( $id ),
			esc_html__( 'Points needed', 'easyq' ),
			esc_attr( Options::option_name( $section ) . '[' . $key . ']' ),
			esc_attr( (string) Options::get_int( $section, $key ) )
		);
	}

	/**
	 * Renders the level of each role.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id    Element ID prefix.
	 * @param string $name  Field name.
	 * @param string $label Field label.
	 * @param mixed  $value Current levels keyed by role.
	 */
	private function render_role_levels( string $id, string $name, string $label, mixed $value ): void {
		$levels = is_array( $value ) ? $value : array();

		printf( '<fieldset aria-describedby="%1$s"><legend class="screen-reader-text">%2$s</legend>', esc_attr( $id . '-description' ), esc_html( $label ) );
		foreach ( wp_roles()->get_names() as $role => $role_name ) {
			$role_id = $id . '-' . $role;

			printf( '<label for="%1$s">%2$s</label> ', esc_attr( $role_id ), esc_html( translate_user_role( $role_name ) ) );
			$this->render_select( $role_id, $name . '[' . $role . ']', Fields::role_level_choices(), $levels[ $role ] ?? Levels::BASIC );
			echo '<br>';
		}
		echo '</fieldset>';
	}

	/**
	 * Returns the element ID of a field.
	 *
	 * @since 0.1.0
	 *
	 * @param string $section Section name.
	 * @param string $key     Setting name.
	 * @return string
	 */
	private static function id( string $section, string $key ): string {
		return 'easyq-' . $section . '-' . str_replace( '_', '-', $key );
	}

	/**
	 * Returns the tab to show.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	private function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects the tab to show; nothing is changed.
		$tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '';

		return isset( Fields::tabs()[ $tab ] ) ? $tab : 'general';
	}
}
