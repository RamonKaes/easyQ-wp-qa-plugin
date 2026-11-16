<?php
/**
 * Tests that what the REST API refuses is shown nowhere else.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Blocks\Block_Context;
use EasyQ\Blocks\Lists;
use EasyQ\Frontend\Page_Meta;
use EasyQ\Frontend\Route;
use EasyQ\Frontend\Routes;
use EasyQ\Frontend\Templates;
use EasyQ\Frontend\URLs;
use EasyQ\Posts\Post;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Users\Permits;
use WP_Block;
use WP_Block_Type_Registry;

/**
 * Hides questions and a profile from visitors as the REST API does, then opens every page of
 * the router in a classic theme and renders every block of the plugin from the registry with
 * them, and expects none of their text anywhere.
 *
 * @coversNothing
 */
final class Visibility_Test extends Test_Case {

	/**
	 * Marker in every text a visitor must not see.
	 *
	 * @var string
	 */
	private const SECRET = 'Zebracorn';

	/**
	 * Questions and the user the visitor must not see, and a visible question.
	 *
	 * @var array{hidden: Post, queued: Post, visible: Post, duplicate: Post, user: int, author: string}
	 */
	private array $fixture;

	public function set_up(): void {
		parent::set_up();
		switch_theme( 'twentytwentyone' );

		$author = $this->user();
		$this->take_part( (int) $author->user_id );
		$hidden = $this->ask( $author, self::SECRET . ' hidden title', self::SECRET . ' hidden text', array( 'widgets' ) );
		$this->answer( $this->user(), $hidden, self::SECRET . ' answer of a hidden question' );
		Services::post_service()->set_question_status( Actor::system(), $hidden, Post::STATUS_HIDDEN );
		$queued    = $this->ask( $author, self::SECRET . ' queued title', self::SECRET . ' queued text', array( 'widgets' ), queued: true );
		$visible   = $this->ask( $author, 'A visible question', 'Visible text.' );
		$duplicate = $this->ask( $author, 'A duplicate question', 'Asked before.' );
		Services::post_service()->close_as_duplicate( Actor::system(), $duplicate, $hidden->id );

		$user = self::factory()->user->create(
			array(
				'display_name' => self::SECRET . ' Person',
				'description'  => self::SECRET . ' biography',
			)
		);

		$this->fixture = array(
			'hidden'    => $this->post( $hidden->id ),
			'queued'    => $this->post( $queued->id ),
			'visible'   => $this->post( $visible->id ),
			'duplicate' => $this->post( $duplicate->id ),
			'user'      => $user,
			'author'    => get_userdata( (int) $author->user_id )->user_nicename,
		);
		wp_set_current_user( 0 );
	}

	/**
	 * Returns every page of the router, and the pages of the hidden questions and the user.
	 *
	 * @return array<string, string>
	 */
	private function pages(): array {
		$pages = array( 'home' => Routes::url( 'home' ) );
		foreach ( array_keys( Routes::SIMPLE ) as $route ) {
			$pages[ $route ] = Routes::url( $route );
		}
		$pages['search'] = Routes::url( 'search', null, null, array( 'q' => 'hidden queued title' ) );
		$pages['tag']    = Routes::url( 'tag', 'widgets' );
		$pages['user']   = Routes::url( 'user', $this->fixture['author'] );
		foreach ( Routes::USER_SECTIONS as $section ) {
			$pages[ 'user-' . $section ] = Routes::url( 'user', $this->fixture['author'], $section );
		}
		foreach ( array( 'hidden', 'queued', 'visible', 'duplicate' ) as $name ) {
			$pages[ 'question ' . $name ] = URLs::question( $this->fixture[ $name ] );
		}
		$nicename                   = get_userdata( $this->fixture['user'] )->user_nicename;
		$pages['user not shown']    = Routes::url( 'user', $nicename );
		$pages['message not shown'] = Routes::url( 'message', $nicename );

		return $pages;
	}

	/**
	 * Opens an address in a classic theme and returns all it prints: head, title, robots, page.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	private function open( string $url ): string {
		Lists::reset();
		$this->go_to( $url );
		$router = Block_Context::router();
		ob_start();
		$template = ( new Templates( $router ) )->template_include( 'index.php' );
		( new Page_Meta( $router ) )->head();
		$head = (string) ob_get_clean();
		$page = 'not_found' === $router->current()?->name || str_ends_with( $template, '404.php' ) ? '' : Templates::classic_html();
		remove_all_filters( 'render_block_easyq/page-title' );
		remove_all_filters( 'render_block_easyq/post-title' );

		return implode( "\n", array( wp_get_document_title(), (string) ( $GLOBALS['post']->post_title ?? '' ), $head, $page ) );
	}

	/**
	 * Renders every block of the plugin with the hidden posts and the user that is not shown,
	 * as attributes and as context, with title, content and bound paragraphs inside.
	 *
	 * @return array<string, string>
	 */
	private function blocks(): array {
		$inner = array(
			'<!-- wp:easyq/post-title /-->',
			'<!-- wp:easyq/post-content /-->',
			'<!-- wp:easyq/post-excerpt /-->',
			'<!-- wp:easyq/post-status /-->',
			'<!-- wp:easyq/user-name /-->',
			'<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"easyq/post","args":{"key":"title"}}}}} --><p></p><!-- /wp:paragraph -->',
			'<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"easyq/user","args":{"key":"name"}}}}} --><p></p><!-- /wp:paragraph -->',
			'<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"easyq/user","args":{"key":"description"}}}}} --><p></p><!-- /wp:paragraph -->',
		);
		$inner = parse_blocks( implode( '', $inner ) );
		$html  = array();

		foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
			if ( ! str_starts_with( $name, 'easyq/' ) ) {
				continue;
			}
			foreach ( array( 'hidden', 'queued', 'duplicate' ) as $post ) {
				$id     = $this->fixture[ $post ]->id;
				$parsed = array(
					'blockName'    => $name,
					'attrs'        => array(
						'questionId' => $id,
						'userId'     => $this->fixture['user'],
					),
					'innerBlocks'  => $inner,
					'innerHTML'    => '',
					'innerContent' => array_fill( 0, count( $inner ), null ),
				);
				Lists::reset();
				$html[ "$name $post" ] = ( new WP_Block(
					$parsed,
					array(
						'easyq/postId' => $id,
						'easyq/userId' => $this->fixture['user'],
					)
				) )->render();
			}
		}
		$this->assertArrayHasKey( 'easyq/question hidden', $html );

