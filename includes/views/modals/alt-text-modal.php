<?php
/**
 * Alt Text Modal Template
 *
 * This modal is used for editing image alt text with SEO recommendations
 * and AI optimization features.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Get scoring parameters
$infyp_scoring_params = \IPAIS\Core\INFYP_SEO_Score_Calculator::get_scoring_params();
$infyp_alt_params = $infyp_scoring_params['alt_text'] ?? [
    'min_chars' => 50,
    'max_chars' => 125,
    'min_words' => 10,
    'max_words' => 16
];
?>

<div class="infyp-lightbox-overlay" id="infyp-lightbox-overlay"></div>
<div class="infyp-alt-text-lightbox" id="infyp-alt-text-lightbox" data-image-id="">
    <div class="infyp-alt-text-lightbox-header">
        <h3><?php echo esc_html__('Edit Alt Text', 'infy-ai-image-seo-optimizer'); ?> <span class="infyp-image-id"></span></h3>
        <button class="infyp-alt-text-lightbox-close">&times;</button>
    </div>
    <div class="infyp-alt-text-lightbox-content">
        <!-- Keywords section will be populated dynamically -->
        <div class="infyp-keywords-container" style="display: none;">
            <!-- Keywords content will be inserted here -->
        </div>

        <div class="infyp-modal-image-preview">
            <img src="" alt="<?php echo esc_attr__('Image preview', 'infy-ai-image-seo-optimizer'); ?>" />
        </div>

        <div class="infyp-field-optimization-section">
            <div class="infyp-field-with-ai">
                <textarea class="infyp-alt-text-textarea"
                         id="infyp-alt-text-editor"
                         maxlength="500"
                         placeholder="<?php echo esc_attr__('Enter descriptive alt text for this image...', 'infy-ai-image-seo-optimizer'); ?>"></textarea>
            </div>
            <div class="infyp-alt-text-counter">
                <span class="char-count">0 | <?php echo esc_html($infyp_alt_params['max_chars']); ?> <?php echo esc_html__('chars', 'infy-ai-image-seo-optimizer'); ?></span> •
                <span class="word-count">0 | <?php echo esc_html($infyp_alt_params['max_words']); ?> <?php echo esc_html__('words', 'infy-ai-image-seo-optimizer'); ?></span>
            </div>
            <?php do_action( 'infyp_modal_context_section', isset( $image_id ) ? absint( $image_id ) : 0 ); ?>
            <div class="infyp-optimize-button-grid infyp-modal-optimize-grid">
                <div class="infyp-ai-disclaimer">
                    <span class="dashicons dashicons-info"></span>
                    <span class="infyp-disclaimer-text"><?php echo esc_html__('AI Disclaimer', 'infy-ai-image-seo-optimizer'); ?></span>
                    <span class="infyp-tooltip"><?php echo esc_html__('AI can make mistakes. Please double-check responses.', 'infy-ai-image-seo-optimizer'); ?></span>
                </div>
                <button class="button button-primary infyp-optimize-ai infyp-optimize-alt-text"
                        id="infyp-optimize-alt-text-btn"
                        data-id="">
                    <span class="infyp-optimize-icon"></span>
                    <?php echo esc_html__('Optimize with AI', 'infy-ai-image-seo-optimizer'); ?>
                </button>
            </div>
        </div>

        <div class="infyp-seo-guidelines">
            <h4><?php echo esc_html__('Best Practices', 'infy-ai-image-seo-optimizer'); ?></h4>
            <ul>
                <li>
                    <span class="dashicons dashicons-info"></span>
                    <?php echo esc_html__('Describe what\'s actually in the image', 'infy-ai-image-seo-optimizer'); ?>
                </li>
                <li>
                    <span class="dashicons dashicons-info"></span>
                    <?php
                    printf(
                        /* translators: 1: minimum characters, 2: maximum characters, 3: "characters" text, 4: minimum words, 5: maximum words, 6: "words" text */
                        esc_html__('Optimal: %1$d-%2$d %3$s (%4$d-%5$d %6$s)', 'infy-ai-image-seo-optimizer'),
                        absint( $infyp_alt_params['min_chars'] ),
                        absint( $infyp_alt_params['max_chars'] ),
                        esc_html__('characters', 'infy-ai-image-seo-optimizer'),
                        absint( $infyp_alt_params['min_words'] ),
                        absint( $infyp_alt_params['max_words'] ),
                        esc_html__('words', 'infy-ai-image-seo-optimizer')
                    );
                    ?>
                </li>
                <li>
                    <span class="dashicons dashicons-info"></span>
                    <?php echo esc_html__('Include relevant keywords naturally', 'infy-ai-image-seo-optimizer'); ?>
                </li>
                <li>
                    <span class="dashicons dashicons-info"></span>
                    <?php echo esc_html__('Don\'t start with "image of" or "picture of"', 'infy-ai-image-seo-optimizer'); ?>
                </li>
                <li>
                    <span class="dashicons dashicons-info"></span>
                    <?php echo esc_html__('Be specific and descriptive', 'infy-ai-image-seo-optimizer'); ?>
                </li>
            </ul>
        </div>

        <div class="infyp-score-display">
            <div class="infyp-score-header">
                <div class="infyp-score-percentage"></div>
            </div>
            <div class="infyp-score-breakdown"></div>
            <div class="infyp-score-hint"></div>
        </div>
    </div>

    <div class="infyp-alt-text-lightbox-footer">
        <button class="button infyp-cancel-alt-text">
            <?php echo esc_html__('Cancel', 'infy-ai-image-seo-optimizer'); ?>
        </button>
        <button class="button button-primary infyp-save-alt-text" data-image-id="">
            <?php echo esc_html__('Save Changes', 'infy-ai-image-seo-optimizer'); ?>
        </button>
    </div>
</div>