<?php
/**
 * Renders the main plugin page.
 *
 * This template is responsible for displaying the primary user interface of the plugin,
 * including the image list table with its filters, search and sort controls.
 * It serves as the central hub for all plugin operations.
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
    <h1><?php echo esc_html( get_admin_page_title() ); ?><?php echo wp_kses_post( apply_filters('infyp_admin_header_badge', '') ); ?></h1>

    <div id="poststuff">
        <div id="post-body" class="metabox-holder columns-1">
            <div id="postbox-container-2" class="postbox-container">
                <?php do_meta_boxes( $this->main_page_screen_id, 'normal', null ); ?>

                <div id="infyp-global-actions" class="infyp-flex infyp-justify-between infyp-items-center infyp-my-sm">
                    <div id="infyp-global-status" class="infyp-flex infyp-items-center"></div>
                </div>
                
                <form id="infyp-image-seo-form" action="" method="get">
                    <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just preserving page parameter for form submission ?>
                    <input type="hidden" name="page" value="<?php echo esc_attr( isset($_REQUEST['page']) ? sanitize_text_field( wp_unslash($_REQUEST['page']) ) : '' ); ?>" />

                    <?php
                    // Note: prepare_items() is called via AJAX for performance on large media libraries
                    // The skeleton loader shows while data loads asynchronously
                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                    $infyp_current_search = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '';
                    ?>

                    <!-- Search and Filter Row: Post Type | Page/Post | Search | Upload Date | SEO Score -->
                    <div class="infyp-search-grid">
                        <div class="infyp-search-grid-filter">
                            <?php $this->image_list_table->render_post_type_filter_standalone(); ?>
                        </div>
                        <div class="infyp-search-grid-page">
                            <?php $this->image_list_table->render_page_filter(); ?>
                        </div>
                        <div class="infyp-search-grid-search">
                            <div class="infyp-modern-search">
                                <span class="infyp-search-icon dashicons dashicons-search"></span>
                                <input type="search"
                                       id="infyp-modern-search-input"
                                       name="s"
                                       class="infyp-modern-search-input"
                                       placeholder="<?php esc_attr_e('Search images by filename, alt text, title...', 'infy-ai-image-seo-optimizer'); ?>"
                                       value="<?php echo esc_attr($infyp_current_search); ?>"
                                       autocomplete="off" />
                                <span class="infyp-search-spinner spinner"></span>
                                <button type="button" class="infyp-search-clear" aria-label="<?php esc_attr_e('Clear search', 'infy-ai-image-seo-optimizer'); ?>" <?php echo esc_attr( $infyp_current_search ? '' : 'style="display:none;"' ); ?>>
                                    <span class="dashicons dashicons-no-alt"></span>
                                </button>
                            </div>
                        </div>
                        <div class="infyp-search-grid-sort">
                            <?php $this->image_list_table->render_date_sort_control(); ?>
                            <?php $this->image_list_table->render_seo_sort_control(); ?>
                        </div>
                    </div>

                    <?php do_action('infyp_fab_button'); ?>

                    <?php
                    
                    // Set a flag to indicate we're rendering the table
                    do_action('infyp_before_table_display');
                    $this->image_list_table->display();
                    do_action('infyp_after_table_display');
                    
                    /**
                     * Action hook to add custom content after the image list table.
                     *
                     * This hook allows for the addition of custom functionality, such as modals,
                     * immediately after the image list table display.
                     *
                     * @since 1.0.0
                     */
                    do_action( 'infyp_after_image_list_table' );
                    ?>
                </form>
            </div>
        </div>
        <br class="clear">
    </div>
    
    <?php
    /**
     * Action hook to add custom content after the main page content.
     *
     * This hook allows for the addition of custom functionality, such as modals,
     * to the main page of the plugin.
     *
     * @since 1.0.0
     */
    do_action( 'infyp_after_main_page_content' );
    ?>
</div>