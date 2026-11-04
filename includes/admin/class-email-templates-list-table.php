<?php
/**
 * Email template list table.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Admin;

use EasyQ\Mail\Templates;
use WP_List_Table;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( WP_List_Table::class ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists the emails with who gets them and whether their text was edited.
 *
 * @since 0.1.0
 */
final class Email_Templates_List_Table extends WP_List_Table {

	/**
	 * Sets up the table.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		parent::__construct(
			array(
				'plural'   => 'easyq-email-templates',
				'singular' => 'easyq-email-template',
				'screen'   => Email_Templates_Screen::PAGE,
			)
		);
	}

	/**
	 * Loads the templates.
	 *
	 * @since 0.1.0
	 */
	public function prepare_items(): void {
		$items = array();
		foreach ( Templates::info() as $name => $info ) {
			$items[] = array( 'name' => $name ) + $info;
		}

		$this->items           = $items;
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
			'label'    => __( 'Email', 'easyq' ),
			'audience' => __( 'Goes to', 'easyq' ),
			'subject'  => __( 'Subject', 'easyq' ),
			'status'   => __( 'Text', 'easyq' ),
		);
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
	 * Returns a short name for the row header: the name of the email.
	 *
	 * @since 0.1.0
	 *
	 * @param array{name: string, label: string, audience: string} $item Template.
	 * @return string
	 */
	protected function get_primary_column_aria_label( $item ): string {
		return $item['label'];
	}

	/**
	 * Renders the name with the row action.
	 *
	 * @since 0.1.0
	 *
	 * @param array{name: string, label: string, audience: string} $item Template.
	 * @return string
	 */
	public function column_label( array $item ): string {
		$url = Email_Templates_Screen::edit_url( $item['name'] );

		return sprintf( '<strong><a class="row-title" href="%1$s">%2$s</a></strong>', esc_url( $url ), esc_html( $item['label'] ) )
			. $this->row_actions(
				array(
					'edit' => sprintf(
						'<a href="%1$s" aria-label="%2$s">%3$s</a>',
						esc_url( $url ),
						/* translators: %s: name of the email. */
						esc_attr( sprintf( __( 'Edit “%s”', 'easyq' ), $item['label'] ) ),
						esc_html__( 'Edit', 'easyq' )
					),
				)
			);
	}

	/**
	 * Renders who gets the email.
	 *
	 * @since 0.1.0
	 *
	 * @param array{name: string, label: string, audience: string} $item Template.
	 * @return string
	 */
	public function column_audience( array $item ): string {
		return esc_html( $item['audience'] );
	}

	/**
	 * Renders the subject.
	 *
	 * @since 0.1.0
	 *
	 * @param array{name: string, label: string, audience: string} $item Template.
	 * @return string
	 */
	public function column_subject( array $item ): string {
		return '<code>' . esc_html( Templates::get( $item['name'] )['subject'] ?? '' ) . '</code>';
	}

	/**
	 * Renders whether the text was edited.
	 *
	 * @since 0.1.0
	 *
	 * @param array{name: string, label: string, audience: string} $item Template.
	 * @return string
	 */
	public function column_status( array $item ): string {
		return esc_html( Templates::is_edited( $item['name'] ) ? __( 'Edited', 'easyq' ) : __( 'Default', 'easyq' ) );
	}
}
