<?php
/**
 * Text processing helpers.
 *
 * @package easyQ
 */

namespace EasyQ\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Splits text into words, parses tags, extracts plain text and censors words.
 *
 * Ported from Question2Answer `qa-include/util/string.php` and the basic viewer.
 * The functions work on UTF-8 strings; the regular expressions match bytes, as
 * in Question2Answer, which is safe because all separators are ASCII.
 */
final class Text {

	/**
	 * Characters that separate words in the search index. `$ & - _ # % @` are not separators.
	 *
	 * @var string
	 */
	private const INDEX_WORD_SEPARATOR = '[\n\r\t\ \!\"\\\'\(\)\*\+\,\.\/\:\;\<\=\>\?\[\\\\\]\^\`\{\|\}\~]';

	/**
	 * Characters that separate words in block word lists. `*` is a wildcard, not a separator.
	 *
	 * @var string
	 */
	private const BLOCK_WORD_SEPARATOR = '[\n\r\t\ \!\"\\\'\(\)\+\,\.\/\:\;\<\=\>\?\[\\\\\]\^\`\{\|\}\~\$\&\-\_\#\%\@]';

	/**
	 * Thai, Chinese, Japanese and Korean ideographs in UTF-8; each one is treated as a word.
	 *
	 * @var string
	 */
	private const CJK_IDEOGRAPHS = '\xE0[\xB8-\xB9][\x80-\xBF]|\xE2[\xBA-\xBF][\x80-\xBF]|\xE3[\x80\x88-\xBF][\x80-\xBF]|[\xE4-\xE9][\x80-\xBF][\x80-\xBF]|\xEF[\xA4-\xAB][\x80-\xBF]|\xF0[\xA0-\xAF][\x80-\xBF][\x80-\xBF]';

	/**
	 * HTML elements that end a line when extracting text.
	 *
	 * @var string
	 */
	private const HTML_LINE_SEPARATORS = 'br|option';

	/**
	 * HTML elements that end a paragraph when extracting text.
	 *
	 * @var string
	 */
	private const HTML_PARAGRAPH_SEPARATORS = 'address|applet|blockquote|center|cite|col|div|dd|dl|dt|embed|form|frame|frameset|h1|h2|h3|h4|h5|h6|hr|iframe|input|li|marquee|ol|p|pre|samp|select|spacer|table|tbody|td|textarea|tfoot|th|thead|tr|ul';

