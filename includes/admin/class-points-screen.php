<?php
/**
 * Points and user titles screens.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Admin;

use EasyQ\Capabilities;
use EasyQ\Points\Points;
use EasyQ\Settings\Fields;
use EasyQ\Settings\Options;
use EasyQ\Settings\Registry;
use EasyQ\Users\User_Titles;

defined( 'ABSPATH' ) || exit;

/**
 * Sets how many points users get, and the titles they earn with them, through the
 * Settings API, like the Question2Answer pages `admin/points` and `admin/usertitles`.
 *
 * Saving the points recalculates the points of all users at once.
 *
 * @since 0.1.0
 */
final class Points_Screen {

	/**
	 * Page slug of the points screen.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PAGE = 'easyq-points';

	/**
	 * Page slug of the user titles screen.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const TITLES_PAGE = 'easyq-user-titles';

	/**
	 * Empty rows offered for new titles.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const NEW_TITLE_ROWS = 3;

	/**
	 * Hooks the screens into WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 12 );
		add_action( 'admin_init', array( $this, 'add_fields' ) );
		add_action( 'update_option_' . Options::option_name( 'points' ), array( $this, 'report_recalculation' ), 20, 2 );
	}

	/**
	 * Tells the user that saving recalculated the points of all users.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $old   Settings before the change.
	 * @param mixed $value Settings after the change.
	 */
	public function report_recalculation( mixed $old, mixed $value ): void {
		if ( array() !== Points::changed_settings( is_array( $old ) ? $old : array(), is_array( $value ) ? $value : array() ) ) {
			add_settings_error( Registry::group( 'points' ), 'easyq-recalculated', __( 'Settings saved. The points of all users were recalculated.', 'easyq' ), 'success' );
		}
	}

	/**
	 * Adds the screens to the easyQ menu.
	 *
	 * @since 0.1.0
	 */
	public function add_menu(): void {
		add_submenu_page( Dashboard_Screen::PAGE, __( 'Points', 'easyq' ), __( 'Points', 'easyq' ), Capabilities::MANAGE, self::PAGE, array( $this, 'render' ) );
		add_submenu_page( Dashboard_Screen::PAGE, __( 'User Titles', 'easyq' ), __( 'User Titles', 'easyq' ), Capabilities::MANAGE, self::TITLES_PAGE, array( $this, 'render_titles' ) );
	}

	/**
	 * Returns the groups of the points form, as Question2Answer separates them.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: string, 1: string[]}> Title and settings of each group.
	 */
	public static function groups(): array {
		return array(
			'questions' => array( __( 'Questions', 'easyq' ), array( 'points_post_q', 'points_select_a', 'points_per_q_voted_up', 'points_per_q_voted_down', 'points_q_voted_max_gain', 'points_q_voted_max_loss' ) ),
			'answers'   => array( __( 'Answers', 'easyq' ), array( 'points_post_a', 'points_a_selected', 'points_per_a_voted_up', 'points_per_a_voted_down', 'points_a_voted_max_gain', 'points_a_voted_max_loss' ) ),
			'comments'  => array( __( 'Comments', 'easyq' ), array( 'points_per_c_voted_up', 'points_per_c_voted_down', 'points_c_voted_max_gain', 'points_c_voted_max_loss' ) ),
			'voting'    => array( __( 'Voting', 'easyq' ), array( 'points_vote_up_q', 'points_vote_down_q', 'points_vote_up_a', 'points_vote_down_a' ) ),
			'all'       => array( __( 'All users', 'easyq' ), array( 'points_multiple', 'points_base' ) ),
		);
	}

	/**
	 * Adds the points fields to the Settings API.
	 *
	 * @since 0.1.0
	 */
	public function add_fields(): void {
		$fields   = Fields::section( 'points' );
		$renderer = new Settings_Screen();
		$defaults = $this->showing_defaults();

		foreach ( self::groups() as $group => [ $title, $keys ] ) {
			$section = Registry::group( 'points' ) . '_' . $group;
			add_settings_section( $section, $title, '__return_empty_string', self::PAGE );

			foreach ( $keys as $key ) {
				if ( ! isset( $fields[ $key ] ) ) {
					continue;
				}

				add_settings_field(
					$key,
					esc_html( $fields[ $key ]['label'] ),
					array( $renderer, 'render_field' ),
					self::PAGE,
					$section,
					array(
						'section'   => 'points',
						'key'       => $key,
						'defaults'  => $defaults,
						'label_for' => 'easyq-points-' . str_replace( '_', '-', $key ),
					)
				);
			}
		}
	}

