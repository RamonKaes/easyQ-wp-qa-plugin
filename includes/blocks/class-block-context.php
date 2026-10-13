<?php
/**
 * Data of easyQ blocks.
 *
 * @package easyQ
 */

namespace EasyQ\Blocks;

use EasyQ\Actor;
use EasyQ\Frontend\Router;
use EasyQ\Posts\Post;
use EasyQ\Services;
use EasyQ\Users\Visitor_Cookie;
use WP_Block;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the post, route and viewer a block is rendered for.
 */
final class Block_Context {

	/**
	 * Router of the current request.
	 *
	 * @var Router|null
	 */
	private static ?Router $router = null;

	/**
	 * Sets the router, once the plugin is loaded.
	 *
	 * @param Router $router Router.
	 */
	public static function set_router( Router $router ): void {
		self::$router = $router;
	}

	/**
	 * Returns the router.
	 *
	 * @return Router
	 */
	public static function router(): Router {
		if ( null === self::$router ) {
			self::$router = new Router();
		}

		return self::$router;
	}

	/**
	 * Returns the viewer of the page.
	 *
	 * @return Actor
	 */
	public static function viewer(): Actor {
		return Actor::current( Visitor_Cookie::current() );
	}

	/**
	 * Returns the post a block shows: from block context, or the question of the page.
	 *
	 * Posts the viewer may not see are not returned.
	 *
	 * @param WP_Block $block Block.
	 * @return Post|null
	 */
	public static function post( WP_Block $block ): ?Post {
		$id   = $block->context['easyq/postId'] ?? null;
		$post = is_numeric( $id ) ? Services::posts()->find( (int) $id ) : self::router()->current()?->question();

		if ( null === $post || ! Services::permissions()->post_rules( $post, self::viewer() )['viewable'] ) {
			return null;
		}

		return $post;
	}

	/**
	 * Returns the list ID from block context.
	 *
	 * @param WP_Block $block Block.
	 * @return string
	 */
	public static function list_id( WP_Block $block ): string {
		$id = $block->context['easyq/listId'] ?? '';

		return is_string( $id ) ? $id : '';
	}
}
