<?php
/**
 * Image Preview Modal Template
 *
 * This modal displays a full-size preview of an image from the dashboard table
 * with a link to edit the image in the WordPress Media Library.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div class="infyp-lightbox-overlay" id="infyp-lightbox-overlay"></div>
<div class="infyp-image-preview-lightbox" id="infyp-image-preview-lightbox" data-image-id="">
    <div class="infyp-image-preview-lightbox-header">
        <h3><?php echo esc_html__( 'Image Preview', 'infy-ai-image-seo-optimizer' ); ?> <span class="infyp-image-id"></span></h3>
        <button class="infyp-image-preview-lightbox-close">&times;</button>
    </div>
    <div class="infyp-image-preview-lightbox-content">
        <div class="infyp-modal-image-preview">
            <img src="" alt="<?php echo esc_attr__( 'Image preview', 'infy-ai-image-seo-optimizer' ); ?>" />
        </div>
    </div>
    <div class="infyp-image-preview-lightbox-footer">
        <a href="" class="button infyp-edit-media-link" target="_blank">
            <?php echo esc_html__( 'Edit in Media Library', 'infy-ai-image-seo-optimizer' ); ?>
        </a>
        <button class="button button-primary infyp-optimize-from-modal" data-id="">
            <span class="infyp-optimize-icon"></span>
            <?php echo esc_html__( 'Optimize with AI', 'infy-ai-image-seo-optimizer' ); ?>
        </button>
    </div>
</div>
