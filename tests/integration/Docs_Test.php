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
	 * Blocks that the feature mapping names but that are not built: the updates page shows a question list.
	 *
	 * @var string[]
	 */
	private const PLANNED_BLOCKS = array( 'easyq/updates' );

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
		// Every filter easyQ applies: its own, and those of core and others it applies too.
		preg_match_all( '/\bapply_filters(?:_ref_array|_deprecated)?\(\s*[\'"]([a-z][^\'"]+)[\'"]/', $source, $matches );

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

		// And the other way: every binding source of easyQ is described in the document of hooks.
		$hooks = $this->read( self::root() . 'docs/hooks.md' );
		$own   = array_filter( $sources, static fn ( string $name ): bool => str_starts_with( $name, 'easyq/' ) );
		$this->assertContains( 'easyq/user', $own );
		$this->assertSame( array(), array_values( array_filter( $own, static fn ( string $name ): bool => ! str_contains( $hooks, "binding source `$name`" ) ) ), 'Binding sources without documentation.' );
	}

	public function test_every_parameter_and_setting_of_the_rest_api_is_documented(): void {
		$document = $this->read( self::root() . 'docs/rest-api.md' );
		$missing  = array();
		foreach ( rest_get_server()->get_routes( 'easyq/v1' ) as $route => $handlers ) {
			// The parameters of the path are named by the path.
			preg_match_all( '/\(\?P<(\w+)>/', $route, $path );
			foreach ( $handlers as $handler ) {
				foreach ( array_keys( (array) ( $handler['args'] ?? array() ) ) as $arg ) {
					if ( 'namespace' !== $arg && ! in_array( $arg, $path[1], true ) && ! str_contains( $document, "`$arg`" ) ) {
						$missing[] = "$route: $arg";
					}
				}
			}
		}
		foreach ( array_keys( get_registered_settings() ) as $setting ) {
			if ( str_starts_with( $setting, 'easyq_' ) && ! str_contains( $document, "`$setting`" ) ) {
				$missing[] = "setting $setting";
			}
		}

		$this->assertSame( array(), array_values( array_unique( $missing ) ) );
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
		preg_match_all( '/\bdo_action(?:_ref_array|_deprecated)?\(\s*[\'"]([a-z][^\'"]+)[\'"]/', $source, $actions );
		// Every action fired with a name of its own is documented: those of others, and easyQ's own
		// (`easyq_event` and the events, whose dynamic `easyq_{event}` stands for the list below).
		$literal = array_filter( array_unique( $actions[1] ), static fn ( string $name ): bool => ! str_contains( $name, '{' ) );
		$this->assertContains( 'easyq_event', $literal );
		$this->assertSame( array(), array_values( array_filter( $literal, static fn ( string $name ): bool => ! str_contains( $hooks, "`$name`" ) ) ), 'Actions easyQ fires without documentation.' );
		$this->assertNotSame( array(), $documented[1] );
		$this->assertSame( array(), array_values( array_diff( $documented[1], $applied ) ), 'Documented filters that do not exist.' );

		// The events of the plugin are one list, which dispatch() holds every event to.
		preg_match_all( '/Events::dispatch\(\s*\'(\w+)\'/', $source, $events );
		$this->assertSame( array(), array_values( array_diff( array_unique( $events[1] ), \EasyQ\Events\Events::NAMES ) ), 'Events missing from Events::NAMES.' );
		$section = substr( $hooks, (int) strpos( $hooks, '## Events' ) );
		$section = substr( $section, 0, (int) strpos( $section, "\n## ", 1 ) );
		preg_match_all( '/^\| ([^|]+) \|/m', $section, $cells );
		preg_match_all( '/`([a-z_]+)`/', implode( ' ', $cells[1] ), $documented );
		$documented = array_values( array_diff( $documented[1], array( 'easyq_event' ) ) );
		$this->assertSame( array(), array_values( array_diff( \EasyQ\Events\Events::NAMES, $documented ) ), 'Events without documentation.' );
		$this->assertSame( array(), array_values( array_diff( $documented, \EasyQ\Events\Events::NAMES ) ), 'Documented events that do not exist.' );
		$this->assertGreaterThan( 80, count( \EasyQ\Events\Events::NAMES ) );
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

	public function test_the_values_route_parameters_take_are_documented(): void {
		$document = $this->read( self::root() . 'docs/rest-api.md' );
		$row      = static function ( string $route ) use ( $document ): string {
			preg_match( '/^\| `[A-Z]+ ' . preg_quote( $route, '/' ) . '` \|.*$/m', $document, $match );
			return $match[0] ?? '';
		};
		$missing  = array();

		foreach ( array_keys( \EasyQ\Tools\Tools::STEPS ) as $tool ) {
			if ( ! str_contains( $row( '/tools/{tool}' ), '`' . $tool . '`' ) ) {
				$missing[] = "tool $tool";
			}
		}
		foreach ( array_keys( (array) ( new \ReflectionClassConstant( \EasyQ\REST\Posts_Controller::class, 'ACTIONS' ) )->getValue() ) as $action ) {
			// The first cell of a row of the table of actions, which may name several.
			if ( 1 !== preg_match( '/^\| (?:`[a-z-]+`, )*`' . preg_quote( (string) $action, '/' ) . '`(?:, `[a-z-]+`)* \|/m', $document ) ) {
				$missing[] = "action $action";
			}
		}
		// Path parameters that list their values, such as `(?P<action>start|cancel|test)`.
		foreach ( array_keys( rest_get_server()->get_routes( 'easyq/v1' ) ) as $route ) {
			if ( preg_match_all( '/\(\?P<(\w+)>([a-z_-]+(?:\|[a-z_-]+)+)\)/', $route, $lists, PREG_SET_ORDER ) ) {
				foreach ( $lists as [ , , $values ] ) {
					foreach ( explode( '|', $values ) as $value ) {
						if ( ! str_contains( $document, '/' . $value . '`' ) && ! str_contains( $document, '`' . $value . '`' ) ) {
							$missing[] = "value $value of $route";
						}
					}
				}
			}
		}

		$this->assertNotSame( '', $row( '/tools/{tool}' ) );
		$this->assertSame( array(), $missing );
	}

	public function test_the_inventory_commands_of_the_review_find_what_they_look_for(): void {
		$review = $this->read( self::root() . 'docs/review.md' );
		$start  = (int) strpos( $review, '```bash', (int) strpos( $review, '## Entry classes' ) );
		$block  = substr( $review, $start + 7, (int) strpos( $review, '```', $start + 7 ) - $start - 7 );
		$lines  = array_values( array_filter( array_map( 'trim', explode( "\n", $block ) ) ) );
		$empty  = array();

		foreach ( $lines as $index => $line ) {
			$command = trim( (string) preg_replace( '/\s+#.*$/', '', $line ) );
			if ( str_starts_with( $command, 'grep' ) && ! str_contains( $command, ' -' ) ) {
				$empty[] = "no options: $command";
			}
			// Fixed strings, so that a shell whose grep reads `$` as an anchor finds the same.
			if ( str_contains( $command, 'grep' ) && 1 !== preg_match( '/grep -\w*F/', $command ) ) {
				$empty[] = "not fixed strings: $command";
			}
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- Runs the commands of the document in a test.
			$output = (string) shell_exec( 'cd ' . escapeshellarg( self::root() ) . ' && ' . $command . ' 2>/dev/null' );
			$last   = count( $lines ) - 1 === $index;
			if ( $last ? '' !== trim( $output ) : '' === trim( $output ) ) {
				$empty[] = ( $last ? 'not empty: ' : 'empty: ' ) . $command;
			}
		}

		$this->assertGreaterThan( 15, count( $lines ) );
		$this->assertSame( array(), $empty );

		// Every way into the plugin that core registers is searched for: each function of core
		// named register_* the plugin calls (but registering a user, which is no way in).
		$called = array();
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::root() . 'includes', \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( 'php' === $file->getExtension() && preg_match_all( '/(?<![>:\w$])(register_\w+)\s*\(/', $this->read( $file->getPathname() ), $found ) ) {
				$called = array_merge( $called, $found[1] );
			}
		}
		// Functions of core, not of the plugin and not of PHP.
		$core = array_filter(
			array_unique( $called ),
			static fn ( string $name ): bool => function_exists( $name ) && ( new \ReflectionFunction( $name ) )->isUserDefined() && ! str_contains( (string) ( new \ReflectionFunction( $name ) )->getFileName(), '/plugins/' )
		);
		// `grep -F` finds a name that starts with a searched string.
		preg_match_all( "/-e '([^']+)'/", $block, $searched );
		$missing = array_values(
			array_filter(
				array_diff( $core, array( 'register_new_user' ) ),
				static fn ( string $name ): bool => array() === array_filter( $searched[1], static fn ( string $text ): bool => str_starts_with( $name . '(', $text ) )
			)
		);
		$this->assertContains( 'register_block_bindings_source', $core );
		$this->assertSame( array(), $missing );
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
		$this->assertSame( array( 'easyq_email_{name}', 'easyq_a', 'other' ), $this->applied_filters( 'apply_filters( "easyq_email_{$name}", $a ); apply_filters( \'easyq_a\', 1 ); apply_filters( \'other\', 1 );' ), 'Filters of others count too.' );
		$this->assertSame( array( 'easyq_b', 'easyq_c' ), $this->applied_filters( 'apply_filters_ref_array( \'easyq_b\', array( 1 ) ); apply_filters_deprecated( \'easyq_c\', array( 1 ), \'0.2.0\' );' ), 'Every form of applying a filter counts.' );
		$this->assertSame( '/posts/{}/actions/{}', $this->normal_route( '/posts/(?P<id>[\d]+)/actions/(?P<action>[a-z_]+)' ) );
		$this->assertSame( '/posts/{}', $this->normal_route( '/posts/{id}' ) );
	}
}
