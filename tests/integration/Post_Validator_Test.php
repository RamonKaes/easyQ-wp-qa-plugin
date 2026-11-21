<?php
/**
 * Tests for post validation.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Posts\Post_Validator;
use EasyQ\Settings\Options;

/**
 * @covers \EasyQ\Posts\Post_Validator
 */
final class Post_Validator_Test extends Test_Case {

	/**
	 * Validator under test.
	 *
	 * @var Post_Validator
	 */
	private Post_Validator $validator;

	public function set_up(): void {
		parent::set_up();
		$this->validator = new Post_Validator();
	}

	public function test_accepts_a_valid_question(): void {
		$errors = $this->validator->validate_question(
			array(
				'title'   => 'A reasonable title here',
				'content' => '<p>Details</p>',
				'format'  => 'html',
				'tags'    => array( 'one', 'two' ),
			)
		);

		$this->assertFalse( $errors->has_errors() );
	}

	/**
	 * @dataProvider provide_invalid_questions
	 *
	 * @param array<string, mixed> $fields Question fields.
	 * @param string               $field  Field expected to have an error.
	 */
	public function test_rejects_invalid_questions( array $fields, string $field ): void {
		$errors = $this->validator->validate_question( $fields + array( 'title' => 'A reasonable title here' ) );

		$this->assertNotEmpty( $errors->get_error_message( $field ), "Expected an error for $field." );
	}

	/**
	 * Provides invalid question fields.
	 *
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function provide_invalid_questions(): array {
		return array(
			'short title'   => array( array( 'title' => 'Too short' ), 'title' ),
			'long title'    => array( array( 'title' => str_repeat( 'x', 121 ) ), 'title' ),
			'too many tags' => array( array( 'tags' => array( 'a', 'b', 'c', 'd', 'e', 'f' ) ), 'tags' ),
			'long content'  => array( array( 'content' => str_repeat( 'x', 12001 ) ), 'content' ),
			'bad email'     => array(
				array(
					'notify' => true,
					'email'  => 'nope',
				),
				'email',
			),
			// Notifications may be on by default, so the address is checked also without `notify`.
			'long email'    => array( array( 'email' => str_repeat( 'x', 89 ) . '@example.org' ), 'email' ),
			'long name'     => array( array( 'name' => str_repeat( 'x', 41 ) ), 'name' ),
		);
	}

	public function test_an_empty_answer_is_asked_to_be_filled_in_when_one_character_is_enough(): void {
		Options::update( 'posting', array( 'min_len_a_content' => 1 ) );

		$this->assertSame( array( 'Please fill in this field.' ), $this->validator->validate_answer( array( 'content' => '' ) )->get_error_messages( 'content' ) );
	}

	public function test_all_tags_together_are_at_most_as_long_as_the_column_keeps_them(): void {
		Options::update( 'posting', array( 'max_num_q_tags' => 50 ) );
		$tags = array();
		for ( $index = 0; $index < 40; $index++ ) {
			$tags[] = sprintf( 'tag-number-%02d-of-many', $index );
		}

		$errors = $this->validator->validate_question(
			array(
				'title'   => 'A question with very many tags',
				'content' => 'Text of the question.',
				'tags'    => $tags,
			)
		);

		$this->assertSame( array( 'Please enter at most ' . Post_Validator::MAX_TAGS_LENGTH . ' characters.' ), $errors->get_error_messages( 'tags' ) );
	}

	public function test_requires_minimum_number_of_tags(): void {
		Options::update( 'posting', array( 'min_num_q_tags' => 1 ) );

		$errors = $this->validator->validate_question(
			array(
				'title' => 'A reasonable title here',
				'tags'  => array(),
			)
		);

		$this->assertNotEmpty( $errors->get_error_message( 'tags' ) );
	}

	public function test_a_tag_is_at_most_as_long_as_the_index_keeps_it(): void {
		$tag    = str_repeat( 'x', \EasyQ\Search\Basic_Search::MAX_WORD_LENGTH );
		$fields = static fn ( string $tag ): array => array(
			'title'   => 'A reasonable title here',
			'content' => 'With enough content to be valid.',
			'tags'    => array( $tag ),
		);

		$this->assertSame( '', $this->validator->validate_question( $fields( $tag ) )->get_error_message( 'tags' ) );
		// Longer, the tag page could not find it, and its links would widen every list.
		$this->assertStringContainsString( '80 characters', $this->validator->validate_question( $fields( $tag . 'x' ) )->get_error_message( 'tags' ) );
	}

	public function test_measures_content_as_text(): void {
		$errors = $this->validator->validate_answer(
			array(
				'content' => '<p><b>short</b></p>',
				'format'  => 'html',
			)
		);

		$this->assertNotEmpty( $errors->get_error_message( 'content' ) );
	}

	public function test_edits_skip_missing_fields(): void {
		$this->assertFalse( $this->validator->validate_question( array( 'tags' => array( 'a' ) ), is_new: false )->has_errors() );
	}

	public function test_comments_use_their_own_minimum_length(): void {
		Options::update( 'posting', array( 'min_len_c_content' => 3 ) );

		$this->assertFalse( $this->validator->validate_comment( array( 'content' => 'Yes' ) )->has_errors() );
		$this->assertTrue( $this->validator->validate_comment( array( 'content' => 'No' ) )->has_errors() );
	}
}
