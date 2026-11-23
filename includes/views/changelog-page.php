<?php
/**
 * Displays the changelog page.
 *
 * This template is responsible for rendering the changelog page in the WordPress admin area.
 * It includes a title, a brief description, and the changelog table.
 *
 * @package INFYP
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<div class="wrap">
    <h1><?php esc_html_e( 'Optimization Changelog', 'infy-ai-image-seo-optimizer' ); ?><?php echo wp_kses_post( apply_filters('infyp_admin_header_badge', '') ); ?></h1>
    
    <?php
    /**
     * Fires before the changelog table. Extensions can add search boxes,
     * filters, or other controls above the table.
     *
     * @since 1.1.0
     *
     * @param \IPAIS\Tables\INFYP_Changelog_List_Table $list_table The list table instance.
     */
    do_action( 'infyp_before_changelog_table', $this->changelog_list_table );
    ?>

    <form method="get">
        <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just preserving page parameter for form submission ?>
        <input type="hidden" name="page" value="<?php echo esc_attr( isset($_REQUEST['page']) ? sanitize_text_field( wp_unslash($_REQUEST['page']) ) : '' ); ?>" />
        <?php $this->changelog_list_table->display(); ?>
    </form>

    <?php
    /**
     * Fires after the changelog table. Extensions can add summary
     * stats, export buttons, or other controls below the table.
     *
     * @since 1.1.0
     *
     * @param \IPAIS\Tables\INFYP_Changelog_List_Table $list_table The list table instance.
     */
    do_action( 'infyp_after_changelog_table', $this->changelog_list_table );
    ?>
</div>
