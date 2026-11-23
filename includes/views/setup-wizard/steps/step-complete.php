<?php
/**
 * Setup Wizard - Complete Step
 *
 * @package AI_Image_SEO
 * @since 1.0.0
 *
 * @var array $data Template data.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$infyp_has_openai    = $data['has_openai'];
$infyp_has_google    = $data['has_google'];
$infyp_has_anthropic = $data['has_anthropic'];
$infyp_wizard_data   = $data['wizard_data'];

// Count configured providers
$infyp_providers_count = ( $infyp_has_openai ? 1 : 0 ) + ( $infyp_has_google ? 1 : 0 ) + ( $infyp_has_anthropic ? 1 : 0 );

// The connection summary reflects the chosen method, not just stored keys.
$infyp_uses_wp_ai = ( 'wp_ai' === ( $data['connection_method'] ?? '' ) );

// Without a working connection the screen must not claim the site is ready.
$infyp_ready = ! isset( $data['ready'] ) || ! empty( $data['ready'] );

// WordPress AI selected but no connector configured: the missing piece is
// the connector, not an API key. (Without the core AI Client the API Keys
// page is where the method can be switched back.)
$infyp_wp_ai_setup = $infyp_uses_wp_ai && \IPAIS\Core\AI\INFYP_WP_AI_Support::is_available();
?>
<div class="infyp-wizard-step-content infyp-step-complete">
    <?php if ( ! $infyp_ready ) : ?>
    <div class="infyp-complete-icon">
        <svg width="80" height="80" viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <circle cx="40" cy="40" r="40" fill="#d97706"/>
            <path d="M40 22V44M40 56V57" stroke="white" stroke-width="5" stroke-linecap="round"/>
        </svg>
    </div>

    <h1 class="infyp-complete-title"><?php esc_html_e( 'Almost done: connect an AI provider', 'infy-ai-image-seo-optimizer' ); ?></h1>

    <p class="infyp-complete-subtitle">
        <?php if ( $infyp_wp_ai_setup ) : ?>
            <?php esc_html_e( 'Your preferences are saved. WordPress AI is selected as the connection, but no AI connector is set up yet, so images cannot be optimized. Set one up in Settings → Connectors.', 'infy-ai-image-seo-optimizer' ); ?>
        <?php else : ?>
            <?php esc_html_e( 'Your preferences are saved, but no working AI connection is set up yet, so images cannot be optimized. Add an API key for one of the supported providers on the API Keys page.', 'infy-ai-image-seo-optimizer' ); ?>
        <?php endif; ?>
    </p>

    <div class="infyp-complete-summary">
        <h3><?php esc_html_e( 'Setup Summary', 'infy-ai-image-seo-optimizer' ); ?></h3>
        <div class="infyp-summary-items">
            <div class="infyp-summary-item warning">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                    <path d="M10 18C14.4183 18 18 14.4183 18 10C18 5.58172 14.4183 2 10 2C5.58172 2 2 5.58172 2 10C2 14.4183 5.58172 18 10 18Z" stroke="currentColor" stroke-width="1.5"/>
                    <path d="M10 6V10M10 14H10.01" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
                <?php if ( $infyp_wp_ai_setup ) : ?>
                <span><?php esc_html_e( 'Missing: a configured WordPress AI connector', 'infy-ai-image-seo-optimizer' ); ?></span>
                <?php else : ?>
                <span><?php esc_html_e( 'Missing: a working API key for the selected AI model', 'infy-ai-image-seo-optimizer' ); ?></span>
                <?php endif; ?>
            </div>

            <?php if ( ! empty( $infyp_wizard_data['website_type'] ) ) : ?>
            <div class="infyp-summary-item success">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                    <path d="M16.5 5.5L7.5 14.5L3.5 10.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span><?php esc_html_e( 'Website type configured', 'infy-ai-image-seo-optimizer' ); ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="infyp-complete-actions">
        <?php if ( $infyp_wp_ai_setup ) : ?>
        <a href="<?php echo esc_url( \IPAIS\Core\AI\INFYP_WP_AI_Support::connectors_admin_url() ); ?>" class="infyp-wizard-btn infyp-wizard-btn-primary infyp-wizard-btn-large">
            <?php esc_html_e( 'Set up a connector', 'infy-ai-image-seo-optimizer' ); ?>
        </a>
        <?php else : ?>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ai-image-seo-optimizer-api-keys' ) ); ?>" class="infyp-wizard-btn infyp-wizard-btn-primary infyp-wizard-btn-large">
            <?php esc_html_e( 'Add an API key', 'infy-ai-image-seo-optimizer' ); ?>
        </a>
        <?php endif; ?>

        <a href="<?php echo esc_url( admin_url( 'admin.php?page=infy-ai-image-seo-optimizer' ) ); ?>" class="infyp-complete-settings-link">
            <?php esc_html_e( 'Go to the dashboard', 'infy-ai-image-seo-optimizer' ); ?>
        </a>
    </div>
    <?php else : ?>
    <div class="infyp-complete-icon">
        <svg width="80" height="80" viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg">
            <circle class="infyp-complete-circle" cx="40" cy="40" r="40" fill="url(#complete-gradient)"/>
            <path class="infyp-complete-check-path" d="M24 40L34 50L56 28" pathLength="100" stroke="white" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
            <defs>
                <linearGradient id="complete-gradient" x1="0" y1="0" x2="80" y2="80" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#10b981"/>
                    <stop offset="1" stop-color="#059669"/>
                </linearGradient>
            </defs>
        </svg>
    </div>

    <h1 class="infyp-complete-title"><?php esc_html_e( "You're All Set!", 'infy-ai-image-seo-optimizer' ); ?></h1>

    <p class="infyp-complete-subtitle">
        <?php esc_html_e( 'Image SEO Optimizer is now configured and ready to optimize your images.', 'infy-ai-image-seo-optimizer' ); ?>
    </p>

    <!-- Setup Summary -->
    <div class="infyp-complete-summary">
        <h3><?php esc_html_e( 'Setup Summary', 'infy-ai-image-seo-optimizer' ); ?></h3>
        <div class="infyp-summary-items">
            <?php if ( $infyp_uses_wp_ai ) : ?>
            <div class="infyp-summary-item success">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                    <path d="M16.5 5.5L7.5 14.5L3.5 10.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span><?php esc_html_e( 'Connected via WordPress AI (Connectors)', 'infy-ai-image-seo-optimizer' ); ?></span>
            </div>
            <?php elseif ( $infyp_providers_count > 0 ) : ?>
            <div class="infyp-summary-item success">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                    <path d="M16.5 5.5L7.5 14.5L3.5 10.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span>
                    <?php
                    printf(
                        /* translators: %d: number of AI providers */
                        esc_html( _n( '%d AI provider configured', '%d AI providers configured', $infyp_providers_count, 'infy-ai-image-seo-optimizer' ) ),
                        absint( $infyp_providers_count )
                    );
                    ?>
                </span>
            </div>
            <?php endif; ?>

            <?php if ( ! empty( $infyp_wizard_data['website_type'] ) ) : ?>
            <div class="infyp-summary-item success">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                    <path d="M16.5 5.5L7.5 14.5L3.5 10.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span><?php esc_html_e( 'Website type configured', 'infy-ai-image-seo-optimizer' ); ?></span>
            </div>
            <?php endif; ?>

            <div class="infyp-summary-item success">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                    <path d="M16.5 5.5L7.5 14.5L3.5 10.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span><?php esc_html_e( 'Ready to optimize images', 'infy-ai-image-seo-optimizer' ); ?></span>
            </div>
        </div>
    </div>

    <!-- Quick Start Actions -->
    <div class="infyp-complete-actions">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=infy-ai-image-seo-optimizer' ) ); ?>" class="infyp-wizard-btn infyp-wizard-btn-primary infyp-wizard-btn-large">
            <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                <path d="M3 10L10 3L17 10M10 3V17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" transform="rotate(90 10 10)"/>
            </svg>
            <?php esc_html_e( 'Start Optimizing Images', 'infy-ai-image-seo-optimizer' ); ?>
        </a>

        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ai-image-seo-optimizer-settings' ) ); ?>" class="infyp-complete-settings-link">
            <?php esc_html_e( 'Configure Advanced Settings', 'infy-ai-image-seo-optimizer' ); ?>
        </a>
    </div>
    <?php endif; ?>

</div>
