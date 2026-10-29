<?php
/**
 * Q&A pages in the Navigation block.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Frontend;

use WP_Block_Type;
use WP_HTML_Tag_Processor;

defined( 'ABSPATH' ) || exit;

/**
 * Offers the main Q&A pages as variations of the core Navigation Link block, so menus
 * are built with core blocks only.
 *
 * The link search of the Navigation block only queries posts, terms and media, so
 * Q&A pages appear in the block inserter of the Navigation block instead, e.g.
 * "Q&A: Ask a question". The address is taken from the route when the link is
 * rendered, so menus keep working after the base slug changed, and the link of
 * the current page is marked like core marks pages.
 *
 * @since 0.1.0
 */
final class Navigation {

	/**
	 * Attribute value of `kind` that marks Q&A links.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const KIND = 'easyq';

	/**
	 * Sets up the navigation links.
	 *
	 * @since 0.1.0
	 *
	 * @param Router $router Router of the current request.
	 */
	public function __construct( private readonly Router $router ) {
	}

	/**
	 * Hooks the navigation links into WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_filter( 'get_block_type_variations', array( $this, 'variations' ), 10, 2 );
		add_filter( 'render_block_core/navigation-link', array( $this, 'render' ), 10, 2 );
	}

	/**
	 * Returns the Q&A pages offered as links, with their labels.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> Labels keyed by route name.
	 */
	public static function routes(): array {
		return array(
			// Named as in the Question2Answer navigation.
			'home'       => __( 'Q&A', 'easyq' ),
			'questions'  => __( 'Questions', 'easyq' ),
			'ask'        => __( 'Ask a question', 'easyq' ),
			'hot'        => __( 'Hot questions', 'easyq' ),
			'unanswered' => __( 'Unanswered', 'easyq' ),
			'activity'   => __( 'Recent activity', 'easyq' ),
			'tags'       => __( 'Tags', 'easyq' ),
			'categories' => __( 'Categories', 'easyq' ),
			'users'      => __( 'Users', 'easyq' ),
			'account'    => __( 'My account', 'easyq' ),
		);
	}

	/**
	 * Adds the Q&A pages to the variations of the Navigation Link block.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array<string, mixed>> $variations Variations.
	 * @param WP_Block_Type                    $block_type Block type.
	 * @return array<int, array<string, mixed>>
	 */
	public function variations( array $variations, WP_Block_Type $block_type ): array {
		if ( 'core/navigation-link' !== $block_type->name ) {
			return $variations;
		}

		foreach ( self::routes() as $route => $label ) {
			$variations[] = array(
				'name'        => 'easyq-' . $route,
				/* translators: %s: name of a Q&A page, e.g. "Ask a question". */
				'title'       => sprintf( __( 'Q&A: %s', 'easyq' ), $label ),
				/* translators: %s: name of a Q&A page, e.g. "Ask a question". */
				'description' => sprintf( __( 'A link to the Q&A page “%s”.', 'easyq' ), $label ),
				'attributes'  => array(
					'kind'  => self::KIND,
					'type'  => $route,
					'label' => $label,
					'url'   => Routes::url( $route ),
				),
				'isActive'    => array( 'kind', 'type' ),
				'scope'       => array( 'inserter' ),
			);
		}

		return $variations;
	}

	/**
	 * Sets the current address of a Q&A link and marks the link of the current page.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $content      Rendered link.
	 * @param array<mixed> $parsed_block Parsed block with its attributes.
	 * @return string
	 */
	public function render( string $content, array $parsed_block ): string {
		$attributes = is_array( $parsed_block['attrs'] ?? null ) ? $parsed_block['attrs'] : array();
		$route      = is_string( $attributes['type'] ?? null ) ? $attributes['type'] : '';

		if ( self::KIND !== ( $attributes['kind'] ?? null ) || ! isset( self::routes()[ $route ] ) ) {
			return $content;
		}

		$current   = $this->router->current();
		$is_active = null !== $current && $current->name === $route;
		$html      = new WP_HTML_Tag_Processor( $content );

		if ( $is_active && $html->next_tag( array( 'tag_name' => 'li' ) ) ) {
			$html->add_class( 'current-menu-item' );
		}
		if ( $html->next_tag( array( 'tag_name' => 'a' ) ) ) {
			$html->set_attribute( 'href', esc_url( Routes::url( $route ) ) );
			if ( $is_active ) {
				$html->set_attribute( 'aria-current', 'page' );
			}
		}

		return $html->get_updated_html();
	}
}
