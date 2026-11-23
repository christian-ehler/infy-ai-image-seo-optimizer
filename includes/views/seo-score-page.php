<?php
/**
 * SEO Score Page Template - Optimized Settings Interface
 * 
 * @package IPAIS
 * @subpackage Admin/Views
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Get current weights and parameters
$infyp_current_weights = \IPAIS\Core\INFYP_SEO_Score_Calculator::get_weights();
$infyp_scoring_params = \IPAIS\Core\INFYP_SEO_Score_Calculator::get_scoring_params();

// Calculate totals
$infyp_alt_total = ($infyp_current_weights['alt_presence'] ?? 20) + ($infyp_current_weights['alt_quality'] ?? 15);
$infyp_title_total = ($infyp_current_weights['title_presence'] ?? 15) + ($infyp_current_weights['title_quality'] ?? 10);
?>

<div class="wrap" id="infyp-seo-score-wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <div id="infyp-seo-score-notice" style="display: none;"></div>

    <?php
    /**
     * Fires before all settings cards on the Settings page.
     * Extensions can use this hook to add cards at the top.
     *
     * @since 1.0.0
     */
    do_action( 'infyp_before_settings_cards' );
    ?>

    <!-- Scoring Breakdown Section (Now First) -->
    <div class="infyp-card">
        <div class="infyp-card-header">
            <h3 class="infyp-card-title"><?php esc_html_e('SEO Scoring System', 'infy-ai-image-seo-optimizer'); ?></h3>
            <span class="infyp-badge infyp-badge-success">
                <span class="dashicons dashicons-chart-bar"></span>
                <?php esc_html_e('Based on Google Best Practices', 'infy-ai-image-seo-optimizer'); ?>
            </span>
        </div>
        <div class="infyp-card-body">
            <div class="infyp-intro-box">
                <span class="dashicons dashicons-info-outline"></span>
                <p><?php esc_html_e('Our scoring system evaluates images based on Google\'s SEO best practices. Each component contributes to a total score of 100 points:', 'infy-ai-image-seo-optimizer'); ?></p>
            </div>

            <div class="infyp-grid infyp-grid-3 infyp-gap-md infyp-info-grid">
                <div class="infyp-info-item">
                    <div class="infyp-font-semibold infyp-mb-sm"><?php esc_html_e('Filename', 'infy-ai-image-seo-optimizer'); ?></div>
                    <div>
                        <span class="infyp-badge infyp-badge-success">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <?php echo esc_html($infyp_current_weights['filename_quality'] ?? 15); ?>% <?php esc_html_e('Weight', 'infy-ai-image-seo-optimizer'); ?>
                        </span>
                        <div class="infyp-text-sm infyp-text-muted infyp-mt-xs">
                            <?php esc_html_e('SEO-friendly format with hyphens', 'infy-ai-image-seo-optimizer'); ?><br>
                            <?php esc_html_e('Optimal: 40-60 chars (3-6 words)', 'infy-ai-image-seo-optimizer'); ?>
                        </div>
                    </div>
                </div>

                <div class="infyp-info-item">
                    <div class="infyp-font-semibold infyp-mb-sm"><?php esc_html_e('Alt Text', 'infy-ai-image-seo-optimizer'); ?></div>
                    <div>
                        <span class="infyp-badge infyp-badge-success">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <?php echo esc_html($infyp_alt_total); ?>% <?php esc_html_e('Weight', 'infy-ai-image-seo-optimizer'); ?>
                        </span>
                        <div class="infyp-text-sm infyp-text-muted infyp-mt-xs">
                            <?php esc_html_e('Most important for accessibility & SEO', 'infy-ai-image-seo-optimizer'); ?><br>
                            <?php esc_html_e('Optimal: 50-125 chars (10-16 words)', 'infy-ai-image-seo-optimizer'); ?>
                        </div>
                    </div>
                </div>

                <div class="infyp-info-item">
                    <div class="infyp-font-semibold infyp-mb-sm"><?php esc_html_e('Title Text', 'infy-ai-image-seo-optimizer'); ?></div>
                    <div>
                        <span class="infyp-badge infyp-badge-success">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <?php echo esc_html($infyp_title_total); ?>% <?php esc_html_e('Weight', 'infy-ai-image-seo-optimizer'); ?>
                        </span>
                        <div class="infyp-text-sm infyp-text-muted infyp-mt-xs">
                            <?php esc_html_e('Optional tooltip text', 'infy-ai-image-seo-optimizer'); ?><br>
                            <?php esc_html_e('Optimal: 40-60 chars (6-10 words)', 'infy-ai-image-seo-optimizer'); ?>
                        </div>
                    </div>
                </div>

                <div class="infyp-info-item">
                    <div class="infyp-font-semibold infyp-mb-sm"><?php esc_html_e('Keyword Placement', 'infy-ai-image-seo-optimizer'); ?></div>
                    <div>
                        <span class="infyp-badge infyp-badge-success">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <?php echo esc_html($infyp_current_weights['keyword_density'] ?? 15); ?>% <?php esc_html_e('Weight', 'infy-ai-image-seo-optimizer'); ?>
                        </span>
                        <div class="infyp-text-sm infyp-text-muted infyp-mt-xs">
                            <?php esc_html_e('Keywords in alt, title, and filename', 'infy-ai-image-seo-optimizer'); ?><br>
                            <?php esc_html_e('Improves SEO relevance', 'infy-ai-image-seo-optimizer'); ?>
                        </div>
                    </div>
                </div>

                <div class="infyp-info-item">
                    <div class="infyp-font-semibold infyp-mb-sm"><?php esc_html_e('Content Uniqueness', 'infy-ai-image-seo-optimizer'); ?></div>
                    <div>
                        <span class="infyp-badge infyp-badge-success">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <?php echo esc_html($infyp_current_weights['uniqueness'] ?? 10); ?>% <?php esc_html_e('Weight', 'infy-ai-image-seo-optimizer'); ?>
                        </span>
                        <div class="infyp-text-sm infyp-text-muted infyp-mt-xs">
                            <?php esc_html_e('Non-duplicate content', 'infy-ai-image-seo-optimizer'); ?><br>
                            <?php esc_html_e('Avoids SEO penalties', 'infy-ai-image-seo-optimizer'); ?>
                        </div>
                    </div>
                </div>

                <div class="infyp-info-item">
                    <div class="infyp-font-semibold infyp-mb-sm"><?php esc_html_e('Scoring Criteria', 'infy-ai-image-seo-optimizer'); ?></div>
                    <div>
                        <span class="infyp-badge infyp-badge-warning">
                            <span class="dashicons dashicons-info"></span>
                            <?php esc_html_e('How We Score', 'infy-ai-image-seo-optimizer'); ?>
                        </span>
                        <div class="infyp-text-sm infyp-text-muted infyp-mt-xs">
                            <strong><?php esc_html_e('Presence:', 'infy-ai-image-seo-optimizer'); ?></strong> <?php esc_html_e('Base points for having the attribute', 'infy-ai-image-seo-optimizer'); ?><br>
                            <strong><?php esc_html_e('Quality:', 'infy-ai-image-seo-optimizer'); ?></strong> <?php esc_html_e('Points for optimal length & relevance', 'infy-ai-image-seo-optimizer'); ?><br>
                            <strong><?php esc_html_e('Tolerance:', 'infy-ai-image-seo-optimizer'); ?></strong> <?php esc_html_e('Length values slightly outside the optimal range still earn full points and a green badge. Adjust the play room per field below; set it to 0 for strict scoring. Changes apply as scores are recalculated.', 'infy-ai-image-seo-optimizer'); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modern Configuration Section -->
    <div class="infyp-card infyp-config-card">
        <div class="infyp-card-header">
            <h3 class="infyp-card-title"><?php esc_html_e('SEO Configuration & Parameters', 'infy-ai-image-seo-optimizer'); ?></h3>
            <span class="infyp-badge infyp-badge-warning">
                <span class="dashicons dashicons-admin-generic"></span>
                <?php esc_html_e('Customize Scoring System', 'infy-ai-image-seo-optimizer'); ?>
            </span>
        </div>
        <div class="infyp-card-body">
            <form id="infyp-seo-config-form" method="post">
                <?php wp_nonce_field('infyp_save_seo_config', 'infyp_seo_config_nonce'); ?>
                
                <!-- Weight Bars Grid with Total Display -->
                <div class="infyp-weights-grid-container">
                    <div class="infyp-weights-grid">
                    <!-- Alt Text -->
                    <div class="infyp-weight-card" data-weight-key="alt">
                        <div class="infyp-weight-header">
                            <span class="infyp-weight-name"><?php esc_html_e('Alt Text', 'infy-ai-image-seo-optimizer'); ?></span>
                            <span class="infyp-weight-percent" id="alt-weight-display"><?php echo esc_html($infyp_alt_total); ?>%</span>
                        </div>
                        <div class="infyp-visual-bar" data-weight="alt">
                            <div class="infyp-bar-fill" style="width: <?php echo esc_attr($infyp_alt_total); ?>%;" data-value="<?php echo esc_attr($infyp_alt_total); ?>">
                                <span class="infyp-bar-value"><?php echo esc_html($infyp_alt_total); ?>%</span>
                            </div>
                        </div>
                        <input type="hidden" name="weights[alt_total]" id="alt-weight-input" value="<?php echo esc_attr($infyp_alt_total); ?>" />
                        <div class="infyp-sub-weights">
                            <div class="infyp-sub-weight">
                                <label><?php esc_html_e('Presence', 'infy-ai-image-seo-optimizer'); ?></label>
                                <input type="number" name="weights[alt_presence]" 
                                       value="<?php echo esc_attr($infyp_current_weights['alt_presence'] ?? 20); ?>" 
                                       min="0" max="<?php echo esc_attr($infyp_alt_total); ?>" class="infyp-sub-input" />
                            </div>
                            <div class="infyp-sub-weight">
                                <label><?php esc_html_e('Quality', 'infy-ai-image-seo-optimizer'); ?></label>
                                <input type="number" name="weights[alt_quality]" 
                                       value="<?php echo esc_attr($infyp_current_weights['alt_quality'] ?? 15); ?>" 
                                       min="0" max="<?php echo esc_attr($infyp_alt_total); ?>" class="infyp-sub-input" />
                            </div>
                        </div>
                        <details class="infyp-params-details">
                            <summary><?php esc_html_e('Optimal Parameters', 'infy-ai-image-seo-optimizer'); ?></summary>
                            <div class="infyp-params-content" data-field="alt_text" data-avg-word-length="6">
                                <div class="infyp-param-row infyp-auto-link-row">
                                    <input type="hidden" name="params[alt_text][auto_link]" value="0" />
                                    <label class="infyp-auto-link-label">
                                        <input type="checkbox" name="params[alt_text][auto_link]" value="1" class="infyp-auto-link-toggle" data-field="alt_text" <?php checked( ! empty( $infyp_scoring_params['alt_text']['auto_link'] ?? 1 ) ); ?> />
                                        <?php esc_html_e('Auto-link characters and words', 'infy-ai-image-seo-optimizer'); ?>
                                        <span class="infyp-auto-link-hint" title="<?php esc_attr_e('When linked, editing one input updates the other proportionally so the verdict stays consistent.', 'infy-ai-image-seo-optimizer'); ?>">?</span>
                                    </label>
                                </div>
                                <div class="infyp-param-row">
                                    <label><?php esc_html_e('Characters:', 'infy-ai-image-seo-optimizer'); ?></label>
                                    <input type="number" name="params[alt_text][min_chars]" value="<?php echo esc_attr($infyp_scoring_params['alt_text']['min_chars'] ?? 50); ?>" min="1" max="200" data-field="alt_text" data-bound="min_chars" class="infyp-threshold-input" />
                                    <span>-</span>
                                    <input type="number" name="params[alt_text][max_chars]" value="<?php echo esc_attr($infyp_scoring_params['alt_text']['max_chars'] ?? 125); ?>" min="1" max="300" data-field="alt_text" data-bound="max_chars" class="infyp-threshold-input" />
                                </div>
                                <div class="infyp-param-row">
                                    <label><?php esc_html_e('Words:', 'infy-ai-image-seo-optimizer'); ?></label>
                                    <input type="number" name="params[alt_text][min_words]" value="<?php echo esc_attr($infyp_scoring_params['alt_text']['min_words'] ?? 10); ?>" min="1" max="50" data-field="alt_text" data-bound="min_words" class="infyp-threshold-input" />
                                    <span>-</span>
                                    <input type="number" name="params[alt_text][max_words]" value="<?php echo esc_attr($infyp_scoring_params['alt_text']['max_words'] ?? 16); ?>" min="1" max="50" data-field="alt_text" data-bound="max_words" class="infyp-threshold-input" />
                                </div>
                                <div class="infyp-param-row infyp-tolerance-row">
                                    <label><?php esc_html_e('Tolerance:', 'infy-ai-image-seo-optimizer'); ?></label>
                                    <input type="number" name="params[alt_text][tolerance_words]" value="<?php echo esc_attr($infyp_scoring_params['alt_text']['tolerance_words'] ?? 1); ?>" min="0" max="5" class="infyp-threshold-input" />
                                    <span><?php esc_html_e('± words', 'infy-ai-image-seo-optimizer'); ?></span>
                                    <input type="number" name="params[alt_text][tolerance_chars_pct]" value="<?php echo esc_attr($infyp_scoring_params['alt_text']['tolerance_chars_pct'] ?? 15); ?>" min="0" max="50" class="infyp-threshold-input" />
                                    <span><?php esc_html_e('± % chars', 'infy-ai-image-seo-optimizer'); ?></span>
                                    <span class="infyp-auto-link-hint" title="<?php esc_attr_e('Play room outside the optimal range before a length warning appears. Within it, fields keep the full score. 0 = strict.', 'infy-ai-image-seo-optimizer'); ?>">?</span>
                                </div>
                            </div>
                        </details>
                    </div>

                    <!-- Filename -->
                    <div class="infyp-weight-card" data-weight-key="filename">
                        <div class="infyp-weight-header">
                            <span class="infyp-weight-name"><?php esc_html_e('Filename', 'infy-ai-image-seo-optimizer'); ?></span>
                            <span class="infyp-weight-percent" id="filename-weight-display"><?php echo esc_html($infyp_current_weights['filename_quality'] ?? 15); ?>%</span>
                        </div>
                        <div class="infyp-visual-bar" data-weight="filename">
                            <div class="infyp-bar-fill" style="width: <?php echo esc_attr($infyp_current_weights['filename_quality'] ?? 15); ?>%;" data-value="<?php echo esc_attr($infyp_current_weights['filename_quality'] ?? 15); ?>">
                                <span class="infyp-bar-value"><?php echo esc_html($infyp_current_weights['filename_quality'] ?? 15); ?>%</span>
                            </div>
                        </div>
                        <input type="hidden" name="weights[filename_quality]" id="filename-weight-input" value="<?php echo esc_attr($infyp_current_weights['filename_quality'] ?? 15); ?>" />
                        <details class="infyp-params-details">
                            <summary><?php esc_html_e('Optimal Parameters', 'infy-ai-image-seo-optimizer'); ?></summary>
                            <div class="infyp-params-content" data-field="filename" data-avg-word-length="6">
                                <div class="infyp-param-row infyp-auto-link-row">
                                    <input type="hidden" name="params[filename][auto_link]" value="0" />
                                    <label class="infyp-auto-link-label">
                                        <input type="checkbox" name="params[filename][auto_link]" value="1" class="infyp-auto-link-toggle" data-field="filename" <?php checked( ! empty( $infyp_scoring_params['filename']['auto_link'] ?? 1 ) ); ?> />
                                        <?php esc_html_e('Auto-link characters and words', 'infy-ai-image-seo-optimizer'); ?>
                                        <span class="infyp-auto-link-hint" title="<?php esc_attr_e('When linked, editing one input updates the other proportionally so the verdict stays consistent.', 'infy-ai-image-seo-optimizer'); ?>">?</span>
                                    </label>
                                </div>
                                <div class="infyp-param-row">
                                    <label><?php esc_html_e('Characters:', 'infy-ai-image-seo-optimizer'); ?></label>
                                    <input type="number" name="params[filename][min_chars]" value="<?php echo esc_attr($infyp_scoring_params['filename']['min_chars'] ?? 40); ?>" min="1" max="100" data-field="filename" data-bound="min_chars" class="infyp-threshold-input" />
                                    <span>-</span>
                                    <input type="number" name="params[filename][max_chars]" value="<?php echo esc_attr($infyp_scoring_params['filename']['max_chars'] ?? 60); ?>" min="1" max="150" data-field="filename" data-bound="max_chars" class="infyp-threshold-input" />
                                </div>
                                <div class="infyp-param-row">
                                    <label><?php esc_html_e('Words:', 'infy-ai-image-seo-optimizer'); ?></label>
                                    <input type="number" name="params[filename][min_words]" value="<?php echo esc_attr($infyp_scoring_params['filename']['min_words'] ?? 3); ?>" min="1" max="20" data-field="filename" data-bound="min_words" class="infyp-threshold-input" />
                                    <span>-</span>
                                    <input type="number" name="params[filename][max_words]" value="<?php echo esc_attr($infyp_scoring_params['filename']['max_words'] ?? 6); ?>" min="1" max="20" data-field="filename" data-bound="max_words" class="infyp-threshold-input" />
                                </div>
                                <div class="infyp-param-row infyp-tolerance-row">
                                    <label><?php esc_html_e('Tolerance:', 'infy-ai-image-seo-optimizer'); ?></label>
                                    <input type="number" name="params[filename][tolerance_words]" value="<?php echo esc_attr($infyp_scoring_params['filename']['tolerance_words'] ?? 1); ?>" min="0" max="5" class="infyp-threshold-input" />
                                    <span><?php esc_html_e('± words', 'infy-ai-image-seo-optimizer'); ?></span>
                                    <input type="number" name="params[filename][tolerance_chars_pct]" value="<?php echo esc_attr($infyp_scoring_params['filename']['tolerance_chars_pct'] ?? 15); ?>" min="0" max="50" class="infyp-threshold-input" />
                                    <span><?php esc_html_e('± % chars', 'infy-ai-image-seo-optimizer'); ?></span>
                                    <span class="infyp-auto-link-hint" title="<?php esc_attr_e('Play room outside the optimal range before a length warning appears. Within it, fields keep the full score. 0 = strict.', 'infy-ai-image-seo-optimizer'); ?>">?</span>
                                </div>
                            </div>
                        </details>
                    </div>

                    <!-- Title -->
                    <div class="infyp-weight-card" data-weight-key="title">
                        <div class="infyp-weight-header">
                            <span class="infyp-weight-name"><?php esc_html_e('Title Text', 'infy-ai-image-seo-optimizer'); ?></span>
                            <span class="infyp-weight-percent" id="title-weight-display"><?php echo esc_html($infyp_title_total); ?>%</span>
                        </div>
                        <div class="infyp-visual-bar" data-weight="title">
                            <div class="infyp-bar-fill" style="width: <?php echo esc_attr($infyp_title_total); ?>%;" data-value="<?php echo esc_attr($infyp_title_total); ?>">
                                <span class="infyp-bar-value"><?php echo esc_html($infyp_title_total); ?>%</span>
                            </div>
                        </div>
                        <input type="hidden" name="weights[title_total]" id="title-weight-input" value="<?php echo esc_attr($infyp_title_total); ?>" />
                        <div class="infyp-sub-weights">
                            <div class="infyp-sub-weight">
                                <label><?php esc_html_e('Presence', 'infy-ai-image-seo-optimizer'); ?></label>
                                <input type="number" name="weights[title_presence]" 
                                       value="<?php echo esc_attr($infyp_current_weights['title_presence'] ?? 15); ?>" 
                                       min="0" max="<?php echo esc_attr($infyp_title_total); ?>" class="infyp-sub-input" />
                            </div>
                            <div class="infyp-sub-weight">
                                <label><?php esc_html_e('Quality', 'infy-ai-image-seo-optimizer'); ?></label>
                                <input type="number" name="weights[title_quality]" 
                                       value="<?php echo esc_attr($infyp_current_weights['title_quality'] ?? 10); ?>" 
                                       min="0" max="<?php echo esc_attr($infyp_title_total); ?>" class="infyp-sub-input" />
                            </div>
                        </div>
                        <details class="infyp-params-details">
                            <summary><?php esc_html_e('Optimal Parameters', 'infy-ai-image-seo-optimizer'); ?></summary>
                            <div class="infyp-params-content" data-field="title" data-avg-word-length="6">
                                <div class="infyp-param-row infyp-auto-link-row">
                                    <input type="hidden" name="params[title][auto_link]" value="0" />
                                    <label class="infyp-auto-link-label">
                                        <input type="checkbox" name="params[title][auto_link]" value="1" class="infyp-auto-link-toggle" data-field="title" <?php checked( ! empty( $infyp_scoring_params['title']['auto_link'] ?? 1 ) ); ?> />
                                        <?php esc_html_e('Auto-link characters and words', 'infy-ai-image-seo-optimizer'); ?>
                                        <span class="infyp-auto-link-hint" title="<?php esc_attr_e('When linked, editing one input updates the other proportionally so the verdict stays consistent.', 'infy-ai-image-seo-optimizer'); ?>">?</span>
                                    </label>
                                </div>
                                <div class="infyp-param-row">
                                    <label><?php esc_html_e('Characters:', 'infy-ai-image-seo-optimizer'); ?></label>
                                    <input type="number" name="params[title][min_chars]" value="<?php echo esc_attr($infyp_scoring_params['title']['min_chars'] ?? 40); ?>" min="1" max="100" data-field="title" data-bound="min_chars" class="infyp-threshold-input" />
                                    <span>-</span>
                                    <input type="number" name="params[title][max_chars]" value="<?php echo esc_attr($infyp_scoring_params['title']['max_chars'] ?? 60); ?>" min="1" max="150" data-field="title" data-bound="max_chars" class="infyp-threshold-input" />
                                </div>
                                <div class="infyp-param-row">
                                    <label><?php esc_html_e('Words:', 'infy-ai-image-seo-optimizer'); ?></label>
                                    <input type="number" name="params[title][min_words]" value="<?php echo esc_attr($infyp_scoring_params['title']['min_words'] ?? 6); ?>" min="1" max="30" data-field="title" data-bound="min_words" class="infyp-threshold-input" />
                                    <span>-</span>
                                    <input type="number" name="params[title][max_words]" value="<?php echo esc_attr($infyp_scoring_params['title']['max_words'] ?? 10); ?>" min="1" max="30" data-field="title" data-bound="max_words" class="infyp-threshold-input" />
                                </div>
                                <div class="infyp-param-row infyp-tolerance-row">
                                    <label><?php esc_html_e('Tolerance:', 'infy-ai-image-seo-optimizer'); ?></label>
                                    <input type="number" name="params[title][tolerance_words]" value="<?php echo esc_attr($infyp_scoring_params['title']['tolerance_words'] ?? 1); ?>" min="0" max="5" class="infyp-threshold-input" />
                                    <span><?php esc_html_e('± words', 'infy-ai-image-seo-optimizer'); ?></span>
                                    <input type="number" name="params[title][tolerance_chars_pct]" value="<?php echo esc_attr($infyp_scoring_params['title']['tolerance_chars_pct'] ?? 15); ?>" min="0" max="50" class="infyp-threshold-input" />
                                    <span><?php esc_html_e('± % chars', 'infy-ai-image-seo-optimizer'); ?></span>
                                    <span class="infyp-auto-link-hint" title="<?php esc_attr_e('Play room outside the optimal range before a length warning appears. Within it, fields keep the full score. 0 = strict.', 'infy-ai-image-seo-optimizer'); ?>">?</span>
                                </div>
                            </div>
                        </details>
                    </div>
                    
                    <!-- Keywords -->
                    <div class="infyp-weight-card" data-weight-key="keywords">
                        <div class="infyp-weight-header">
                            <span class="infyp-weight-name"><?php esc_html_e('Keyword Placement', 'infy-ai-image-seo-optimizer'); ?></span>
                            <span class="infyp-weight-percent" id="keywords-weight-display"><?php echo esc_html($infyp_current_weights['keyword_density'] ?? 15); ?>%</span>
                        </div>
                        <div class="infyp-visual-bar" data-weight="keywords">
                            <div class="infyp-bar-fill" style="width: <?php echo esc_attr($infyp_current_weights['keyword_density'] ?? 15); ?>%;" data-value="<?php echo esc_attr($infyp_current_weights['keyword_density'] ?? 15); ?>">
                                <span class="infyp-bar-value"><?php echo esc_html($infyp_current_weights['keyword_density'] ?? 15); ?>%</span>
                            </div>
                        </div>
                        <input type="hidden" name="weights[keyword_density]" id="keywords-weight-input" value="<?php echo esc_attr($infyp_current_weights['keyword_density'] ?? 15); ?>" />
                    </div>
                    
                    <!-- Uniqueness -->
                    <div class="infyp-weight-card" data-weight-key="uniqueness">
                        <div class="infyp-weight-header">
                            <span class="infyp-weight-name"><?php esc_html_e('Content Uniqueness', 'infy-ai-image-seo-optimizer'); ?></span>
                            <span class="infyp-weight-percent" id="uniqueness-weight-display"><?php echo esc_html($infyp_current_weights['uniqueness'] ?? 10); ?>%</span>
                        </div>
                        <div class="infyp-visual-bar" data-weight="uniqueness">
                            <div class="infyp-bar-fill" style="width: <?php echo esc_attr($infyp_current_weights['uniqueness'] ?? 10); ?>%;" data-value="<?php echo esc_attr($infyp_current_weights['uniqueness'] ?? 10); ?>">
                                <span class="infyp-bar-value"><?php echo esc_html($infyp_current_weights['uniqueness'] ?? 10); ?>%</span>
                            </div>
                        </div>
                        <input type="hidden" name="weights[uniqueness]" id="uniqueness-weight-input" value="<?php echo esc_attr($infyp_current_weights['uniqueness'] ?? 10); ?>" />
                    </div>
                    
                    <!-- Total Weight Display - Now as 6th item in grid -->
                    <div class="infyp-total-display-card">
                        <div class="infyp-circular-progress">
                            <svg width="80" height="80">
                                <circle cx="40" cy="40" r="36" stroke="#e0e0e0" stroke-width="6" fill="none"/>
                                <circle cx="40" cy="40" r="36" stroke="var(--infyp-success-secondary)" stroke-width="6" fill="none"
                                        stroke-dasharray="226.19" stroke-dashoffset="0"
                                        stroke-linecap="round" id="infyp-progress-circle"/>
                            </svg>
                            <div class="infyp-progress-value optimized" id="infyp-total-value">100%</div>
                        </div>
                        <h4><?php esc_html_e('Total Weight', 'infy-ai-image-seo-optimizer'); ?></h4>
                        <p class="description"><?php esc_html_e('Must equal 100%', 'infy-ai-image-seo-optimizer'); ?></p>
                        <div id="infyp-total-notice" class="infyp-total-notice" style="display:none;"></div>
                    </div>
                </div>
                </div>
                
                <!-- Actions -->
                <div class="infyp-config-actions">
                    <button type="submit" class="button button-primary" id="infyp-save-config">
                        <?php esc_html_e('Save Configuration', 'infy-ai-image-seo-optimizer'); ?>
                    </button>
                    <button type="button" class="button" id="infyp-reset-config">
                        <?php esc_html_e('Reset to Defaults', 'infy-ai-image-seo-optimizer'); ?>
                    </button>
                    <span id="infyp-save-status" class="infyp-save-status"></span>
                </div>
            </form>
        </div>
    </div>
    
    <!-- AI Models Info Section -->
    <div class="infyp-card">
        <div class="infyp-card-header">
            <h3 class="infyp-card-title"><?php esc_html_e('Why These Models?', 'infy-ai-image-seo-optimizer'); ?></h3>
            <span class="infyp-badge infyp-badge-success">
                <span class="dashicons dashicons-welcome-learn-more"></span>
                <?php esc_html_e('AI Model Selection', 'infy-ai-image-seo-optimizer'); ?>
            </span>
        </div>
        <div class="infyp-card-body">
            <div class="infyp-intro-box">
                <span class="dashicons dashicons-info-outline"></span>
                <p><?php esc_html_e('We tested about ten AI vision models on the same set of test images for accuracy, speed and cost. The models offered here returned a valid result for every test image, and every provider has at least one, so you can use whichever API key you have.', 'infy-ai-image-seo-optimizer'); ?></p>
            </div>
            <details class="infyp-model-cost-details">
                <summary><?php esc_html_e('View cost comparison per image', 'infy-ai-image-seo-optimizer'); ?></summary>
                <table class="infyp-model-cost-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Model', 'infy-ai-image-seo-optimizer'); ?></th>
                            <th><?php esc_html_e('Provider', 'infy-ai-image-seo-optimizer'); ?></th>
                            <th><?php esc_html_e('Input $/1M tokens', 'infy-ai-image-seo-optimizer'); ?></th>
                            <th><?php esc_html_e('Output $/1M tokens', 'infy-ai-image-seo-optimizer'); ?></th>
                            <th><?php esc_html_e('Est. Cost/Image', 'infy-ai-image-seo-optimizer'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // One row per provider: the model the plugin starts with for
                        // that provider, with prices from the model registry.
                        $infyp_model_registry = \IPAIS\Core\AI\INFYP_Model_Config_Registry::get_instance();
                        foreach ( [ 'google', 'openai', 'anthropic' ] as $infyp_cost_provider ) :
                            $infyp_cost_config = $infyp_model_registry->get_config( $infyp_model_registry->get_default_model_for_provider( $infyp_cost_provider ) );
                            if ( null === $infyp_cost_config ) {
                                continue;
                            }
                            $infyp_cost_pricing = (array) $infyp_cost_config['pricing'];
                            ?>
                        <tr>
                            <td><?php echo esc_html( $infyp_cost_config['display_name'] ); ?></td>
                            <td><?php echo esc_html( $infyp_cost_config['group'] ); ?></td>
                            <td><?php echo isset( $infyp_cost_pricing['input_per_million'] ) ? esc_html( '$' . number_format_i18n( (float) $infyp_cost_pricing['input_per_million'], 2 ) ) : '&mdash;'; ?></td>
                            <td><?php echo isset( $infyp_cost_pricing['output_per_million'] ) ? esc_html( '$' . number_format_i18n( (float) $infyp_cost_pricing['output_per_million'], 2 ) ) : '&mdash;'; ?></td>
                            <td class="infyp-cost-highlight"><?php echo isset( $infyp_cost_pricing['measured_per_image'] ) ? esc_html( '~$' . number_format_i18n( (float) $infyp_cost_pricing['measured_per_image'], 4 ) ) : '&mdash;'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description"><?php esc_html_e('Est. Cost/Image: measured in November 2026 on 20 test images, for a full optimization (keywords, filename, alt text and title). You pay the provider directly, and prices change.', 'infy-ai-image-seo-optimizer'); ?></p>
            </details>
        </div>
    </div>

    <!-- Comprehensive Summary Section -->
    <div class="infyp-card">
        <div class="infyp-card-header">
            <h3 class="infyp-card-title"><?php esc_html_e('Image SEO Best Practices Summary', 'infy-ai-image-seo-optimizer'); ?></h3>
        </div>
        <div class="infyp-card-body">
            <div class="infyp-intro-box">
                <span class="dashicons dashicons-info-outline"></span>
                <p><?php esc_html_e('Our comprehensive research has identified these key optimization principles based on the latest search engine guidelines:', 'infy-ai-image-seo-optimizer'); ?></p>
            </div>

            <div class="infyp-best-practices-grid">
                <div class="infyp-practice-item">
                    <h4><?php esc_html_e('Alt Text (Most Important)', 'infy-ai-image-seo-optimizer'); ?></h4>
                    <ul>
                        <li><?php esc_html_e('Primary ranking factor for Google Images', 'infy-ai-image-seo-optimizer'); ?></li>
                        <li><?php esc_html_e('Keep concise: 50-125 characters (10-16 words)', 'infy-ai-image-seo-optimizer'); ?></li>
                        <li><?php esc_html_e('Describe images for users, not search engines', 'infy-ai-image-seo-optimizer'); ?></li>
                        <li><?php esc_html_e('Include relevant keywords naturally, avoid stuffing', 'infy-ai-image-seo-optimizer'); ?></li>
                        <li><?php esc_html_e('Don\'t use "image of" or "picture of" prefixes', 'infy-ai-image-seo-optimizer'); ?></li>
                    </ul>
                </div>
                
                <div class="infyp-practice-item">
                    <h4><?php esc_html_e('Filenames', 'infy-ai-image-seo-optimizer'); ?></h4>
                    <ul>
                        <li><?php esc_html_e('Use descriptive, keyword-rich names', 'infy-ai-image-seo-optimizer'); ?></li>
                        <li><?php esc_html_e('Optimal: 40-60 characters (3-6 words)', 'infy-ai-image-seo-optimizer'); ?></li>
                        <li><?php esc_html_e('Use hyphens to separate words, not underscores', 'infy-ai-image-seo-optimizer'); ?></li>
                        <li><?php esc_html_e('Lowercase letters only', 'infy-ai-image-seo-optimizer'); ?></li>
                        <li><?php esc_html_e('Avoid generic names (DSC, IMG, image + numbers)', 'infy-ai-image-seo-optimizer'); ?></li>
                    </ul>
                </div>
                
                <div class="infyp-practice-item">
                    <h4><?php esc_html_e('Title Attributes', 'infy-ai-image-seo-optimizer'); ?></h4>
                    <ul>
                        <li><?php esc_html_e('Minimal SEO impact per Google\'s John Mueller', 'infy-ai-image-seo-optimizer'); ?></li>
                        <li><?php esc_html_e('Use for user experience (tooltip on hover)', 'infy-ai-image-seo-optimizer'); ?></li>
                        <li><?php esc_html_e('Keep shorter than alt text: 40-60 characters', 'infy-ai-image-seo-optimizer'); ?></li>
                        <li><?php esc_html_e('Don\'t duplicate alt text content', 'infy-ai-image-seo-optimizer'); ?></li>
                        <li><?php esc_html_e('Optional but recommended for completeness', 'infy-ai-image-seo-optimizer'); ?></li>
                    </ul>
                </div>
                
                <div class="infyp-practice-item">
                    <h4><?php esc_html_e('Technical Best Practices', 'infy-ai-image-seo-optimizer'); ?></h4>
                    <ul>
                        <li><?php esc_html_e('Use consistent URLs for the same image across your site', 'infy-ai-image-seo-optimizer'); ?></li>
                        <li><?php esc_html_e('Saves crawl budget and improves indexing efficiency', 'infy-ai-image-seo-optimizer'); ?></li>
                        <li><?php esc_html_e('Context matters: surrounding text affects image relevance', 'infy-ai-image-seo-optimizer'); ?></li>
                        <li><?php esc_html_e('Write unique alt text for each image\'s specific context', 'infy-ai-image-seo-optimizer'); ?></li>
                        <li><?php esc_html_e('Avoid AI-generated generic descriptions', 'infy-ai-image-seo-optimizer'); ?></li>
                    </ul>
                </div>
            </div>
            
            <div class="infyp-notice infyp-notice-info">
                <p><strong><?php esc_html_e('2024-2025 Updates:', 'infy-ai-image-seo-optimizer'); ?></strong> <?php esc_html_e('Google emphasizes a "people-first" approach - describe images naturally for users while incorporating relevant keywords. The algorithm rewards useful, information-rich content over keyword-stuffed metadata.', 'infy-ai-image-seo-optimizer'); ?></p>
            </div>
        </div>
    </div>

    <!-- References Section -->
    <div class="infyp-card">
        <div class="infyp-card-header">
            <h3 class="infyp-card-title"><?php esc_html_e('References & Sources', 'infy-ai-image-seo-optimizer'); ?></h3>
            <span class="infyp-badge infyp-badge-success">
                <span class="dashicons dashicons-book-alt"></span>
                <?php esc_html_e('Research-Based Guidelines', 'infy-ai-image-seo-optimizer'); ?>
            </span>
        </div>
        <div class="infyp-card-body">
            <div class="infyp-intro-box">
                <span class="dashicons dashicons-info-outline"></span>
                <p><?php esc_html_e('Our comprehensive guidelines are based on extensive research from these authoritative sources:', 'infy-ai-image-seo-optimizer'); ?></p>
            </div>

            <div class="infyp-references-section">
                <h4><?php esc_html_e('Google Official Documentation', 'infy-ai-image-seo-optimizer'); ?></h4>
                <ul class="infyp-references">
                    <li>
                        <strong><?php esc_html_e('Google Search Central - Image SEO Best Practices', 'infy-ai-image-seo-optimizer'); ?></strong>
                        <p class="description"><?php esc_html_e('Updated July 11, 2025 - Official guidance on descriptive filenames, alt text as the most important attribute, and avoiding keyword stuffing. Emphasizes describing images for users with a "people-first" approach.', 'infy-ai-image-seo-optimizer'); ?></p>
                        <a href="https://developers.google.com/search/docs/appearance/google-images" target="_blank" rel="noopener" class="infyp-source-badge">
                            developers.google.com
                            <span class="dashicons dashicons-external"></span>
                        </a>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Google Consistent Image URLs Guidance', 'infy-ai-image-seo-optimizer'); ?></strong>
                        <p class="description"><?php esc_html_e('May 12, 2025 update - Use the same image URL wherever the same image appears to save crawl budget and improve indexing efficiency.', 'infy-ai-image-seo-optimizer'); ?></p>
                        <a href="https://www.searchenginejournal.com/google-updates-image-seo-best-practices-use-consistent-urls/546552/" target="_blank" rel="noopener" class="infyp-source-badge">
                            searchenginejournal.com
                            <span class="dashicons dashicons-external"></span>
                        </a>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Google Shows How To Use Alt Text For SEO', 'infy-ai-image-seo-optimizer'); ?></strong>
                        <p class="description"><?php esc_html_e('Sep 23, 2024 - John Mueller confirms alt text remains crucial even with AI vision. Context-specific alt text helps Google understand why an image is used on a page.', 'infy-ai-image-seo-optimizer'); ?></p>
                        <a href="https://www.searchenginejournal.com/google-shows-how-to-use-alt-text-for-seo/527871/" target="_blank" rel="noopener" class="infyp-source-badge">
                            searchenginejournal.com
                            <span class="dashicons dashicons-external"></span>
                        </a>
                    </li>
                </ul>
                
                <h4><?php esc_html_e('Industry Expert Guidelines', 'infy-ai-image-seo-optimizer'); ?></h4>
                <ul class="infyp-references">
                    <li>
                        <strong><?php esc_html_e('Ahrefs - Comprehensive On-Page SEO Checklist 2024', 'infy-ai-image-seo-optimizer'); ?></strong>
                        <p class="description"><?php esc_html_e('Mar 28, 2024 - Emphasizes short, descriptive, relevant filenames and alt text. Aligns with Google\'s guidance on using appropriate keywords naturally.', 'infy-ai-image-seo-optimizer'); ?></p>
                        <a href="https://ahrefs.com/blog/on-page-seo-checklist/" target="_blank" rel="noopener" class="infyp-source-badge">
                            ahrefs.com
                            <span class="dashicons dashicons-external"></span>
                        </a>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Search Engine Journal - 12 Important Image SEO Tips', 'infy-ai-image-seo-optimizer'); ?></strong>
                        <p class="description"><?php esc_html_e('Aug 30, 2024 - Comprehensive guide covering descriptive filenames, SEO-friendly alt text, and balancing keywords with genuine description. Warns against keyword stuffing.', 'infy-ai-image-seo-optimizer'); ?></p>
                        <a href="https://www.searchenginejournal.com/on-page-seo/image-optimization/" target="_blank" rel="noopener" class="infyp-source-badge">
                            searchenginejournal.com
                            <span class="dashicons dashicons-external"></span>
                        </a>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Semrush - What Is Alt Text? Why It Matters & How to Write It', 'infy-ai-image-seo-optimizer'); ?></strong>
                        <p class="description"><?php esc_html_e('Jul 14, 2025 - Detailed guide with SEO and accessibility perspectives. Recommends under 125 characters, being descriptive and specific, including relevant keywords naturally.', 'infy-ai-image-seo-optimizer'); ?></p>
                        <a href="https://www.semrush.com/blog/alt-text/" target="_blank" rel="noopener" class="infyp-source-badge">
                            semrush.com
                            <span class="dashicons dashicons-external"></span>
                        </a>
                    </li>
                </ul>
                
            </div>
            
            <div class="infyp-notice infyp-notice-info infyp-mt-lg">
                <p><strong><?php esc_html_e('Note:', 'infy-ai-image-seo-optimizer'); ?></strong> <?php esc_html_e('All sources have been verified and are current as of 2024-2025. Our plugin\'s scoring algorithm and recommendations are continuously updated based on the latest search engine guidelines.', 'infy-ai-image-seo-optimizer'); ?></p>
            </div>
        </div>
    </div>

    <!-- Banner Management Section -->
    <div class="infyp-card infyp-config-card">
        <div class="infyp-card-header">
            <h3 class="infyp-card-title"><?php esc_html_e('Banner & Notice Management', 'infy-ai-image-seo-optimizer'); ?></h3>
            <span class="infyp-badge infyp-badge-warning">
                <span class="dashicons dashicons-visibility"></span>
                <?php esc_html_e('Control Plugin Notifications', 'infy-ai-image-seo-optimizer'); ?>
            </span>
        </div>
        <div class="infyp-card-body">
            <p class="infyp-banner-intro"><?php esc_html_e('Manage the visibility of promotional banners, notices, and call-to-action cards throughout the plugin.', 'infy-ai-image-seo-optimizer'); ?></p>

            <div class="infyp-banner-management-grid">
                <!-- Reset Banners Option -->
                <div class="infyp-banner-option">
                    <div class="infyp-banner-option-header">
                        <span class="dashicons dashicons-update-alt"></span>
                        <h4><?php esc_html_e('Reset All Dismissed Banners', 'infy-ai-image-seo-optimizer'); ?></h4>
                    </div>
                    <p class="description"><?php esc_html_e('Show all previously dismissed banners again, including compression recommendations and API key reminders.', 'infy-ai-image-seo-optimizer'); ?></p>
                    <button type="button" class="button button-secondary" id="infyp-reset-banners">
                        <?php esc_html_e('Reset All Banners', 'infy-ai-image-seo-optimizer'); ?>
                    </button>
                    <span id="infyp-reset-banners-status" class="infyp-action-status"></span>
                </div>

                <!-- Hide Banners Permanently Option -->
                <div class="infyp-banner-option">
                    <div class="infyp-banner-option-header">
                        <span class="dashicons dashicons-hidden"></span>
                        <h4><?php esc_html_e('Hide All Banners Permanently', 'infy-ai-image-seo-optimizer'); ?></h4>
                    </div>
                    <p class="description"><?php esc_html_e('Permanently hide all promotional banners and CTAs. They will not reappear until you enable them again.', 'infy-ai-image-seo-optimizer'); ?></p>
                    <?php
                    $infyp_banners_hidden = get_user_meta(get_current_user_id(), 'infyp_hide_all_banners', true);
                    ?>
                    <div class="infyp-toggle-with-label">
                        <span class="infyp-toggle-label"><?php esc_html_e('Visible', 'infy-ai-image-seo-optimizer'); ?></span>
                        <label class="infyp-toggle-switch">
                            <input type="checkbox" id="infyp-hide-all-banners" <?php checked($infyp_banners_hidden, '1'); ?>>
                            <span class="infyp-toggle-slider"></span>
                        </label>
                        <span class="infyp-toggle-label"><?php esc_html_e('Hidden', 'infy-ai-image-seo-optimizer'); ?></span>
                    </div>
                    <span id="infyp-hide-banners-status" class="infyp-action-status"></span>
                </div>
            </div>

            <div class="infyp-notice infyp-notice-info infyp-mt-lg">
                <p><strong><?php esc_html_e('Affected Banners:', 'infy-ai-image-seo-optimizer'); ?></strong> <?php esc_html_e('Tiny Compress Images recommendation and API key configuration reminders.', 'infy-ai-image-seo-optimizer'); ?></p>
            </div>
        </div>
    </div>

    <!-- Tools Section -->
    <div class="infyp-card infyp-tools-section">
        <div class="infyp-card-header">
            <h3><?php esc_html_e('Tools', 'infy-ai-image-seo-optimizer'); ?></h3>
        </div>
        <div class="infyp-card-body">
            <div class="infyp-tool-item">
                <div class="infyp-tool-info">
                    <h4><?php esc_html_e('Rebuild Search Index', 'infy-ai-image-seo-optimizer'); ?></h4>
                    <p class="infyp-form-description">
                        <?php esc_html_e('Rebuild the search index to improve search accuracy. Use this if search results are not showing expected images or after importing many images.', 'infy-ai-image-seo-optimizer'); ?>
                    </p>
                    <?php
                    $infyp_last_index_build = get_option('infyp_search_index_populated', 0);
                    if ($infyp_last_index_build) {
                        $infyp_last_build_date = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $infyp_last_index_build);
                        echo '<p class="infyp-tool-status"><span class="dashicons dashicons-clock"></span> ';
                        printf(
                            /* translators: %s: date and time of last index rebuild */
                            esc_html__('Last rebuilt: %s', 'infy-ai-image-seo-optimizer'),
                            esc_html($infyp_last_build_date)
                        );
                        echo '</p>';
                    }
                    ?>
                </div>
                <div class="infyp-tool-action">
                    <button type="button" class="button button-secondary" id="infyp-rebuild-search-index">
                        <span class="dashicons dashicons-database"></span>
                        <?php esc_html_e('Rebuild Index', 'infy-ai-image-seo-optimizer'); ?>
                    </button>
                    <span class="spinner" id="infyp-rebuild-spinner"></span>
                </div>
            </div>
            <div id="infyp-rebuild-result" class="infyp-tool-result" style="display: none;"></div>
        </div>
    </div>

    <?php
    /**
     * Fires after all settings cards on the Settings page.
     * Extensions can use this hook to add additional settings cards.
     *
     * @since 1.0.0
     */
    do_action( 'infyp_after_settings_cards' );
    ?>
