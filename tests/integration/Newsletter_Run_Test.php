<?php
/**
 * Tests the sending of the newsletter when batches overlap, die or are cancelled.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Database\Installer;
use EasyQ\Newsletter\Newsletter;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Users\Site_Meta;

/**
 * @covers \EasyQ\Newsletter\Newsletter
 */
final class Newsletter_Run_Test extends Test_Case {

	/**
	 * Recipients of the newsletter emails, in order.
	 *
	 * @var string[]
	 */
	private array $sent = array();

	/**
	 * Called for each newsletter email before it is sent; may throw or return false.
	 *
	 * @var callable|null
	 */
	private $on_send = null;

	public function set_up(): void {
		parent::set_up();
		Options::update(
			'newsletter',
			array(
				'mailing_enabled'    => true,
				'mailing_subject'    => 'News of the month',
				'mailing_body'       => 'Here is what happened.',
				'mailing_per_minute' => 3,
			)
		);
		add_filter(
			'pre_wp_mail',
			function ( $result, array $mail ) {
				if ( 'News of the month' !== $mail['subject'] ) {
					return null === $this->on_send ? true : ( $this->on_send )( $mail );
				}
				$this->sent[] = (string) $mail['to'];
				return null === $this->on_send ? true : ( $this->on_send )( $mail );
			},
			10,
			2
		);
	}

	public function tear_down(): void {
		Newsletter::cancel();
		delete_option( Newsletter::RUN_OPTION . '_lock' );
		parent::tear_down();
	}

	/**
	 * Creates users who confirmed the newsletter.
	 *
	 * @param int $count Number of users.
	 * @return int[]
	 */
	private function subscribers( int $count ): array {
		$ids = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$ids[] = self::factory()->user->create();
			Site_Meta::update( end( $ids ), Newsletter::STATE_META, 'confirmed' );
		}

		return $ids;
	}

	public function test_cancelling_during_a_batch_stops_the_rest(): void {
		$this->subscribers( 3 );
		Services::newsletter()->start();
		$this->on_send = static function (): bool {
			Newsletter::cancel();
			return true;
		};

		Services::newsletter()->send_batch();

		$this->assertCount( 1, $this->sent );
		$this->assertFalse( get_option( Newsletter::RUN_OPTION ) );
		$this->assertFalse( wp_next_scheduled( Newsletter::CRON_HOOK ) );
	}

	public function test_a_batch_that_starts_during_another_sends_nothing(): void {
		$this->subscribers( 4 );
		Services::newsletter()->start();
		$nested        = null;
		$this->on_send = function () use ( &$nested ): bool {
			if ( null === $nested ) {
				$nested = 0;
				Services::newsletter()->start();
				$nested = Services::newsletter()->send_batch();
			}
			return true;
		};

		Services::newsletter()->send_batch();
		$this->on_send = null;
		Services::newsletter()->send_batch();

		$this->assertSame( 0, $nested );
		$this->assertCount( 4, $this->sent );
		$this->assertSame( $this->sent, array_values( array_unique( $this->sent ) ), 'Nobody gets it twice.' );
	}

	public function test_a_batch_that_dies_sends_nobody_twice_and_the_sending_goes_on(): void {
		$this->subscribers( 5 );
		Services::newsletter()->start();
		$this->on_send = function (): bool {
			if ( 2 === count( $this->sent ) ) {
				throw new \RuntimeException( 'The request ended.' );
			}
			return true;
		};

		// WP-Cron removes the event before it runs it.
		wp_clear_scheduled_hook( Newsletter::CRON_HOOK );
		try {
			Services::newsletter()->send_batch();
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}
		$this->assertNotFalse( wp_next_scheduled( Newsletter::CRON_HOOK ), 'The next batch is planned.' );

		$this->on_send = null;
		Services::newsletter()->send_batch();
		Services::newsletter()->send_batch();

		$this->assertSame( $this->sent, array_values( array_unique( $this->sent ) ) );
		$this->assertCount( 4, $this->sent, 'The third of the dying batch is lost, not sent twice.' );
	}

	public function test_a_stale_lock_does_not_stop_the_sending(): void {
		$this->subscribers( 1 );
		Services::newsletter()->start();
		add_option( Newsletter::RUN_OPTION . '_lock', (string) ( time() - HOUR_IN_SECONDS ), '', false );

		$this->assertSame( 1, Services::newsletter()->send_batch() );
	}

	public function test_counting_the_recipients_loads_them_at_once(): void {
		global $wpdb;
		$this->subscribers( 30 );
		wp_cache_flush();

		$before = $wpdb->num_queries;
		Services::newsletter()->start();

		$this->assertLessThan( 15, $wpdb->num_queries - $before );
		$this->assertSame( 30, Newsletter::progress()['total'] );
	}

	public function test_emails_are_written_in_the_language_of_the_recipient(): void {
		[ $user ] = $this->subscribers( 1 );
		update_user_meta( $user, 'locale', 'de_DE' );
		$locales = array();
		add_action(
			'change_locale',
			static function ( string $locale ) use ( &$locales ): void {
				$locales[] = $locale;
			}
		);

		Services::newsletter()->send_to( get_userdata( $user ) );
		$this->assertSame( array( 'de_DE' ), array_values( array_unique( array_diff( $locales, array( get_locale() ) ) ) ), 'The newsletter.' );
		$locales = array();
		// The test site has German; a locale it does not have is not switched to, as in core.
		Services::newsletter()->subscribe( get_userdata( self::factory()->user->create( array( 'locale' => 'de_DE' ) ) ) );
		$this->assertContains( 'de_DE', $locales, 'The confirmation.' );
	}

	public function test_a_failed_confirmation_can_be_asked_for_again(): void {
		$user          = get_userdata( self::factory()->user->create() );
		$this->on_send = static fn (): bool => false;

		$this->assertWPError( Services::newsletter()->subscribe( $user ) );
		$this->assertSame( '', Newsletter::state( $user->ID ) );

		$this->on_send = null;
		$this->assertTrue( Services::newsletter()->subscribe( $user ) );
		$this->assertSame( 'pending', Newsletter::state( $user->ID ) );
	}

	public function test_deactivation_ends_the_sending(): void {
		$this->subscribers( 1 );
		Services::newsletter()->start();

		Installer::deactivate();

		$this->assertFalse( Newsletter::progress()['running'] );
		$this->assertFalse( wp_next_scheduled( Newsletter::CRON_HOOK ) );

		if ( is_multisite() ) {
			Services::newsletter()->start();
			add_filter( 'wp_is_large_network', '__return_true' );
			Installer::deactivate( true );
			$this->assertFalse( Newsletter::progress()['running'], 'A large network cleans the current site at least.' );
		}
	}

	public function test_users_removed_from_a_site_get_no_newsletter(): void {
		[ $member, $removed ] = $this->subscribers( 2 );
		if ( ! is_multisite() ) {
			$this->assertSame( 2, count( array_unique( array( $member, $removed ) ) ) );
			return;
		}
		remove_user_from_blog( $removed, get_current_blog_id() );
		Services::newsletter()->start();
		Services::newsletter()->send_batch();

		$this->assertSame( array( get_userdata( $member )->user_email ), $this->sent );
	}
}
