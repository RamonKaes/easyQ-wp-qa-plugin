<?php
/**
 * User field list table.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Admin;

use EasyQ\REST\User_Fields_Controller;
use EasyQ\Services;
use EasyQ\Users\Permits;
use EasyQ\Users\User_Field;
use WP_List_Table;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( WP_List_Table::class ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists the profile fields in their order.
 *
 * @since 0.1.0
 */
final class User_Fields_List_Table extends WP_List_Table {

	/**
	 * Sets up the table.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		parent::__construct(
			array(
				'plural'   => 'easyq-user-fields',
				'singular' => 'easyq-user-field',
				'screen'   => User_Fields_Screen::PAGE,
			)
		);
	}

	/**
	 * Returns the names of the field types.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	public static function type_labels(): array {
		return array(
			'text'       => __( 'One line of text', 'easyq' ),
			'multi_line' => __( 'Several lines of text', 'easyq' ),
			'link'       => __( 'Web address', 'easyq' ),
		);
	}

	/**
	 * Loads the fields.
	 *
	 * @since 0.1.0
	 */
	public function prepare_items(): void {
		$this->items           = array_values( Services::user_fields()->all() );
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'label' );
	}

	/**
	 * Returns the columns.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'label'    => __( 'Label', 'easyq' ),
			'name'     => __( 'Name', 'easyq' ),
			'type'     => __( 'Type', 'easyq' ),
			'permit'   => __( 'Visible to', 'easyq' ),
			'register' => __( 'Registration form', 'easyq' ),
		);
	}

	/**
	 * Returns the text shown when there are no fields.
	 *
	 * @since 0.1.0
	 */
	public function no_items(): void {
		esc_html_e( 'No profile fields yet.', 'easyq' );
	}

	/**
	 * Renders the table navigation. The list has no bulk actions and no pages.
	 *
	 * @since 0.1.0
	 *
	 * @param string $which Above or below the table.
	 */
	protected function display_tablenav( $which ): void {
	}

	/**
	 * Returns a short name for the row header: the label of the field.
	 *
	 * @since 0.1.0
	 *
	 * @param User_Field $item Field.
	 * @return string
	 */
	protected function get_primary_column_aria_label( $item ): string {
		return $item->display_label();
	}

	/**
	 * Renders the label with the row action.
	 *
	 * @since 0.1.0
	 *
	 * @param User_Field $item Field.
	 * @return string
	 */
	public function column_label( User_Field $item ): string {
		$url = User_Fields_Screen::edit_url( $item->id );

		return sprintf( '<strong><a class="row-title" href="%1$s">%2$s</a></strong>', esc_url( $url ), esc_html( $item->display_label() ) )
			. $this->row_actions(
				array(
					'edit' => sprintf(
						'<a href="%1$s" aria-label="%2$s">%3$s</a>',
						esc_url( $url ),
						/* translators: %s: field label. */
						esc_attr( sprintf( __( 'Edit “%s”', 'easyq' ), $item->display_label() ) ),
						esc_html__( 'Edit', 'easyq' )
					),
				)
			);
	}

	/**
	 * Renders the internal name.
	 *
	 * @since 0.1.0
	 *
	 * @param User_Field $item Field.
	 * @return string
	 */
	public function column_name( User_Field $item ): string {
		return '<code>' . esc_html( $item->name ) . '</code>';
	}

	/**
	 * Renders the type.
	 *
	 * @since 0.1.0
	 *
	 * @param User_Field $item Field.
	 * @return string
	 */
	public function column_type( User_Field $item ): string {
		return esc_html( self::type_labels()[ User_Fields_Controller::type( $item ) ] ?? '' );
	}

	/**
	 * Renders who may see the field.
	 *
	 * @since 0.1.0
	 *
	 * @param User_Field $item Field.
	 * @return string
	 */
	public function column_permit( User_Field $item ): string {
		return esc_html( Permits::labels()[ $item->permit ?? Permits::ALL ] ?? '' );
	}

	/**
	 * Renders whether the field is on the registration form.
	 *
	 * @since 0.1.0
	 *
	 * @param User_Field $item Field.
	 * @return string
	 */
	public function column_register( User_Field $item ): string {
		return esc_html( $item->on_register() ? __( 'Yes', 'easyq' ) : __( 'No', 'easyq' ) );
	}
}
