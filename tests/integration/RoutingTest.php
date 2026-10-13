<?php
/**
 * Tests for Q&A routes.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Blocks\Block_Context;
use EasyQ\Frontend\Routes;
use EasyQ\Frontend\Urls;
use EasyQ\Services;
use EasyQ\Settings\Options;

/**
 * @covers \EasyQ\Frontend\Routes
 * @covers \EasyQ\Frontend\Router
 * @covers \EasyQ\Frontend\Route
 * @covers \EasyQ\Frontend\Urls
 */
final class RoutingTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		Block_Context::router()->add_rewrite_rules();
		flush_rewrite_rules( false );
	}

	public function test_builds_pretty_addresses(): void {
		$this->assertSame( home_url( '/questions/' ), Routes::url( 'home' ) );
		$this->assertSame( home_url( '/questions/tag/block%20themes/' ), Routes::url( 'tag', 'block themes' ) );
		$this->assertSame( home_url( '/questions/categories/a/b/' ), Routes::url( 'category', 'a/b' ) );
		$this->assertSame( home_url( '/questions/user/ada/wall/' ), Routes::url( 'user', 'ada', 'wall' ) );
		$this->assertSame( home_url( '/questions/ask/?follow=3' ), Routes::url( 'ask', null, null, array( 'follow' => 3 ) ) );
	}

	public function test_builds_query_addresses_without_pretty_permalinks(): void {
		$this->set_permalink_structure( '' );

		$this->assertSame( home_url( '/?easyq_route=tag&easyq_arg=php' ), Routes::url( 'tag', 'php' ) );
	}

	public function test_question_addresses_contain_a_shortened_title(): void {
		Options::update( 'viewing', array( 'q_urls_title_length' => 20 ) );
		$question = $this->ask( $this->user(), 'How do I configure the widget settings page?' );

		$this->assertSame( home_url( '/questions/' . $question->id . '/how-do-i-configure/' ), Urls::question( $question ) );
		$this->assertSame( 'question', Urls::slug( '!!!' ) );
	}

	public function test_uses_the_configured_base_slug(): void {
		Options::update( 'general', array( 'base_slug' => 'fragen' ) );

		$this->assertSame( home_url( '/fragen/' ), Routes::url( 'home' ) );
		$this->assertArrayHasKey( '^fragen/?$', Routes::rewrite_rules() );
	}

	public function test_resolves_routes(): void {
		$router   = Block_Context::router();
		$question = $this->ask( $this->user() );
		$category = Services::categories()->create( null, 'Software' );
		$this->assertIsInt( $category );
		$child = Services::categories()->create( $category, 'Plugins' );
		$this->assertIsInt( $child );
		$user = self::factory()->user->create_and_get( array( 'user_nicename' => 'ada' ) );

		$this->assertSame( $question->id, $router->resolve( 'question', (string) $question->id, null )->question()?->id );
		$this->assertSame( 'not_found', $router->resolve( 'question', '999999', null )->name );
		$this->assertSame( 'block themes', $router->resolve( 'tag', 'block%20themes', null )->tag() );
		$this->assertSame( $child, $router->resolve( 'category', 'software/plugins', null )->category()?->id );
		$this->assertSame( 'not_found', $router->resolve( 'category', 'plugins', null )->name );
		$this->assertSame( $user->ID, $router->resolve( 'user', 'ada', 'wall' )->user()?->ID );
		$this->assertNull( $router->resolve( 'user', 'ada', 'bogus' )->section );
		$this->assertSame( 'not_found', $router->resolve( 'unknown', null, null )->name );
		$this->assertSame( 'easyq-ask', $router->resolve( 'ask', null, null )->template_slug() );
	}

	public function test_requests_are_routed_without_404(): void {
		$question = $this->ask( $this->user() );

		$this->go_to( Urls::question( $question ) );

		$this->assertSame( 'question', Block_Context::router()->current()?->name );
		$this->assertFalse( is_404() );
		$this->assertSame( array(), $GLOBALS['wp_query']->posts );
	}

	public function test_unknown_addresses_below_the_base_are_not_found(): void {
		$this->go_to( home_url( '/questions/999999/missing/' ) );

		$this->assertSame( 'not_found', Block_Context::router()->current()?->name );
	}

	public function test_other_requests_are_left_alone(): void {
		$post_id = self::factory()->post->create();

		$this->go_to( get_permalink( $post_id ) );

		$this->assertNull( Block_Context::router()->current() );
		$this->assertTrue( is_single() );
	}
}
