<?php
/**
 * Tests for the suggested privacy policy text.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Notices\Notices;
use EasyQ\Privacy\Policy_Text;
use EasyQ\Settings\Options;
use EasyQ\Users\Permits;
use EasyQ\Users\Visitor_Cookie;

/**
 * @covers \EasyQ\Privacy\Policy_Text
 */
final class Policy_Text_Test extends Test_Case {

	public function test_adds_the_text_to_the_privacy_policy_guide_on_admin_init(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';

		$this->assertSame( 10, has_action( 'admin_init', array( Policy_Text::class, 'add' ) ) );

		set_current_screen( 'dashboard' );
		remove_all_actions( 'admin_init' );
		add_action( 'admin_init', array( Policy_Text::class, 'add' ) );
		do_action( 'admin_init' );

		$sections = array_column( \WP_Privacy_Policy_Content::get_suggested_policy_text(), 'policy_text', 'plugin_name' );
		$this->assertSame( Policy_Text::content(), $sections['easyQ Questions and Answers'] ?? null );
	}

	public function test_follows_the_markup_of_the_suggested_text_of_core(): void {
		$content = Policy_Text::content();

		$this->assertStringStartsWith( '<h2 class="wp-block-heading">', $content );
		$this->assertStringContainsString( '<strong class="privacy-policy-tutorial">Suggested text: </strong>', $content );
		$this->assertStringContainsString( '<p class="privacy-policy-tutorial">', $content );
		$this->assertSame( $content, wp_kses_post( $content ), 'The guide shows the text as it is.' );

		$document = new \DOMDocument();
		$this->assertTrue( $document->loadXML( '<div>' . $content . '</div>' ), 'Every element is closed.' );
		$headings = $document->getElementsByTagName( 'h2' )->length + $document->getElementsByTagName( 'h3' )->length;
		$this->assertSame( 4, $headings );
		$this->assertSame( $headings, substr_count( $content, 'Suggested text:' ), 'Each topic marks the text to copy, as core does.' );
	}

	public function test_names_the_cookies_and_the_retention_period_of_the_settings(): void {
		$this->assertStringContainsString( 'after 30 days', Policy_Text::content() );
		$this->assertStringContainsString( 'ten years', Policy_Text::content() );
		$this->assertStringNotContainsString( 'shortened by its last part', Policy_Text::content() );

		Options::update(
			'spam',
			array(
				'ip_retention_days' => 1,
				'ip_anonymize'      => true,
			)
		);
		$this->assertStringContainsString( 'after 1 day;', Policy_Text::content() );
		$this->assertStringContainsString( 'shortened by its last part', Policy_Text::content() );

		// The lifetimes in the text are those of the cookies.
		foreach ( array( Notices::class, Visitor_Cookie::class ) as $class ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file in a test.
			$source = (string) file_get_contents( (string) ( new \ReflectionClass( $class ) )->getFileName() );
			$this->assertMatchesRegularExpression( Notices::class === $class ? "/Cookies::set\( self::COOKIE, '1', 10 \* YEAR_IN_SECONDS \)/" : '/Cookies::set\( self::NAME, \(string\) \$id, YEAR_IN_SECONDS \)/', $source );
		}
	}

	public function test_promises_only_what_the_code_does(): void {
		$text = Policy_Text::content();

		// The rate limits are removed by the daily run of IP_Retention, not after exactly one hour.
		$this->assertStringContainsString( 'by the daily clean-up after the hour they count for', $text );
		$this->assertStringNotContainsString( 'after one hour.', $text );
		// Page_Meta::count_view() keeps a hash of the address for an hour.
		$this->assertStringContainsString( 'a hash of your IP address is kept with the question for at most one hour', $text );
		$this->assertStringNotContainsString( 'without storing your IP address', $text );
	}

	public function test_describes_posting_without_an_account_only_when_it_is_allowed(): void {
		$this->assertStringNotContainsString( 'without an account', Policy_Text::content() );

		foreach ( array( 'permit_post_q', 'permit_post_a', 'permit_post_c' ) as $permit ) {
			Options::update( 'permissions', array_fill_keys( array( 'permit_post_q', 'permit_post_a', 'permit_post_c' ), Permits::USERS ) );
			Options::update( 'permissions', array( $permit => Permits::ALL ) );

			$content = Policy_Text::content();
			$this->assertStringContainsString( 'If you post without an account, we store the name', $content, $permit );
			$this->assertStringContainsString( 'lasts for one year', $content, $permit );
		}
	}

	public function test_describes_only_the_features_that_are_on(): void {
		$features = array(
			array( 'users', 'avatar_allow_upload', false, 'media library' ),
			array( 'users', 'allow_user_walls', true, 'wall of a profile' ),
			array( 'emails', 'feedback_enabled', true, 'feedback form' ),
			array( 'newsletter', 'mailing_enabled', false, 'newsletter' ),
		);

		foreach ( $features as [ $section, $key, $default, $phrase ] ) {
			$this->assertSame( $default, Options::get_bool( $section, $key ) );
			Options::update( $section, array( $key => true ) );
			$this->assertStringContainsString( $phrase, Policy_Text::content(), $key );
			Options::update( $section, array( $key => false ) );
			$this->assertStringNotContainsString( $phrase, Policy_Text::content(), $key );
		}

		$this->assertStringNotContainsString( 'accepted our terms', Policy_Text::content() );
		Options::update( 'users', array( 'show_register_terms' => true ) );
		$this->assertStringContainsString( 'we store the time at which you accepted our terms and the text you accepted', Policy_Text::content() );

		Options::update( 'users', array( 'allow_private_messages' => false ) );
		$this->assertStringNotContainsString( 'Private messages', Policy_Text::content() );
		Options::update( 'users', array( 'allow_private_messages' => true ) );
		$this->assertStringContainsString( 'stored until the sender and the recipient have deleted them', Policy_Text::content() );
		Options::update( 'users', array( 'show_message_history' => false ) );
		$this->assertStringContainsString( 'not stored on the site', Policy_Text::content() );
	}

	public function test_describes_what_erasing_does_with_the_setting_of_the_site(): void {
		$this->assertStringContainsString( 'your questions, answers and comments stay without your name', Policy_Text::content() );
		$this->assertStringContainsString( 'votes, flags and your profile', Policy_Text::content() );

		Options::update( 'general', array( 'erase_posts' => 'delete' ) );
		$this->assertStringContainsString( 'your questions, answers and comments are deleted; those others have replied to stay', Policy_Text::content() );
	}

	public function test_names_gravatar_while_the_site_shows_avatars(): void {
		// As core's suggested text for comments (F-228): the pictures come from Gravatar.
		update_option( 'show_avatars', '1' );
		$this->assertStringContainsString( 'Gravatar', Policy_Text::content() );
		$this->assertStringContainsString( 'https://automattic.com/privacy/', Policy_Text::content() );

		update_option( 'show_avatars', '0' );
		$this->assertStringNotContainsString( 'Gravatar', Policy_Text::content() );
	}

	public function test_the_readme_names_gravatar_with_the_promise_of_no_external_requests(): void {
		$readme = (string) file_get_contents( dirname( __DIR__, 2 ) . '/readme.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file in a test.
		$this->assertStringContainsString( 'makes no requests to external services', $readme );
		$this->assertStringContainsString( 'Gravatar', $readme );
	}
}
