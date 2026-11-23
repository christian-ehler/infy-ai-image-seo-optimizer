<?php
/**
 * Setup Wizard — per-panel navigation footer.
 *
 * Included by wizard-page.php inside every non-complete step panel. Back links
 * carry a real URL (no-JS fallback) plus a data attribute the panel manager
 * intercepts for the animated client-side transition.
 *
 * @package AI_Image_SEO
 * @since 1.0.6
 *
 * @var array $data             Template data passed from controller.
 * @var array $infyp_steps      Step definitions keyed by slug.
 * @var array $infyp_step_keys  Ordered step slugs.
 * @var int   $infyp_total_steps Total number of steps.
 * @var int   $infyp_nav_index  Index of the panel this nav belongs to.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="infyp-wizard-navigation infyp-panel-nav">
    <div class="infyp-wizard-nav-left">
        <?php if ( $infyp_nav_index > 0 ) : ?>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ai-image-seo-optimizer-wizard&step=' . $infyp_step_keys[ $infyp_nav_index - 1 ] ) ); ?>"
            class="infyp-wizard-btn infyp-wizard-btn-secondary infyp-wizard-prev"
            data-step-target="<?php echo esc_attr( $infyp_step_keys[ $infyp_nav_index - 1 ] ); ?>">
            <svg class="infyp-nav-icon" width="20" height="20" viewBox="0 0 20 20" fill="none">
                <path d="M12.5 15L7.5 10L12.5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <?php esc_html_e( 'Back', 'infy-ai-image-seo-optimizer' ); ?>
        </a>
        <?php endif; ?>
        <button type="button" class="infyp-wizard-skip">
            <?php esc_html_e( 'Skip Setup', 'infy-ai-image-seo-optimizer' ); ?>
        </button>
        <?php if ( 'api-keys' === $infyp_step_keys[ $infyp_nav_index ] && isset( $infyp_step_keys[ $infyp_nav_index + 1 ] ) ) : ?>
        <?php // Leaves the key step without saving a key; the final screen then says what is missing. ?>
        <button type="button" class="infyp-wizard-skip-step infyp-hidden" data-next-step="<?php echo esc_attr( $infyp_step_keys[ $infyp_nav_index + 1 ] ); ?>">
            <?php esc_html_e( 'Continue without a key', 'infy-ai-image-seo-optimizer' ); ?>
        </button>
        <?php endif; ?>
    </div>

    <?php if ( $infyp_nav_index < $infyp_total_steps - 2 ) : ?>
    <button type="button" class="infyp-wizard-btn infyp-wizard-btn-primary infyp-wizard-next" data-next-step="<?php echo esc_attr( $infyp_step_keys[ $infyp_nav_index + 1 ] ); ?>">
        <?php esc_html_e( 'Continue', 'infy-ai-image-seo-optimizer' ); ?>
        <svg class="infyp-nav-icon" width="20" height="20" viewBox="0 0 20 20" fill="none">
            <path d="M7.5 15L12.5 10L7.5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </button>
    <?php elseif ( $infyp_nav_index === $infyp_total_steps - 2 ) : ?>
    <?php // Both buttons: Continue walks the sub-screens, Complete shows on the last one (JS toggles). ?>
    <button type="button" class="infyp-wizard-btn infyp-wizard-btn-primary infyp-wizard-next" data-next-step="<?php echo esc_attr( $infyp_step_keys[ $infyp_nav_index + 1 ] ); ?>">
        <?php esc_html_e( 'Continue', 'infy-ai-image-seo-optimizer' ); ?>
        <svg class="infyp-nav-icon" width="20" height="20" viewBox="0 0 20 20" fill="none">
            <path d="M7.5 15L12.5 10L7.5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </button>
    <button type="button" class="infyp-wizard-btn infyp-wizard-btn-primary infyp-wizard-complete-btn infyp-hidden" id="infyp-wizard-complete">
        <?php esc_html_e( 'Complete Setup', 'infy-ai-image-seo-optimizer' ); ?>
        <svg class="infyp-nav-icon" width="20" height="20" viewBox="0 0 20 20" fill="none">
            <path d="M16.5 5.5L7.5 14.5L3.5 10.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </button>
    <?php endif; ?>
</div>