	/**
	 * Unicode punctuation converted to ASCII before splitting words.
	 *
	 * @var array<string, string>
	 */
	private const PUNCTUATION = array(
		"\xC2\xA1"     => ' ', // Inverted exclamation mark.
		"\xC2\xA6"     => ' ', // Broken bar.
		"\xC2\xAB"     => ' ', // Left-pointing double angle quotation mark.
		"\xC2\xB1"     => ' ', // Plus-minus sign.
		"\xC2\xBB"     => ' ', // Right-pointing double angle quotation mark.
		"\xC2\xBF"     => ' ', // Inverted question mark.
		"\xC3\x97"     => ' ', // Multiplication sign.
		"\xC3\xB7"     => ' ', // Division sign.
		"\xE2\x80\x80" => ' ', // En quad.
		"\xE2\x80\x81" => ' ', // Em quad.
		"\xE2\x80\x82" => ' ', // En space.
		"\xE2\x80\x83" => ' ', // Em space.
		"\xE2\x80\x84" => ' ', // Three-per-em space.
		"\xE2\x80\x85" => ' ', // Four-per-em space.
		"\xE2\x80\x86" => ' ', // Six-per-em space.
		"\xE2\x80\x87" => ' ', // Figure space.
		"\xE2\x80\x88" => ' ', // Punctuation space.
		"\xE2\x80\x89" => ' ', // Thin space.
		"\xE2\x80\x8A" => ' ', // Hair space.
		"\xE2\x80\x8B" => ' ', // Zero width space.
		"\xE2\x80\x8C" => ' ', // Zero width non-joiner.
		"\xE2\x80\x8E" => ' ', // Left-to-right mark.
		"\xE2\x80\x8F" => ' ', // Right-to-left mark.
		"\xE2\x80\x90" => '-', // Hyphen.
		"\xE2\x80\x91" => '-', // Non-breaking hyphen.
		"\xE2\x80\x92" => '-', // Figure dash.
		"\xE2\x80\x93" => '-', // En dash.
		"\xE2\x80\x94" => '-', // Em dash.
		"\xE2\x80\x95" => '-', // Horizontal bar.
		"\xE2\x80\x96" => ' ', // Double vertical line.
		"\xE2\x80\x98" => ' ', // Left single quotation mark.
		"\xE2\x80\x99" => "'", // Right single quotation mark.
		"\xE2\x80\x9A" => ' ', // Single low-9 quotation mark.
		"\xE2\x80\x9B" => ' ', // Single high-reversed-9 quotation mark.
		"\xE2\x80\x9C" => ' ', // Left double quotation mark.
		"\xE2\x80\x9D" => ' ', // Right double quotation mark.
		"\xE2\x80\x9E" => ' ', // Double low-9 quotation mark.
		"\xE2\x80\x9F" => ' ', // Double high-reversed-9 quotation mark.
		"\xE2\x80\xA2" => ' ', // Bullet.
		"\xE2\x80\xA4" => ' ', // One dot leader.
		"\xE2\x80\xA5" => ' ', // Two dot leader.
		"\xE2\x80\xA6" => ' ', // Horizontal ellipsis.
		"\xE2\x80\xB9" => ' ', // Single left-pointing angle quotation mark.
		"\xE2\x80\xBA" => ' ', // Single right-pointing angle quotation mark.
		"\xE2\x80\xBC" => ' ', // Double exclamation mark.
		"\xE2\x80\xBD" => ' ', // Interrobang.
		"\xE2\x81\x87" => ' ', // Double question mark.
		"\xE2\x81\x88" => ' ', // Question exclamation mark.
		"\xE2\x81\x89" => ' ', // Exclamation question mark.
		"\xE3\x80\x80" => ' ', // Ideographic space.
		"\xE3\x80\x81" => ' ', // Ideographic comma.
		"\xE3\x80\x82" => ' ', // Ideographic full stop.
	);

	/**
	 * Splits a string into words.
	 *
	 * @param string $text            Input text.
	 * @param bool   $lowercase       Whether to convert the words to lowercase.
	 * @param bool   $delimiters      Whether to keep the delimiters after each word.
	 * @param bool   $split_ideographs Whether every CJK ideograph is a separate word.
	 * @param bool   $split_hyphens   Whether hyphens separate words.
	 * @return string[]
	 */
	public static function to_words( string $text, bool $lowercase = true, bool $delimiters = false, bool $split_ideographs = true, bool $split_hyphens = true ): array {
		if ( $lowercase ) {
			$text = self::lowercase( $text );
		}

		$text = strtr( $text, self::PUNCTUATION );

		$separator = self::INDEX_WORD_SEPARATOR;
		if ( $split_hyphens ) {
			$separator .= '|\-';
		}

		if ( $delimiters ) {
			if ( $split_ideographs ) {
				$separator .= '|' . self::CJK_IDEOGRAPHS;
			}
		} else {
			// Remove apostrophes within words.
			$text = (string) preg_replace( "/(\S)'(\S)/", '\1\2', $text );

			if ( $split_ideographs ) {
				$text = (string) preg_replace( '/' . self::CJK_IDEOGRAPHS . '/', ' \0 ', $text );
			}
		}

		$flags = PREG_SPLIT_NO_EMPTY | ( $delimiters ? PREG_SPLIT_DELIM_CAPTURE : 0 );
		$words = preg_split( '/(' . $separator . '+)/', $text, -1, $flags );

		return false === $words ? array() : $words;
	}

