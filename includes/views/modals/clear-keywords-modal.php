<?php
/**
 * Clear Keywords Confirmation Modal Template
 *
 * This modal confirms before clearing all keywords from an image.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div class="infyp-lightbox-overlay" id="infyp-clear-keywords-overlay"></div>
<div class="infyp-keywords-lightbox infyp-clear-keywords-lightbox" id="infyp-clear-keywords-modal" data-image-id="">
    <div class="infyp-keywords-lightbox-header">
        <h3><?php echo esc_html__('Clear Keywords', 'infy-ai-image-seo-optimizer'); ?></h3>
        <button class="infyp-keywords-lightbox-close infyp-clear-keywords-close">&times;</button>
    </div>
    <div class="infyp-keywords-lightbox-content">
        <p style="margin: 0; font-size: 14px; color: #50575e;">
            <?php echo esc_html__('Are you sure you want to clear all keywords for this image?', 'infy-ai-image-seo-optimizer'); ?>
        </p>
    </div>
    <div class="infyp-keywords-lightbox-footer">
        <button class="button infyp-clear-keywords-cancel">
            <?php echo esc_html__('Cancel', 'infy-ai-image-seo-optimizer'); ?>
        </button>
        <button class="button button-primary infyp-clear-keywords-confirm" data-image-id="">
            <?php echo esc_html__('Clear Keywords', 'infy-ai-image-seo-optimizer'); ?>
        </button>
    </div>
</div>
