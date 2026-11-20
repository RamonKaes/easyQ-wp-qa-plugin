<?php
/**
 * Tests that the admin lists query the same with more rows.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Admin\Posts_Screen;
use EasyQ\Admin\Users_Screen;
use EasyQ\Services;

/**
 * Moderators may show up to 999 rows per page (Screen Options); a query per row then makes
 * the screen slow.
 *
 * @coversNothing
 */
final class Admin_Query_Growth_Test extends Test_Case {

	/**
	 * Queries of the last render.
	 *
	 * @var string[]
	 */
	private array $log = array();

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-users-list-table.php';
		wp_set_current_user( $this->administrator() );
	}

	/**
	 * Remembers a query.
	 *
	 * @param string $query SQL.
	 * @return string
	 */
	public function remember( string $query ): string {
		$this->log[] = $query;

		return $query;
	}

	/**
	 * Runs a render with empty caches and returns its statements, with their values replaced.
	 *
	 * @param callable(): void $render Render.
	 * @return array<string, int> Number of queries by statement.
	 */
	private function queries( callable $render ): array {
		Services::reset();
		wp_cache_flush();
		$this->log = array();
		add_filter( 'query', array( $this, 'remember' ) );
		ob_start();
		$render();
		ob_end_clean();
		remove_filter( 'query', array( $this, 'remember' ) );

		$statements = array();
		foreach ( $this->log as $query ) {
			$statement                = (string) preg_replace( array( "/'[^']*'/", '/\b\d+\b/', '/\s+/', '/(\?,\s*)+\?/' ), array( '?', '?', ' ', '?' ), $query );
			$statements[ $statement ] = ( $statements[ $statement ] ?? 0 ) + 1;
		}

		return $statements;
	}

	/**
	 * Checks that no statement runs more often with more rows.
	 *
	 * @param array<string, int> $short Statements with few rows.
	 * @param array<string, int> $long  Statements with many rows.
	 */
	private function assert_no_growth( array $short, array $long ): void {
		$grown = array();
		foreach ( $long as $statement => $count ) {
			if ( $count > ( $short[ $statement ] ?? 0 ) ) {
				$grown[] = ( $short[ $statement ] ?? 0 ) . ' -> ' . $count . ': ' . $statement;
			}
		}

		$this->assertSame( array(), $grown );
	}

	/**
	 * Adds posts of new authors: questions, answers on them and comments on the answers.
	 *
	 * @param int $count Number of each.
	 */
	private function add_posts( int $count ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			$question = $this->ask( $this->user(), "Question number $i of the list" );
			$answer   = $this->answer( $this->user(), $question, "Answer number $i of the list here." );
			Services::post_service()->create_comment( $this->user(), $question, $answer, "Comment number $i of the list." );
		}
	}

	public function test_the_posts_screen_queries_the_same_with_more_rows(): void {
		update_user_option( get_current_user_id(), Posts_Screen::PER_PAGE_OPTION, 200 );
		$render = static function (): void {
			set_current_screen( 'easyq_page_' . Posts_Screen::PAGE );
			( new Posts_Screen() )->render();
		};

		$this->add_posts( 2 );
		$short = $this->queries( $render );
		$this->add_posts( 12 );
		$long = $this->queries( $render );

		$this->assert_no_growth( $short, $long );
	}

	public function test_the_users_screen_queries_the_same_with_more_rows(): void {
		( new Users_Screen() )->register();
		$render = static function (): void {
			set_current_screen( 'users' );
			$GLOBALS['wp_list_table'] = _get_list_table( 'WP_Users_List_Table', array( 'screen' => 'users' ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- As wp-admin/users.php sets it.
			$GLOBALS['wp_list_table']->prepare_items();
			$GLOBALS['wp_list_table']->display();
		};

		for ( $i = 0; $i < 3; $i++ ) {
			$this->take_part( (int) $this->user()->user_id );
		}
		$short = $this->queries( $render );
		for ( $i = 0; $i < 20; $i++ ) {
			$this->take_part( (int) $this->user()->user_id );
		}
		$long = $this->queries( $render );

		$this->assert_no_growth( $short, $long );
	}
}
