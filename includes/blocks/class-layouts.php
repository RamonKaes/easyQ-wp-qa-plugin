<?php
/**
 * Block layouts shared by templates and patterns.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Blocks;

use EasyQ\Frontend\Routes;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the block markup of the Q&A layouts, so the block templates and the block
 * patterns stay alike.
 *
 * @since 0.1.0
 */
final class Layouts {

	/**
	 * Returns the markup of the start page: title, ask box and the recent questions and answers.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function home(): string {
		return '<!-- wp:easyq/page-title /-->' . "\n" . '<!-- wp:easyq/ask-box /-->' . "\n" . self::question_list();
	}

	/**
	 * Returns the markup of the questions page: title, sort links and the question list.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function questions(): string {
		return '<!-- wp:easyq/page-title /-->' . "\n" . '<!-- wp:easyq/sort-navigation {"fontSize":"small"} /-->' . "\n" . self::question_list();
	}

	/**
	 * Returns the markup of a question list.
	 *
	 * @since 0.1.0
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
<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column {"width":"8rem"} -->
<div class="wp-block-column" style="flex-basis:8rem"><!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap","verticalAlignment":"top"},"fontSize":"small"} -->
<div class="wp-block-group has-small-font-size"><!-- wp:easyq/post-votes {"layout":{"type":"flex","orientation":"vertical"},"fontSize":"small"} /-->
<!-- wp:easyq/post-answer-count /--></div>
<!-- /wp:group --></div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column"><!-- wp:easyq/post-title {"isLink":true,"fontSize":"medium"} /-->
<!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap"},"fontSize":"small"} -->
<div class="wp-block-group has-small-font-size"><!-- wp:easyq/post-tags /-->
<!-- wp:easyq/post-category /-->
<!-- wp:easyq/post-activity /--></div>
<!-- /wp:group --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->
<!-- /wp:easyq/question-template -->
<!-- wp:easyq/query-pagination /-->
<!-- wp:easyq/query-no-results /-->
<!-- /wp:easyq/question-query -->';
	}

	/**
	 * Returns the markup of a compact question list: title, answers and last activity in one line.
	 *
	 * The list has no ID: the editor gives each inserted list one of its own.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $query Query of the list, see the easyq/question-query block.
	 * @return string
	 */
	public static function compact_question_list( array $query ): string {
		return '<!-- wp:easyq/question-query ' . self::json( array( 'query' => $query ) ) . ' -->
<!-- wp:easyq/question-template -->
<!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between"}} -->
<div class="wp-block-group"><!-- wp:easyq/post-title {"level":3,"isLink":true,"fontSize":"medium"} /-->
<!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap"},"fontSize":"small"} -->
<div class="wp-block-group has-small-font-size"><!-- wp:easyq/post-answer-count /-->
<!-- wp:easyq/post-activity {"showPoints":false} /--></div>
<!-- /wp:group --></div>
<!-- /wp:group -->
<!-- /wp:easyq/question-template -->
<!-- wp:easyq/query-no-results /-->
<!-- /wp:easyq/question-query -->';
	}

