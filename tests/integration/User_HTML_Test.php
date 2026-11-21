<?php
/**
 * Tests that HTML users write is filtered as core filters comments and posts.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Blocks\Block_Context;
use EasyQ\Frontend\Route;
use EasyQ\Posts\Post;
use EasyQ\Services;

/**
 * Sends HTML with directives of the Interactivity API, styles and lists to every route of
 * the server that takes a format, as a subscriber, and reads it back through the REST API,
 * the question page and the bindings.
 *
 * @coversNothing
 */
final class User_HTML_Test extends REST_Test_Case {

	/**
	 * HTML a subscriber writes.
	 *
	 * @var string
	 */
	private const HTML = '<p data-wp-text="state.restUrl" data-wp-bind--hidden="state.x" data-wp-on--click="actions.vote" data-note="kept" style="color: red">Marked text</p><ul><li>Item</li></ul><strong>Strong</strong>';

	/**
	 * Returns the base type a route writes, from its address.
	 *
	 * @param string $route Route.
	 * @return string
	 */
	private static function type_of( string $route ): string {
		return match ( true ) {
			str_contains( $route, 'comments' ) => Post::COMMENT,
			str_contains( $route, 'answers' )  => Post::ANSWER,
			default                            => Post::QUESTION,
		};
	}

	/**
	 * Checks HTML of a post of a type.
	 *
	 * @param string $html  HTML.
	 * @param string $type  Base type.
	 * @param string $where Where it was read.
	 */
	private function assert_filtered( string $html, string $type, string $where ): void {
		$this->assertStringContainsString( 'Marked text', $html, $where );
		$this->assertStringNotContainsString( 'data-wp-', $html, $where );
		$this->assertStringContainsString( '<strong>Strong</strong>', $html, $where );
		if ( Post::COMMENT === $type ) {
			// The tags core allows in comments.
			$this->assertStringNotContainsString( '<ul>', $html, $where );
			$this->assertStringNotContainsString( 'style=', $html, $where );
			$this->assertStringNotContainsString( 'data-note', $html, $where );
		} else {
			// The post filter core applies to contributors.
			$this->assertStringContainsString( '<li>Item</li>', $html, $where );
			$this->assertStringContainsString( 'style="color: red"', $html, $where );
			$this->assertStringContainsString( 'data-note="kept"', $html, $where );
		}
	}

	public function test_every_route_filters_html_as_core(): void {
		$author = $this->login();
		$this->take_part( $author );
		$actor    = new \EasyQ\Actor( $author );
		$question = $this->ask( $actor );
		$answer   = $this->answer( $actor, $question );
		$comment  = $this->comment( $actor, $question, $answer );
		$ids      = array(
			Post::QUESTION => $question->id,
			Post::ANSWER   => $answer->id,
			Post::COMMENT  => $comment->id,
		);
		$written  = array();

		foreach ( rest_get_server()->get_routes( 'easyq/v1' ) as $route => $endpoints ) {
			foreach ( $endpoints as $endpoint ) {
				$methods = array_keys( array_filter( (array) $endpoint['methods'] ) );
				$method  = array_values( array_intersect( array( 'PUT', 'POST' ), $methods ) )[0] ?? null;
				if ( null === $method || ! isset( $endpoint['args']['format'] ) ) {
					continue;
				}
				$type = self::type_of( $route );
				// A route below a post writes a child of it; a route of a post edits it.
				$parent = str_ends_with( $route, 'comments' ) ? $ids[ Post::ANSWER ] : $ids[ Post::QUESTION ];
				$edits  = 1 === preg_match( '/\(\?P<id>[^)]+\)$/', $route );
				$id     = $edits ? $ids[ $type ] : $parent;
				$path   = str_replace( '/easyq/v1', '', (string) preg_replace( '/\(\?P<\w+>[^)]+\)/', (string) $id, $route ) );
				$params = array(
					'content' => self::HTML,
					'format'  => 'html',
				) + ( Post::QUESTION === $type && ! $edits ? array( 'title' => 'A question with formatted text' ) : array() );

				$response = $this->request( $method, $path, $params );
				$this->assertLessThan( 300, $response->get_status(), "$method $route: " . wp_json_encode( $response->get_data() ) );
				$data = $this->data( $response );
				$this->assert_filtered( (string) $data['content']['raw'], $type, "$method $route raw" );
				$this->assert_filtered( (string) $data['content']['rendered'], $type, "$method $route rendered" );
				$written[ "$method $route" ] = (int) $data['id'];
			}
		}

		$this->assertGreaterThanOrEqual( 6, count( $written ), 'Writing and editing questions, answers and comments.' );
	}