	/**
	 * Parses tags as entered by a user.
	 *
	 * @param string $input           Tags entered in a form field.
	 * @param bool   $comma_separated Whether tags are separated by commas (and may contain spaces).
	 * @return string[] Unique lowercase tags.
	 */
	public static function parse_tags( string $input, bool $comma_separated ): array {
		if ( $comma_separated ) {
			$tags = preg_split( '/\s*,\s*/', trim( self::lowercase( strtr( $input, '/', ' ' ) ) ), -1, PREG_SPLIT_NO_EMPTY );

			return array_values( array_unique( false === $tags ? array() : $tags ) );
		}

		return array_values( array_unique( self::to_words( $input, true, false, false, false ) ) );
	}

	/**
	 * Normalizes tags passed programmatically as an array or comma-separated string.
	 *
	 * @param string|string[] $tags Tags.
	 * @return string[] Unique lowercase tags.
	 */
	public static function normalize_tags( string|array $tags ): array {
		if ( is_array( $tags ) ) {
			$tags = implode( ',', $tags );
		}

		$tags = preg_split( '/\s*,\s*/', self::lowercase( strtr( $tags, '/', ' ' ) ), -1, PREG_SPLIT_NO_EMPTY );

		return array_values( array_unique( array_map( 'trim', false === $tags ? array() : $tags ) ) );
	}

	/**
	 * Converts tags into the string stored in the database.
	 *
	 * @param string[] $tags Tags.
	 * @return string
	 */
	public static function tags_to_string( array $tags ): string {
		return implode( ',', $tags );
	}

	/**
	 * Converts a stored tag string into tags.
	 *
	 * @param string|null $tag_string Stored tag string.
	 * @return string[]
	 */
	public static function string_to_tags( ?string $tag_string ): array {
		return ( null === $tag_string || '' === $tag_string ) ? array() : explode( ',', $tag_string );
	}

	/**
	 * Extracts the plain text of post content, used for indexing and length checks.
	 *
	 * @param string $content Stored content.
	 * @param string $format  Content format: '' for plain text or 'html'.
	 * @return string
	 */
	public static function content_to_text( string $content, string $format ): string {
		if ( 'html' !== $format ) {
			return $content;
		}

		$text = strtr( $content, "\n\r\t", '   ' );
		$text = (string) preg_replace( '/<\s*(' . self::HTML_LINE_SEPARATORS . ')[^A-Za-z0-9]/i', "\n\\0", $text );
		$text = (string) preg_replace( '/<\s*(' . self::HTML_PARAGRAPH_SEPARATORS . ')[^A-Za-z0-9]/i', "\n\n\\0", $text );
		$text = self::strip_tags( $text );
		$text = (string) preg_replace( '/  +/', ' ', $text );
		$text = (string) preg_replace( '/ *\n */', "\n", $text );
		$text = (string) preg_replace( '/\n\n\n+/', "\n\n", $text );
		$text = strtr(
			$text,
			array(
				'&#34;'  => "\x22",
				'&#38;'  => "\x26",
				'&#39;'  => "\x27",
				'&#60;'  => "\x3C",
				'&#62;'  => "\x3E",
				'&nbsp;' => ' ',
				'&quot;' => "\x22",
				'&amp;'  => "\x26",
				'&lt;'   => "\x3C",
				'&gt;'   => "\x3E",
			)
		);

		return trim( $text );
	}

	/**
	 * Splits a block word list as entered by the site owner.
	 *
	 * @param string $words Block words separated by spaces, commas or other punctuation; `*` is a wildcard.
	 * @return string[]
	 */
	public static function block_words_explode( string $words ): array {
		$list = preg_split( '/' . self::BLOCK_WORD_SEPARATOR . '+/', $words, -1, PREG_SPLIT_NO_EMPTY );

		return false === $list ? array() : $list;
	}