		return $html;
	}

	/**
	 * Returns where the secret appears.
	 *
	 * @param array<string, string> $html Printed text by source.
	 * @return string[]
	 */
	private static function leaks( array $html ): array {
		return array_keys( array_filter( $html, static fn ( string $text ): bool => str_contains( $text, self::SECRET ) ) );
	}

	public function test_hidden_questions_and_profiles_are_shown_nowhere(): void {
		$html = array();
		foreach ( $this->pages() as $label => $url ) {
			$html[ 'page ' . $label ] = $this->open( $url );
		}
		$html += $this->blocks();

		$this->assertSame( '', do_blocks( '<!-- wp:easyq/user {"userId":' . $this->fixture['user'] . '} --><!-- wp:easyq/user-name /--><!-- /wp:easyq/user -->' ), 'A user who is not shown is not rendered at all.' );

		$this->assertSame( array(), self::leaks( $html ) );

		// The pages of the hidden questions are not found, as in the REST API and core.
		foreach ( array( 'hidden', 'queued' ) as $name ) {
			$this->go_to( URLs::question( $this->fixture[ $name ] ) );
			$this->assertSame( 'not_found', Block_Context::router()->current()?->name, $name );
		}
	}

	public function test_the_author_and_moderators_still_see_them(): void {
		wp_set_current_user( (int) $this->fixture['hidden']->user_id );
		$this->assertStringContainsString( self::SECRET . ' queued title', $this->open( URLs::question( $this->fixture['queued'] ) ) );

		wp_set_current_user( $this->administrator() );
		$this->assertStringContainsString( self::SECRET . ' hidden title', $this->open( URLs::question( $this->fixture['hidden'] ) ) );
		$this->assertStringContainsString( self::SECRET . ' hidden title', $this->open( URLs::question( $this->fixture['duplicate'] ) ), 'The duplicate names its original to those who may see it.' );
		$this->assertStringContainsString( self::SECRET . ' biography', do_blocks( '<!-- wp:easyq/user {"userId":' . $this->fixture['user'] . '} --><!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"easyq/user","args":{"key":"description"}}}}} --><p></p><!-- /wp:paragraph --><!-- /wp:easyq/user -->' ) );

		wp_set_current_user( 0 );
		$this->take_part( $this->fixture['user'] );
		$this->assertStringContainsString( self::SECRET . ' Person', do_blocks( '<!-- wp:easyq/user {"userId":' . $this->fixture['user'] . '} --><!-- wp:easyq/user-name /--><!-- /wp:easyq/user -->' ), 'A user who took part is shown to visitors.' );
	}

	public function test_without_the_permission_to_view_questions_nothing_of_them_is_shown(): void {
		Options::update( 'permissions', array( 'permit_view_q_page' => Permits::USERS ) );
		$visible = $this->fixture['visible'];
		$page    = $this->open( URLs::question( $visible ) );

		$this->assertSame( 'question', Block_Context::router()->current()?->name );
		$this->assertStringNotContainsString( 'A visible question', $page );
		$this->assertStringNotContainsString( 'Visible text.', $page );
		$this->assertStringContainsString( 'Log in', $page, 'The question block gives the reason.' );
		$this->assertContains( 'noindex', array_keys( array_filter( ( new Page_Meta( Block_Context::router() ) )->robots( array() ) ) ) );

		$block = do_blocks( '<!-- wp:easyq/question {"questionId":' . $visible->id . '} --><!-- wp:easyq/post-title /--><!-- /wp:easyq/question -->' );
		$this->assertStringNotContainsString( 'A visible question', $block );

		// Every block with the question as context, as attribute and as the question of the page.
		Block_Context::router()->set_current( new Route( 'home' ) );
		foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
			if ( ! str_starts_with( $name, 'easyq/' ) ) {
				continue;
			}
			Lists::reset();
			$html = ( new WP_Block(
				array(
					'blockName'    => $name,
					'attrs'        => array( 'questionId' => $visible->id ),
					'innerBlocks'  => parse_blocks( '<!-- wp:easyq/post-title /--><!-- wp:easyq/post-content /-->' ),
					'innerHTML'    => '',
					'innerContent' => array( null, null ),
				),
				array( 'easyq/postId' => $visible->id )
			) )->render();
			$this->assertStringNotContainsString( 'Visible text.', $html, $name );
		}

		wp_set_current_user( (int) $this->user()->user_id );
		$this->assertStringContainsString( 'A visible question', $this->open( URLs::question( $visible ) ) );
		Block_Context::router()->set_current( new Route( 'question', $visible ) );
	}
}
