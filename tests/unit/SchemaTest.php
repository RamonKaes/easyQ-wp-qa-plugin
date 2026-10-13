<?php
/**
 * Tests for the schema definitions.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Unit;

use EasyQ\Database\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Guards the format that dbDelta() needs and the index size limits of MySQL.
 *
 * @covers \EasyQ\Database\Schema
 */
final class SchemaTest extends TestCase {

	/**
	 * Longest indexable prefix in characters for utf8mb4 on older InnoDB row formats.
	 *
	 * @var int
	 */
	private const MAX_INDEX_CHARS = 191;

	public function test_defines_all_tables(): void {
		$expected = array(
			'posts',
			'categories',
			'words',
			'titlewords',
			'contentwords',
			'tagwords',
			'posttags',
			'uservotes',
			'userpoints',
			'userlevels',
			'userfields',
			'userfavorites',
			'usernotices',
			'userevents',
			'sharedevents',
			'messages',
			'userlimits',
			'iplimits',
			'cookies',
			'postmeta',
			'categorymeta',
			'tagmeta',
		);

		$expected_names = array_map(
			static fn ( string $name ): string => 'wp_easyq_' . $name,
			$expected
		);

		$this->assertSame( $expected_names, array_keys( Schema::definitions( 'wp_', '' ) ) );
		$this->assertSame( $expected_names, Schema::table_names( 'wp_' ) );
	}

	public function test_uses_the_given_prefix_and_collation(): void {
		$definitions = Schema::definitions( 'wp_3_', 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci' );

		$this->assertArrayHasKey( 'wp_3_easyq_posts', $definitions );
		$this->assertStringEndsWith( ') DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;', $definitions['wp_3_easyq_posts'] );
	}

	/**
	 * @dataProvider provide_definitions
	 *
	 * @param string $sql CREATE TABLE statement.
	 */
	public function test_definition_matches_dbdelta_format( string $sql ): void {
		$this->assertMatchesRegularExpression( '/^CREATE TABLE \w+ \(\n/', $sql );
		$this->assertStringContainsString( 'PRIMARY KEY  (', $sql, 'dbDelta() needs two spaces after PRIMARY KEY.' );
		$this->assertStringNotContainsString( '`', $sql, 'dbDelta() cannot parse backticks.' );
		$this->assertStringNotContainsStringIgnoringCase( 'FOREIGN KEY', $sql );
		$this->assertStringNotContainsStringIgnoringCase( ' INDEX ', $sql, 'dbDelta() expects KEY instead of INDEX.' );

		foreach ( $this->column_lines( $sql ) as $line ) {
			$this->assertMatchesRegularExpression( '/^[a-z0-9_]+ [a-z]+/', $line, 'Column names and types must be lowercase.' );
			$this->assertDoesNotMatchRegularExpression( '/ default [^\'N]/i', $line, 'dbDelta() only compares quoted default values.' );
		}

		foreach ( $this->key_lines( $sql ) as $line ) {
			$this->assertMatchesRegularExpression( '/^(UNIQUE )?KEY [a-z0-9_]+ \(/', $line, 'Every key needs a name.' );
		}
	}

	/**
	 * @dataProvider provide_definitions
	 *
	 * @param string $sql CREATE TABLE statement.
	 */
	public function test_long_text_columns_are_indexed_with_a_prefix( string $sql ): void {
		$lengths = array();
		foreach ( $this->column_lines( $sql ) as $line ) {
			if ( preg_match( '/^([a-z0-9_]+) varchar\((\d+)\)/', $line, $match ) ) {
				$lengths[ $match[1] ] = (int) $match[2];
			}
		}

		$unprefixed = array();
		foreach ( $this->key_lines( $sql ) as $line ) {
			preg_match( '/\((.+)\)$/', $line, $match );
			foreach ( explode( ',', $match[1] ) as $column ) {
				if ( ( $lengths[ $column ] ?? 0 ) > self::MAX_INDEX_CHARS ) {
					$unprefixed[] = $column;
				}
			}
		}

		$this->assertSame( array(), $unprefixed, 'Long varchar columns must be indexed with a prefix length.' );
	}

	/**
	 * Provides every table definition.
	 *
	 * @return array<string, array{string}>
	 */
	public static function provide_definitions(): array {
		$cases = array();
		foreach ( Schema::definitions( 'wp_', '' ) as $table => $sql ) {
			$cases[ $table ] = array( $sql );
		}

		return $cases;
	}

	/**
	 * Returns the column lines of a statement.
	 *
	 * @param string $sql CREATE TABLE statement.
	 * @return string[]
	 */
	private function column_lines( string $sql ): array {
		return array_filter(
			$this->body_lines( $sql ),
			static fn ( string $line ): bool => ! preg_match( '/^(PRIMARY KEY|UNIQUE KEY|KEY) /', $line )
		);
	}

	/**
	 * Returns the secondary key lines of a statement.
	 *
	 * @param string $sql CREATE TABLE statement.
	 * @return string[]
	 */
	private function key_lines( string $sql ): array {
		return array_filter(
			$this->body_lines( $sql ),
			static fn ( string $line ): bool => (bool) preg_match( '/^(UNIQUE KEY|KEY) /', $line )
		);
	}

	/**
	 * Returns the lines between the parentheses of a statement, without trailing commas.
	 *
	 * @param string $sql CREATE TABLE statement.
	 * @return string[]
	 */
	private function body_lines( string $sql ): array {
		$lines = array_slice( explode( "\n", $sql ), 1, -1 );

		return array_map( static fn ( string $line ): string => rtrim( $line, ',' ), $lines );
	}
}
