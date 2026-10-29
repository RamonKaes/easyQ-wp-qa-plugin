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
use EasyQ\Users\Permits;
use EasyQ\Util\Assets;

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
		add_action( 'admin_menu', array( $this, 'add_menu' ), 14 );
		add_action( 'admin_init', array( $this, 'add_fields' ) );
	}

	/**
	 * Adds the settings page to the easyQ menu.
	 *
	 * @since 0.1.0
	 */
	public function add_menu(): void {
		$hook = add_submenu_page( Dashboard_Screen::PAGE, __( 'Q&A Settings', 'easyq' ), __( 'Settings', 'easyq' ), Capabilities::MANAGE, self::PAGE, array( $this, 'render' ) );
		if ( false !== $hook ) {
			add_action(
				'load-' . $hook,
				static function (): void {
					add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
				}
			);
		}
	}

	/**
	 * Loads the script that shows the points minimum of a permission only when it is needed.
	 *
	 * @since 0.1.0
	 */
	public static function enqueue(): void {
		$asset = Assets::get( 'settings' );
		wp_enqueue_script(
			'easyq-settings',
			plugins_url( 'build/settings.js', \EasyQ\PLUGIN_FILE ),
			array_values( array_filter( $asset['dependencies'], static fn ( $dependency ): bool => is_string( $dependency ) && '' !== $dependency ) ),
			$asset['version'],
			true
		);
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
		foreach ( array_keys( Fields::tabs() ) as $tab ) {
			$fields = Fields::section( $tab );

			foreach ( Settings_Layout::sections( $tab ) as $index => $section ) {
				$section_id  = Registry::group( $tab ) . '_' . $index;
				$description = $section['description'];
				add_settings_section(
					$section_id,
					$section['title'],
					static function () use ( $description ): void {
						if ( '' !== $description ) {
							printf( '<p>%s</p>', esc_html( $description ) );
						}
					},
					self::page( $tab )
				);

				foreach ( $section['rows'] as $number => $row ) {
					// Fields of the site owner are only shown to the site owner.
					$keys = array_values( array_filter( is_string( $row ) ? array( $row ) : $row['keys'], static fn ( string $key ): bool => isset( $fields[ $key ] ) && Fields::may_change( $fields[ $key ] ) ) );
					if ( array() === $keys ) {
						continue;
					}

					if ( is_string( $row ) && 'role_levels' === $fields[ $row ]['type'] ) {
						// One row per role, as core lists one setting per row.
						foreach ( wp_roles()->get_names() as $role => $role_name ) {
							add_settings_field(
								$row . '-' . $role,
								esc_html( translate_user_role( $role_name ) ),
								array( $this, 'render_role_level' ),
								self::page( $tab ),
								$section_id,
								array(
									'section'   => $tab,
									'key'       => $row,
									'role'      => $role,
									'label_for' => self::id( $tab, $row ) . '-' . $role,
								)
							);
						}
						continue;
					}

					if ( is_string( $row ) ) {
						$args = array(
							'section' => $tab,
							'key'     => $row,
						);
						if ( 'role_levels' !== $fields[ $row ]['type'] ) {
							$args['label_for'] = self::id( $tab, $row );
						}
						add_settings_field( $row, esc_html( $fields[ $row ]['short'] ?? $fields[ $row ]['label'] ), array( $this, 'render_field' ), self::page( $tab ), $section_id, $args );
						continue;
					}

					add_settings_field(
						'easyq-row-' . $index . '-' . $number,
						esc_html( $row['title'] ),
						array( $this, 'render_row' ),
						self::page( $tab ),
						$section_id,
						array(
							'section' => $tab,
							'title'   => $row['title'],
							'keys'    => $keys,
						)
					);
				}
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
	 * @param array{section: string, key: string, defaults?: bool} $args Section and setting name; `defaults` shows the default value instead of the stored one.
	 */
	public function render_field( array $args ): void {
		$section = $args['section'];
		$key     = $args['key'];
		$field   = Fields::section( $section )[ $key ] ?? null;
		if ( null === $field ) {
			return;
		}

		$description = $field['description'] ?? '';
		$this->render_control( $section, $key, ! empty( $args['defaults'] ), '' === $description ? '' : self::id( $section, $key ) . '-description' );
		if ( '' !== $description ) {
			printf( '<p class="description" id="%1$s">%2$s</p>', esc_attr( self::id( $section, $key ) . '-description' ), esc_html( $description ) );
		}
	}

	/**
	 * Renders several related settings in one row, as core does under Settings →
	 * Discussion: each control with its text after it.
	 *
	 * A description that several settings share is shown once.
	 *
	 * @since 0.1.0
	 *
	 * @param array{section: string, title: string, keys: string[], defaults?: bool} $args Section, row title and settings; `defaults` shows the default values.
	 */
	public function render_row( array $args ): void {
		$defaults = ! empty( $args['defaults'] );
		$section  = $args['section'];
		$fields   = Fields::section( $section );
		$keys     = array_values( array_filter( $args['keys'], static fn ( string $key ): bool => isset( $fields[ $key ] ) ) );

		// Descriptions follow the controls of the row, each once.
		$descriptions = array();
		foreach ( $keys as $key ) {
			$description = $fields[ $key ]['description'] ?? '';
			if ( '' !== $description && ! isset( $descriptions[ $description ] ) ) {
				$descriptions[ $description ] = self::id( $section, $key ) . '-description';
			}
		}

		printf( '<fieldset><legend class="screen-reader-text"><span>%s</span></legend>', esc_html( $args['title'] ) );
		foreach ( $keys as $position => $key ) {
			$field     = $fields[ $key ];
			$id        = self::id( $section, $key );
			$label     = $field['short'] ?? $field['label'];
			$described = $descriptions[ $field['description'] ?? '' ] ?? '';

			if ( $position > 0 ) {
				echo '<br>';
			}
			// The control comes first, so that the controls of a row line up.
			if ( 'bool' === $field['type'] ) {
				printf( '<label for="%s">', esc_attr( $id ) );
				$this->render_control( $section, $key, $defaults, $described );
				echo ' ' . esc_html( $label ) . '</label>';
			} else {
				$this->render_control( $section, $key, $defaults, $described );
				printf( ' <label for="%1$s">%2$s</label>', esc_attr( $id ), esc_html( $label ) );
			}
		}
		foreach ( $descriptions as $description => $description_id ) {
			printf( '<p class="description" id="%1$s">%2$s</p>', esc_attr( $description_id ), esc_html( $description ) );
		}
		echo '</fieldset>';
	}

	/**
	 * Renders the control of a setting, without its description.
	 *
	 * @since 0.1.0
	 *
	 * @param string $section   Section name.
	 * @param string $key       Setting name.
	 * @param bool   $defaults  Whether to show the default value instead of the stored one.
	 * @param string $described ID of the element that describes the control; empty for none.
	 */
	private function render_control( string $section, string $key, bool $defaults, string $described ): void {
		$fields = Fields::section( $section );
		$field  = $fields[ $key ] ?? null;
		if ( null === $field ) {
			return;
		}

		$value     = $defaults ? ( Options::defaults()[ $section ][ $key ] ?? null ) : Options::get( $section, $key );
		$id        = self::id( $section, $key );
		$name      = Options::option_name( $section ) . '[' . $key . ']';
		$described = '' === $described ? '' : ' aria-describedby="' . esc_attr( $described ) . '"';

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
				$points = isset( $fields[ $key . '_points' ] ) ? $key . '_points' : null;
				// Permissions get one width, so that their points line up.
				$this->render_select( $id, $name, $field['choices'] ?? array(), $value, $described, 'permissions' === $section ? 'regular-text' : '', null === $points ? null : self::id( $section, $points ) . '-wrap' );
				if ( null !== $points ) {
					$this->render_points( $section, $points, $defaults );
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

			default:
				printf(
					'<input type="text" class="regular-text" id="%1$s" name="%2$s" value="%3$s"%4$s>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( is_scalar( $value ) ? (string) $value : '' ),
					$described // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				);
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
	 * @param string                    $css_class Class of the box, e.g. `regular-text` for the core width; empty for none.
	 * @param string|null               $points    ID of the points minimum that only choices with points need, or null.
	 */
	private function render_select( string $id, string $name, array $choices, mixed $value, string $described = '', string $css_class = '', ?string $points = null ): void {
		printf(
			'<select id="%1$s" name="%2$s"%3$s%4$s%5$s>',
			esc_attr( $id ),
			esc_attr( $name ),
			'' === $css_class ? '' : ' class="' . esc_attr( $css_class ) . '"',
			null === $points ? '' : ' data-easyq-points="' . esc_attr( $points ) . '"',
			$described // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller.
		);
		foreach ( $choices as $choice => $label ) {
			printf(
				'<option value="%1$s"%2$s%3$s>%4$s</option>',
				esc_attr( (string) $choice ),
				null !== $points && is_int( $choice ) && Permits::uses_points( $choice ) ? ' data-points' : '',
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
	 * The script of the screen hides it unless the chosen threshold needs points.
	 *
	 * @param string $section  Section name.
	 * @param string $key      Setting name of the points minimum.
	 * @param bool   $defaults Whether to show the default value instead of the stored one.
	 */
	private function render_points( string $section, string $key, bool $defaults = false ): void {
		$id    = self::id( $section, $key );
		$value = $defaults ? ( Options::defaults()[ $section ][ $key ] ?? 0 ) : Options::get_int( $section, $key );

		printf(
			' <span id="%1$s-wrap"><label for="%1$s">%2$s</label> <input type="number" class="small-text" id="%1$s" name="%3$s" value="%4$s" min="0"></span>',
			esc_attr( $id ),
			esc_html__( 'Points needed', 'easyq' ),
			esc_attr( Options::option_name( $section ) . '[' . $key . ']' ),
			esc_attr( is_numeric( $value ) ? (string) (int) $value : '0' )
		);
	}

	/**
	 * Renders the Q&A level of one role.
	 *
	 * @since 0.1.0
	 *
	 * @param array{section: string, key: string, role: string} $args Section, setting name and role.
	 */
	public function render_role_level( array $args ): void {
		$levels = Options::get( $args['section'], $args['key'] );
		$id     = self::id( $args['section'], $args['key'] ) . '-' . $args['role'];

		$this->render_select(
			$id,
			Options::option_name( $args['section'] ) . '[' . $args['key'] . '][' . $args['role'] . ']',
			Fields::role_level_choices(),
			is_array( $levels ) ? ( $levels[ $args['role'] ] ?? Levels::BASIC ) : Levels::BASIC,
			'',
			'regular-text'
		);
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
