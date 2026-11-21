<?php
/**
 * Post content output.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Posts;

use EasyQ\Settings\Options;
use EasyQ\Util\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Turns stored content into safe HTML and censors block words.
 *
 * Ported from the Question2Answer basic viewer: plain text is escaped, line
 * breaks become paragraphs and web addresses become links; HTML content is
 * filtered as core filters what users write, see `kses()`.
 *
 * @since 0.1.0
 */
final class Content_Renderer {

	/**
	 * Tags that end a line or a paragraph; words do not continue across them.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const BLOCK_TAGS = 'address|article|aside|blockquote|br|dd|div|dl|dt|figcaption|figure|footer|h[1-6]|header|hr|li|main|nav|ol|p|pre|section|table|tbody|td|tfoot|th|thead|tr|ul';

	/**
	 * Returns the HTML of post content.
	 *
	 * @since 0.1.0
	 *
	 * @param string $content Stored content.
	 * @param string $format  Content format: '' or 'html'.
	 * @param string $type    Base type of the post, see `Post::base_type()`.
	 * @return string
	 */
	public function html( string $content, string $format, string $type = Post::QUESTION ): string {
		if ( 'html' === $format ) {
			$html = $this->html_link_attributes( self::kses( $content, $type ) );
		} else {
			$html = wpautop( esc_html( $content ) );

			if ( Options::get_bool( 'viewing', 'show_url_links' ) ) {
				$html = make_clickable( $html );
				$html = $this->link_attributes( $html );
			}
		}

		return $this->censor_html( $html );
	}

	/**
	 * Filters HTML a user wrote, on saving and on showing it.
	 *
	 * Comments get the tags core allows in comments (`$allowedtags`); questions and answers
	 * get the post filter core applies to contributors. Both lose the directives of the
	 * Interactivity API, see `kses_post()`.
	 *
	 * @since 0.1.0
	 *
	 * @param string $content HTML.
	 * @param string $type    Base type of the post, see `Post::base_type()`.
	 * @return string
	 */
	public static function kses( string $content, string $type ): string {
		// An HTML comment is text, as Question2Answer treats it (htmLawed with `safe`): kses
		// keeps comments, but filtering a comment with `>` again turns its inside into markup,
		// and easyQ filters on saving and on showing.
		$content = str_replace( '<!--', '&lt;!--', $content );

		return Post::COMMENT === $type ? self::without_directives( wp_kses( $content, 'data' ) ) : self::kses_post( $content );
	}

