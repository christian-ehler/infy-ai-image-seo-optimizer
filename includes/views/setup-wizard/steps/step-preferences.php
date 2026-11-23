<?php
/**
 * Setup Wizard - Preferences Step
 *
 * Since 1.0.6 this step is three conversational sub-screens: "Your site"
 * (website type as icon cards), "Content" (language + library size) and
 * "Stay in touch" (newsletter/telemetry). All inputs stay in the DOM and the
 * whole step is still saved with the single `preferences` AJAX call when the
 * user finishes, so the payload shape is unchanged.
 *
 * @package AI_Image_SEO
 * @since 1.0.0
 * @since 1.0.6 Conversational sub-screens.
 *
 * @var array $data Template data.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$infyp_languages   = $data['languages'];
$infyp_wizard_data = $data['wizard_data'];
$infyp_site_type   = $infyp_wizard_data['website_type'] ?? '';

$infyp_site_types = [
    'blog'      => [
        'label' => __( 'Blog / Personal', 'infy-ai-image-seo-optimizer' ),
        'icon'  => 'M4 19.5A2.5 2.5 0 0 1 6.5 17H20M4 19.5A2.5 2.5 0 0 0 6.5 22H20V2H6.5A2.5 2.5 0 0 0 4 4.5v15z',
    ],
    'ecommerce' => [
        'label' => __( 'E-commerce', 'infy-ai-image-seo-optimizer' ),
        'icon'  => 'M9 22a1 1 0 1 0 0-2 1 1 0 0 0 0 2zM20 22a1 1 0 1 0 0-2 1 1 0 0 0 0 2zM1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6',
    ],
    'portfolio' => [
        'label' => __( 'Portfolio / Photography', 'infy-ai-image-seo-optimizer' ),
        'icon'  => 'M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2v11zM12 17a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
    ],
    'business'  => [
        'label' => __( 'Business / Corporate', 'infy-ai-image-seo-optimizer' ),
        'icon'  => 'M20 7H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2zM16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16',
    ],
    'news'      => [
        'label' => __( 'News / Media', 'infy-ai-image-seo-optimizer' ),
        'icon'  => 'M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2M18 14h-8M15 18h-5M10 6h8v4h-8V6z',
    ],
    'other'     => [
        'label' => __( 'Something else', 'infy-ai-image-seo-optimizer' ),
        'icon'  => 'M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10zM9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3M12 17h.01',
    ],
];

$current_user     = wp_get_current_user();
$infyp_user_email = $current_user->user_email;
$infyp_user_name  = $current_user->display_name ? $current_user->display_name : $current_user->user_login;
?>
<div class="infyp-wizard-step-content infyp-step-preferences">
    <form class="infyp-preferences-form" id="infyp-preferences-form">
        <input type="hidden" id="infyp-website-type" name="website_type" value="<?php echo esc_attr( $infyp_site_type ); ?>" />

        <!-- Sub-screen: Your site -->
        <div class="infyp-subpanel is-active" data-subscreen="site-type">
            <h2 class="infyp-step-title"><?php esc_html_e( 'What kind of site is this?', 'infy-ai-image-seo-optimizer' ); ?></h2>
            <p class="infyp-step-description">
                <?php esc_html_e( 'Optional — just continue if you\'re not sure. Your answer is only sent to us if you subscribe to updates below.', 'infy-ai-image-seo-optimizer' ); ?>
            </p>

            <div class="infyp-choice-grid infyp-choice-grid-compact" role="radiogroup" aria-label="<?php esc_attr_e( 'Website type', 'infy-ai-image-seo-optimizer' ); ?>">
                <?php foreach ( $infyp_site_types as $infyp_type_key => $infyp_type ) : ?>
                <label class="infyp-choice-card infyp-choice-card-compact<?php echo $infyp_type_key === $infyp_site_type ? ' is-selected' : ''; ?>">
                    <input type="radio" class="infyp-visually-hidden infyp-site-type-choice" name="infyp_site_type_choice" value="<?php echo esc_attr( $infyp_type_key ); ?>" <?php checked( $infyp_type_key, $infyp_site_type ); ?> />
                    <span class="infyp-choice-check" aria-hidden="true">
                        <svg width="12" height="12" viewBox="0 0 16 16" fill="none">
                            <path d="M13.5 4.5L6 12L2.5 8.5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </span>
                    <span class="infyp-choice-icon" aria-hidden="true">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="<?php echo esc_attr( $infyp_type['icon'] ); ?>"/>
                        </svg>
                    </span>
                    <span class="infyp-choice-title"><?php echo esc_html( $infyp_type['label'] ); ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Sub-screen: Content -->
        <div class="infyp-subpanel" data-subscreen="content">
            <h2 class="infyp-step-title"><?php esc_html_e( 'Language and library size', 'infy-ai-image-seo-optimizer' ); ?></h2>
            <p class="infyp-step-description">
                <?php esc_html_e( 'The AI writes in your content language. The library size is optional and is only sent to us if you subscribe to updates below.', 'infy-ai-image-seo-optimizer' ); ?>
            </p>

            <div class="infyp-form-group">
                <label for="infyp-language"><?php esc_html_e( 'Primary content language', 'infy-ai-image-seo-optimizer' ); ?></label>
                <select id="infyp-language" name="language" class="infyp-select">
                    <?php foreach ( $infyp_languages as $infyp_code => $infyp_name ) : ?>
                        <option value="<?php echo esc_attr( $infyp_code ); ?>" <?php selected( get_locale(), $infyp_code ); ?>>
                            <?php echo esc_html( $infyp_name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="infyp-form-group infyp-form-group-full">
                <label><?php esc_html_e( 'Roughly how many images will you optimize?', 'infy-ai-image-seo-optimizer' ); ?></label>
                <div class="infyp-radio-group">
                    <label class="infyp-radio-card">
                        <input type="radio" name="image_count" value="small" <?php checked( $infyp_wizard_data['image_count'] ?? '', 'small' ); ?> />
                        <span class="infyp-radio-content">
                            <span class="infyp-radio-label"><?php esc_html_e( '1 - 100', 'infy-ai-image-seo-optimizer' ); ?></span>
                            <span class="infyp-radio-desc"><?php esc_html_e( 'Small library', 'infy-ai-image-seo-optimizer' ); ?></span>
                        </span>
                    </label>
                    <label class="infyp-radio-card">
                        <input type="radio" name="image_count" value="medium" <?php checked( $infyp_wizard_data['image_count'] ?? '', 'medium' ); ?> />
                        <span class="infyp-radio-content">
                            <span class="infyp-radio-label"><?php esc_html_e( '100 - 1,000', 'infy-ai-image-seo-optimizer' ); ?></span>
                            <span class="infyp-radio-desc"><?php esc_html_e( 'Medium library', 'infy-ai-image-seo-optimizer' ); ?></span>
                        </span>
                    </label>
                    <label class="infyp-radio-card">
                        <input type="radio" name="image_count" value="large" <?php checked( $infyp_wizard_data['image_count'] ?? '', 'large' ); ?> />
                        <span class="infyp-radio-content">
                            <span class="infyp-radio-label"><?php esc_html_e( '1,000 - 10,000', 'infy-ai-image-seo-optimizer' ); ?></span>
                            <span class="infyp-radio-desc"><?php esc_html_e( 'Large library', 'infy-ai-image-seo-optimizer' ); ?></span>
                        </span>
                    </label>
                    <label class="infyp-radio-card">
                        <input type="radio" name="image_count" value="enterprise" <?php checked( $infyp_wizard_data['image_count'] ?? '', 'enterprise' ); ?> />
                        <span class="infyp-radio-content">
                            <span class="infyp-radio-label"><?php esc_html_e( '10,000+', 'infy-ai-image-seo-optimizer' ); ?></span>
                            <span class="infyp-radio-desc"><?php esc_html_e( 'Enterprise', 'infy-ai-image-seo-optimizer' ); ?></span>
                        </span>
                    </label>
                </div>
            </div>
        </div>

        <!-- Sub-screen: Stay in touch -->
        <div class="infyp-subpanel" data-subscreen="stay-in-touch">
            <h2 class="infyp-step-title"><?php esc_html_e( 'Stay in touch?', 'infy-ai-image-seo-optimizer' ); ?></h2>
            <p class="infyp-step-description">
                <?php esc_html_e( 'Completely optional — finish either way.', 'infy-ai-image-seo-optimizer' ); ?>
            </p>

            <div class="infyp-newsletter-optin">
                <div class="infyp-toggle-wrapper infyp-toggle-newsletter">
                    <input type="checkbox" id="infyp-newsletter-consent" name="newsletter_consent" value="1" <?php checked( $infyp_wizard_data['newsletter_consent'] ?? false ); ?> />
                    <label for="infyp-newsletter-consent">
                        <span class="infyp-toggle-switch"></span>
                        <span class="infyp-toggle-label">
                            <span class="infyp-toggle-title"><?php esc_html_e( 'Subscribe for SGE Tips, Product updates and special Promotions', 'infy-ai-image-seo-optimizer' ); ?></span>
                            <span class="infyp-toggle-desc">
                                <?php
                                printf(
                                    /* translators: %s: User's email address */
                                    esc_html__( 'We\'ll send occasional updates to %s and collect basic site info to improve your plugin experience. Unsubscribe anytime.', 'infy-ai-image-seo-optimizer' ),
                                    '<strong>' . esc_html( $infyp_user_email ) . '</strong>'
                                );
                                ?>
                                <a href="https://infypress.com/privacy/" target="_blank" rel="noopener" class="infyp-privacy-link"><?php esc_html_e( 'Read more about data collection in our Privacy Policy', 'infy-ai-image-seo-optimizer' ); ?></a>
                            </span>
                        </span>
                    </label>
                </div>

                <!-- Data Disclosure Panel -->
                <div class="infyp-data-disclosure">
                    <button type="button" class="infyp-disclosure-trigger" aria-expanded="false">
                        <svg class="infyp-disclosure-icon" width="16" height="16" viewBox="0 0 16 16" fill="none">
                            <path d="M6 4L10 8L6 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <?php esc_html_e( 'See what data we collect', 'infy-ai-image-seo-optimizer' ); ?>
                    </button>

                    <div class="infyp-disclosure-content" aria-hidden="true">
                        <div class="infyp-disclosure-inner">
                            <div class="infyp-disclosure-intro">
                                <p><?php esc_html_e( 'This data helps us troubleshoot plugin and theme conflicts, so we can fix issues before they affect you.', 'infy-ai-image-seo-optimizer' ); ?></p>
                                <p><?php esc_html_e( 'We never sell or share your data with third parties. It is used only for development purposes so image optimization stays a smooth experience.', 'infy-ai-image-seo-optimizer' ); ?></p>
                            </div>

                            <!-- Required: Account Info -->
                            <div class="infyp-disclosure-category">
                                <div class="infyp-disclosure-category-header">
                                    <div class="infyp-disclosure-category-info">
                                        <span class="infyp-disclosure-category-title"><?php esc_html_e( 'Account Info', 'infy-ai-image-seo-optimizer' ); ?></span>
                                        <span class="infyp-disclosure-badge-required"><?php esc_html_e( 'Required', 'infy-ai-image-seo-optimizer' ); ?></span>
                                    </div>
                                </div>
                                <span class="infyp-disclosure-category-desc"><?php esc_html_e( 'Name and email address, needed for newsletter delivery.', 'infy-ai-image-seo-optimizer' ); ?></span>
                            </div>

                            <!-- Optional: Site Environment -->
                            <div class="infyp-disclosure-category">
                                <div class="infyp-disclosure-category-header">
                                    <div class="infyp-toggle-compact">
                                        <input type="checkbox" id="infyp-telemetry-environment" name="telemetry_environment" value="1" checked />
                                        <label for="infyp-telemetry-environment">
                                            <span class="infyp-toggle-compact-switch"></span>
                                        </label>
                                    </div>
                                    <span class="infyp-disclosure-category-title"><?php esc_html_e( 'Site Environment', 'infy-ai-image-seo-optimizer' ); ?></span>
                                </div>
                                <span class="infyp-disclosure-category-desc"><?php esc_html_e( 'WP, PHP, and MySQL versions, server software, memory limit, and debug mode. Helps us reproduce and fix compatibility issues.', 'infy-ai-image-seo-optimizer' ); ?></span>
                            </div>

                            <!-- Optional: Theme Info -->
                            <div class="infyp-disclosure-category">
                                <div class="infyp-disclosure-category-header">
                                    <div class="infyp-toggle-compact">
                                        <input type="checkbox" id="infyp-telemetry-theme" name="telemetry_theme" value="1" checked />
                                        <label for="infyp-telemetry-theme">
                                            <span class="infyp-toggle-compact-switch"></span>
                                        </label>
                                    </div>
                                    <span class="infyp-disclosure-category-title"><?php esc_html_e( 'Theme Info', 'infy-ai-image-seo-optimizer' ); ?></span>
                                </div>
                                <span class="infyp-disclosure-category-desc"><?php esc_html_e( 'Theme name, version, and parent theme. Helps us identify theme-specific layout conflicts.', 'infy-ai-image-seo-optimizer' ); ?></span>
                            </div>

                            <!-- Optional: Content Overview -->
                            <div class="infyp-disclosure-category">
                                <div class="infyp-disclosure-category-header">
                                    <div class="infyp-toggle-compact">
                                        <input type="checkbox" id="infyp-telemetry-content" name="telemetry_content" value="1" checked />
                                        <label for="infyp-telemetry-content">
                                            <span class="infyp-toggle-compact-switch"></span>
                                        </label>
                                    </div>
                                    <span class="infyp-disclosure-category-title"><?php esc_html_e( 'Content Overview', 'infy-ai-image-seo-optimizer' ); ?></span>
                                </div>
                                <span class="infyp-disclosure-category-desc"><?php esc_html_e( 'Media, image, post, page, and product counts. Helps us understand the library sizes the plugin is used with.', 'infy-ai-image-seo-optimizer' ); ?></span>
                            </div>

                            <!-- Optional: Plugin Detection -->
                            <div class="infyp-disclosure-category">
                                <div class="infyp-disclosure-category-header">
                                    <div class="infyp-toggle-compact">
                                        <input type="checkbox" id="infyp-telemetry-plugins" name="telemetry_plugins" value="1" checked />
                                        <label for="infyp-telemetry-plugins">
                                            <span class="infyp-toggle-compact-switch"></span>
                                        </label>
                                    </div>
                                    <span class="infyp-disclosure-category-title"><?php esc_html_e( 'Plugin Detection', 'infy-ai-image-seo-optimizer' ); ?></span>
                                </div>
                                <span class="infyp-disclosure-category-desc"><?php esc_html_e( 'Plugin compatibility flags and lists. Helps us ensure compatibility with WooCommerce, Yoast, Rank Math, Elementor, and others.', 'infy-ai-image-seo-optimizer' ); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Hidden fields for user data -->
                <input type="hidden" name="user_email" value="<?php echo esc_attr( $infyp_user_email ); ?>" />
                <input type="hidden" name="user_name" value="<?php echo esc_attr( $infyp_user_name ); ?>" />
                <input type="hidden" name="user_id" value="<?php echo esc_attr( $current_user->ID ); ?>" />
            </div>
        </div>
    </form>
</div>
