<?php
/**
 * Tests that values other plugins hand on through filters break nothing.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Blocks\Block_Context;
use EasyQ\Frontend\Route;
use EasyQ\Search\Indexed_Post;
use EasyQ\Search\Search_Provider;
use EasyQ\Services;
use EasyQ\Settings\Options;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * @coversNothing
 */
final class Filter_Values_Test extends REST_Test_Case {

	/**
	 * Returns a value of a parameter type, for the arguments after the filtered value.
	 *
	 * @param \ReflectionParameter $parameter Parameter.
	 * @return array{0: bool, 1: mixed} Whether a value could be made, and the value.
	 */
	private static function sample( \ReflectionParameter $parameter ): array {
		$type = $parameter->getType();
		if ( ! $type instanceof ReflectionNamedType || ( $type->allowsNull() && 'mixed' !== $type->getName() ) ) {
			return array( true, null );
		}

		return match ( $type->getName() ) {
			'mixed'   => array( true, null ),
			'int'     => array( true, 0 ),
			'string'  => array( true, '' ),
			'array'   => array( true, array() ),
			'bool'    => array( true, false ),
			'WP_User' => array( true, new \WP_User( 0 ) ),
			default   => array( false, null ),
		};
	}

	public function test_every_filter_of_the_plugin_hands_on_what_it_does_not_know(): void {
		global $wp_filter;
		// The filters of the admin screens, which the plugin adds in the admin.
		foreach ( array( 'Profile_Screen', 'Users_Screen', 'Dashboard_Screen', 'Settings_Screen', 'Categories_Screen', 'Posts_Screen', 'Points_Screen', 'User_Fields_Screen', 'Email_Templates_Screen', 'Tools_Screen' ) as $screen ) {
			$class = '\\EasyQ\\Admin\\' . $screen;
			( new $class() )->register();
		}

		$foreign  = new \stdClass();
		$checked  = 0;
		$failures = array();
		foreach ( $wp_filter as $hook => $callbacks ) {
			foreach ( $callbacks->callbacks as $by_priority ) {
				foreach ( $by_priority as $callback ) {
					$function = $callback['function'];
					if ( ! is_array( $function ) || ! str_starts_with( is_object( $function[0] ) ? get_class( $function[0] ) : (string) $function[0], 'EasyQ\\' ) ) {
						continue;
					}
					$method = new ReflectionMethod( $function[0], $function[1] );
					// The callbacks of the plugin, not of the test framework.
					if ( ! str_starts_with( $method->getDeclaringClass()->getName(), 'EasyQ\\' ) || str_starts_with( $method->getDeclaringClass()->getName(), 'EasyQ\\Tests\\' ) ) {
						continue;
					}
					$return = $method->getReturnType();
					if ( null === $return || 'void' === (string) $return || 0 === $method->getNumberOfParameters() ) {
						continue;
					}
					$args = array( $foreign );
					foreach ( array_slice( $method->getParameters(), 1, max( 0, (int) $callback['accepted_args'] - 1 ) ) as $parameter ) {
						[ $made, $value ] = self::sample( $parameter );
						if ( ! $made ) {
							continue 2;
						}
						$args[] = $value;
					}
					++$checked;
					try {
						call_user_func_array( $function, $args );
					} catch ( \TypeError $e ) {
						$failures[] = "$hook: " . $method->class . '::' . $method->name . ' – ' . $e->getMessage();
					}
				}
			}
		}

		$this->assertGreaterThan( 30, $checked, 'The filters of the plugin, also of the admin screens.' );
		$this->assertSame( array(), $failures );
	}

	public function test_emails_keep_their_headers_when_a_filter_returns_lines(): void {
		$sent = array();
		add_filter(
			'pre_wp_mail',
			static function ( $result, array $mail ) use ( &$sent ) {
				$sent[] = $mail;
				return true;
			},
			10,
			2
		);
		add_filter(
			'easyq_email_q_answered',
			static fn ( array $email ): array => array( 'headers' => array_values( array_filter( explode( "\n", (string) $email['headers'] ) ) ) ) + $email
		);
		$asker = $this->user();
		$this->take_part( (int) $asker->user_id );
		$question = $this->post( (int) Services::post_service()->create_question( $asker, 'A question with emails', 'Text.', '', array(), null, '@' ) );

		$this->answer( $this->user(), $question );

		$this->assertNotEmpty( $sent );
		$this->assertIsArray( $sent[0]['headers'] );
		$this->assertStringContainsString( 'List-Unsubscribe:', implode( "\n", $sent[0]['headers'] ) );
	}

