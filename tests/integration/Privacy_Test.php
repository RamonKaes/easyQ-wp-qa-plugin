<?php
/**
 * Tests for the personal data tools.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Privacy\Privacy;
use EasyQ\Services;

/**
 * @covers \EasyQ\Privacy\Privacy
 */
final class Privacy_Test extends Test_Case {

	public function test_registers_with_the_tools_of_wordpress(): void {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );

		$this->assertIsCallable( $exporters['easyq-favorites']['callback'] );
		$this->assertIsCallable( $erasers['easyq-favorites']['callback'] );
	}

	public function test_exports_and_erases_only_the_favorites_of_the_user(): void {
		$author   = $this->user();
		$question = $this->ask( $author, 'A question to export', 'Text.', array( 'exported' ) );
		$category = Services::categories()->create( null, 'Exported category' );
		$this->assertIsInt( $category );
		$fan   = $this->user();
		$other = $this->user();
		foreach ( array( $fan, $other ) as $actor ) {
			Services::favorites()->set( $actor, 'question', $question->id, true );
		}
		Services::favorites()->set( $fan, 'user', (int) $author->user_id, true );
		Services::favorites()->set( $fan, 'tag', 'exported', true );
		Services::favorites()->set( $fan, 'category', $category, true );
		$email = get_userdata( (int) $fan->user_id )->user_email;

		$export = Privacy::export_favorites( $email );
		$this->assertTrue( $export['done'] );
		$this->assertCount( 4, $export['data'] );
		$values = wp_json_encode( array_column( $export['data'], 'data' ) );
		foreach ( array( 'A question to export', 'exported', 'Exported category' ) as $expected ) {
			$this->assertStringContainsString( $expected, (string) $values );
		}
		$this->assertSame( 'easyq-favorites', $export['data'][0]['group_id'] );
		$this->assertCount( 4, array_unique( array_column( $export['data'], 'item_id' ) ) );

		$erased = Privacy::erase_favorites( $email );
		$this->assertTrue( $erased['items_removed'] );
		$this->assertFalse( $erased['items_retained'] );
		$this->assertTrue( $erased['done'] );

		Services::reset();
		$this->assertSame( array(), Privacy::export_favorites( $email )['data'] );
		$this->assertFalse( Privacy::erase_favorites( $email )['items_removed'] );
		$this->assertTrue( Services::favorites()->has( (int) $other->user_id, 'question', $question->id ) );
	}

	public function test_an_unknown_address_has_nothing(): void {
		$this->assertSame( array(), Privacy::export_favorites( 'nobody@example.org' )['data'] );
		$this->assertFalse( Privacy::erase_favorites( 'nobody@example.org' )['items_removed'] );
	}
}
