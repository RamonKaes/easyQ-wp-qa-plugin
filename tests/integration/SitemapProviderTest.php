<?php
/**
 * Tests for the sitemap provider.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Frontend\Sitemap_Provider;
use EasyQ\Frontend\Urls;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Users\Permits;

/**
 * @covers \EasyQ\Frontend\Sitemap_Provider
 */
final class SitemapProviderTest extends TestCase {

	public function test_is_registered_with_the_core_sitemaps(): void {
		$this->assertInstanceOf( Sitemap_Provider::class, wp_sitemaps_get_server()->registry->get_provider( 'easyq' ) );
	}

	public function test_lists_profiles_only_when_the_site_owner_allows_it(): void {
		$provider = new Sitemap_Provider();

		$this->assertSame( array( 'questions', 'tags', 'categories' ), array_keys( $provider->get_object_subtypes() ) );

		Options::update( 'viewing', array( 'sitemap_users' => true ) );
		$this->assertArrayHasKey( 'users', $provider->get_object_subtypes() );
	}

	public function test_lists_visible_questions_with_their_last_change(): void {
		$visible = $this->ask( $this->user(), 'Sitemap question' );
		$this->ask( $this->user(), 'Queued sitemap question', 'x', array(), null, true );

		$entries = ( new Sitemap_Provider() )->get_url_list( 1, 'questions' );

		$this->assertSame( array( Urls::question( $visible ) ), array_column( $entries, 'loc' ) );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $entries[0]['lastmod'] );
		$this->assertSame( 1, ( new Sitemap_Provider() )->get_max_num_pages( 'questions' ) );
	}

	public function test_lists_tags_categories_and_profiles(): void {
		Options::update( 'viewing', array( 'sitemap_users' => true ) );
		$author   = $this->user();
		$category = Services::categories()->find( (int) Services::categories()->create( null, 'Cooking' ) );
		$this->assertNotNull( $category );
		$this->ask( $author, 'Tagged sitemap question', 'x', array( 'pasta' ), $category->id );
		$user = get_userdata( (int) $author->user_id );
		$this->assertInstanceOf( \WP_User::class, $user );

		$provider = new Sitemap_Provider();

		$this->assertSame( array( Urls::tag( 'pasta' ) ), array_column( $provider->get_url_list( 1, 'tags' ), 'loc' ) );
		$this->assertSame( array( Urls::category( $category ) ), array_column( $provider->get_url_list( 1, 'categories' ), 'loc' ) );
		$this->assertContains( Urls::user( $user ), array_column( $provider->get_url_list( 1, 'users' ), 'loc' ) );
	}

	public function test_lists_nothing_when_visitors_may_not_view_the_qa_area(): void {
		$this->ask( $this->user() );
		Options::update( 'permissions', array( 'permit_view_q_page' => Permits::USERS ) );

		$provider = new Sitemap_Provider();

		$this->assertSame( array(), $provider->get_object_subtypes() );
		$this->assertSame( array(), $provider->get_url_list( 1, 'questions' ) );
		$this->assertSame( 0, $provider->get_max_num_pages( 'questions' ) );
	}
}
