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
			'long name'     => array( array( 'name' => str_repeat( 'x', 41 ) ), 'name' ),
		);
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
