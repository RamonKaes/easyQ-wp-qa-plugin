<?php
/**
 * Tests for reading integers.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Unit;

use EasyQ\Util\Number;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EasyQ\Util\Number
 */
final class Number_Test extends TestCase {

	public function test_reads_ids_as_absint_reads_them(): void {
		$this->assertSame( 12, Number::id( '12' ) );
		$this->assertSame( 12, Number::id( ' 012 ' ) );
		$this->assertSame( 12, Number::id( '+12' ) );
		$this->assertSame( 12, Number::id( 12 ) );
		$this->assertSame( 12, Number::id( 12.0 ) );
	}

	public function test_refuses_what_is_no_positive_id(): void {
		foreach ( array( '', '0', '-1', '1.5', '12abc', 'abc', '1e3', array( '1' ), null, true, 1.5, -3, NAN, INF ) as $key => $value ) {
			$this->assertSame( 0, Number::id( $value ), "Value $key" );
		}
	}

	public function test_refuses_ids_beyond_the_integer_range(): void {
		foreach ( array( (string) PHP_INT_MIN, '9223372036854775808', '99999999999999999999', 1e20, -1e20, (float) PHP_INT_MAX ) as $key => $value ) {
			$this->assertSame( 0, Number::id( $value ), "Value $key" );
		}
		$this->assertSame( PHP_INT_MAX, Number::id( (string) PHP_INT_MAX ) );
	}

	public function test_keeps_values_inside_the_range(): void {
		$this->assertSame( 0, Number::int_in_range( '0', 0 ) );
		$this->assertSame( -5, Number::int_in_range( '-5', -10, 10 ) );
		$this->assertNull( Number::int_in_range( '11', -10, 10 ) );
		$this->assertNull( Number::int_in_range( 11, -10, 10 ) );
		$this->assertNull( Number::int_in_range( -11.0, -10, 10 ) );
	}

	public function test_clamps_attributes(): void {
		$this->assertSame( 6, Number::clamp( 9, 2, 1, 6 ) );
		$this->assertSame( 1, Number::clamp( -9, 2, 1, 6 ) );
		$this->assertSame( 4, Number::clamp( 4.0, 2, 1, 6 ) );
		$this->assertSame( 2, Number::clamp( 1e20, 2, 1, 6 ), 'Beyond the integer range: the fallback.' );
		$this->assertSame( 2, Number::clamp( 2.5, 2, 1, 6 ) );
		$this->assertSame( 2, Number::clamp( null, 2, 1, 6 ) );
		$this->assertSame( 2, Number::clamp( '99999999999999999999', 2, 1, 6 ), 'A string beyond the range is not a number either.' );
	}

	public function test_reads_pages(): void {
		$this->assertSame( 3, Number::page( '3' ) );
		$this->assertSame( 1, Number::page( null ) );
		$this->assertSame( 1, Number::page( '0' ) );
		$this->assertSame( 1, Number::page( '-4' ) );
		$this->assertSame( 1, Number::page( (string) PHP_INT_MIN ) );
		$this->assertSame( 1, Number::page( 'abc' ) );
		$this->assertSame( Number::MAX_PAGE, Number::page( (string) PHP_INT_MAX ) );
		$this->assertSame( Number::MAX_PAGE, Number::page( '99999999999999999999' ) );
		$this->assertSame( Number::MAX_PAGE, Number::page( (string) ( Number::MAX_PAGE + 1 ) ) );
	}

	public function test_the_offset_of_the_last_page_stays_an_integer(): void {
		$this->assertIsInt( ( Number::MAX_PAGE - 1 ) * 1000 );
	}
}
