<?php
/**
 * Tests that numbers out of range in an address never break a page.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Blocks\Block_Context;
use EasyQ\Blocks\Lists;
use EasyQ\Frontend\Page_Meta;
use EasyQ\Frontend\Routes;
use EasyQ\Frontend\Templates;
use EasyQ\Frontend\URLs;

/**
 * Sends every query parameter the frontend reads, found in its source, with the limits of
 * the integer range to every page of the router, and expects no error and no PHP message.
 *
 * @coversNothing
 */
final class Request_Bounds_Test extends Test_Case {

	/**
	 * Values at and beyond the limits of a 64-bit integer, and an array where a string is
	 * expected.
	 *
	 * @var array<string, string|string[]>
	 */
	private const VALUES = array(
		'min'   => '-9223372036854775808',
		'max'   => '9223372036854775807',
		'huge'  => '99999999999999999999',
		'array' => array( '1' ),
	);

	/**
	 * Values sent to each parameter alone.
	 *
	 * @var string[]
	 */
	private const ALONE = array(
		'min'  => '-9223372036854775808',
		'huge' => '99999999999999999999',
	);

	/**
	 * Parameters whose name is a variable in the source, with the names it takes.
	 *
	 * @var array<string, string[]>
	 */
	private const DYNAMIC = array(
		'page_var' => array( Lists::MAIN_PAGE_VAR, 'easyq_page_1', 'easyq_page_2', 'easyq_page_3', 'easyq_answers' ),
		'name'     => array( 'easyq_edit', 'easyq_close', 'easyq_answer', 'easyq_comment' ),
		'param'    => array( 'sort' ),
	);

	/**
	 * Most pages a list may claim: the lists count their items, the search stops at
	 * `Query_Resolver::MAX_SEARCH_START`.
	 *
	 * @var int
	 */
	private const MAX_PAGES = 100000;

	/**
	 * Directories of the frontend.
	 *
	 * @var string[]
	 */
	private const FRONTEND = array( 'includes/blocks', 'includes/frontend', 'includes/feeds' );

	/**
	 * Returns every query parameter the frontend reads.
	 *
	 * @return string[]
	 */
	private function parameters(): array {
		$names = array();
		$root  = dirname( __DIR__, 2 ) . '/';

		foreach ( self::FRONTEND as $directory ) {
			$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . $directory ) );
			foreach ( $files as $file ) {
				if ( 'php' !== $file->getExtension() ) {
					continue;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file in a test.
				$source = (string) file_get_contents( $file->getPathname() );
				preg_match_all( '/\$_GET\[\s*(?:\'([a-z_]+)\'|self::([A-Z_]+)|\$(\w+))\s*\]/', $source, $matches, PREG_SET_ORDER );
				foreach ( $matches as $match ) {
					if ( '' !== $match[1] ) {
						$names[] = $match[1];
					} elseif ( '' !== $match[2] ) {
						$this->assertSame( 1, preg_match( '/const ' . $match[2] . " = '([a-z_]+)';/", $source, $constant ), 'A constant used as a parameter is defined in its class: ' . $match[2] );
						$names[] = $constant[1];
					} else {
						$this->assertArrayHasKey( $match[3], self::DYNAMIC, 'A parameter whose name is a variable is known to this test: $' . $match[3] . ' in ' . $file->getFilename() );
						$names = array_merge( $names, self::DYNAMIC[ $match[3] ] );
					}
				}
			}
		}

		$names = array_values( array_unique( $names ) );
		$this->assertContains( 'easyq_edit', $names );
		$this->assertContains( Lists::MAIN_PAGE_VAR, $names );

