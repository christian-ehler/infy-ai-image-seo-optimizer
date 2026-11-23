<?php
/**
 * AI Prompt Settings Meta Box Template
 *
 * Displays the AI model selector, language picker, prompt add-on textarea,
 * and per-image prompt toggle.
 *
 * Available variables (prepared by render_prompt_settings_meta_box()):
 *   $ai_model              string  Currently selected AI model key
 *   $ai_models             array   Grouped model options for dropdown
 *   $language              string  Currently selected language locale
 *   $supported_languages   array   Locale code => display name pairs
 *   $prompt_addon          string  Current prompt add-on text
 *   $enable_per_image      bool    Whether per-image prompts are enabled
 *
 * @package   IPAIS\Admin
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<form id="infyp-prompt-settings-form" class="infyp-settings-form">
    <div class="infyp-form-grid-50">
        <div class="infyp-form-row">
            <label for="infyp_ai_model_accordion" class="infyp-form-label">
                <strong><?php esc_html_e( 'AI Model', 'infy-ai-image-seo-optimizer' ); ?></strong>
            </label>
            <p class="description infyp-field-description"><?php esc_html_e( 'Select the AI model for generating SEO data.', 'infy-ai-image-seo-optimizer' ); ?></p>
            <select id="infyp_ai_model_accordion" name="infyp_optimization_settings[ai_model]" class="widefat">
                <?php
                foreach ( $ai_models as $infyp_group_label => $infyp_models ) {
                    if ( ! empty( $infyp_models ) ) {
                        echo '<optgroup label="' . esc_attr( $infyp_group_label ) . '">';
                        foreach ( $infyp_models as $infyp_model_key => $infyp_model_label ) {
                            echo '<option value="' . esc_attr( $infyp_model_key ) . '" '
                                 . selected( $ai_model, $infyp_model_key, false ) . '>'
                                 . esc_html( $infyp_model_label ) . '</option>';
                        }
                        echo '</optgroup>';
                    }
                }
                ?>
            </select>
            <?php if ( \IPAIS\Core\AI\INFYP_WP_AI_Support::is_active() ) : ?>
                <span class="infyp-wp-ai-active-badge" title="<?php esc_attr_e( 'Your model choice is sent as a preference; WordPress routes the request to a compatible model from the configured connectors.', 'infy-ai-image-seo-optimizer' ); ?>">
                    <svg width="12" height="12" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                        <path d="M13.5 4.5L6 12L2.5 8.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <?php esc_html_e( 'AI Connectors active', 'infy-ai-image-seo-optimizer' ); ?>
                </span>
            <?php endif; ?>
            <div id="infyp-api-key-notice" class="notice notice-warning notice-alt is-dismissible infyp-model-notice"></div>
        </div>

        <div class="infyp-form-row">
            <label for="infyp_language_accordion" class="infyp-form-label">
                <strong><?php esc_html_e( 'Language', 'infy-ai-image-seo-optimizer' ); ?></strong>
            </label>
            <p class="description infyp-field-description"><?php esc_html_e( 'Choose the language for the generated text.', 'infy-ai-image-seo-optimizer' ); ?></p>
            <select id="infyp_language_accordion" name="infyp_prompt_settings[language]" class="widefat">
                <?php
                foreach ( $supported_languages as $infyp_lang_code => $infyp_lang_name ) {
                    echo '<option value="' . esc_attr( $infyp_lang_code ) . '" ' . selected( $language, $infyp_lang_code, false ) . '>' . esc_html( $infyp_lang_name ) . '</option>';
                }
                ?>
            </select>
        </div>
    </div>

    <div class="infyp-form-row">
        <label for="infyp_prompt_addon_accordion" class="infyp-form-label">
            <strong><?php esc_html_e( 'Prompt Add-on', 'infy-ai-image-seo-optimizer' ); ?></strong>
        </label>
        <p class="description infyp-field-description"><?php esc_html_e( 'Add custom instructions to the AI prompt (e.g., "Focus on e-commerce keywords").', 'infy-ai-image-seo-optimizer' ); ?></p>
        <textarea id="infyp_prompt_addon_accordion" name="infyp_prompt_settings[prompt_addon]" rows="4" class="widefat"<?php echo $enable_per_image ? ' disabled' : ''; ?>><?php echo esc_textarea( $prompt_addon ); ?></textarea>
    </div>

    <div class="infyp-form-row">
        <label class="infyp-form-label">
            <input type="checkbox" id="infyp_enable_per_image_prompt" name="infyp_prompt_settings[enable_per_image_prompt]" value="1" <?php checked( $enable_per_image ); ?>>
            <strong><?php esc_html_e( 'Enable custom prompt per image', 'infy-ai-image-seo-optimizer' ); ?></strong>
        </label>
        <p class="description infyp-field-description"><?php esc_html_e( 'When enabled, clicking "Optimize with AI" will first ask for image-specific instructions. This helps optimize different images more specifically without changing the global prompt add-on.', 'infy-ai-image-seo-optimizer' ); ?></p>
    </div>

    <div class="infyp-form-row infyp-form-actions">
        <button type="submit" id="infyp-save-prompt-settings" class="button button-primary"><?php esc_html_e( 'Save Settings', 'infy-ai-image-seo-optimizer' ); ?></button>
        <span id="infyp-prompt-save-status" class="infyp-save-status"></span>
    </div>
</form>