	public function test_a_default_a_filter_leaves_out_keeps_the_stored_value(): void {
		Options::update( 'general', array( 'base_slug' => 'fragen' ) );
		add_filter(
			'easyq_default_settings',
			static function ( array $defaults ): array {
				unset( $defaults['general']['base_slug'], $defaults['newsletter'] );
				return $defaults;
			}
		);

		$this->assertSame( 'fragen', Options::get( 'general', 'base_slug' ) );
		$this->assertContains( 'newsletter', Options::sections(), 'The uninstall removes the section too.' );
	}

	public function test_the_defaults_are_filtered_once_per_page(): void {
		$question = $this->ask( $this->user() );
		$this->answer( $this->user(), $question );
		$calls = 0;
		add_filter(
			'easyq_default_settings',
			static function ( array $defaults ) use ( &$calls ): array {
				++$calls;
				return $defaults;
			}
		);
		Block_Context::router()->set_current( new Route( 'question', $question ) );

		do_blocks( '<!-- wp:easyq/question --><!-- wp:easyq/post-title /--><!-- wp:easyq/post-content /--><!-- wp:easyq/post-votes /--><!-- wp:easyq/answer-list --><!-- wp:easyq/answer-template --><!-- wp:easyq/post-content /--><!-- /wp:easyq/answer-template --><!-- /wp:easyq/answer-list --><!-- wp:easyq/answer-form /--><!-- /wp:easyq/question -->' );

		$this->assertSame( 1, $calls );
	}

	public function test_providers_cannot_break_the_search_or_take_its_id(): void {
		$question = $this->ask( $this->user(), 'How do I configure the widget?' );
		$numbers  = new class() implements Search_Provider {
			public function id(): string {
				return 'numbers';
			}
			public function label(): string {
				return 'Numbers';
			}
			public function index_post( Indexed_Post $post ): void {
			}
			public function unindex_post( int $post_id ): void {
			}
			public function move_post( int $post_id, ?int $category_id ): void {
			}
			public function search( string $query, int $start, int $count, ?int $user_id ): array {
				return array( 1, 2, 'x' );
			}
		};
		$basic    = new class() implements Search_Provider {
			public function id(): string {
				return 'basic';
			}
			public function label(): string {
				return 'Not the built-in search';
			}
			public function index_post( Indexed_Post $post ): void {
			}
			public function unindex_post( int $post_id ): void {
			}
			public function move_post( int $post_id, ?int $category_id ): void {
			}
			public function search( string $query, int $start, int $count, ?int $user_id ): array {
				return array();
			}
		};
		add_filter( 'easyq_search_providers', static fn ( array $providers ): array => array_merge( $providers, array( $numbers, $basic ) ) );
		Services::reset();

		$this->assertInstanceOf( \EasyQ\Search\Basic_Search::class, Services::search()->providers()['basic'] );

		Options::update( 'lists', array( 'search_provider' => 'numbers' ) );
		$this->assertSame( array(), Services::search()->search( 'widget', 0, 10 ) );
		$response = $this->request( 'GET', '/questions/similar', array( 'title' => 'configure the widget' ) );
		$this->assertSame( 200, $response->get_status() );
		unset( $question );
	}

	public function test_the_filters_of_easyq_take_any_value_back(): void {
		// A net over the source: the value a filter of easyQ returns is checked, never cast.
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/includes', \FilesystemIterator::SKIP_DOTS ) );
		$read  = 0;
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			++$read;
			$source = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$this->assertSame( 0, preg_match( '/\(\s*(string|int|bool|array|float)\s*\)\s*apply_filters(_ref_array)?\s*\(/', $source ), $file->getFilename() );
		}
		$this->assertGreaterThan( 100, $read );

		// The markup of a classic page with a filter that returns no string: the page as without the filter.
		switch_theme( 'twentytwentyone' );
		$this->go_to( \EasyQ\Frontend\Routes::url( 'questions' ) );
		$router = \EasyQ\Blocks\Block_Context::router();
		foreach ( array( array( 'markup' ), new \stdClass() ) as $value ) {
			$filter = static fn () => $value;
			add_filter( 'easyq_classic_template_content', $filter );
			( new \EasyQ\Frontend\Templates( $router ) )->template_include( 'index.php' );
			remove_filter( 'easyq_classic_template_content', $filter );
			$this->assertStringContainsString( 'wp-block-easyq-', \EasyQ\Frontend\Templates::classic_html() );
		}
		// What the page left behind, as `Visibility_Test` cleans up after a page.
		remove_all_filters( 'render_block_easyq/page-title' );
		remove_all_filters( 'render_block_easyq/post-title' );
		\EasyQ\Blocks\Lists::reset();
		$router->set_current( null );
	}
}
