<?php
/**
 * Tests for the removal of stored IP addresses.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Database\Installer;
use EasyQ\Database\Schema;
use EasyQ\Limits\Limits;
use EasyQ\Privacy\IP_Retention;
use EasyQ\Services;
use EasyQ\Settings\Fields;
use EasyQ\Settings\Options;
use EasyQ\Uninstaller;
use EasyQ\Util\IP;

/**
 * @covers \EasyQ\Privacy\IP_Retention
 * @covers \EasyQ\Posts\Post_Repository::clear_ips_before
 * @covers \EasyQ\Uninstaller
 */
final class IP_Retention_Test extends Test_Case {

	public function test_schedules_a_daily_event_once_like_core(): void {
		$this->assertSame( 10, has_action( 'init', array( IP_Retention::class, 'schedule' ) ) );
		$this->assertSame( 10, has_action( IP_Retention::CRON_HOOK, array( IP_Retention::class, 'run' ) ) );

		wp_clear_scheduled_hook( IP_Retention::CRON_HOOK );
		IP_Retention::schedule();
		IP_Retention::schedule();

		$this->assertSame( 'daily', wp_get_schedule( IP_Retention::CRON_HOOK ) );
		$events = 0;
		foreach ( _get_cron_array() as $hooks ) {
			$events += count( $hooks[ IP_Retention::CRON_HOOK ] ?? array() );
		}
		$this->assertSame( 1, $events );
	}

	public function test_deactivation_and_uninstall_clear_every_scheduled_event_of_the_plugin(): void {
		$hooks = array();
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/includes', \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file in a test.
			if ( preg_match_all( "/const CRON_HOOK = '([a-z_]+)'/", (string) file_get_contents( (string) $file ), $matches ) ) {
				$hooks = array_merge( $hooks, $matches[1] );
			}
		}
		$this->assertContains( IP_Retention::CRON_HOOK, $hooks );
		$this->assertGreaterThanOrEqual( 2, count( $hooks ) );

