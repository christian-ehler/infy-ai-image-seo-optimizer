<?php
/**
 * Usage Info Modal Template
 *
 * This modal displays where an image is being used across the site
 * including posts, pages, and page builder elements.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div class="infyp-lightbox-overlay" id="infyp-lightbox-overlay"></div>
<div class="infyp-usage-lightbox" id="infyp-usage-lightbox" data-image-id="">
    <div class="infyp-usage-lightbox-header">
        <h3><?php echo esc_html__('Image Usage', 'infy-ai-image-seo-optimizer'); ?> <span class="infyp-image-id"></span></h3>
        <button class="infyp-usage-lightbox-close">&times;</button>
    </div>
    <div class="infyp-usage-lightbox-content">
        <div class="infyp-modal-image-preview">
            <img src="" alt="<?php echo esc_attr__('Image preview', 'infy-ai-image-seo-optimizer'); ?>" />
        </div>
        <div class="infyp-usage-details-section">
            <h4><?php echo esc_html__('Where This Image Is Used', 'infy-ai-image-seo-optimizer'); ?></h4>
            <div class="infyp-usage-list">
                <!-- Usage data will be inserted here dynamically -->
            </div>
        </div>
        <div class="infyp-usage-info">
            <h4><?php echo esc_html__('Usage Types', 'infy-ai-image-seo-optimizer'); ?></h4>
            <ul class="infyp-usage-legend">
                <li>
                    <span class="infyp-usage-badge featured"><?php echo esc_html__('Featured', 'infy-ai-image-seo-optimizer'); ?></span>
                    - <?php echo esc_html__('Set as featured image for posts or pages', 'infy-ai-image-seo-optimizer'); ?>
                </li>
                <li>
                    <span class="infyp-usage-badge content"><?php echo esc_html__('In Content', 'infy-ai-image-seo-optimizer'); ?></span>
                    - <?php echo esc_html__('Embedded within the post or page content', 'infy-ai-image-seo-optimizer'); ?>
                </li>
                <li>
                    <span class="infyp-usage-badge builder"><?php echo esc_html__('Page Builder', 'infy-ai-image-seo-optimizer'); ?></span>
                    - <?php echo esc_html__('Used in page builder elements (Elementor, etc.)', 'infy-ai-image-seo-optimizer'); ?>
                </li>
            </ul>
        </div>
    </div>
    <div class="infyp-usage-lightbox-footer">
        <button class="button infyp-close-usage">
            <?php echo esc_html__('Close', 'infy-ai-image-seo-optimizer'); ?>
        </button>
        <button class="button button-primary infyp-refresh-usage" data-image-id="">
            <span class="dashicons dashicons-update"></span>
            <?php echo esc_html__('Refresh', 'infy-ai-image-seo-optimizer'); ?>
        </button>
    </div>
</div>