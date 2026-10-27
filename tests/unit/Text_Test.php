<?php
/**
 * Tests for the text helpers.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Unit;

use EasyQ\Util\Text;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EasyQ\Util\Text
 */
final class Text_Test extends TestCase {

	public function test_splits_words_lowercase_without_apostrophes_and_hyphens(): void {
		$this->assertSame(
			array( 'hello', 'world', 'its', 'a', 'test', 'case' ),
			Text::to_words( "Hello, World! It's a test-case." )
		);
	}

	public function test_keeps_index_relevant_characters_inside_words(): void {
		$this->assertSame( array( 'c#', 'user@example', 'com', '100%' ), Text::to_words( 'C# user@example.com 100%' ) );
	}

	public function test_converts_unicode_punctuation(): void {
		$this->assertSame( array( 'foo', 'bar', 'zitat' ), Text::to_words( "foo\u{2014}bar \u{201E}Zitat\u{201C}" ) );
	}

	public function test_lowercases_multibyte_characters(): void {
		$this->assertSame( array( 'größe', 'ärger' ), Text::to_words( 'Größe ÄRGER' ) );
	}

	public function test_treats_each_ideograph_as_a_word(): void {
		$this->assertSame( array( '日', '本', '語', 'テスト' ), Text::to_words( '日本語テスト' ) );
	}

	public function test_can_keep_delimiters_and_case(): void {
		// As in Question2Answer, each delimiter character is returned separately.
		$this->assertSame( array( 'Hello', ',', ' ', 'World' ), Text::to_words( 'Hello, World', lowercase: false, delimiters: true ) );
	}

	public function test_parses_comma_separated_tags(): void {
		$this->assertSame( array( 'wordpress', 'php', 'block themes' ), Text::parse_tags( 'WordPress, PHP ,  php, Block Themes', comma_separated: true ) );
	}

	public function test_parses_space_separated_tags_keeping_hyphens(): void {
		$this->assertSame( array( 'e-mail', 'php', 'mysql' ), Text::parse_tags( 'E-Mail php/mysql php', comma_separated: false ) );
	}

	public function test_normalizes_programmatic_tags(): void {
		$this->assertSame( array( 'a', 'b' ), Text::normalize_tags( array( 'A', ' b ', 'a' ) ) );
		$this->assertSame( array( 'x y', 'z' ), Text::normalize_tags( 'x/y, z' ) );
	}

	public function test_converts_between_tag_lists_and_strings(): void {
		$this->assertSame( 'a,b', Text::tags_to_string( array( 'a', 'b' ) ) );
		$this->assertSame( array( 'a', 'b' ), Text::string_to_tags( 'a,b' ) );
		$this->assertSame( array(), Text::string_to_tags( '' ) );
		$this->assertSame( array(), Text::string_to_tags( null ) );
	}

	public function test_extracts_text_from_html(): void {
		$this->assertSame(
			"Hello world\n\nSecond line & more",
			Text::content_to_text( '<p>Hello <b>world</b></p><p>Second&nbsp;line &amp; more</p>', 'html' )
		);
	}

	public function test_breaks_lines_at_line_elements(): void {
		$this->assertSame( "one\ntwo", Text::content_to_text( 'one<br>two', 'html' ) );
	}

	public function test_returns_plain_text_unchanged(): void {
		$this->assertSame( 'a <b>', Text::content_to_text( 'a <b>', '' ) );
	}

	public function test_replaces_block_words_with_wildcards(): void {
		$regex = Text::block_words_to_regex( 'damn, h*ll' );

		$this->assertSame( '**** this **** and hello', Text::block_words_replace( 'Damn this hell and hello', $regex ) );
	}

	public function test_finds_block_word_positions(): void {
		$regex = Text::block_words_to_regex( 'spam' );

		$this->assertSame( array( 4 => 4 ), Text::block_words_match_all( 'Buy SPAM now', $regex ) );
		$this->assertSame( array(), Text::block_words_match_all( 'spammer', $regex ) );
	}

	public function test_block_word_replacement_keeps_multibyte_text_intact(): void {
		$regex = Text::block_words_to_regex( 'mist' );

		$this->assertSame( 'Größe ****!', Text::block_words_replace( 'Größe Mist!', $regex ) );
	}

	public function test_empty_block_list_matches_nothing(): void {
		$this->assertSame( '', Text::block_words_to_regex( '' ) );
		$this->assertSame( 'text', Text::block_words_replace( 'text', '' ) );
	}

	public function test_shortens_lines_from_two_thirds(): void {
		$this->assertSame( 'one two ... six', Text::shorten_line( 'one two three four five six', 15 ) );
		$this->assertSame( 'short line', Text::shorten_line( "short\nline", 15 ) );
	}

	public function test_counts_characters_not_bytes(): void {
		$this->assertSame( 5, Text::length( 'Größe' ) );
		$this->assertSame( 'größe', Text::lowercase( 'GRÖßE' ) );
	}
}