		$this->assertFalse( Uninstaller::site_opted_in() );
		$steps = array(
			'Deactivation'                            => array( Installer::class, 'deactivate' ),
			'Uninstalling a site that keeps its data' => array( Uninstaller::class, 'run' ),
		);
		foreach ( $steps as $step => $callback ) {
			foreach ( $hooks as $hook ) {
				wp_schedule_single_event( time() + HOUR_IN_SECONDS, $hook );
			}
			$callback();

			foreach ( $hooks as $hook ) {
				$this->assertFalse( wp_next_scheduled( $hook ), "$step leaves $hook scheduled." );
			}
		}
		$this->assertTrue( $this->table_exists( Schema::table( 'posts' ) ) );
	}

	public function test_the_retention_period_is_a_setting_of_at_least_one_day(): void {
		$field = Fields::section( 'spam' )['ip_retention_days'] ?? null;

		$this->assertIsArray( $field );
		$this->assertSame( 'int', $field['type'] );
		$this->assertSame( 1, $field['min'] );
		$this->assertSame( 30, Options::get_int( 'spam', 'ip_retention_days' ) );
	}

	public function test_removes_the_addresses_of_posts_after_the_retention_period(): void {
		Options::update( 'spam', array( 'ip_retention_days' => 7 ) );
		$author   = new Actor( $this->user()->user_id, null, '192.0.2.10' );
		$old      = $this->ask( $author, 'An old question with an address' );
		$edited   = $this->ask( $author, 'An old question edited lately' );
		$recent   = $this->ask( $author, 'A recent question with an address' );
		$boundary = $this->ask( $author, 'A question just inside the period' );
		Services::posts()->update( $edited->id, array( 'content' => 'Edited.' ), new Actor( $author->user_id, null, '198.51.100.20' ), \EasyQ\Posts\Post::UPDATE_CONTENT );
		$this->backdate( $old->id, 8, null );
		$this->backdate( $edited->id, 30, 0 );
		$this->backdate( $boundary->id, 6, null );

		// Read them once, so the object cache holds the addresses.
		foreach ( array( $old, $edited, $recent, $boundary ) as $post ) {
			$this->assertNotNull( Services::posts()->find( $post->id )?->created_ip );
		}

		IP_Retention::run();

		$this->assertNull( Services::posts()->find( $old->id )?->created_ip );
		$this->assertNull( $this->column( 'posts', 'created_ip', $old->id ) );
		$this->assertNull( $this->column( 'posts', 'created_ip', $edited->id ) );
		$this->assertSame( '198.51.100.20', IP::from_binary( $this->column( 'posts', 'last_ip', $edited->id ) ), 'The address of a recent change stays.' );
		$this->assertSame( '198.51.100.20', IP::from_binary( Services::posts()->find( $edited->id )?->last_ip ) );
		$this->assertSame( '192.0.2.10', IP::from_binary( $this->column( 'posts', 'created_ip', $recent->id ) ) );
		$this->assertSame( '192.0.2.10', IP::from_binary( $this->column( 'posts', 'created_ip', $boundary->id ) ) );

		$this->backdate( $edited->id, 30, 8 );
		IP_Retention::run();
		$this->assertNull( $this->column( 'posts', 'last_ip', $edited->id ) );
		$this->assertSame( 0, Services::posts()->clear_ips_before( gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ), 'Nothing is left to remove.' );
	}

	public function test_removes_the_addresses_of_visitor_cookies_after_the_retention_period(): void {
		global $wpdb;

		$table = Schema::table( 'cookies' );
		$ip    = IP::to_binary( '192.0.2.10' );
		$rows  = array(
			1 => array( 40, 40 ),
			2 => array( 40, 2 ),
			3 => array( 2, 2 ),
			4 => array( 40, null ),
		);
		foreach ( $rows as $id => [ $created, $written ] ) {
			$wpdb->insert(
				$table,
				array(
					'id'         => $id,
					'created'    => gmdate( 'Y-m-d H:i:s', time() - $created * DAY_IN_SECONDS ),
					'created_ip' => $ip,
					'written'    => null === $written ? null : gmdate( 'Y-m-d H:i:s', time() - $written * DAY_IN_SECONDS ),
					'written_ip' => null === $written ? null : $ip,
				)
			);
		}

		IP_Retention::run();

		$this->assertSame( array( null, null ), $this->cookie( 1 ) );
		$this->assertSame( array( null, '192.0.2.10' ), $this->cookie( 2 ) );
		$this->assertSame( array( '192.0.2.10', '192.0.2.10' ), $this->cookie( 3 ) );
		$this->assertSame( array( null, null ), $this->cookie( 4 ) );
	}

	public function test_removes_the_rate_limit_counters_of_past_hours(): void {
		global $wpdb;

		$limits = Services::limits();
		$actor  = new Actor( null, null, '192.0.2.10' );
		$limits->increment( Limits::FLAGS, $actor );
		$wpdb->insert(
			Schema::table( 'iplimits' ),
			array(
				'ip'     => IP::to_binary( '198.51.100.20' ),
				'action' => Limits::VOTES,
				'period' => Limits::period() - 1,
				'count'  => 3,
			)
		);
		$remaining = $limits->remaining( Limits::FLAGS, $actor );

		IP_Retention::run();

		$this->assertSame( array( '192.0.2.10' ), array_map( array( IP::class, 'from_binary' ), $wpdb->get_col( $wpdb->prepare( 'SELECT ip FROM %i', Schema::table( 'iplimits' ) ) ) ) );
		$this->assertSame( $remaining, $limits->remaining( Limits::FLAGS, $actor ), 'The counter of the current hour still counts.' );
	}

	/**
	 * Moves the creation and the last change of a post into the past.
	 *
	 * @param int      $id      Post ID.
	 * @param int      $created Days since the creation.
	 * @param int|null $updated Days since the last change, or null for none.
	 */
	private function backdate( int $id, int $created, ?int $updated ): void {
		global $wpdb;

		$wpdb->update(
			Schema::table( 'posts' ),
			array(
				'created' => gmdate( 'Y-m-d H:i:s', time() - $created * DAY_IN_SECONDS ),
				'updated' => null === $updated ? null : gmdate( 'Y-m-d H:i:s', time() - $updated * DAY_IN_SECONDS - MINUTE_IN_SECONDS ),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Reads a column of a row straight from the database.
	 *
	 * @param string $table  Table without prefix.
	 * @param string $column Column.
	 * @param int    $id     Row ID.
	 * @return string|null
	 */
	private function column( string $table, string $column, int $id ): ?string {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( 'SELECT %i FROM %i WHERE id = %d', $column, Schema::table( $table ), $id ) );
	}

	/**
	 * Returns the addresses of a visitor cookie.
	 *
	 * @param int $id Cookie ID.
	 * @return array{0: string|null, 1: string|null}
	 */
	private function cookie( int $id ): array {
		return array( IP::from_binary( $this->column( 'cookies', 'created_ip', $id ) ), IP::from_binary( $this->column( 'cookies', 'written_ip', $id ) ) );
	}
}
