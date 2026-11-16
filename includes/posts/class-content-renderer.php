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
			$html = self::kses( $content, $type );
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
	 * get the post filter core applies to contributors, without the directives of the
	 * Interactivity API: easyQ shows them inside its own interactive region, where a
	 * `data-wp-*` attribute would act on its store, e.g. cast a vote for the reader.
	 *
	 * @since 0.1.0
	 *
	 * @param string $content HTML.
	 * @param string $type    Base type of the post, see `Post::base_type()`.
	 * @return string
	 */
	public static function kses( string $content, string $type ): string {
		if ( Post::COMMENT === $type ) {
			return wp_kses( $content, 'data' );
		}

		$tags = new \WP_HTML_Tag_Processor( wp_kses_post( $content ) );
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
	 * Censors block words in the text between HTML tags.
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

		$parts = preg_split( '/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts ) {
			return $html;
		}

		foreach ( $parts as $index => $part ) {
			if ( '' !== $part && '<' !== $part[0] ) {
				$parts[ $index ] = Text::block_words_replace( $part, $regex );
			}
		}

		return implode( '', $parts );
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
		$attributes = Options::get_bool( 'viewing', 'links_in_new_window' ) ? ' target="_blank" rel="nofollow ugc noopener"' : ' rel="nofollow ugc"';

		return (string) preg_replace( '/<a href="([^"]*)" rel="nofollow">/', '<a href="$1"' . $attributes . '>', $html );
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
