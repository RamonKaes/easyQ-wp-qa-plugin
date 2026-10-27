<?php
/**
 * Tag lists.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Posts;

use EasyQ\Database\DB;
use EasyQ\Database\Row;
use EasyQ\Database\Schema;
use EasyQ\Util\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Lists tags by popularity, optionally completing a prefix.
 *
 * @since 0.1.0
 */
final class Tags {

	/**
	 * Lists tags used by visible questions, most used first.
	 *
	 * @since 0.1.0
	 *
	 * @param string $prefix Only tags starting with this text; empty for all.
	 * @param int    $page   Page number, starting at 1.
	 * @param int    $count  Tags per page.
	 * @return array{tags: array<int, array{name: string, count: int}>, total: int}
	 */
	public function popular( string $prefix = '', int $page = 1, int $count = 30 ): array {
		$wpdb   = DB::wpdb();
		$table  = Schema::table( 'words' );
		$prefix = Text::lowercase( trim( $prefix ) );
		$offset = max( 0, $page - 1 ) * $count;

		if ( '' === $prefix ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
			$rows = DB::rows( $wpdb->get_results( $wpdb->prepare( 'SELECT word, tag_count FROM %i WHERE tag_count > 0 ORDER BY tag_count DESC, word LIMIT %d, %d', $table, $offset, $count ) ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE tag_count > 0', $table ) );
		} else {
			$like = $wpdb->esc_like( $prefix ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
			$rows = DB::rows( $wpdb->get_results( $wpdb->prepare( 'SELECT word, tag_count FROM %i WHERE tag_count > 0 AND word LIKE %s ORDER BY tag_count DESC, word LIMIT %d, %d', $table, $like, $offset, $count ) ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE tag_count > 0 AND word LIKE %s', $table, $like ) );
		}

		$tags = array();
		foreach ( $rows as $row ) {
			$tags[] = array(
				'name'  => Row::string( $row, 'word' ),
				'count' => Row::int( $row, 'tag_count' ),
			);
		}

		return array(
			'tags'  => $tags,
			'total' => $total,
		);
	}
}
