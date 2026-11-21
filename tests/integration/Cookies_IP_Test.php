<?php
/**
 * Tests the cookies and the client address as core handles the comment cookies and addresses.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

require_once __DIR__ . '/seams/cookies.php';

use EasyQ\Actor;
use EasyQ\Blocks\Render\Forms;
use EasyQ\Limits\Limits;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Users\Visitor_Cookie;
use EasyQ\Util\Cookies;
use EasyQ\Util\IP;

/**
 * @covers \EasyQ\Util\Cookies
 * @covers \EasyQ\Users\Visitor_Cookie
 * @covers \EasyQ\Util\IP
 * @covers \EasyQ\Limits\Limits
 */
final class Cookies_IP_Test extends REST_Test_Case {

	/**
	 * Host of the request before the test.
	 *
	 * @var string|null
	 */
	private ?string $host = null;

	public function set_up(): void {
		parent::set_up();
		$this->host                    = isset( $_SERVER['HTTP_HOST'] ) && is_string( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : null;
		$GLOBALS['easyq_test_cookies'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		unset( $_COOKIE[ Cookies::name( Visitor_Cookie::NAME ) ] );
	}

	public function tear_down(): void {
		unset( $_COOKIE[ Cookies::name( Visitor_Cookie::NAME ) ], $_COOKIE[ Cookies::name( 'easyq_noticed' ) ] );
		if ( null !== $this->host ) {
			$_SERVER['HTTP_HOST'] = $this->host;
		} else {
			unset( $_SERVER['HTTP_HOST'] );
		}
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		parent::tear_down();
	}

	/**
	 * Returns the cookies set since the start of the test.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function cookies(): array {
		return $GLOBALS['easyq_test_cookies'];
	}

	public function test_cookies_are_secure_by_the_scheme_of_the_home_address(): void {
		add_filter( 'home_url', static fn ( string $url ): string => set_url_scheme( $url, 'https' ) );
		Visitor_Cookie::ensure( new Actor() );
		$this->assertTrue( self::cookies()[0]['secure'] );
		$this->assertTrue( self::cookies()[0]['httponly'] );
		// Sent along with links from other sites, not with their requests in the background (F-231).
		$this->assertSame( 'Lax', self::cookies()[0]['samesite'] );
		$this->assertSame( COOKIEPATH, self::cookies()[0]['path'] );

		// As `wp_set_comment_cookies()`: the scheme of `home_url()`, which follows the request over HTTPS too.
		remove_all_filters( 'home_url' );
		Cookies::set( 'easyq_noticed', '1', 60 );
		$this->assertSame( 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ), end( $GLOBALS['easyq_test_cookies'] )['secure'] );
	}

	public function test_the_visitor_cookie_is_renewed_with_every_post(): void {
		$id = Visitor_Cookie::ensure( new Actor() );
		$this->assertSame( $id, Visitor_Cookie::ensure( new Actor() ) );
		$this->assertCount( 2, self::cookies() );
		$this->assertSame( (string) $id, self::cookies()[1]['value'] );
		$this->assertGreaterThan( time() + YEAR_IN_SECONDS - 60, self::cookies()[1]['expires'] );
	}

	public function test_each_site_of_a_network_has_a_cookie_of_its_own(): void {
		if ( ! is_multisite() ) {
			$this->assertSame( Visitor_Cookie::NAME, Cookies::name( Visitor_Cookie::NAME ) );
			return;
		}

		$first = Cookies::name( Visitor_Cookie::NAME );
		$site  = self::factory()->blog->create();
		switch_to_blog( $site );
		$second = Cookies::name( Visitor_Cookie::NAME );
		restore_current_blog();

		$this->assertNotSame( $first, $second );
	}

	public function test_visitors_are_remembered_only_with_consent_when_the_site_asks(): void {
		update_option( 'show_comments_cookies_opt_in', '1' );
		Options::update( 'permissions', array( 'permit_post_q' => \EasyQ\Users\Permits::ALL ) );

		$this->assertNull( Visitor_Cookie::ensure( new Actor(), false ) );
		$this->assertSame( array(), self::cookies() );

		$params   = array(
			'title'   => 'A question without an account and without a cookie',
			'content' => 'Text of the question.',
		);
		$response = $this->request( 'POST', '/questions', $params );
		$this->assertSame( 201, $response->get_status() );
		$this->assertNull( $this->post( (int) $this->data( $response )['id'] )->cookie_id );
		$this->assertSame( array(), self::cookies() );

		$response = $this->request(
			'POST',
			'/questions',
			array(
				'remember' => true,
				'title'    => 'A question without an account that remembers me',
			) + $params
		);
		$this->assertNotNull( $this->post( (int) $this->data( $response )['id'] )->cookie_id );
		$this->assertCount( 1, self::cookies() );

		$this->assertStringContainsString( 'name="remember"', do_blocks( '<!-- wp:easyq/ask-form /-->' ) );
		update_option( 'show_comments_cookies_opt_in', '' );
		\EasyQ\Blocks\Render::reset_ids();
		$this->assertStringNotContainsString( 'name="remember"', do_blocks( '<!-- wp:easyq/ask-form /-->' ), 'Without the setting no consent is asked, as in core.' );
	}

	public function test_the_login_link_uses_the_host_of_the_site(): void {
		$_SERVER['HTTP_HOST']   = 'attacker.example';
		$_SERVER['REQUEST_URI'] = '/questions/';

		$html = Forms::permission_message( 'login' );

		$this->assertStringNotContainsString( 'attacker.example', $html );
		$this->assertStringContainsString( rawurlencode( (string) preg_replace( '~^(https?://[^/]+).*$~', '$1', home_url() ) . '/questions/' ), $html );
	}

	public function test_mapped_and_listed_addresses_are_read_as_the_client(): void {
		$_SERVER['REMOTE_ADDR'] = '::ffff:203.0.113.7';
		$this->assertSame( '203.0.113.7', IP::current() );
		$this->assertTrue( IP::is_blocked( '::ffff:203.0.113.7', '203.0.113.*' ) );

		// The client writes the start of `X-Forwarded-For` itself; the trusted proxy appends the address it saw.
		$header = '198.51.100.4, 203.0.113.9';
		add_filter(
			'easyq_client_ip',
			static function () use ( &$header ): string {
				return $header;
			}
		);
		$this->assertSame( '203.0.113.9', IP::current() );
		$header = '203.0.113.9, not-an-address';
		$this->assertNull( IP::current(), 'An invalid last address does not fall back to one the client wrote.' );
	}

	public function test_requests_without_an_address_share_a_limit(): void {
		Options::update( 'spam', array( 'max_rate_ip_qs' => 2 ) );
		$limits = Services::limits();
		$actor  = new Actor( null, null, null );

		$this->assertSame( 2, $limits->remaining( Limits::QUESTIONS, $actor ) );
		$limits->increment( Limits::QUESTIONS, $actor );
		$limits->increment( Limits::QUESTIONS, new Actor( null, null, null ) );
		$this->assertSame( 0, $limits->remaining( Limits::QUESTIONS, $actor ) );
	}

	public function test_a_visitor_cookie_with_more_than_digits_is_no_cookie(): void {
		global $wpdb;
		$wpdb->insert(
			\EasyQ\Database\Schema::table( 'cookies' ),
			array(
				'id'      => 12,
				'created' => current_time( 'mysql', true ),
			)
		);
		$name = Cookies::name( Visitor_Cookie::NAME );

		$_COOKIE[ $name ] = '12';
		$this->assertSame( 12, Visitor_Cookie::current() );

		// Read as a number by the query, '12abc' would be cookie 12 (F-231).
		$_COOKIE[ $name ] = '12abc';
		$this->assertNull( Visitor_Cookie::current() );

		// Digits without a row are no cookie either: the row went with the retention or never was.
		$_COOKIE[ $name ] = '13';
		$this->assertNull( Visitor_Cookie::current() );
		unset( $_COOKIE[ $name ] );
	}

	public function test_only_a_valid_ipv4_address_mapped_into_ipv6_is_taken_as_ipv4(): void {
		$this->assertSame( '203.0.113.7', IP::normalize( '::ffff:203.0.113.7' ) );
		$this->assertSame( '::ffff:999.0.113.7', IP::normalize( '::ffff:999.0.113.7' ) );
	}

	public function test_users_who_log_in_on_a_q_and_a_page_are_remembered_there_only(): void {
		$name = Cookies::name( 'easyq_noticed' );
		unset( $_COOKIE[ $name ] );
		wp_set_current_user( (int) $this->user()->user_id );
		\EasyQ\Blocks\Block_Context::router()->set_current( new \EasyQ\Frontend\Route( 'questions' ) );

		// Without a notice for visitors, as by default, there is nothing to remember.
		Options::update( 'users', array( 'show_notice_visitor' => false ) );
		\EasyQ\Notices\Notices::remember_logged_in();
		$this->assertArrayNotHasKey( $name, $_COOKIE, 'No notice for visitors.' );
		Options::update( 'users', array( 'show_notice_visitor' => true ) );
		\EasyQ\Blocks\Block_Context::router()->set_current( null );

		\EasyQ\Notices\Notices::remember_logged_in();
		$this->assertArrayNotHasKey( $name, $_COOKIE, 'Not on other pages of the site.' );

		\EasyQ\Blocks\Block_Context::router()->set_current( new \EasyQ\Frontend\Route( 'questions' ) );
		\EasyQ\Notices\Notices::remember_logged_in();
		\EasyQ\Blocks\Block_Context::router()->set_current( null );
		$this->assertSame( '1', Cookies::get( 'easyq_noticed' ) );
	}
}