		return $names;
	}

	/**
	 * Returns every page of the router.
	 *
	 * @param string $nicename A user who took part.
	 * @param int    $question A visible question.
	 * @return array<string, string>
	 */
	private function pages( string $nicename, int $question ): array {
		$pages = array( 'home' => Routes::url( 'home' ) );
		foreach ( array_keys( Routes::SIMPLE ) as $route ) {
			$pages[ $route ] = Routes::url( $route );
		}
		$pages['question'] = URLs::question( $this->post( $question ) );
		$pages['tag']      = Routes::url( 'tag', 'widgets' );
		$pages['user']     = Routes::url( 'user', $nicename );
		foreach ( Routes::USER_SECTIONS as $section ) {
			$pages[ 'user-' . $section ] = Routes::url( 'user', $nicename, $section );
		}
		$pages['message'] = Routes::url( 'message', $nicename );

		return $pages;
	}

	/**
	 * Opens an address in a classic theme and renders the page; returns what went wrong.
	 *
	 * @param string $url Address.
	 * @return string[]
	 */
	private function problems( string $url ): array {
		$problems = array();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Records PHP messages of the page.
		set_error_handler(
			static function ( int $errno, string $errstr, string $file, int $line ) use ( &$problems ): bool {
				$problems[] = "$errstr @ " . basename( $file ) . ":$line";
				return true;
			}
		);
		$level = ob_get_level();
		ob_start();
		try {
			Lists::reset();
			$this->go_to( $url );
			$router = Block_Context::router();
			( new Templates( $router ) )->template_include( 'index.php' );
			( new Page_Meta( $router ) )->robots( array() );
			wp_get_document_title();
			( new Page_Meta( $router ) )->head();
			Templates::classic_html();

			// The pagination of core visits every page; a list that claims more pages than it can have costs seconds.
			$results = ( new \ReflectionProperty( Lists::class, 'results' ) )->getValue();
			foreach ( (array) $results as $list_id => $result ) {
				if ( is_array( $result ) && ceil( $result['total'] / max( 1, $result['per_page'] ) ) > self::MAX_PAGES ) {
					$problems[] = sprintf( 'List %s claims %d items', $list_id, $result['total'] );
				}
			}
		} catch ( \Throwable $e ) {
			$problems[] = get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine();
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			restore_error_handler();
			remove_all_filters( 'render_block_easyq/page-title' );
			remove_all_filters( 'render_block_easyq/post-title' );
		}

		return $problems;
	}

	public function test_numbers_out_of_range_break_no_page(): void {
		switch_theme( 'twentytwentyone' );
		$author = $this->user();
		$this->take_part( (int) $author->user_id );
		$question = $this->ask( $author );
		$this->answer( $this->user(), $question );
		$nicename   = get_userdata( (int) $author->user_id )->user_nicename;
		$parameters = $this->parameters();
		$reader     = $this->user();
		$this->take_part( (int) $reader->user_id );
		$failures = array();

		foreach ( array(
			'visitor' => 0,
			'user'    => (int) $reader->user_id,
		) as $viewer => $user_id ) {
			wp_set_current_user( $user_id );
			foreach ( $this->pages( $nicename, $question->id ) as $page => $url ) {
				foreach ( self::VALUES as $label => $value ) {
					$query = array_fill_keys( $parameters, $value );
					if ( 'unsubscribe' === $page ) {
						$query = array(
							'newsletter' => 'stop',
							'token'      => 'x',
							'user'       => $value,
						) + $query;
					}
					foreach ( $this->problems( add_query_arg( urlencode_deep( $query ), $url ) ) as $problem ) {
						$failures[] = "$viewer $page $label: $problem";
					}
				}
			}

			if ( 0 !== $user_id ) {
				continue;
			}
			foreach ( $parameters as $parameter ) {
				foreach ( self::ALONE as $label => $value ) {
					$url = add_query_arg( urlencode_deep( array( $parameter => $value ) ), URLs::question( $question ) );
					foreach ( $this->problems( $url ) as $problem ) {
						$failures[] = "$viewer question $parameter=$label: $problem";
					}
				}
			}
		}

		$unsubscribe = Routes::url( 'unsubscribe', null, null, array( 'token' => 'x' ) );
		foreach ( self::VALUES as $label => $value ) {
			foreach ( $this->problems( add_query_arg( urlencode_deep( array( 'post' => $value ) ), $unsubscribe ) ) as $problem ) {
				$failures[] = "visitor unsubscribe post=$label: $problem";
			}
		}

		$this->assertSame( array(), array_values( array_unique( $failures ) ) );
	}

	public function test_no_request_value_is_cast_without_a_range(): void {
		$root  = dirname( __DIR__, 2 ) . '/includes';
		$found = array();
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root ) );
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			foreach ( (array) file( $file->getPathname() ) as $number => $line ) {
				if ( preg_match( '/\$_(GET|POST|REQUEST|COOKIE|SERVER)\b/', (string) $line ) && preg_match( '/\babsint\(|\bintval\(|\(int\)/', (string) $line ) ) {
					$found[] = $file->getFilename() . ':' . ( $number + 1 );
				}
			}
		}

		$this->assertSame( array(), $found, 'Integers from a request go through EasyQ\Util\Number.' );
	}

	public function test_request_values_are_read_only_from_the_superglobals(): void {
		// The guards of this class read $_GET, $_POST, $_REQUEST, $_COOKIE and $_SERVER; a value read
		// another way (filter_input() reads the request as PHP got it, past them) would pass unseen.
		$root  = dirname( __DIR__, 2 ) . '/includes';
		$found = array();
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root ) );
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			foreach ( (array) file( $file->getPathname() ) as $number => $line ) {
				if ( preg_match( '/\b(filter_input(_array)?|filter_has_var|getenv)\s*\(|\$HTTP_\w+_VARS\b|\bphp:\/\/input\b/', (string) $line ) ) {
					$found[] = $file->getFilename() . ':' . ( $number + 1 );
				}
			}
		}

		$this->assertSame( array(), $found );
	}
}