	/**
	 * Filters HTML that easyQ shows inside its own interactive region with the post filter
	 * of core, without the directives of the Interactivity API.
	 *
	 * Inside the region a `data-wp-*` attribute would act on the store of easyQ, e.g. cast a
	 * vote for the reader. Core keeps directives in the HTML of contributors, so every text
	 * of someone else easyQ prints there goes through here: posts, the affixes of blocks,
	 * the texts above forms and the notices.
	 *
	 * @since 0.1.0
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function kses_post( string $html ): string {
		return self::without_directives( wp_kses_post( $html ) );
	}

	/**
	 * Removes every attribute that starts with `data-wp-`.
	 *
	 * @since 0.1.0
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function without_directives( string $html ): string {
		$tags = new \WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag() ) {
			foreach ( (array) $tags->get_attribute_names_with_prefix( 'data-wp-' ) as $name ) {
				$tags->remove_attribute( $name );
			}
		}

		return $tags->get_updated_html();
	}

	/**
	 * Returns a title or other plain text with block words censored.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public function text( string $text ): string {
		$regex = $this->block_words_regex();

		return '' === $regex ? $text : Text::block_words_replace( $text, $regex );
	}

	/**
	 * Censors block words in the text of HTML, as the basic viewer of Question2Answer does.
	 *
	 * The words are found in the text without tags, so a word split by inline tags
	 * (`da<b></b>rn`) is found; block tags and entities separate words, so `&amp;` is never
	 * taken for the word `amp`. Each character of a word becomes one `*`; tags stay.
	 *
	 * @since 0.1.0
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public function censor_html( string $html ): string {
		$regex = $this->block_words_regex();
		if ( '' === $regex ) {
			return $html;
		}

		// The text without tags, and for each of its bytes the offset in the HTML (-1: a separator).
		preg_match_all( '/<[^>]*>|&(?:#[0-9]+|#x[0-9a-f]+|[a-z][a-z0-9]*);/i', $html, $tokens, PREG_OFFSET_CAPTURE );
		$text = '';
		$map  = array();
		$from = 0;
		foreach ( array_merge( $tokens[0], array( array( '', strlen( $html ) ) ) ) as [ $token, $offset ] ) {
			for ( $byte = $from; $byte < $offset; $byte++ ) {
				$text .= $html[ $byte ];
				$map[] = $byte;
			}
			if ( '' !== $token && ( '&' === $token[0] || 1 === preg_match( '/^<\/?(' . self::BLOCK_TAGS . ')\b/i', $token ) ) ) {
				$text .= ' ';
				$map[] = -1;
			}
			$from = $offset + strlen( $token );
		}

		$matches = Text::block_words_match_all( $text, $regex );
		krsort( $matches, SORT_NUMERIC );
		foreach ( $matches as $start => $length ) {
			// From the last character, so the offsets before it stay valid.
			$characters = preg_split( '//u', substr( $text, $start, $length ), -1, PREG_SPLIT_NO_EMPTY );
			$byte       = $start + $length;
			foreach ( array_reverse( false === $characters ? array() : $characters ) as $character ) {
				$byte -= strlen( $character );
				// Not reachable today: a match never holds a separator added for a block tag; the check keeps such a byte out of the stars.
				if ( $map[ $byte ] < 0 ) {
					continue;
				}
				$html = substr_replace( $html, '*', $map[ $byte ], strlen( $character ) );
			}
		}

		return $html;
	}

	/**
	 * Adds the link attributes the site wants to links created from addresses.
	 *
	 * @since 0.1.0
	 *
	 * @param string $html HTML with links.
	 * @return string
	 */
	private function link_attributes( string $html ): string {
		$new_window = Options::get_bool( 'viewing', 'links_in_new_window' );

		return (string) preg_replace_callback(
			// make_clickable() marks links away from the site `nofollow` and links to it with no rel.
			'/<a href="([^"]*)"(?: rel="nofollow")?>/',
			static fn ( array $link ): string => sprintf(
				'<a href="%1$s"%2$s rel="%3$s">',
				$link[1],
				$new_window ? ' target="_blank"' : '',
				implode( ' ', self::rel( html_entity_decode( $link[1] ), array(), $new_window ) )
			),
			$html
		);
	}

	/**
	 * Returns the rel values of a link users wrote, as `wp_rel_ugc()` adds them: `ugc`, and
	 * `nofollow` except for a link to the site itself (`wp_rel_callback()`), with `noopener`
	 * for a new window.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $href       Address of the link.
	 * @param string[] $existing   Values the link has.
	 * @param bool     $new_window Whether the link opens a new window.
	 * @return string[]
	 */
	public static function rel( string $href, array $existing, bool $new_window ): array {
		$added = wp_is_internal_link( $href ) ? array( 'ugc' ) : array( 'nofollow', 'ugc' );

		return array_values( array_unique( array_merge( $existing, $added, $new_window ? array( 'noopener' ) : array() ) ) );
	}

	/**
	 * Marks the links of HTML users wrote as `wp_rel_ugc()` does, and opens them in a new
	 * window as the links of plain text when the site wants it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function html_link_attributes( string $html ): string {
		$new_window = Options::get_bool( 'viewing', 'links_in_new_window' );
		$tags       = new \WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag( array( 'tag_name' => 'a' ) ) ) {
			if ( null === $tags->get_attribute( 'href' ) ) {
				continue;
			}
			$rel = preg_split( '/\s+/', strtolower( (string) $tags->get_attribute( 'rel' ) ), -1, PREG_SPLIT_NO_EMPTY );
			$tags->set_attribute( 'rel', implode( ' ', self::rel( (string) $tags->get_attribute( 'href' ), is_array( $rel ) ? $rel : array(), $new_window ) ) );
			if ( $new_window ) {
				$tags->set_attribute( 'target', '_blank' );
			}
		}

		return $tags->get_updated_html();
	}

	/**
	 * Returns the regular expression for the block words of the site.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	private function block_words_regex(): string {
		return Text::block_words_to_regex( Options::get_string( 'posting', 'block_bad_words' ) );
	}
}
