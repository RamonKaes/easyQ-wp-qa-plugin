<?php
/**
 * Tests that the tokens of the links in emails hold on their own site only.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;

/**
 * @coversNothing
 * @group ms-required
 */
final class Email_Tokens_Test extends Test_Case {

	/**
	 * Returns the classes of the plugin that create and check tokens: those with a public static
	 * `token()` and `verify()`.
	 *
	 * @return array<string, class-string>
	 */
	private static function token_classes(): array {
		$classes = array();
		$root    = dirname( __DIR__, 2 ) . '/includes';
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, RecursiveDirectoryIterator::SKIP_DOTS ) ) as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file in a test.
			$source = (string) file_get_contents( $file->getPathname() );
			if ( 1 !== preg_match( '/^namespace\s+([\w\\\\]+);/m', $source, $space ) ) {
				continue;
			}
			$tokens = token_get_all( $source );
			foreach ( $tokens as $index => $token ) {
				if ( is_array( $token ) && T_CLASS === $token[0] ) {
					$next = $index + 1;
					while ( isset( $tokens[ $next ] ) && ( ! is_array( $tokens[ $next ] ) || T_STRING !== $tokens[ $next ][0] ) ) {
						++$next;
					}
					$name = $space[1] . '\\' . ( is_array( $tokens[ $next ] ?? null ) ? $tokens[ $next ][1] : '' );
					if ( ! class_exists( $name ) ) {
						continue;
					}
					$class = new ReflectionClass( $name );
					$check = static fn ( string $method ): bool => $class->hasMethod( $method ) && $class->getMethod( $method )->isStatic() && $class->getMethod( $method )->isPublic();
					if ( $check( 'token' ) && $check( 'verify' ) ) {
						$classes[ $class->getShortName() ] = $class->getName();
					}
					break;
				}
			}
		}

		return $classes;
	}

	public function test_a_token_of_one_site_does_not_hold_on_another(): void {
		$classes = self::token_classes();
		$this->assertGreaterThanOrEqual( 2, count( $classes ), 'The classes with tokens were not found.' );

		$other = self::factory()->blog->create();
		foreach ( $classes as $short => $class ) {
			$token  = ( new ReflectionMethod( $class, 'token' ) )->invoke( null, 7 );
			$verify = new ReflectionMethod( $class, 'verify' );
			$this->assertTrue( $verify->invoke( null, 7, $token ), "$short: own site" );
			$this->assertFalse( $verify->invoke( null, 8, $token ), "$short: other ID" );

			switch_to_blog( $other );
			$elsewhere = $verify->invoke( null, 7, $token );
			restore_current_blog();
			$this->assertFalse( $elsewhere, "$short: other site" );
		}
	}
}