	/**
	 * Renders the points screen.
	 *
	 * @since 0.1.0
	 */
	public function render(): void {
		$defaults = $this->showing_defaults();

		echo '<div class="wrap"><h1>' . esc_html( get_admin_page_title() ) . '</h1>';
		settings_errors();
		if ( $defaults ) {
			printf( '<div class="notice notice-info"><p>%s</p></div>', esc_html__( 'The default values are shown. Save the changes to use them.', 'easyq' ) );
		}
		printf( '<p>%s</p>', esc_html__( 'Users get these points for their activity. Saving recalculates the points of all users.', 'easyq' ) );

		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( Registry::group( 'points' ) );
		do_settings_sections( self::PAGE );
		echo '<p class="submit">';
		submit_button( __( 'Save and Recalculate', 'easyq' ), 'primary', 'submit', false );
		if ( $defaults ) {
			printf( ' <a class="button" href="%1$s">%2$s</a>', esc_url( self::url() ), esc_html__( 'Cancel', 'easyq' ) );
		} else {
			printf( ' <a class="button" href="%1$s">%2$s</a>', esc_url( self::url( array( 'defaults' => 1 ) ) ), esc_html__( 'Show Default Values', 'easyq' ) );
		}
		echo '</p></form></div>';
	}

	/**
	 * Renders the user titles screen: one row per title, and empty rows for new ones.
	 *
	 * @since 0.1.0
	 */
	public function render_titles(): void {
		$name = Options::option_name( 'points' ) . '[user_titles]';
		$rows = array();
		foreach ( User_Titles::thresholds() as $points => $title ) {
			$rows[] = array( (string) $points, $title );
		}
		usort( $rows, static fn ( array $a, array $b ): int => (int) $b[0] <=> (int) $a[0] );
		for ( $i = 0; $i < self::NEW_TITLE_ROWS; $i++ ) {
			$rows[] = array( '', '' );
		}

		echo '<div class="wrap"><h1>' . esc_html( get_admin_page_title() ) . '</h1>';
		settings_errors();
		printf(
			'<p id="easyq-titles-description">%s</p>',
			esc_html__( 'Users earn a title once they have the points needed. Users with a special level, such as Expert or Moderator, show their level instead. To remove a title, clear it.', 'easyq' )
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( Registry::group( 'points' ) );
		printf(
			'<table class="widefat striped" aria-describedby="easyq-titles-description"><thead><tr><th scope="col">%1$s</th><th scope="col">%2$s</th></tr></thead><tbody>',
			esc_html__( 'Points needed', 'easyq' ),
			esc_html__( 'Title', 'easyq' )
		);
		foreach ( $rows as $index => [ $points, $title ] ) {
			$row = $index + 1;
			printf(
				'<tr><td><label class="screen-reader-text" for="easyq-title-points-%1$d">%2$s</label><input type="number" class="small-text" min="0" id="easyq-title-points-%1$d" name="%3$s[%1$d][points]" value="%4$s"></td>'
				. '<td><label class="screen-reader-text" for="easyq-title-%1$d">%5$s</label><input type="text" class="regular-text" maxlength="%6$d" id="easyq-title-%1$d" name="%3$s[%1$d][title]" value="%7$s"></td></tr>',
				(int) $row,
				/* translators: %d: row number. */
				esc_html( sprintf( __( 'Points needed for title %d', 'easyq' ), $row ) ),
				esc_attr( $name ),
				esc_attr( $points ),
				/* translators: %d: row number. */
				esc_html( sprintf( __( 'Title %d', 'easyq' ), $row ) ),
				(int) Fields::MAX_TITLE_LENGTH,
				esc_attr( $title )
			);
		}
		echo '</tbody></table>';
		submit_button( __( 'Save Titles', 'easyq' ) );
		echo '</form></div>';
	}

	/**
	 * Returns the address of the points screen.
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
	 * Checks whether the form shows the default values, as Question2Answer offers.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	private function showing_defaults(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects which values to show; nothing is changed.
		return isset( $_GET['page'], $_GET['defaults'] ) && self::PAGE === $_GET['page'] && '1' === $_GET['defaults'];
	}
}
