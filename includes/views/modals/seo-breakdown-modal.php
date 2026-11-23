<?php
/**
 * SEO Score Breakdown Modal Template
 *
 * This modal displays detailed SEO scoring breakdown for an image
 * showing what elements are present, missing, and what can be improved.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div id="infyp-score-modal" class="infyp-score-modal">
    <div class="infyp-score-modal-content">
        <div class="infyp-score-modal-header">
            <h2><?php echo esc_html__('SEO Score Breakdown', 'infy-ai-image-seo-optimizer'); ?></h2>
            <div class="infyp-modal-header-badges">
                <span class="infyp-points-badge infyp-points-earned">0</span>
                <span class="infyp-points-separator">|</span>
                <span class="infyp-points-badge infyp-points-total infyp-badge-success">100</span>
            </div>
            <span class="infyp-score-modal-close">&times;</span>
        </div>
        <div class="infyp-score-modal-body">
            <!-- Individual Field Score Cards -->
            <div class="infyp-score-breakdown-sections">
                <!-- Overall SEO Score Section -->
                <div class="infyp-score-field-section" data-field="overall">
                    <h3><?php echo esc_html__('Overall SEO Score', 'infy-ai-image-seo-optimizer'); ?></h3>
                    <div class="infyp-score-display">
                        <div class="infyp-score-header">
                            <div class="infyp-score-percentage"></div>
                        </div>
                        <div class="infyp-score-breakdown"></div>
                    </div>
                </div>

                <!-- Alt Text Section -->
                <div class="infyp-score-field-section" data-field="alt_text">
                    <h3><?php echo esc_html__('Alt Text', 'infy-ai-image-seo-optimizer'); ?></h3>
                    <div class="infyp-score-display">
                        <div class="infyp-score-header">
                            <div class="infyp-score-percentage"></div>
                        </div>
                        <div class="infyp-score-breakdown"></div>
                    </div>
                </div>

                <!-- Filename Section -->
                <div class="infyp-score-field-section" data-field="filename">
                    <h3><?php echo esc_html__('Filename', 'infy-ai-image-seo-optimizer'); ?></h3>
                    <div class="infyp-score-display">
                        <div class="infyp-score-header">
                            <div class="infyp-score-percentage"></div>
                        </div>
                        <div class="infyp-score-breakdown"></div>
                    </div>
                </div>

                <!-- Title Section -->
                <div class="infyp-score-field-section" data-field="title">
                    <h3><?php echo esc_html__('Title', 'infy-ai-image-seo-optimizer'); ?></h3>
                    <div class="infyp-score-display">
                        <div class="infyp-score-header">
                            <div class="infyp-score-percentage"></div>
                        </div>
                        <div class="infyp-score-breakdown"></div>
                    </div>
                </div>

                <!-- Keyword Density Section -->
                <div class="infyp-score-field-section" data-field="keyword_density">
                    <h3><?php echo esc_html__('Keyword Usage', 'infy-ai-image-seo-optimizer'); ?></h3>
                    <div class="infyp-score-display">
                        <div class="infyp-score-header">
                            <div class="infyp-score-percentage"></div>
                        </div>
                        <div class="infyp-score-breakdown"></div>
                    </div>
                </div>

                <!-- Uniqueness Section -->
                <div class="infyp-score-field-section" data-field="uniqueness">
                    <h3><?php echo esc_html__('Uniqueness', 'infy-ai-image-seo-optimizer'); ?></h3>
                    <div class="infyp-score-display">
                        <div class="infyp-score-header">
                            <div class="infyp-score-percentage"></div>
                        </div>
                        <div class="infyp-score-breakdown"></div>
                    </div>
                </div>
            </div>

            <!-- Optimize Button -->
            <div class="infyp-modal-optimize-center">
                <button class="button button-primary infyp-optimize-from-modal" style="display: none;">
                    <span class="infyp-optimize-icon"></span>
                    <?php echo esc_html__('Optimize with AI', 'infy-ai-image-seo-optimizer'); ?>
                </button>
            </div>
        </div>
    </div>
</div>