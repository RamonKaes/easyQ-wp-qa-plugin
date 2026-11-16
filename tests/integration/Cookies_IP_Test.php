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

		add_filter( 'easyq_client_ip', static fn (): string => '198.51.100.4, 10.0.0.1' );
		$this->assertSame( '198.51.100.4', IP::current() );
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
}
