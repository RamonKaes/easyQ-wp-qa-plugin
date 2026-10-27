<?php
/**
 * Data of easyQ blocks.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Blocks;

use EasyQ\Actor;
use EasyQ\Frontend\Router;
use EasyQ\Posts\Post;
use EasyQ\Services;
use EasyQ\Users\Visitor_Cookie;
use WP_Block;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the post, route and viewer a block is rendered for.
 *
 * @since 0.1.0
 */
final class Block_Context {

	/**
	 * Router of the current request.
	 *
	 * @since 0.1.0
	 *
	 * @var Router|null
	 */
	private static ?Router $router = null;

	/**
	 * Sets the router, once the plugin is loaded.
	 *
	 * @since 0.1.0
	 *
	 * @param Router $router Router.
	 */
	public static function set_router( Router $router ): void {
		self::$router = $router;
	}

	/**
	 * Returns the router.
	 *
	 * @since 0.1.0
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
	 * @since 0.1.0
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
	 * @since 0.1.0
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
	 * Returns the user a block shows: from block context, or the user of the page.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Block $block Block.
	 * @return WP_User|null
	 */
	public static function user( WP_Block $block ): ?WP_User {
		$id = $block->context['easyq/userId'] ?? null;
		if ( ! is_numeric( $id ) ) {
			return self::router()->current()?->user();
		}

		$user = get_userdata( (int) $id );

		return false === $user ? null : $user;
	}

	/**
	 * Returns the list ID from block context.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Block $block Block.
	 * @return string
	 */
	public static function list_id( WP_Block $block ): string {
		$id = $block->context['easyq/listId'] ?? '';

		return is_string( $id ) ? $id : '';
	}
}
