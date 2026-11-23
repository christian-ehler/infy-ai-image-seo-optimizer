<?php
/**
 * Setup Wizard Main Page Template (Fullscreen)
 *
 * This template renders a fullscreen wizard experience without WordPress admin UI.
 *
 * Since the Phase-2 redesign every step panel is rendered into the document and
 * the active one is switched client-side with animated transitions; the
 * `?step=` parameter still selects the server-activated panel so deep links and
 * the no-JS fallback (real URLs on the Back links) keep working.
 *
 * @package AI_Image_SEO
 * @since 1.0.0
 * @since 1.0.6 Single-page panel shell with accessible progress rail.
 *
 * @var array $data Template data passed from controller.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$infyp_steps         = $data['steps'];
$infyp_current_step  = $data['current_step'];
$infyp_current_index = $data['current_index'];
$infyp_step_keys     = array_keys( $infyp_steps );
$infyp_total_steps   = count( $infyp_steps );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php esc_html_e( 'Image SEO Setup', 'infy-ai-image-seo-optimizer' ); ?> - <?php bloginfo( 'name' ); ?></title>
    <?php wp_head(); ?>
    <script>
        (function() {
            // Light is the wizard default; dark only when explicitly chosen
            // via the header switcher (persisted in localStorage).
            var t = localStorage.getItem('infypress-theme') || 'light';
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
</head>
<body class="infyp-wizard-body infyp-wizard-fullscreen">
    <div class="infyp-wizard-page">
        <!-- Header -->
        <div class="infyp-wizard-header">
            <div class="infyp-wizard-logo">
                <svg class="infyp-wizard-logo-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 -960 960 960" width="40" height="40">
                    <path d="M480-118 120-398l66-50 294 228 294-228 66 50-360 280Zm0-202L120-600l360-280 360 280-360 280Zm0-280Zm0 178 230-178-230-178-230 178 230 178Z" fill="currentColor"/>
                </svg>
                <span class="infyp-wizard-logo-text">InfyPress</span>
            </div>

            <button type="button" class="infyp-theme-toggle" aria-label="<?php esc_attr_e( 'Toggle dark mode', 'infy-ai-image-seo-optimizer' ); ?>">
                <svg class="infyp-theme-icon-moon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                </svg>
                <svg class="infyp-theme-icon-sun" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="4"/>
                    <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>
                </svg>
            </button>
        </div>

        <!-- Progress rail -->
        <nav class="infyp-wizard-progress" aria-label="<?php esc_attr_e( 'Setup progress', 'infy-ai-image-seo-optimizer' ); ?>" data-total-steps="<?php echo esc_attr( $infyp_total_steps ); ?>">
            <ol class="infyp-wizard-steps">
                <?php foreach ( $infyp_steps as $infyp_step_key => $infyp_step ) :
                    $infyp_step_index = array_search( $infyp_step_key, $infyp_step_keys, true );
                    $infyp_step_class = 'infyp-wizard-step';

                    if ( $infyp_step_index < $infyp_current_index ) {
                        $infyp_step_class .= ' completed';
                    } elseif ( $infyp_step_index === $infyp_current_index ) {
                        $infyp_step_class .= ' active';
                        // The final milestone earns its check when reached.
                        if ( $infyp_step_index === $infyp_total_steps - 1 ) {
                            $infyp_step_class .= ' completed';
                        }
                    }
                ?>
                <li class="infyp-wizard-step-item">
                    <button type="button"
                        class="<?php echo esc_attr( $infyp_step_class ); ?>"
                        data-step="<?php echo esc_attr( $infyp_step_key ); ?>"
                        data-index="<?php echo esc_attr( $infyp_step_index ); ?>"
                        <?php if ( $infyp_step_index === $infyp_current_index ) : ?>aria-current="step"<?php endif; ?>
                        <?php disabled( $infyp_step_index > $infyp_current_index ); ?>>
                        <span class="infyp-step-dot" aria-hidden="true">
                            <svg class="infyp-step-check" width="16" height="16" viewBox="0 0 16 16" fill="none">
                                <path d="M13.5 4.5L6 12L2.5 8.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <span class="infyp-step-number"><?php echo esc_html( $infyp_step_index + 1 ); ?></span>
                        </span>
                        <span class="infyp-step-label"><?php echo esc_html( $infyp_step['name'] ); ?></span>
                    </button>
                </li>
                <?php endforeach; ?>
            </ol>
            <div class="infyp-progress-track" aria-hidden="true">
                <div class="infyp-progress-fill"></div>
            </div>
        </nav>

        <!-- Screen-reader step announcements -->
        <p class="infyp-visually-hidden" aria-live="polite" id="infyp-wizard-live"></p>

        <!-- Step panels -->
        <div class="infyp-wizard-content" id="infyp-wizard-content">
            <?php foreach ( $infyp_steps as $infyp_step_key => $infyp_step ) :
                $infyp_step_index = array_search( $infyp_step_key, $infyp_step_keys, true );
                $infyp_is_active  = ( $infyp_step_key === $infyp_current_step );
            ?>
            <section class="infyp-wizard-panel<?php echo $infyp_is_active ? ' is-active' : ''; ?>"
                data-step="<?php echo esc_attr( $infyp_step_key ); ?>"
                data-index="<?php echo esc_attr( $infyp_step_index ); ?>"
                role="group"
                aria-label="<?php echo esc_attr( $infyp_step['name'] ); ?>"
                <?php if ( ! $infyp_is_active ) : ?>hidden<?php endif; ?>>
                <?php
                $infyp_step_view = INFYP_PLUGIN_DIR . 'includes/views/setup-wizard/steps/' . $infyp_steps[ $infyp_step_key ]['view'] . '.php';
                if ( file_exists( $infyp_step_view ) ) {
                    include $infyp_step_view;
                }

                if ( 'complete' !== $infyp_step_key ) {
                    $infyp_nav_index = $infyp_step_index;
                    include INFYP_PLUGIN_DIR . 'includes/views/setup-wizard/partials/panel-nav.php';
                }
                ?>
            </section>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Footer -->
    <footer class="infyp-wizard-footer">
        <div class="infyp-wizard-footer-brand-center">
            <svg class="infyp-wizard-footer-logo" xmlns="http://www.w3.org/2000/svg" viewBox="0 -960 960 960" width="48" height="48">
                <path d="M480-118 120-398l66-50 294 228 294-228 66 50-360 280Zm0-202L120-600l360-280 360 280-360 280Zm0-280Zm0 178 230-178-230-178-230 178 230 178Z" fill="currentColor"/>
            </svg>
            <span class="infyp-wizard-footer-brand-name">InfyPress</span>
        </div>
        <div class="infyp-wizard-footer-bottom">
            <p>&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> InfyPress. <?php esc_html_e( 'All rights reserved.', 'infy-ai-image-seo-optimizer' ); ?></p>
        </div>
    </footer>

    <?php wp_footer(); ?>
</body>
</html>
