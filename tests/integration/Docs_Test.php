<?php
/**
 * Tests that the documentation names what exists and names all of it.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Blocks\Block_Context;
use EasyQ\Frontend\Templates;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use WP_Block_Patterns_Registry;
use WP_Block_Type_Registry;

/**
 * @coversNothing
 */
final class Docs_Test extends Test_Case {

	/**
	 * Blocks that the feature mapping describes before they exist: the updates page uses a question list, the feedback form comes in phase 7e.
	 *
	 * @var string[]
	 */
	private const PLANNED_BLOCKS = array( 'easyq/updates', 'easyq/feedback-form' );

	/**
	 * Returns the directory of the plugin.
	 *
	 * @return string
	 */
	private static function root(): string {
		return dirname( __DIR__, 2 ) . '/';
	}

	/**
	 * Returns the contents of the documents, keyed by path below the plugin.
	 *
	 * @return array<string, string>
	 */
	private function documents(): array {
		$documents = array();
		foreach ( array_merge( (array) glob( self::root() . 'docs/*.md' ), array( self::root() . 'README.md', self::root() . 'readme.txt' ) ) as $file ) {
			$documents[ substr( $file, strlen( self::root() ) ) ] = $this->read( $file );
		}
		$this->assertGreaterThan( 4, count( $documents ) );

		return $documents;
	}

	/**
	 * Returns the contents of a file.
	 *
	 * @param string $file Path.
	 * @return string
	 */
	private function read( string $file ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file in a test.
		return (string) file_get_contents( $file );
	}