</div>

<?php
// Add banner settings script to footer
add_action( 'admin_footer', function() {
    $nonce_reset = wp_create_nonce( 'infyp_reset_all_banners' );
    $nonce_toggle = wp_create_nonce( 'infyp_toggle_hide_banners' );
    $str_resetting = esc_js( __( 'Resetting...', 'infy-ai-image-seo-optimizer' ) );
    $str_failed_reset = esc_js( __( 'Failed to reset banners', 'infy-ai-image-seo-optimizer' ) );
    $str_failed_update = esc_js( __( 'Failed to update setting', 'infy-ai-image-seo-optimizer' ) );
    $str_error = esc_js( __( 'An error occurred', 'infy-ai-image-seo-optimizer' ) );

    $script = 'jQuery(document).ready(function($) {'
        . '$("#infyp-reset-banners").on("click", function(e) {'
        . 'e.preventDefault();'
        . 'var $button = $(this);'
        . 'var $status = $("#infyp-reset-banners-status");'
        . 'var originalText = $button.text();'
        . '$button.prop("disabled", true).text("' . $str_resetting . '");'
        . '$status.removeClass("show success error");'
        . '$.ajax({'
        . 'url: ajaxurl,'
        . 'type: "POST",'
        . 'data: {'
        . 'action: "infyp_reset_all_banners",'
        . 'nonce: "' . $nonce_reset . '"'
        . '},'
        . 'success: function(response) {'
        . 'if (response.success) {'
        . '$status.addClass("show success").text(response.data.message);'
        . '} else {'
        . '$status.addClass("show error").text(response.data.message || "' . $str_failed_reset . '");'
        . '}'
        . '},'
        . 'error: function() {'
        . '$status.addClass("show error").text("' . $str_error . '");'
        . '},'
        . 'complete: function() {'
        . '$button.prop("disabled", false).text(originalText);'
        . 'setTimeout(function() {'
        . '$status.removeClass("show");'
        . '}, 3000);'
        . '}'
        . '});'
        . '});'
        . '$("#infyp-hide-all-banners").on("change", function() {'
        . 'var $checkbox = $(this);'
        . 'var $status = $("#infyp-hide-banners-status");'
        . 'var hideStatus = $checkbox.is(":checked") ? "1" : "0";'
        . '$status.removeClass("show success error");'
        . '$.ajax({'
        . 'url: ajaxurl,'
        . 'type: "POST",'
        . 'data: {'
        . 'action: "infyp_toggle_hide_all_banners",'
        . 'nonce: "' . $nonce_toggle . '",'
        . 'hide_banners: hideStatus'
        . '},'
        . 'success: function(response) {'
        . 'if (response.success) {'
        . '$status.addClass("show success").text(response.data.message);'
        . '} else {'
        . '$status.addClass("show error").text(response.data.message || "' . $str_failed_update . '");'
        . '$checkbox.prop("checked", !$checkbox.is(":checked"));'
        . '}'
        . '},'
        . 'error: function() {'
        . '$status.addClass("show error").text("' . $str_error . '");'
        . '$checkbox.prop("checked", !$checkbox.is(":checked"));'
        . '},'
        . 'complete: function() {'
        . 'setTimeout(function() {'
        . '$status.removeClass("show");'
        . '}, 3000);'
        . '}'
        . '});'
        . '});'
        . '});';
    wp_print_inline_script_tag( $script );
} );
?>