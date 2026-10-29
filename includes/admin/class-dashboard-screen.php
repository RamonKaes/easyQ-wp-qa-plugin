<?php
/**
 * Dashboard screen and widget.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Admin;

use EasyQ\Capabilities;
use EasyQ\Database\Installer;
use EasyQ\Stats\Counts;
use EasyQ\Stats\Site_Stats;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the easyQ menu with its dashboard: the figures of the Question2Answer page
 * `admin/stats`, the posts waiting for moderators and links to the other screens;
 * plus a widget on the WordPress dashboard.
 *
 * Unlike Question2Answer, the dashboard does not look up the latest version, which
 * would be an external request; PHP and database versions are in Site Health.
 *
 * @since 0.1.0
 */
final class Dashboard_Screen {

	/**
	 * Page slug of the dashboard, which is also the slug of the easyQ menu.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PAGE = 'easyq';

	/**
	 * Hooks the screen and the widget into WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		// Before the other screens, which add their entries to this menu.
		add_action( 'admin_menu', array( $this, 'add_menu' ), 9 );
		add_action( 'wp_dashboard_setup', array( $this, 'add_widget' ) );
	}

	/**
	 * Adds the easyQ menu and the dashboard as its first entry.
	 *
	 * The menu is shown to moderators too; without the dashboard, it leads to the first
	 * screen they may use.
	 *
	 * @since 0.1.0
	 */
	public function add_menu(): void {
		add_menu_page( __( 'Q&A Dashboard', 'easyq' ), 'easyQ', Capabilities::MODERATE, self::PAGE, array( $this, 'render' ), 'dashicons-format-chat', 26 );
		add_submenu_page( self::PAGE, __( 'Q&A Dashboard', 'easyq' ), __( 'Dashboard', 'easyq' ), Capabilities::MANAGE, self::PAGE, array( $this, 'render' ) );
	}

	/**
	 * Adds the widget to the WordPress dashboard of moderators.
	 *
	 * @since 0.1.0
	 */
	public function add_widget(): void {
		if ( current_user_can( Capabilities::MODERATE ) ) {
			wp_add_dashboard_widget( 'easyq_dashboard', __( 'Questions and Answers', 'easyq' ), array( $this, 'render_widget' ) );
		}
	}

	/**
	 * Renders the dashboard.
	 *
	 * @since 0.1.0
	 */
	public function render(): void {
		echo '<div class="wrap"><h1>' . esc_html( get_admin_page_title() ) . '</h1>';
		$this->render_queues();

		$questions = Counts::get( Counts::QUESTIONS );
		$answers   = Counts::get( Counts::ANSWERS );
		$comments  = Counts::get( Counts::COMMENTS );
		$anonymous = array(
			'Q' => Site_Stats::anonymous( 'Q' ),
			'A' => Site_Stats::anonymous( 'A' ),
			'C' => Site_Stats::anonymous( 'C' ),
		);
		$users     = count_users();

		printf( '<h2 id="easyq-figures-posts">%s</h2>', esc_html__( 'Posts', 'easyq' ) );
		$this->render_table(
			'easyq-figures-posts',
			array(
				__( 'Questions', 'easyq' )            => $questions,
				__( 'Unanswered questions', 'easyq' ) => Counts::get( Counts::UNANSWERED ),
				__( 'Questions by users', 'easyq' )   => $questions - $anonymous['Q'],
				__( 'Questions by visitors without account', 'easyq' ) => $anonymous['Q'],
				__( 'Answers', 'easyq' )              => $answers,
				__( 'Answers by users', 'easyq' )     => $answers - $anonymous['A'],
				__( 'Answers by visitors without account', 'easyq' ) => $anonymous['A'],
				__( 'Comments', 'easyq' )             => $comments,
				__( 'Comments by users', 'easyq' )    => $comments - $anonymous['C'],
				__( 'Comments by visitors without account', 'easyq' ) => $anonymous['C'],
			)
		);

		printf( '<h2 id="easyq-figures-users">%s</h2>', esc_html__( 'Users', 'easyq' ) );
		$this->render_table(
			'easyq-figures-users',
			array(
				__( 'Registered users', 'easyq' )  => $users['total_users'],
				__( 'Users with points', 'easyq' ) => Counts::get( Counts::USERS_WITH_POINTS ),
				__( 'Users who posted', 'easyq' )  => Site_Stats::users_who_posted(),
				__( 'Users who voted', 'easyq' )   => Site_Stats::users_who_voted(),
			)
		);

		printf( '<h2 id="easyq-figures-system">%s</h2>', esc_html__( 'System', 'easyq' ) );
		echo '<table class="widefat striped" aria-labelledby="easyq-figures-system"><tbody>';
		$this->render_row( __( 'easyQ version', 'easyq' ), \EasyQ\VERSION );
		$this->render_row( __( 'Database version', 'easyq' ), (string) Installer::installed_version() );
		$this->render_row( __( 'Size of the Q&A tables', 'easyq' ), (string) size_format( Site_Stats::tables_size(), 1 ) );
		echo '</tbody></table>';
		printf(
			'<p>%1$s <a href="%2$s">%3$s</a></p>',
			esc_html__( 'The PHP and database versions are in Site Health.', 'easyq' ),
			esc_url( admin_url( 'site-health.php?tab=debug' ) ),
			esc_html__( 'Open Site Health', 'easyq' )
		);

		printf(
			'<p><a class="button" href="%1$s">%2$s</a> <a class="button" href="%3$s">%4$s</a></p>',
			esc_url( add_query_arg( 'page', 'easyq-settings', admin_url( 'admin.php' ) ) ),
			esc_html__( 'Settings', 'easyq' ),
			esc_url( add_query_arg( 'page', Tools_Screen::PAGE, admin_url( 'admin.php' ) ) ),
			esc_html__( 'Tools', 'easyq' )
		);
		echo '</div>';
	}