	/**
	 * Returns the PHP source of the plugin, keyed by path.
	 *
	 * @param string $directory Directory below the plugin.
	 * @return array<string, string>
	 */
	private function sources( string $directory ): array {
		$sources = array();
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( self::root() . $directory, RecursiveDirectoryIterator::SKIP_DOTS ) ) as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$sources[ $file->getPathname() ] = $this->read( $file->getPathname() );
			}
		}

		return $sources;
	}

	/**
	 * Lists the paths named in a text that do not exist.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private function missing_paths( string $text ): array {
		preg_match_all( '#`((?:includes|blocks|src|tests|docs|languages|\.github)/[\w./-]+)`#', $text, $matches );

		return array_values( array_filter( array_unique( $matches[1] ), static fn ( string $path ): bool => ! file_exists( self::root() . $path ) ) );
	}

	/**
	 * Tells whether the file of a class of the plugin exists, by the rules of the autoloader.
	 *
	 * @param string $name Class name.
	 * @return bool
	 */
	private function class_file_exists( string $name ): bool {
		$parts = explode( '\\', trim( $name, '\\' ) );
		array_shift( $parts );
		$file      = str_replace( '_', '-', strtolower( (string) array_pop( $parts ) ) );
		$directory = implode( '/', array_map( static fn ( string $part ): string => str_replace( '_', '-', strtolower( $part ) ), $parts ) );
		$base      = self::root() . 'includes/' . ( '' === $directory ? '' : $directory . '/' );

		foreach ( array( 'class-', 'interface-', 'trait-', 'enum-' ) as $prefix ) {
			if ( file_exists( $base . $prefix . $file . '.php' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Lists the classes named in a text that do not exist.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private function missing_classes( string $text ): array {
		preg_match_all( '/\\\\?EasyQ((?:\\\\[A-Z]\w*)+)/', $text, $matches );
		$names = array_map( static fn ( string $name ): string => 'EasyQ' . $name, array_unique( $matches[1] ) );

		return array_values( array_filter( $names, fn ( string $name ): bool => ! defined( $name ) && ! $this->class_file_exists( $name ) && ! str_contains( $name, 'EasyQ\\Tests' ) ) );
	}

	/**
	 * Lists the decision numbers named in a text that the decisions log does not have.
	 *
	 * @param string $text Text.
	 * @param string $log  Document with the decisions log.
	 * @return string[]
	 */
	private function missing_decisions( string $text, string $log ): array {
		preg_match_all( '/^\| (\d+) \|/m', substr( $log, (int) strpos( $log, '## 14. Decisions log' ) ), $known );
		preg_match_all( '/\b[Dd]ecisions? (\d+)(?:(?:, | and |–|-)(\d+))*/', $text, $named );

		return array_values( array_diff( array_unique( array_merge( $named[1], array_filter( $named[2] ) ) ), $known[1] ) );
	}

	/**
	 * Returns the filters the source applies, with dynamic parts as `{name}`.
	 *
	 * @param string $source Source.
	 * @return string[]
	 */
	private function applied_filters( string $source ): array {
		preg_match_all( '/\bapply_filters\(\s*[\'"](easyq_[^\'"]+)[\'"]/', $source, $matches );

		return array_map( static fn ( string $name ): string => (string) preg_replace( '/\{\$(\w+)\}/', '{$1}', $name ), $matches[1] );
	}

	/**
	 * Returns a route of the API with its parameters reduced to `{}`.
	 *
	 * @param string $route Route, as registered or as documented.
	 * @return string
	 */
	private function normal_route( string $route ): string {
		$route = (string) preg_replace( array( '/\(\?P<\w+>(?:[^()]|\([^()]*\))*\)/', '/\{\w+\}/' ), '{}', $route );

		return '/' === $route ? $route : rtrim( $route, '/' );
	}

	public function test_paths_classes_and_decisions_of_the_documents_exist(): void {
		$documents = $this->documents();
		$problems  = array();
		foreach ( $documents as $path => $text ) {
			foreach ( $this->missing_paths( $text ) as $missing ) {
				$problems[] = "$path: path $missing";
			}
			foreach ( $this->missing_classes( $text ) as $missing ) {
				$problems[] = "$path: class $missing";
			}
			foreach ( $this->missing_decisions( $text, $documents['docs/feature-mapping.md'] ) as $missing ) {
				$problems[] = "$path: decision $missing";
			}
		}

		$this->assertSame( array(), $problems );
	}

	public function test_covers_annotations_name_existing_classes(): void {
		$sources = $this->sources( 'tests' );
		$this->assertGreaterThan( 30, count( $sources ) );

		$missing = array();
		foreach ( $sources as $path => $source ) {
			preg_match_all( '/@covers\s+(\\\\EasyQ[\w\\\\]+)/', $source, $matches );
			foreach ( $matches[1] as $name ) {
				if ( ! $this->class_file_exists( $name ) ) {
					$missing[] = "$path: $name";
				}
			}
		}

		$this->assertSame( array(), $missing );
	}

	public function test_blocks_patterns_and_binding_sources_in_the_documents_exist(): void {
		$sources = array_keys( get_all_registered_block_bindings_sources() );
		$missing = array();
		$built   = array();
		foreach ( $this->documents() as $path => $text ) {
			preg_match_all( '#`(easyq/[a-z-]+)`#', $text, $matches );
			foreach ( array_unique( $matches[1] ) as $name ) {
				$exists = WP_Block_Type_Registry::get_instance()->is_registered( $name )
					|| WP_Block_Patterns_Registry::get_instance()->is_registered( $name )
					|| in_array( $name, $sources, true );
				if ( $exists && in_array( $name, self::PLANNED_BLOCKS, true ) ) {
					$built[] = $name;
				} elseif ( ! $exists && ! in_array( $name, self::PLANNED_BLOCKS, true ) ) {
					$missing[] = "$path: $name";
				}
			}
		}

		$this->assertSame( array(), $missing );
		$this->assertSame( array(), $built, 'No longer planned: remove them from the list.' );
	}

	public function test_filters_and_events_match_the_hooks_document(): void {
		$hooks  = $this->read( self::root() . 'docs/hooks.md' );
		$source = implode( "\n", $this->sources( 'includes' ) );

		$applied = array_unique( $this->applied_filters( $source ) );
		$this->assertContains( 'easyq_client_ip', $applied );
		$this->assertSame( array(), array_values( array_filter( $applied, static fn ( string $name ): bool => ! str_contains( $hooks, "`$name`" ) ) ), 'Filters without documentation.' );

		$filters = substr( $hooks, (int) strpos( $hooks, '## Filters' ) );
		$filters = substr( $filters, 0, (int) strpos( $filters, "\n## ", 1 ) );
		preg_match_all( '/^\| `(easyq_[^`]+)` \|/m', $filters, $documented );
		$this->assertNotSame( array(), $documented[1] );
		$this->assertSame( array(), array_values( array_diff( $documented[1], $applied ) ), 'Documented filters that do not exist.' );

		preg_match_all( '/Events::dispatch\(\s*\'(\w+)\'/', $source, $events );
		$this->assertNotSame( array(), $events[1] );
		$this->assertSame( array(), array_values( array_filter( array_unique( $events[1] ), static fn ( string $event ): bool => ! str_contains( $hooks, "`$event`" ) && ! str_contains( $hooks, "`easyq_$event`" ) ) ), 'Events without documentation.' );
	}

	public function test_routes_match_the_rest_api_document(): void {
		$routes = array();
		foreach ( array_keys( rest_get_server()->get_routes( 'easyq/v1' ) ) as $route ) {
			$routes[] = $this->normal_route( '/' . ltrim( substr( $route, strlen( '/easyq/v1' ) ), '/' ) );
		}
		$routes = array_values( array_diff( array_unique( $routes ), array( '/' ) ) );

		$document = $this->read( self::root() . 'docs/rest-api.md' );
		preg_match_all( '/^\| ([^|]*) \|/m', substr( $document, (int) strpos( $document, '## Endpoints' ) ), $cells );
		preg_match_all( '#/[a-z][\w/{}-]*#', implode( "\n", $cells[1] ), $named );
		$documented = array_unique( array_map( array( $this, 'normal_route' ), $named[0] ) );

		$this->assertGreaterThan( 20, count( $routes ) );
		$this->assertSame( array(), array_values( array_diff( $routes, $documented ) ), 'Routes without documentation.' );
		$this->assertSame( array(), array_values( array_diff( $documented, $routes ) ), 'Documented routes that do not exist.' );
	}

	public function test_templates_are_in_the_feature_mapping(): void {
		$mapping     = $this->read( self::root() . 'docs/feature-mapping.md' );
		$definitions = ( new Templates( Block_Context::router() ) )->definitions();
		$this->assertGreaterThan( 10, count( $definitions ) );

		$this->assertSame( array(), array_values( array_filter( array_keys( $definitions ), static fn ( string $slug ): bool => ! str_contains( $mapping, "`$slug`" ) ) ) );
	}

	public function test_the_checks_find_what_is_missing(): void {
		$this->assertSame( array( 'includes/not-there.php' ), $this->missing_paths( '`includes/not-there.php` and `includes/class-plugin.php`' ) );
		$this->assertSame( array( 'EasyQ\\Not\\There' ), $this->missing_classes( '`EasyQ\\Not\\There` and `EasyQ\\Posts\\Post` and `EasyQ\\REST\\Controller::context()`' ) );
		$log = "## 14. Decisions log\n| 1 | a |\n| 2 | b |\n";
		$this->assertSame( array( '9' ), $this->missing_decisions( 'decision 1, decisions 2 and 9', $log ) );
		$this->assertSame( array( 'easyq_email_{name}', 'easyq_a' ), $this->applied_filters( 'apply_filters( "easyq_email_{$name}", $a ); apply_filters( \'easyq_a\', 1 ); apply_filters( \'other\', 1 );' ) );
		$this->assertSame( '/posts/{}/actions/{}', $this->normal_route( '/posts/(?P<id>[\d]+)/actions/(?P<action>[a-z_]+)' ) );
		$this->assertSame( '/posts/{}', $this->normal_route( '/posts/{id}' ) );
	}
}
