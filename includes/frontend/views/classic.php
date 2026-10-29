<?php
/**
 * Q&A page in classic themes without a page template: the rendered block template between the theme's header and footer.
 *
 * Themes can replace this file with `easyq/classic.php`.
 *
 * @package easyQ
 * @since 0.1.0
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<div class="entry-content">
	<?php echo EasyQ\Frontend\Templates::classic_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered blocks, escaped by each block. ?>
</div>
<?php
get_footer();
