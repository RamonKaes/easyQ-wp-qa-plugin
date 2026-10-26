<?php
/**
 * Block layouts shared by templates and patterns.
 *
 * @package easyQ
 */

namespace EasyQ\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the block markup of the Q&A layouts used by the block templates.
 */
final class Layouts {

	/**
	 * Returns the markup of the start page: title, ask box, sort links and the question list.
	 *
	 * @return string
	 */
	public static function home(): string {
		return '<!-- wp:easyq/page-title /-->' . "\n" . '<!-- wp:easyq/ask-box /-->' . "\n" . '<!-- wp:easyq/sort-navigation /-->' . "\n" . self::question_list();
	}

	/**
	 * Returns the markup of a question list.
	 *
	 * @param array<string, mixed> $query    Query of the list, see the easyq/question-query block.
	 * @param int                  $query_id ID of the list, unique on the page.
	 * @return string
	 */
	public static function question_list( array $query = array( 'inherit' => true ), int $query_id = 1 ): string {
		return '<!-- wp:easyq/question-query ' . self::json(
			array(
				'queryId' => $query_id,
				'query'   => $query,
			)
		) . ' -->
<!-- wp:easyq/question-template -->
<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"top"}} -->
<div class="wp-block-group"><!-- wp:easyq/post-votes {"layout":{"type":"flex","orientation":"vertical"}} /-->
<!-- wp:easyq/post-answer-count /-->
<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group"><!-- wp:easyq/post-title {"isLink":true} /-->
<!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap"}} -->
<div class="wp-block-group"><!-- wp:easyq/post-tags /-->
<!-- wp:easyq/post-category /-->
<!-- wp:easyq/post-activity /--></div>
<!-- /wp:group --></div>
<!-- /wp:group --></div>
<!-- /wp:group -->
<!-- /wp:easyq/question-template -->
<!-- wp:easyq/query-pagination /-->
<!-- wp:easyq/query-no-results /-->
<!-- /wp:easyq/question-query -->';
	}

	/**
	 * Returns the markup of the question page: the question with answers, comments and forms.
	 *
	 * @return string
	 */
	public static function question_page(): string {
		$post_body = '<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"top"}} -->
<div class="wp-block-group"><!-- wp:easyq/post-votes {"layout":{"type":"flex","orientation":"vertical"}} /-->
<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group"><!-- wp:easyq/post-content /-->
<!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between"}} -->
<div class="wp-block-group"><!-- wp:easyq/post-actions /-->
<!-- wp:easyq/post-author /--></div>
<!-- /wp:group -->
<!-- wp:easyq/comment-list -->
<!-- wp:easyq/comment-template -->
<!-- wp:easyq/post-content /-->
<!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap"}} -->
<div class="wp-block-group"><!-- wp:easyq/post-author {"showAvatar":false} /-->
<!-- wp:easyq/post-date /-->
<!-- wp:easyq/post-actions /--></div>
<!-- /wp:group -->
<!-- /wp:easyq/comment-template -->
<!-- wp:easyq/comment-form /-->
<!-- /wp:easyq/comment-list --></div>
<!-- /wp:group --></div>
<!-- /wp:group -->';

		return '<!-- wp:easyq/question -->
<!-- wp:easyq/breadcrumbs /-->
<!-- wp:easyq/post-title {"level":1} /-->
<!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap"}} -->
<div class="wp-block-group"><!-- wp:easyq/post-status /-->
<!-- wp:easyq/post-tags /-->
<!-- wp:easyq/post-category /-->
<!-- wp:easyq/post-view-count /--></div>
<!-- /wp:group -->
' . $post_body . '
<!-- wp:easyq/answer-list {"queryId":2} -->
<!-- wp:easyq/answer-template -->
' . $post_body . '
<!-- /wp:easyq/answer-template -->
<!-- wp:easyq/query-pagination /-->
<!-- /wp:easyq/answer-list -->
<!-- wp:easyq/answer-form /-->
<!-- wp:easyq/related-questions /-->
<!-- /wp:easyq/question -->';
	}

	/**
	 * Returns the markup of a user list.
	 *
	 * @param array<string, mixed> $query    Query of the list, see the easyq/user-query block.
	 * @param int                  $query_id ID of the list, unique on the page.
	 * @return string
	 */
	public static function user_list( array $query = array( 'inherit' => true ), int $query_id = 1 ): string {
		return '<!-- wp:easyq/user-query ' . self::json(
			array(
				'queryId' => $query_id,
				'query'   => $query,
			)
		) . ' -->
<!-- wp:easyq/user-template {"layout":{"type":"grid","columnCount":3}} -->
<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap"}} -->
<div class="wp-block-group"><!-- wp:easyq/user-avatar {"avatarSize":48} /-->
<!-- wp:group {"layout":{"type":"flex","orientation":"vertical"}} -->
<div class="wp-block-group"><!-- wp:easyq/user-name /-->
<!-- wp:easyq/user-points /-->
<!-- wp:easyq/user-title /--></div>
<!-- /wp:group --></div>
<!-- /wp:group -->
<!-- /wp:easyq/user-template -->
<!-- wp:easyq/query-pagination /-->
<!-- wp:easyq/query-no-results ' . self::json( array( 'message' => __( 'No users found.', 'easyq' ) ) ) . ' /-->
<!-- /wp:easyq/user-query -->';
	}

	/**
	 * Returns the markup of a profile page.
	 *
	 * @param string $content Blocks below the profile header and navigation.
	 * @return string
	 */
	public static function user_page( string $content ): string {
		return '<!-- wp:easyq/user -->
<!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap","verticalAlignment":"center"}} -->
<div class="wp-block-group"><!-- wp:easyq/user-avatar {"avatarSize":96} /-->
<!-- wp:group {"layout":{"type":"flex","orientation":"vertical"}} -->
<div class="wp-block-group"><!-- wp:easyq/page-title /-->
<!-- wp:easyq/user-title /-->
<!-- wp:easyq/user-points {"showRank":true} /--></div>
<!-- /wp:group --></div>
<!-- /wp:group -->
<!-- wp:easyq/user-navigation /-->
' . $content . '
<!-- /wp:easyq/user -->';
	}

	/**
	 * Encodes block attributes for block comments.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @return string
	 */
	public static function json( array $attributes ): string {
		return (string) wp_json_encode( $attributes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}
}