	/**
	 * Renders the widget: the number of posts and the posts waiting for moderators.
	 *
	 * @since 0.1.0
	 */
	public function render_widget(): void {
		$questions = Counts::get( Counts::QUESTIONS );
		$answers   = Counts::get( Counts::ANSWERS );
		$comments  = Counts::get( Counts::COMMENTS );

		echo '<ul>';
		/* translators: %s: number of questions. */
		$this->render_count( _n( '%s question', '%s questions', $questions, 'easyq' ), $questions );
		/* translators: %s: number of answers. */
		$this->render_count( _n( '%s answer', '%s answers', $answers, 'easyq' ), $answers );
		/* translators: %s: number of comments. */
		$this->render_count( _n( '%s comment', '%s comments', $comments, 'easyq' ), $comments );
		echo '</ul>';
		$this->render_queues();
	}

	/**
	 * Renders the posts waiting for moderators, with links to them.
	 *
	 * @since 0.1.0
	 */
	private function render_queues(): void {
		if ( ! current_user_can( Capabilities::MODERATE ) ) {
			return;
		}

		$pending = Counts::get( Counts::QUEUED );
		$flagged = Counts::get( Counts::FLAGGED );
		if ( 0 === $pending && 0 === $flagged ) {
			printf( '<p>%s</p>', esc_html__( 'No posts are waiting for moderators.', 'easyq' ) );

			return;
		}

		echo '<ul>';
		if ( $pending > 0 ) {
			printf(
				'<li><a href="%1$s">%2$s</a></li>',
				esc_url( Posts_Screen::url( array( 'view' => 'pending' ) ) ),
				/* translators: %s: number of posts. */
				esc_html( sprintf( _n( '%s post awaiting approval', '%s posts awaiting approval', $pending, 'easyq' ), number_format_i18n( $pending ) ) )
			);
		}
		if ( $flagged > 0 ) {
			printf(
				'<li><a href="%1$s">%2$s</a></li>',
				esc_url( Posts_Screen::url( array( 'view' => 'flagged' ) ) ),
				/* translators: %s: number of posts. */
				esc_html( sprintf( _n( '%s flagged post', '%s flagged posts', $flagged, 'easyq' ), number_format_i18n( $flagged ) ) )
			);
		}
		echo '</ul>';
	}

	/**
	 * Renders a table of figures.
	 *
	 * @since 0.1.0
	 *
	 * @param string             $heading ID of the heading that names the table.
	 * @param array<string, int> $figures Numbers keyed by their labels.
	 */
	private function render_table( string $heading, array $figures ): void {
		printf( '<table class="widefat striped" aria-labelledby="%s"><tbody>', esc_attr( $heading ) );
		foreach ( $figures as $label => $number ) {
			$this->render_row( $label, number_format_i18n( $number ) );
		}
		echo '</tbody></table>';
	}

	/**
	 * Renders a row of a table.
	 *
	 * @since 0.1.0
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 */
	private function render_row( string $label, string $value ): void {
		printf( '<tr><th scope="row">%1$s</th><td>%2$s</td></tr>', esc_html( $label ), esc_html( $value ) );
	}

	/**
	 * Renders a count of the widget.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text  Text with a placeholder for the number.
	 * @param int    $count Number.
	 */
	private function render_count( string $text, int $count ): void {
		printf( '<li>%s</li>', esc_html( sprintf( $text, number_format_i18n( $count ) ) ) );
	}
}
