<?php
/**
 * Block registration.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Blocks;

use EasyQ\Posts\Post;
use EasyQ\Posts\Post_Service;
use EasyQ\REST\Post_Input;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Util\Assets;
use WP_Block;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the easyQ blocks, their scripts and the block bindings source.
 *
 * Every block is server-side rendered by Renderer::render_callback(). The
 * editor script and the interactivity module are shared by all blocks.
 *
 * @since 0.1.0
 */
final class Blocks {

	/**
	 * Blocks that render their inner blocks themselves, once per item.
	 *
	 * @since 0.1.0
	 *
	 * @var string[]
	 */
	private const LOOPS = array( 'question', 'question-template', 'answer-template', 'comment-template', 'user', 'user-template' );

	/**
	 * Hooks the registration into WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_blocks' ) );
		add_filter( 'block_categories_all', array( $this, 'add_category' ) );
		add_filter( 'render_block_data', array( $this, 'give_list_an_id' ) );
	}

	/**
	 * Gives a list block without a `queryId` one before it renders, so the context reaches
	 * its template, pagination and "no results" blocks, and two such lists page on their own.
	 *
	 * The editor sets the ID when a list is inserted; a list placed outside the editor, e.g. a
	 * pattern referenced in a theme file, arrives without one.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $parsed_block Parsed block.
	 * @return array<string, mixed>
	 */
	public function give_list_an_id( array $parsed_block ): array {
		if ( ! in_array( $parsed_block['blockName'] ?? '', array( 'easyq/question-query', 'easyq/answer-list', 'easyq/user-query' ), true ) ) {
			return $parsed_block;
		}

		$attrs = is_array( $parsed_block['attrs'] ?? null ) ? $parsed_block['attrs'] : array();
		if ( ! is_numeric( $attrs['queryId'] ?? null ) ) {
			$attrs['queryId']      = Lists::next_id();
			$parsed_block['attrs'] = $attrs;
		}

		return $parsed_block;
	}

	/**
	 * Registers scripts, blocks and the bindings source.
	 *
	 * @since 0.1.0
	 */
	public function register_blocks(): void {
		$this->register_scripts();

		foreach ( (array) glob( \EasyQ\PLUGIN_DIR . '/blocks/*/block.json' ) as $file ) {
			$dir  = dirname( (string) $file );
			$args = array( 'render_callback' => array( Renderer::class, 'render_callback' ) );
			if ( in_array( basename( $dir ), self::LOOPS, true ) ) {
				$args['skip_inner_blocks'] = true;
			}

			register_block_type( $dir, $args );
		}

		register_block_bindings_source(
			'easyq/post',
			array(
				'label'              => __( 'Q&A post', 'easyq' ),
				'get_value_callback' => array( $this, 'binding_value' ),
				'uses_context'       => array( 'easyq/postId' ),
			)
		);

		register_block_bindings_source(
			'easyq/user',
			array(
				'label'              => __( 'Q&A user', 'easyq' ),
				'get_value_callback' => array( $this, 'user_binding_value' ),
				'uses_context'       => array( 'easyq/userId' ),
			)
		);
	}

	/**
	 * Adds the block category.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array<string, mixed>> $categories Block categories.
	 * @return array<int, array<string, mixed>>
	 */
	public function add_category( array $categories ): array {
		$categories[] = array(
			'slug'  => 'easyq',
			'title' => __( 'Questions & Answers', 'easyq' ),
			'icon'  => null,
		);

		return $categories;
	}

	/**
	 * Returns a value of the current post for a bound block attribute.
	 *
	 * Supported keys: title, content, excerpt, url, author, date, extra. The extra field of a
	 * question is empty unless question pages show it, as the Extra Field block.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $source_args Binding arguments with the key.
	 * @param WP_Block             $block       Bound block.
	 * @return string|null
	 */
	public function binding_value( array $source_args, WP_Block $block ): ?string {
		$post = Block_Context::post( $block );
		if ( null === $post ) {
			return null;
		}

		$renderer = Services::content_renderer();

		return match ( $source_args['key'] ?? '' ) {
			'title'   => esc_html( $renderer->text( (string) $post->title ) ),
			'content' => $renderer->html( (string) $post->content, $post->format ),
			'excerpt' => esc_html( $renderer->text( \EasyQ\Util\Text::shorten_line( $post->text(), 200 ) ) ),
			'url'     => esc_url( \EasyQ\Frontend\URLs::post( $post ) ),
			'author'  => esc_html( Render::author_name( $post ) ),
			'date'    => esc_html( Render::date( $post->created ) ),
			'extra'   => Post::QUESTION === $post->base_type() && Post_Input::uses_extra_field() && Options::get_bool( 'posting', 'extra_field_display' )
				? esc_html( $renderer->text( Post_Service::extra( $post ) ) )
				: '',
			default   => null,
		};
	}

	/**
	 * Returns a value of the current user for a bound block attribute.
	 *
	 * Supported keys: name, url, avatar, points, title, description.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $source_args Binding arguments with the key.
	 * @param WP_Block             $block       Bound block.
	 * @return string|null
	 */
	public function user_binding_value( array $source_args, WP_Block $block ): ?string {
		$user = Block_Context::user( $block );
		if ( null === $user ) {
			return null;
		}

		return match ( $source_args['key'] ?? '' ) {
			'name'        => esc_html( $user->display_name ),
			'url'         => esc_url( \EasyQ\Frontend\URLs::user( $user ) ),
			'avatar'      => esc_url( (string) get_avatar_url( $user->ID, array( 'size' => 192 ) ) ),
			'points'      => esc_html( Render::number( Services::points()->get( $user->ID ) ) ),
			'title'       => esc_html( Services::user_titles()->title( $user->ID ) ),
			'description' => esc_html( $user->description ),
			default       => null,
		};
	}

	/**
	 * Registers the shared editor script and interactivity module.
	 *
	 * @since 0.1.0
	 */
	private function register_scripts(): void {
		$editor = Assets::get( 'editor' );
		$src    = plugins_url( 'build/editor.js', \EasyQ\PLUGIN_FILE );
		wp_register_script(
			'easyq-editor',
			'' === $src ? false : $src,
			array_values( array_filter( $editor['dependencies'], static fn ( $dependency ): bool => is_string( $dependency ) && '' !== $dependency ) ),
			$editor['version'],
			array( 'in_footer' => true )
		);
		wp_set_script_translations( 'easyq-editor', 'easyq', \EasyQ\PLUGIN_DIR . '/languages' );

		$view = Assets::get( 'view' );
		wp_register_script_module(
			'easyq-view',
			plugins_url( 'build/view.js', \EasyQ\PLUGIN_FILE ),
			$this->module_dependencies( $view['dependencies'] ),
			$view['version']
		);
	}

	/**
	 * Returns the module dependencies of a built module: IDs, or arrays with ID and import type.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, mixed> $dependencies Dependencies from the asset file.
	 * @return array<int, string|array<string, string>>
	 */
	private function module_dependencies( array $dependencies ): array {
		$valid = array();
		foreach ( $dependencies as $dependency ) {
			if ( is_string( $dependency ) ) {
				$valid[] = $dependency;
			} elseif ( is_array( $dependency ) && is_string( $dependency['id'] ?? null ) ) {
				$module = array();
				foreach ( $dependency as $key => $value ) {
					if ( is_string( $key ) && is_string( $value ) ) {
						$module[ $key ] = $value;
					}
				}
				$valid[] = $module;
			}
		}

		return $valid;
	}
}