	public function test_stored_html_is_filtered_when_shown(): void {
		$author   = $this->user();
		$question = $this->ask( $author );
		$answer   = $this->answer( $author, $question );
		$comment  = $this->comment( $author, $question, $answer );
		global $wpdb;
		foreach ( array( $question, $answer, $comment ) as $post ) {
			// Stored before the filter, e.g. imported.
			$wpdb->update(
				\EasyQ\Database\Schema::table( 'posts' ),
				array(
					'content' => self::HTML,
					'format'  => 'html',
				),
				array( 'id' => $post->id )
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		wp_cache_flush();
		$posts = array(
			Post::QUESTION => $this->post( $question->id ),
			Post::ANSWER   => $this->post( $answer->id ),
			Post::COMMENT  => $this->post( $comment->id ),
		);

		foreach ( $posts as $type => $post ) {
			$this->assert_filtered( Services::content_renderer()->html( (string) $post->content, $post->format, $post->base_type() ), $type, "renderer $type" );
			$binding = ( new \WP_Block( parse_blocks( '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"easyq/post","args":{"key":"content"}}}}} --><p></p><!-- /wp:paragraph -->' )[0], array( 'easyq/postId' => $post->id ) ) )->render();
			$this->assert_filtered( $binding, $type, "binding $type" );
			$rest = $this->data( $this->request( 'GET', '/posts/' . $post->id, array( 'context' => 'view' ) ) );
			$this->assert_filtered( (string) $rest['content']['rendered'], $type, "REST $type" );
		}

		Block_Context::router()->set_current( new Route( 'question', $posts[ Post::QUESTION ] ) );
		$page = do_blocks( '<!-- wp:easyq/question --><!-- wp:easyq/post-content /--><!-- wp:easyq/answer-list --><!-- wp:easyq/answer-template --><!-- wp:easyq/post-content /--><!-- wp:easyq/comment-list --><!-- wp:easyq/comment-template --><!-- wp:easyq/post-content /--><!-- /wp:easyq/comment-template --><!-- /wp:easyq/comment-list --><!-- /wp:easyq/answer-template --><!-- /wp:easyq/answer-list --><!-- /wp:easyq/question -->' );
		$this->assertSame( 3, substr_count( $page, 'Marked text' ), 'The question, the answer and the comment are shown.' );
		$this->assertSame( 2, substr_count( $page, '<li>Item</li>' ), 'The comment has the tags of comments.' );
		$this->assertStringNotContainsString( 'data-wp-text="state.restUrl"', $page );
		$this->assertStringNotContainsString( 'data-wp-on--click="actions.vote"', $page );
	}

	public function test_html_of_others_in_the_region_of_easyq_has_no_directives(): void {
		$author   = $this->user();
		$question = $this->ask( $author );
		$answer   = $this->answer( $author, $question );
		$this->comment( $author, $question, $answer );
		$directive = '<span data-wp-on--click="actions.vote" data-wp-init="actions.favorite" data-note="kept">Affix</span>';

		// The affixes of blocks, written by an author without `unfiltered_html`.
		Block_Context::router()->set_current( new Route( 'question', $question ) );
		$attributes = wp_json_encode(
			array(
				'prefix' => $directive,
				'suffix' => $directive,
			)
		);
		$page       = do_blocks( '<!-- wp:easyq/question --><!-- wp:easyq/post-tags ' . $attributes . ' /--><!-- /wp:easyq/question -->' );
		$this->assertSame( 2, substr_count( $page, 'data-note="kept"' ), 'Both affixes are shown.' );

		// The texts of the site owner above the forms, and the notices.
		\EasyQ\Settings\Options::update(
			'posting',
			array(
				'show_custom_answer' => true,
				'custom_answer'      => $directive,
			)
		);
		\EasyQ\Settings\Options::update(
			'users',
			array(
				'show_notice_visitor' => true,
				'notice_visitor'      => $directive,
			)
		);
		wp_set_current_user( (int) $this->user()->user_id );
		$page .= do_blocks( '<!-- wp:easyq/question --><!-- wp:easyq/answer-form /--><!-- /wp:easyq/question -->' );
		wp_set_current_user( 0 );
		$notices = Services::notices()->for_actor( new \EasyQ\Actor( null ) );
		$this->assertNotEmpty( $notices );
		$page .= implode( '', array_column( $notices, 'content' ) );

		$this->assertGreaterThanOrEqual( 4, substr_count( $page, 'data-note="kept"' ), 'Affixes, the text above the form and the notice are shown.' );
		$this->assertStringNotContainsString( 'data-wp-on--click="actions.vote"', $page );
		$this->assertStringNotContainsString( 'data-wp-init="actions.favorite"', $page );

		// Comments, also where another plugin lets `data-*` into the tags of comments.
		$allow = static function ( $tags, $context ) {
			if ( is_array( $tags ) && 'data' === $context ) {
				$tags['span'] = array( 'data-*' => true );
			}
			return $tags;
		};
		add_filter( 'wp_kses_allowed_html', $allow, 10, 2 );
		$comment = \EasyQ\Posts\Content_Renderer::kses( $directive, Post::COMMENT );
		remove_filter( 'wp_kses_allowed_html', $allow );
		$this->assertStringContainsString( 'data-note="kept"', $comment );
		$this->assertStringNotContainsString( 'data-wp-', $comment );
	}

	public function test_no_html_of_others_reaches_the_page_without_the_filter_of_easyq(): void {
		// A net over the source: every filter of HTML goes through `Content_Renderer`, which
		// removes directives. Outside it, only the texts of the site owner are filtered as core
		// does: the setting that stores them and the registration form, which is no page of easyQ;
		// and translated sentences may keep their links, an allow list without attributes for directives.
		$own   = array( 'class-content-renderer.php', 'class-fields.php', 'class-registration.php' );
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/includes', \FilesystemIterator::SKIP_DOTS ) );
		$read  = 0;
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() || in_array( $file->getFilename(), $own, true ) ) {
				continue;
			}
			++$read;
			$source = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$source = (string) preg_replace_callback(
				'/\bwp_kses\s*(\((?:[^()]++|(?1))*\))/',
				static fn ( array $call ): string => 1 === preg_match( '/,\s*array\(\s*\'a\'\s*=>\s*array\(\s*\'href\'\s*=>\s*true\s*\)\s*\)\s*\)$/', $call[1] ) ? 'links_only' : $call[0],
				$source
			);
			$this->assertSame( 0, preg_match( '/\b(wp_kses|wp_kses_post|wp_kses_data|wp_filter_post_kses|wp_filter_kses|wp_kses_post_deep)\s*\(|([\'"])(wp_kses\w*|wp_filter_\w*kses)\2/', $source ), $file->getFilename() );
		}
		$this->assertGreaterThan( 100, $read );
	}
}
