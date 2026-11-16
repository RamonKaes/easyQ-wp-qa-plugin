<?php
/**
 * Tests that numbers out of range in block attributes break no block.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Blocks\Block_Context;
use EasyQ\Blocks\Lists;
use EasyQ\Frontend\Route;
use WP_Block;
use WP_Block_Type_Registry;

/**
 * Renders every block of the plugin from the registry with each numeric attribute, and each
 * key of the query attribute, beyond the integer range, and expects no error and no PHP
 * message. A cast of such a float is a warning from PHP 8.5 on, which the CI runs.
 *
 * @coversNothing
 */
final class Block_Attributes_Test extends Test_Case {

	/**
	 * Values beyond the integer range, as a theme or a pattern can save them.
	 *
	 * @var array<string, mixed>
	 */
	private const VALUES = array(
		'huge'          => 1e20,
		'negative huge' => -1e20,
		'string'        => '99999999999999999999',
		'fraction'      => 2.5,
	);

	/**
	 * Keys of the query attribute that hold numbers.
	 *
	 * @var string[]
	 */
	private const QUERY_NUMBERS = array( 'perPage', 'category', 'author', 'offset', 'pages' );

	/**
	 * Renders a block and returns what went wrong.
	 *
	 * @param string               $name       Block name.
	 * @param array<string, mixed> $attributes Attributes.
	 * @param array<string, mixed> $context    Context.
	 * @return string[]
	 */
	private static function problems( string $name, array $attributes, array $context ): array {
		$problems = array();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Records PHP messages of the block.
		set_error_handler(
			static function ( int $errno, string $errstr, string $file, int $line ) use ( &$problems ): bool {
				$problems[] = "$errstr @ " . basename( $file ) . ":$line";
				return true;
			}
		);
		try {
			Lists::reset();
			$inner = parse_blocks( '<!-- wp:easyq/post-title /--><!-- wp:easyq/user-name /-->' );
			( new WP_Block(
				array(
					'blockName'    => $name,
					'attrs'        => $attributes,
					'innerBlocks'  => $inner,
					'innerHTML'    => '',
					'innerContent' => array( null, null ),
				),
				$context
			) )->render();
		} catch ( \Throwable $e ) {
			$problems[] = get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine();
		} finally {
			restore_error_handler();
		}

		return $problems;
	}

	public function test_numbers_out_of_range_break_no_block(): void {
		$author = $this->user();
		$this->take_part( (int) $author->user_id );
		$question = $this->ask( $author );
		$answer   = $this->answer( $author, $question );
		wp_set_current_user( (int) $author->user_id );
		Block_Context::router()->set_current( new Route( 'question', $question ) );
		$context  = array(
			'easyq/postId' => $answer->id,
			'easyq/userId' => (int) $author->user_id,
			'easyq/listId' => 'list',
		);
		$failures = array();
		$numeric  = 0;

		foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
			if ( ! str_starts_with( $name, 'easyq/' ) ) {
				continue;
			}
			foreach ( (array) $type->attributes as $attribute => $schema ) {
				$types = (array) ( $schema['type'] ?? array() );
				if ( 'query' === $attribute ) {
					foreach ( self::VALUES as $label => $value ) {
						$query = array_fill_keys( self::QUERY_NUMBERS, $value );
						foreach ( self::problems( $name, array( 'query' => $query ), $context ) as $problem ) {
							$failures[] = "$name query $label: $problem";
						}
					}
					continue;
				}
				if ( array() === array_intersect( $types, array( 'number', 'integer' ) ) ) {
					continue;
				}
				++$numeric;
				foreach ( self::VALUES as $label => $value ) {
					foreach ( self::problems( $name, array( $attribute => $value ), $context ) as $problem ) {
						$failures[] = "$name $attribute $label: $problem";
					}
				}
			}
		}

		$this->assertGreaterThan( 10, $numeric, 'The blocks have numeric attributes.' );
		$this->assertSame( array(), array_values( array_unique( $failures ) ) );
	}

	public function test_no_attribute_is_cast_without_a_range(): void {
		$found = array();
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/includes' ) );
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			foreach ( (array) file( $file->getPathname() ) as $number => $line ) {
				// Attributes and arguments come from JSON and may be floats; a cast of a float beyond
				// the integer range is a warning on PHP 8.5, a cast of a string is not.
				if ( preg_match( '/\(int\) *\(? *\$(attributes|query|attrs|args|context)\[/', (string) $line ) ) {
					$found[] = $file->getFilename() . ':' . ( $number + 1 );
				}
			}
		}

		$this->assertSame( array(), $found, 'Integers from attributes and arguments go through EasyQ\Util\Number.' );
	}
}
