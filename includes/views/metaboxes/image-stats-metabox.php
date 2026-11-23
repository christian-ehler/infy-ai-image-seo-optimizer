<?php
/**
 * Image Statistics Dashboard Meta Box Template
 *
 * Displays the dashboard overview with optimization stats,
 * field percentages, and recommendation cards.
 *
 * Available variables (prepared by render_image_stats_meta_box()):
 *   $stats                 array   Full stats array
 *   $total_images          int     Total image count
 *   $optimized_images      int     Count of images that have been AI-optimized
 *   $optimized_percentage  int     Coverage: share of images optimized (0-100).
 *                                  Drives the circle, the count and the badge.
 *   $overall_percentage    int     Quality: average SEO score of scored images (0-100).
 *                                  Available but not rendered on this card.
 *   $keywords_percentage   int     Keywords optimization percentage
 *   $filename_percentage   int     Filename optimization percentage
 *   $alt_percentage        int     Alt text optimization percentage
 *   $title_percentage      int     Title optimization percentage
 *   $get_badge_class       Closure Returns CSS class based on percentage
 *   $show_compression_card bool    Whether to show TinyPNG card
 *   $show_api_usage_card   bool    Whether to show API usage card
 *   $show_api_usage_hidden bool    Whether to render hidden API usage card
 *
 * @package   IPAIS\Admin
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="infyp-dashboard-container">
    <!-- First Row: 50/50 Grid -->
    <div class="infyp-dashboard-row-top">
        <!-- Total Images Card -->
        <div class="infyp-dashboard-total-card">
            <!-- Main Content: Circle + Total Info -->
            <div class="infyp-dashboard-total-info">
                <div class="infyp-dashboard-circle">
                    <svg class="infyp-score-svg" viewBox="0 0 36 36">
                        <path class="infyp-score-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                        <path class="infyp-score-progress" stroke-dasharray="<?php echo esc_attr( $optimized_percentage ); ?>, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                    </svg>
                    <div class="infyp-score-text">
                        <span class="infyp-score-number"><?php echo esc_html( $optimized_percentage ); ?>%</span>
                    </div>
                </div>
                <div class="infyp-dashboard-total-content">
                    <h3><span class="infyp-field-stat-optimized"><?php echo esc_html( number_format_i18n( $optimized_images ) ); ?></span><span class="infyp-field-stat-divider"></span><span class="infyp-field-stat-total"><?php echo esc_html( number_format_i18n( $total_images ) ); ?></span></h3>
                    <p><?php esc_html_e( 'Total Images', 'infy-ai-image-seo-optimizer' ); ?> <span class="infyp-optimized-badge <?php echo esc_attr( $get_badge_class( $optimized_percentage ) ); ?>"><?php echo esc_html( $optimized_percentage ); ?>% <?php esc_html_e( 'optimized', 'infy-ai-image-seo-optimizer' ); ?></span></p>
                </div>
            </div>
        </div>

        <!-- Middle: Field Optimization Stats Card -->
        <div class="infyp-field-optimization-card">
            <div class="infyp-field-optimization-header">
                <h4><?php esc_html_e( 'Image SEO Stats', 'infy-ai-image-seo-optimizer' ); ?></h4>
                <?php if ( has_action( 'infyp_dashboard_extended_field_stats' ) ) : ?>
                <button type="button" class="infyp-toggle-fields-btn" id="infyp-toggle-all-fields" aria-expanded="false">
                    <span class="infyp-toggle-show"><?php esc_html_e( 'Show Extended Fields', 'infy-ai-image-seo-optimizer' ); ?></span>
                    <span class="infyp-toggle-hide" style="display: none;"><?php esc_html_e( 'Show Base Fields', 'infy-ai-image-seo-optimizer' ); ?></span>
                </button>
                <?php endif; ?>
            </div>

            <!-- Main 4-column grid: Keywords | Filenames | Alt Text | Titles -->
            <div class="infyp-stats-grid">
                <div class="infyp-stats-card">
                    <span class="infyp-stats-card-title"><?php esc_html_e( 'Keywords', 'infy-ai-image-seo-optimizer' ); ?></span>
                    <span class="infyp-optimized-badge <?php echo esc_attr( $get_badge_class( $keywords_percentage ) ); ?>"><?php echo esc_html( $keywords_percentage ); ?>% <?php esc_html_e( 'optimized', 'infy-ai-image-seo-optimizer' ); ?></span>
                </div>

                <div class="infyp-stats-card">
                    <span class="infyp-stats-card-title"><?php esc_html_e( 'Filenames', 'infy-ai-image-seo-optimizer' ); ?></span>
                    <span class="infyp-optimized-badge <?php echo esc_attr( $get_badge_class( $filename_percentage ) ); ?>"><?php echo esc_html( $filename_percentage ); ?>% <?php esc_html_e( 'optimized', 'infy-ai-image-seo-optimizer' ); ?></span>
                </div>

                <div class="infyp-stats-card">
                    <span class="infyp-stats-card-title"><?php esc_html_e( 'Alt Text', 'infy-ai-image-seo-optimizer' ); ?></span>
                    <span class="infyp-optimized-badge <?php echo esc_attr( $get_badge_class( $alt_percentage ) ); ?>"><?php echo esc_html( $alt_percentage ); ?>% <?php esc_html_e( 'optimized', 'infy-ai-image-seo-optimizer' ); ?></span>
                </div>

                <div class="infyp-stats-card">
                    <span class="infyp-stats-card-title"><?php esc_html_e( 'Titles', 'infy-ai-image-seo-optimizer' ); ?></span>
                    <span class="infyp-optimized-badge <?php echo esc_attr( $get_badge_class( $title_percentage ) ); ?>"><?php echo esc_html( $title_percentage ); ?>% <?php esc_html_e( 'optimized', 'infy-ai-image-seo-optimizer' ); ?></span>
                </div>
            </div>

            <?php
            /**
             * Hook for extensions to add additional field stats
             *
             * @param array $stats All dashboard statistics
             * @param int   $total_images Total number of images
             */
            do_action( 'infyp_dashboard_extended_field_stats', $stats, $total_images );
            ?>
        </div>

        <?php if ( $show_extension_card ) : ?>
        <!-- PRO Upgrade Card -->
        <div class="infyp-dashboard-cta-box infyp-extension-card" id="infyp-extension-card">
            <button type="button" class="infyp-card-dismiss" id="infyp-dismiss-extension-card" aria-label="<?php esc_attr_e( 'Dismiss', 'infy-ai-image-seo-optimizer' ); ?>">
                <span class="dashicons dashicons-no-alt"></span>
            </button>
            <h4><?php esc_html_e( 'Unlock the Full Power of Image SEO Optimizer', 'infy-ai-image-seo-optimizer' ); ?></h4>
            <p><?php esc_html_e( 'Bulk processing, real-time upload optimization, image context generation, and best-in-class AI models — everything you need to rank higher on Google Images and get cited in AI search results.', 'infy-ai-image-seo-optimizer' ); ?></p>
            <div class="infyp-extension-card-actions">
                <a href="<?php echo esc_url( 'https://infypress.com/plugins/image-seo-optimizer/?utm_source=wp&utm_medium=dashboard_card&utm_campaign=get_pro' ); ?>" class="button button-primary" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e( 'Upgrade to PRO', 'infy-ai-image-seo-optimizer' ); ?>
                </a>
                <a href="<?php echo esc_url( 'https://infypress.com/plugins/image-seo-optimizer/?utm_source=wp&utm_medium=dashboard_card&utm_campaign=learn_more' ); ?>" class="button button-secondary" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e( 'Learn More', 'infy-ai-image-seo-optimizer' ); ?>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <?php if ( $show_compression_card ) : ?>
        <!-- Compression Recommendation Card -->
        <div class="infyp-dashboard-cta-box infyp-compression-card" id="infyp-compression-card">
            <button type="button" class="infyp-card-dismiss" id="infyp-dismiss-compression-card" aria-label="<?php esc_attr_e( 'Dismiss', 'infy-ai-image-seo-optimizer' ); ?>">
                <span class="dashicons dashicons-no-alt"></span>
            </button>
            <h4><?php esc_html_e( 'Compress Your Images with TinyPNG', 'infy-ai-image-seo-optimizer' ); ?></h4>
            <p><?php esc_html_e( 'The best & easiest image compression plugin for WordPress. Speed up your website by compressing and converting your images with TinyPNG.', 'infy-ai-image-seo-optimizer' ); ?></p>
            <div class="infyp-compression-card-actions">
                <a href="<?php echo esc_url( admin_url( 'plugin-install.php?s=tiny-compress-images&tab=search&type=term' ) ); ?>" class="button button-primary" target="_blank">
                    <?php esc_html_e( 'View in Plugin Directory', 'infy-ai-image-seo-optimizer' ); ?>
                </a>
                <a href="https://wordpress.org/plugins/tiny-compress-images/" target="_blank" rel="noopener noreferrer" class="button button-secondary">
                    <?php esc_html_e( 'Learn More', 'infy-ai-image-seo-optimizer' ); ?>
                </a>
            </div>
        </div>
        <?php elseif ( $show_compression_hidden ) : ?>
        <!-- Compression Card (Hidden, shown when extension card is dismissed) -->
        <div class="infyp-dashboard-cta-box infyp-compression-card" id="infyp-compression-card" style="display: none;">
            <button type="button" class="infyp-card-dismiss" id="infyp-dismiss-compression-card" aria-label="<?php esc_attr_e( 'Dismiss', 'infy-ai-image-seo-optimizer' ); ?>">
                <span class="dashicons dashicons-no-alt"></span>
            </button>
            <h4><?php esc_html_e( 'Compress Your Images with TinyPNG', 'infy-ai-image-seo-optimizer' ); ?></h4>
            <p><?php esc_html_e( 'The best & easiest image compression plugin for WordPress. Speed up your website by compressing and converting your images with TinyPNG.', 'infy-ai-image-seo-optimizer' ); ?></p>
            <div class="infyp-compression-card-actions">
                <a href="<?php echo esc_url( admin_url( 'plugin-install.php?s=tiny-compress-images&tab=search&type=term' ) ); ?>" class="button button-primary" target="_blank">
                    <?php esc_html_e( 'View in Plugin Directory', 'infy-ai-image-seo-optimizer' ); ?>
                </a>
                <a href="https://wordpress.org/plugins/tiny-compress-images/" target="_blank" rel="noopener noreferrer" class="button button-secondary">
                    <?php esc_html_e( 'Learn More', 'infy-ai-image-seo-optimizer' ); ?>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <?php if ( $show_api_usage_card ) : ?>
        <!-- API Usage Card -->
        <div class="infyp-dashboard-cta-box infyp-api-usage-card" id="infyp-api-usage-card">
            <h4><?php esc_html_e( 'Monitor API Usage', 'infy-ai-image-seo-optimizer' ); ?></h4>
            <p><?php esc_html_e( 'Track your AI optimization usage and costs. View detailed statistics and optimize your API consumption.', 'infy-ai-image-seo-optimizer' ); ?></p>
            <div class="infyp-api-usage-card-actions">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ai-image-seo-optimizer-api-usage' ) ); ?>" class="button button-secondary">
                    <?php esc_html_e( 'View API Usage', 'infy-ai-image-seo-optimizer' ); ?>
                </a>
            </div>
        </div>
        <?php elseif ( $show_api_usage_hidden ) : ?>
        <!-- API Usage Card (Hidden, shown when compression card is dismissed) -->
        <div class="infyp-dashboard-cta-box infyp-api-usage-card" id="infyp-api-usage-card" style="display: none;">
            <h4><?php esc_html_e( 'Monitor API Usage', 'infy-ai-image-seo-optimizer' ); ?></h4>
            <p><?php esc_html_e( 'Track your AI optimization usage and costs. View detailed statistics and optimize your API consumption.', 'infy-ai-image-seo-optimizer' ); ?></p>
            <div class="infyp-api-usage-card-actions">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ai-image-seo-optimizer-api-usage' ) ); ?>" class="button button-secondary">
                    <?php esc_html_e( 'View API Usage', 'infy-ai-image-seo-optimizer' ); ?>
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