	/**
	 * Returns the markup of the question page: the question with answers, comments and forms.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function question_page(): string {
		$post_body = '<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column {"width":"6rem"} -->
<div class="wp-block-column" style="flex-basis:6rem"><!-- wp:easyq/post-votes {"layout":{"type":"flex","orientation":"vertical"},"fontSize":"small"} /--></div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column"><!-- wp:easyq/post-content /-->
<!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between"}} -->
<div class="wp-block-group"><!-- wp:easyq/post-actions {"fontSize":"small"} /-->
<!-- wp:easyq/post-author {"fontSize":"small"} /--></div>
<!-- /wp:group -->
<!-- wp:easyq/comment-list -->
<!-- wp:easyq/comment-template -->
<!-- wp:easyq/post-content {"fontSize":"small"} /-->
<!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap"},"fontSize":"small"} -->
<div class="wp-block-group has-small-font-size"><!-- wp:easyq/post-author {"showAvatar":false} /-->
<!-- wp:easyq/post-date /-->
<!-- wp:easyq/post-actions {"fontSize":"small"} /--></div>
<!-- /wp:group -->
<!-- /wp:easyq/comment-template -->
<!-- wp:easyq/comment-form {"fontSize":"small"} /-->
<!-- /wp:easyq/comment-list --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->';

		return '<!-- wp:easyq/question -->
<!-- wp:easyq/breadcrumbs {"fontSize":"small"} /-->
<!-- wp:easyq/post-title {"level":1} /-->
' . self::breaking_words(
			'<!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap"},"fontSize":"small"} -->
<div class="wp-block-group has-small-font-size"><!-- wp:easyq/post-status /-->
<!-- wp:easyq/post-tags /-->
<!-- wp:easyq/post-category /-->
<!-- wp:easyq/post-view-count /-->
<!-- wp:easyq/favorite-button {"fontSize":"small"} /--></div>
<!-- /wp:group -->'
		) . '
' . $post_body . '
<!-- wp:easyq/answer-list {"queryId":2} -->
<!-- wp:easyq/answer-template -->
' . $post_body . '
<!-- /wp:easyq/answer-template -->
<!-- wp:easyq/query-pagination /-->
<!-- /wp:easyq/answer-list -->
<!-- wp:easyq/answer-form /-->
' . self::breaking_words( '<!-- wp:easyq/related-questions {"fontSize":"small"} /-->' ) . '
<!-- /wp:easyq/question -->';
	}

	/**
	 * Returns the markup of a user list.
	 *
	 * @since 0.1.0
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
<!-- wp:easyq/user-template {"layout":{"type":"grid","minimumColumnWidth":"14rem"}} -->
<!-- wp:columns {"isStackedOnMobile":false} -->
<div class="wp-block-columns is-not-stacked-on-mobile"><!-- wp:column {"width":"48px"} -->
<div class="wp-block-column" style="flex-basis:48px"><!-- wp:easyq/user-avatar {"avatarSize":48} /--></div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column"><!-- wp:group {"layout":{"type":"flex","orientation":"vertical"}} -->
<div class="wp-block-group"><!-- wp:easyq/user-name /-->
<!-- wp:easyq/user-points {"fontSize":"small"} /-->
<!-- wp:easyq/user-title {"fontSize":"small"} /--></div>
<!-- /wp:group --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->
<!-- /wp:easyq/user-template -->
<!-- wp:easyq/query-pagination /-->
<!-- wp:easyq/query-no-results ' . self::json( array( 'message' => __( 'No users found.', 'easyq' ) ) ) . ' /-->
<!-- /wp:easyq/user-query -->';
	}

	/**
	 * Returns the markup of a compact user list: avatar, name and points, one user per line.
	 *
	 * The list has no ID: the editor gives each inserted list one of its own.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $query Query of the list, see the easyq/user-query block.
	 * @return string
	 */
	public static function compact_user_list( array $query ): string {
		return '<!-- wp:easyq/user-query ' . self::json( array( 'query' => $query ) ) . ' -->
<!-- wp:easyq/user-template -->
<!-- wp:columns {"isStackedOnMobile":false} -->
<div class="wp-block-columns is-not-stacked-on-mobile"><!-- wp:column {"width":"32px"} -->
<div class="wp-block-column" style="flex-basis:32px"><!-- wp:easyq/user-avatar {"avatarSize":32} /--></div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column"><!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap"}} -->
<div class="wp-block-group"><!-- wp:easyq/user-name /-->
<!-- wp:easyq/user-points {"fontSize":"small"} /--></div>
<!-- /wp:group --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->
<!-- /wp:easyq/user-template -->
<!-- wp:easyq/query-no-results ' . self::json( array( 'message' => __( 'No users found.', 'easyq' ) ) ) . ' /-->
<!-- /wp:easyq/user-query -->';
	}

	/**
	 * Returns the markup of a profile page.
	 *
	 * @since 0.1.0
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
<!-- wp:easyq/user-title {"fontSize":"small"} /-->
<!-- wp:easyq/user-points {"showRank":true,"fontSize":"small"} /-->
<!-- wp:easyq/favorite-button {"fontSize":"small"} /--></div>
<!-- /wp:group --></div>
<!-- /wp:group -->
<!-- wp:easyq/user-navigation {"fontSize":"small"} /-->
' . $content . '
<!-- /wp:easyq/user -->';
	}

	/**
	 * Returns the markup of the favorites page: questions, users, tags and categories the viewer follows.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function favorites(): string {
		$heading = static fn ( string $title ): string => '<!-- wp:heading -->
<h2 class="wp-block-heading">' . esc_html( $title ) . '</h2>
<!-- /wp:heading -->';

		return '<!-- wp:easyq/page-title /-->
' . $heading( __( 'Questions', 'easyq' ) ) . '
' . self::question_list( array( 'source' => 'favorites' ), 2 ) . '
' . $heading( __( 'Users', 'easyq' ) ) . '
' . self::user_list( array( 'sort' => 'favorites' ), 3 ) . '
' . $heading( __( 'Tags', 'easyq' ) ) . '
' . self::breaking_words( '<!-- wp:easyq/tags {"favorites":true} /-->' ) . '
' . $heading( __( 'Categories', 'easyq' ) ) . '
<!-- wp:easyq/categories {"favorites":true} /-->';
	}

	/**
	 * Returns the markup of the updates page: what happened to the favorites and posts of the viewer.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function updates(): string {
		return '<!-- wp:easyq/page-title /-->
<!-- wp:easyq/sort-navigation {"fontSize":"small"} /-->
<!-- wp:easyq/question-query ' . self::json(
			array(
				'queryId' => 1,
				'query'   => array( 'inherit' => true ),
			)
		) . ' -->
<!-- wp:easyq/question-template -->
<!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between"}} -->
<div class="wp-block-group"><!-- wp:easyq/post-title {"isLink":true,"fontSize":"medium"} /-->
<!-- wp:easyq/post-activity {"fontSize":"small"} /--></div>
<!-- /wp:group -->
<!-- /wp:easyq/question-template -->
<!-- wp:easyq/query-no-results ' . self::json( array( 'message' => __( 'No recent updates', 'easyq' ) ) ) . ' /-->
<!-- /wp:easyq/question-query -->
<!-- wp:paragraph {"fontSize":"small"} -->
<p class="has-small-font-size"><a href="' . esc_url( Routes::url( 'favorites' ) ) . '">' . esc_html__( 'For more updates, add items to your favorites.', 'easyq' ) . '</a></p>
<!-- /wp:paragraph -->';
	}

	/**
	 * Wraps blocks in a single core column, the core block that breaks long words and may shrink.
	 *
	 * Flex rows and inline blocks are as wide as their longest word, so long tags and links need this.
	 *
	 * @since 0.1.0
	 *
	 * @param string $content Blocks.
	 * @return string
	 */
	public static function breaking_words( string $content ): string {
		return '<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column -->
<div class="wp-block-column">' . $content . '</div>
<!-- /wp:column --></div>
<!-- /wp:columns -->';
	}

	/**
	 * Encodes block attributes for block comments.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @return string
	 */
	public static function json( array $attributes ): string {
		return (string) wp_json_encode( $attributes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}
}
