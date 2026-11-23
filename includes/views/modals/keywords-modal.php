<?php
/**
 * Keywords Modal Template
 *
 * This modal is used for editing image keywords with AI generation
 * and recommendations. Supports up to 3 keywords per image.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div class="infyp-lightbox-overlay" id="infyp-lightbox-overlay"></div>
<div class="infyp-keywords-lightbox" id="infyp-keywords-lightbox" data-image-id="">
    <div class="infyp-keywords-lightbox-header">
        <h3><?php echo esc_html__('Edit Keywords', 'infy-ai-image-seo-optimizer'); ?></h3>
        <button class="infyp-keywords-lightbox-close">&times;</button>
    </div>
    <div class="infyp-keywords-lightbox-content">
        <div class="infyp-form-token-field">
            <div class="infyp-token-field-header">
                <label for="infyp-keyword-input" class="infyp-token-field-label">
                    <?php echo esc_html__('Add Keywords', 'infy-ai-image-seo-optimizer'); ?>
                </label>
                <div class="infyp-keywords-counter">
                    <span class="keyword-count">0 | 3 <?php echo esc_html__('Keywords', 'infy-ai-image-seo-optimizer'); ?></span>
                </div>
            </div>
            <div class="infyp-token-field-input-container">
                <div class="infyp-token-list" id="infyp-keywords-tokens">
                    <!-- Keywords will be inserted here dynamically -->
                    <input type="text" 
                           id="infyp-keyword-input" 
                           class="infyp-token-input" 
                           placeholder="" 
                           maxlength="30"
                           autocomplete="off"
                           role="combobox"
                           aria-autocomplete="list"
                           aria-expanded="false">
                </div>
            </div>
            <p class="infyp-token-field-help">
                <?php echo esc_html__('Separate Keywords (maximum 3 phrases) with commas or type enter.', 'infy-ai-image-seo-optimizer'); ?>
            </p>
        </div>
        
        <div class="infyp-keywords-recommendations">
            <h4><?php echo esc_html__('Best Practices', 'infy-ai-image-seo-optimizer'); ?></h4>
            <ul>
                <li>
                    <span class="dashicons dashicons-info"></span>
                    <?php echo esc_html__('Use 1-3 relevant keywords', 'infy-ai-image-seo-optimizer'); ?>
                </li>
                <li>
                    <span class="dashicons dashicons-info"></span>
                    <?php echo esc_html__('Each keyword should be 1-3 words', 'infy-ai-image-seo-optimizer'); ?>
                </li>
                <li>
                    <span class="dashicons dashicons-info"></span>
                    <?php echo esc_html__('Focus on search terms', 'infy-ai-image-seo-optimizer'); ?>
                </li>
                <li>
                    <span class="dashicons dashicons-info"></span>
                    <?php echo esc_html__('Be specific and relevant', 'infy-ai-image-seo-optimizer'); ?>
                </li>
            </ul>
        </div>

        <?php do_action( 'infyp_modal_context_section', isset( $image_id ) ? absint( $image_id ) : 0 ); ?>

        <div class="infyp-keywords-ai-generate">
            <button class="button button-primary infyp-has-icon infyp-generate-keywords" data-image-id="">
                <span class="infyp-optimize-icon"></span>
                <?php echo esc_html__('Generate with AI', 'infy-ai-image-seo-optimizer'); ?>
            </button>
        </div>
    </div>
    <div class="infyp-keywords-lightbox-footer">
        <button class="button infyp-cancel-keywords">
            <?php echo esc_html__('Cancel', 'infy-ai-image-seo-optimizer'); ?>
        </button>
        <button class="button button-primary infyp-save-keywords" data-image-id="">
            <?php echo esc_html__('Save Changes', 'infy-ai-image-seo-optimizer'); ?>
        </button>
    </div>
</div>