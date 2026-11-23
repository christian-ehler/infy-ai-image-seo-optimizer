<?php
/**
 * Filename Modal Template
 *
 * This modal is used for editing image filenames with SEO recommendations
 * and AI optimization features.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Get scoring parameters
$infyp_scoring_params = \IPAIS\Core\INFYP_SEO_Score_Calculator::get_scoring_params();
$infyp_filename_params = $infyp_scoring_params['filename'] ?? [
    'min_chars' => 40,
    'max_chars' => 60,
    'min_words' => 3,
    'max_words' => 6
];
?>

<div class="infyp-lightbox-overlay" id="infyp-lightbox-overlay"></div>
<div class="infyp-filename-lightbox" id="infyp-filename-lightbox" data-image-id="">
    <div class="infyp-filename-lightbox-header">
        <h3><?php echo esc_html__('Edit Filename', 'infy-ai-image-seo-optimizer'); ?> <span class="infyp-image-id"></span></h3>
        <button class="infyp-filename-lightbox-close">&times;</button>
    </div>
    <div class="infyp-filename-lightbox-content">
        <!-- Keywords section will be populated dynamically -->
        <div class="infyp-keywords-container" style="display: none;">
            <!-- Keywords content will be inserted here -->
        </div>

        <div class="infyp-modal-image-preview">
            <img src="" alt="<?php echo esc_attr__('Image preview', 'infy-ai-image-seo-optimizer'); ?>" />
        </div>

        <div class="infyp-field-optimization-section">
            <div class="infyp-field-with-ai">
                <input type="text"
                       class="infyp-filename-input"
                       id="infyp-filename-editor"
                       maxlength="200"
                       placeholder="<?php echo esc_attr__('Enter SEO-friendly filename...', 'infy-ai-image-seo-optimizer'); ?>" />
            </div>
            <div class="infyp-filename-counter">
                <span class="char-count">0 | <?php echo esc_html($infyp_filename_params['max_chars']); ?> <?php echo esc_html__('chars', 'infy-ai-image-seo-optimizer'); ?></span> •
                <span class="word-count">0 | <?php echo esc_html($infyp_filename_params['max_words']); ?> <?php echo esc_html__('words', 'infy-ai-image-seo-optimizer'); ?></span>
            </div>
            <?php do_action( 'infyp_modal_context_section', isset( $image_id ) ? absint( $image_id ) : 0 ); ?>
            <div class="infyp-optimize-button-grid infyp-modal-optimize-grid">
                <div class="infyp-ai-disclaimer">
                    <span class="dashicons dashicons-info"></span>
                    <span class="infyp-disclaimer-text"><?php echo esc_html__('AI Disclaimer', 'infy-ai-image-seo-optimizer'); ?></span>
                    <span class="infyp-tooltip"><?php echo esc_html__('AI can make mistakes. Please double-check responses.', 'infy-ai-image-seo-optimizer'); ?></span>
                </div>
                <button class="button button-primary infyp-optimize-ai infyp-optimize-filename"
                        id="infyp-optimize-filename-btn"
                        data-id="">
                    <span class="infyp-optimize-icon"></span>
                    <?php echo esc_html__('Optimize with AI', 'infy-ai-image-seo-optimizer'); ?>
                </button>
            </div>
        </div>

        <div class="infyp-seo-guidelines">
            <h4><?php echo esc_html__('Filename Guidelines', 'infy-ai-image-seo-optimizer'); ?></h4>
            <ul>
                <li>
                    <span class="dashicons dashicons-info"></span>
                    <?php echo esc_html__('Use lowercase letters only', 'infy-ai-image-seo-optimizer'); ?>
                </li>
                <li>
                    <span class="dashicons dashicons-info"></span>
                    <?php echo esc_html__('Use hyphens (-) to separate words, not underscores', 'infy-ai-image-seo-optimizer'); ?>
                </li>
                <li>
                    <span class="dashicons dashicons-info"></span>
                    <?php
                    printf(
                        /* translators: 1: minimum characters, 2: maximum characters, 3: "characters" text, 4: minimum words, 5: maximum words, 6: "words" text */
                        esc_html__('Optimal: %1$d-%2$d %3$s (%4$d-%5$d %6$s)', 'infy-ai-image-seo-optimizer'),
                        absint( $infyp_filename_params['min_chars'] ),
                        absint( $infyp_filename_params['max_chars'] ),
                        esc_html__('characters', 'infy-ai-image-seo-optimizer'),
                        absint( $infyp_filename_params['min_words'] ),
                        absint( $infyp_filename_params['max_words'] ),
                        esc_html__('words', 'infy-ai-image-seo-optimizer')
                    );
                    ?>
                </li>
                <li>
                    <span class="dashicons dashicons-info"></span>
                    <?php echo esc_html__('Include relevant keywords', 'infy-ai-image-seo-optimizer'); ?>
                </li>
                <li>
                    <span class="dashicons dashicons-info"></span>
                    <?php echo esc_html__('Avoid generic names (DSC, IMG, image + numbers)', 'infy-ai-image-seo-optimizer'); ?>
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

    <div class="infyp-filename-lightbox-footer">
        <button class="button infyp-cancel-filename">
            <?php echo esc_html__('Cancel', 'infy-ai-image-seo-optimizer'); ?>
        </button>
        <button class="button button-primary infyp-save-filename" data-image-id="">
            <?php echo esc_html__('Save Changes', 'infy-ai-image-seo-optimizer'); ?>
        </button>
    </div>
</div>