	/**
	 * Builds a regular expression fragment that matches the block words.
	 *
	 * @param string $words Block words, see block_words_explode().
	 * @return string Fragment for block_words_match_all(), or '' if there are no block words.
	 */
	public static function block_words_to_regex( string $words ): string {
		$patterns = array();

		foreach ( self::block_words_explode( $words ) as $word ) {
			$pattern = str_replace( '\\*', '[^ ]*', preg_quote( self::lowercase( $word ), '/' ) );

			// Require word boundaries unless the word starts or ends with an ideograph.
			if ( ! preg_match( '/^(' . self::CJK_IDEOGRAPHS . ')/', $word ) ) {
				$pattern = '(?<= )' . $pattern;
			}
			if ( ! preg_match( '/(' . self::CJK_IDEOGRAPHS . ')$/', $word ) ) {
				$pattern .= '(?= )';
			}

			$patterns[] = $pattern;
		}

		return implode( '|', $patterns );
	}

	/**
	 * Finds all block words in a string.
	 *
	 * @param string $text  Text to search.
	 * @param string $regex Fragment from block_words_to_regex().
	 * @return array<int, int> Byte lengths of the matches keyed by byte offset.
	 */
	public static function block_words_match_all( string $text, string $regex ): array {
		if ( '' === $regex ) {
			return array();
		}

		// Replace separators with spaces of the same byte length so offsets stay valid.
		$same_length = array();
		foreach ( array_keys( self::PUNCTUATION ) as $character ) {
			$same_length[ $character ] = str_repeat( ' ', strlen( $character ) );
		}

		$text = strtr( self::lowercase( $text ), $same_length );
		$text = (string) preg_replace( '/' . self::BLOCK_WORD_SEPARATOR . '/', ' ', $text );

		preg_match_all( '/' . $regex . '/', ' ' . $text . ' ', $matches, PREG_OFFSET_CAPTURE );

		$found = array();
		foreach ( $matches[0] as $match ) {
			$found[ $match[1] - 1 ] = strlen( $match[0] );
		}

		return $found;
	}

	/**
	 * Replaces block words with a repeated character.
	 *
	 * @param string $text      Text to censor.
	 * @param string $regex     Fragment from block_words_to_regex().
	 * @param string $character Replacement character.
	 * @return string
	 */
	public static function block_words_replace( string $text, string $regex, string $character = '*' ): string {
		$matches = self::block_words_match_all( $text, $regex );
		krsort( $matches, SORT_NUMERIC );

		foreach ( $matches as $start => $length ) {
			$replacement = str_repeat( $character, self::length( substr( $text, $start, $length ) ) );
			$text        = substr_replace( $text, $replacement, $start, $length );
		}

		return $text;
	}

	/**
	 * Shortens a string to a single line, removing words from about two thirds into it.
	 *
	 * @param string $text     Text to shorten.
	 * @param int    $length   Maximum length in characters.
	 * @param string $ellipsis Text that replaces the removed words.
	 * @return string
	 */
	public static function shorten_line( string $text, int $length, string $ellipsis = ' ... ' ): string {
		$text = strtr( $text, "\r\n\t", '   ' );

		if ( self::length( $text ) <= $length ) {
			return $text;
		}

		$remaining = $length - self::length( $ellipsis );
		$words     = self::to_words( $text, false, true );
		$count     = count( $words );
		$prefix    = '';
		$suffix    = '';

		// Order: prefix, suffix, prefix, prefix, suffix, prefix, ...
		for ( $index = 0; $index < $count; $index++ ) {
			$to_suffix   = 1 === $index % 3;
			$word        = (string) ( $to_suffix ? array_pop( $words ) : array_shift( $words ) );
			$word_length = self::length( $word );

			if ( $word_length > $remaining ) {
				break;
			}

			if ( $to_suffix ) {
				$suffix = $word . $suffix;
			} else {
				$prefix .= $word;
			}

			$remaining -= $word_length;
		}

		return $prefix . $ellipsis . $suffix;
	}

	/**
	 * Returns the length of a string in characters.
	 *
	 * @param string $text Input string.
	 * @return int
	 */
	public static function length( string $text ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}

	/**
	 * Converts a string to lowercase.
	 *
	 * @param string $text Input string.
	 * @return string
	 */
	public static function lowercase( string $text ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	/**
	 * Removes HTML tags; uses the WordPress function when available.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function strip_tags( string $html ): string {
		return function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $html ) : strip_tags( $html ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Fallback for unit tests without WordPress.
	}
}
