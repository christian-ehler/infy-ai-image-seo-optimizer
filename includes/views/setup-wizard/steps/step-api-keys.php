<?php
/**
 * Setup Wizard - API Keys Step
 *
 * Since 1.0.6 this step is two conversational sub-screens: "Connect" (choose
 * between WordPress AI Connectors and plugin API keys, selection cards) and
 * "Provider key" (one provider visible at a time via tabs). All three key
 * inputs stay in the DOM so `collectStepData('api-keys')` and the
 * `infyp_after_api_key_fields` extension point are unchanged. The Connect
 * sub-screen only renders when WordPress AI is actually usable; otherwise the
 * provider sub-screen shows directly.
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

$infyp_has_openai    = $data['has_openai'];
$infyp_has_google    = $data['has_google'];
$infyp_has_anthropic = $data['has_anthropic'];
$infyp_has_any_key   = $infyp_has_openai || $infyp_has_google || $infyp_has_anthropic;
$infyp_wp_ai_ready   = ! empty( $data['wp_ai_ready'] );

// Pre-selection: honor the saved method; fresh installs with a usable
// connector and no keys default to WordPress AI.
$infyp_method = ( 'wp_ai' === ( $data['connection_method'] ?? '' ) || ( ! $infyp_has_any_key && $infyp_wp_ai_ready ) ) ? 'wp_ai' : 'api_keys';
if ( ! $infyp_wp_ai_ready ) {
    $infyp_method = 'api_keys';
}

// The provider tab opened first: the recommended one, unless only another
// provider already has a key.
$infyp_active_provider = 'google';
if ( ! $infyp_has_google && $infyp_has_anthropic ) {
    $infyp_active_provider = 'anthropic';
} elseif ( ! $infyp_has_google && $infyp_has_openai ) {
    $infyp_active_provider = 'openai';
}

// The model each provider starts with, from the model registry (the default
// model for its own provider, the registry's provider pick for the others).
$infyp_model_registry  = \IPAIS\Core\AI\INFYP_Model_Config_Registry::get_instance();
$infyp_provider_models = [];
$infyp_provider_descs  = [];
foreach ( [ 'google', 'openai', 'anthropic' ] as $infyp_provider_id ) {
    $infyp_provider_model_id = $infyp_model_registry->get_default_model_for_provider( $infyp_provider_id );
    $infyp_provider_config   = $infyp_model_registry->get_config( $infyp_provider_model_id );
    $infyp_provider_name     = $infyp_provider_config['display_name'] ?? $infyp_provider_model_id;
    $infyp_provider_cost     = isset( $infyp_provider_config['pricing']['measured_per_image'] ) ? (float) $infyp_provider_config['pricing']['measured_per_image'] * 1000 : 0.0;

    $infyp_provider_models[ $infyp_provider_id ] = $infyp_provider_name;
    // The month names the measurement behind 'measured_per_image' in the
    // model registry: update both together.
    $infyp_provider_descs[ $infyp_provider_id ]  = $infyp_provider_cost > 0
        ? sprintf(
            /* translators: 1: cost in US dollars, e.g. "$1.46", 2: AI model name, e.g. "Gemini 3.1 Flash-Lite" */
            __( 'Measured in November 2026 on 20 test images: about %1$s per 1,000 images with %2$s. You pay the provider directly, and prices change.', 'infy-ai-image-seo-optimizer' ),
            '$' . number_format_i18n( $infyp_provider_cost, 2 ),
            $infyp_provider_name
        )
        : '';
}

$infyp_providers = [
    'google'    => [
        'label'   => __( 'Google Gemini', 'infy-ai-image-seo-optimizer' ),
        'desc'    => $infyp_provider_descs['google'],
        'key_url' => 'https://aistudio.google.com/app/apikey',
        'key_cta' => __( 'Get API key', 'infy-ai-image-seo-optimizer' ),
        'has_key' => $infyp_has_google,
    ],
    'anthropic' => [
        'label'   => __( 'Anthropic Claude', 'infy-ai-image-seo-optimizer' ),
        'desc'    => $infyp_provider_descs['anthropic'],
        'key_url' => 'https://platform.claude.com/settings/keys',
        'key_cta' => __( 'Get API key', 'infy-ai-image-seo-optimizer' ),
        'has_key' => $infyp_has_anthropic,
    ],
    'openai'    => [
        'label'   => __( 'OpenAI', 'infy-ai-image-seo-optimizer' ),
        'desc'    => $infyp_provider_descs['openai'],
        'key_url' => 'https://platform.openai.com/api-keys',
        'key_cta' => __( 'Get API key', 'infy-ai-image-seo-optimizer' ),
        'has_key' => $infyp_has_openai,
    ],
];

$infyp_key_placeholders = [
    'google'    => __( 'Enter your Gemini API key', 'infy-ai-image-seo-optimizer' ),
    'anthropic' => __( 'Enter your Anthropic API key', 'infy-ai-image-seo-optimizer' ),
    'openai'    => __( 'Enter your OpenAI API key', 'infy-ai-image-seo-optimizer' ),
];
?>
<div class="infyp-wizard-step-content infyp-step-api-keys">
    <input type="hidden" id="infyp-connection-method" name="connection_method" value="<?php echo esc_attr( $infyp_method ); ?>" />

    <?php if ( $infyp_wp_ai_ready ) : ?>
    <!-- Sub-screen: Connect — how would you like to connect? -->
    <div class="infyp-subpanel is-active" data-subscreen="connect">
        <h2 class="infyp-step-title"><?php esc_html_e( 'How would you like to connect to AI?', 'infy-ai-image-seo-optimizer' ); ?></h2>
        <p class="infyp-step-description">
            <?php esc_html_e( 'Your site can talk to AI through WordPress itself, or with your own provider API keys. You can change this any time.', 'infy-ai-image-seo-optimizer' ); ?>
        </p>

        <div class="infyp-choice-grid" role="radiogroup" aria-label="<?php esc_attr_e( 'Connection method', 'infy-ai-image-seo-optimizer' ); ?>">
            <label class="infyp-choice-card<?php echo 'wp_ai' === $infyp_method ? ' is-selected' : ''; ?>">
                <input type="radio" class="infyp-visually-hidden infyp-connection-choice" name="infyp_connection_choice" value="wp_ai" <?php checked( 'wp_ai', $infyp_method ); ?> />
                <span class="infyp-choice-check" aria-hidden="true">
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                        <path d="M13.5 4.5L6 12L2.5 8.5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </span>
                <span class="infyp-choice-icon" aria-hidden="true">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                    </svg>
                </span>
                <span class="infyp-choice-title"><?php esc_html_e( 'WordPress AI (Connectors)', 'infy-ai-image-seo-optimizer' ); ?></span>
                <span class="infyp-choice-desc"><?php esc_html_e( 'Use the AI connector already configured in WordPress (Settings → Connectors).', 'infy-ai-image-seo-optimizer' ); ?></span>
                <span class="infyp-choice-badge"><?php esc_html_e( 'No API key needed', 'infy-ai-image-seo-optimizer' ); ?></span>
            </label>

            <label class="infyp-choice-card<?php echo 'api_keys' === $infyp_method ? ' is-selected' : ''; ?>">
                <input type="radio" class="infyp-visually-hidden infyp-connection-choice" name="infyp_connection_choice" value="api_keys" <?php checked( 'api_keys', $infyp_method ); ?> />
                <span class="infyp-choice-check" aria-hidden="true">
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                        <path d="M13.5 4.5L6 12L2.5 8.5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </span>
                <span class="infyp-choice-icon" aria-hidden="true">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>
                    </svg>
                </span>
                <span class="infyp-choice-title"><?php esc_html_e( 'Use my own API keys', 'infy-ai-image-seo-optimizer' ); ?></span>
                <span class="infyp-choice-desc"><?php esc_html_e( 'Bring a key from Google Gemini, Anthropic Claude or OpenAI.', 'infy-ai-image-seo-optimizer' ); ?></span>
                <?php if ( $infyp_has_any_key ) : ?>
                <span class="infyp-choice-badge is-detected"><?php esc_html_e( 'Keys detected', 'infy-ai-image-seo-optimizer' ); ?></span>
                <?php endif; ?>
            </label>
        </div>
    </div>
    <?php endif; ?>

    <!-- Sub-screen: Provider key — one provider at a time -->
    <div class="infyp-subpanel<?php echo $infyp_wp_ai_ready ? '' : ' is-active'; ?>" data-subscreen="providers">
        <h2 class="infyp-step-title"><?php esc_html_e( 'Paste a key from your favourite provider', 'infy-ai-image-seo-optimizer' ); ?></h2>
        <p class="infyp-step-description">
            <?php esc_html_e( 'Pick one to start — you can add more providers later in the plugin settings.', 'infy-ai-image-seo-optimizer' ); ?>
        </p>

        <div class="infyp-provider-tabs" role="tablist" aria-label="<?php esc_attr_e( 'AI providers', 'infy-ai-image-seo-optimizer' ); ?>">
            <?php foreach ( $infyp_providers as $infyp_provider_key => $infyp_provider ) : ?>
            <button type="button"
                class="infyp-provider-tab<?php echo $infyp_provider_key === $infyp_active_provider ? ' is-active' : ''; ?>"
                role="tab"
                aria-selected="<?php echo $infyp_provider_key === $infyp_active_provider ? 'true' : 'false'; ?>"
                data-provider="<?php echo esc_attr( $infyp_provider_key ); ?>">
                <?php echo esc_html( $infyp_provider['label'] ); ?>
                <?php if ( 'google' === $infyp_provider_key ) : ?>
                <span class="infyp-provider-tab-badge"><?php esc_html_e( 'Recommended', 'infy-ai-image-seo-optimizer' ); ?></span>
                <?php endif; ?>
                <?php if ( $infyp_provider['has_key'] ) : ?>
                <span class="infyp-provider-tab-dot" aria-hidden="true"></span>
                <?php endif; ?>
            </button>
            <?php endforeach; ?>
        </div>

        <div class="infyp-api-keys-grid infyp-provider-panes">
            <?php foreach ( $infyp_providers as $infyp_provider_key => $infyp_provider ) : ?>
            <div class="infyp-api-card<?php echo esc_attr( ( $infyp_provider['has_key'] ? ' has-key' : '' ) . ( $infyp_provider_key === $infyp_active_provider ? ' is-active-pane' : '' ) ); ?>" data-provider="<?php echo esc_attr( $infyp_provider_key ); ?>">
                <div class="infyp-api-card-header">
                    <div class="infyp-api-provider-info">
                        <div>
                            <h4><?php echo esc_html( $infyp_provider['label'] ); ?></h4>
                            <span class="infyp-api-badge<?php echo 'google' === $infyp_provider_key ? ' recommended' : ''; ?>"><?php echo esc_html( $infyp_provider_models[ $infyp_provider_key ] ); ?></span>
                        </div>
                    </div>
                    <div class="infyp-api-status" id="infyp-status-<?php echo esc_attr( $infyp_provider_key ); ?>">
                        <?php if ( $infyp_provider['has_key'] ) : ?>
                            <span class="infyp-status-badge connected">
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                                    <path d="M13.5 4.5L6 12L2.5 8.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                <?php esc_html_e( 'Connected', 'infy-ai-image-seo-optimizer' ); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ( '' !== $infyp_provider['desc'] ) : ?>
                <p class="infyp-api-card-desc"><?php echo esc_html( $infyp_provider['desc'] ); ?></p>
                <?php endif; ?>
                <?php
                // A stored key must be visible as such: masked placeholder +
                // keep-hint instead of an empty-looking "enter your key" field.
                $infyp_masked      = $data['masked_keys'][ $infyp_provider_key ] ?? '';
                $infyp_placeholder = ( $infyp_provider['has_key'] && '' !== $infyp_masked )
                    ? $infyp_masked
                    : $infyp_key_placeholders[ $infyp_provider_key ];
                ?>
                <div class="infyp-api-input-wrapper">
                    <input type="password"
                           class="infyp-api-input"
                           id="infyp-api-<?php echo esc_attr( $infyp_provider_key ); ?>"
                           name="<?php echo esc_attr( $infyp_provider_key ); ?>"
                           placeholder="<?php echo esc_attr( $infyp_placeholder ); ?>"
                           data-provider="<?php echo esc_attr( $infyp_provider_key ); ?>"
                           autocomplete="off" />
                    <button type="button" class="infyp-toggle-visibility" aria-label="<?php esc_attr_e( 'Toggle visibility', 'infy-ai-image-seo-optimizer' ); ?>">
                        <svg class="eye-open" width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <path d="M2.5 10C2.5 10 5 4.16667 10 4.16667C15 4.16667 17.5 10 17.5 10C17.5 10 15 15.8333 10 15.8333C5 15.8333 2.5 10 2.5 10Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            <circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.5"/>
                        </svg>
                        <svg class="eye-closed infyp-hidden" width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <path d="M2.5 2.5L17.5 17.5M8.23 8.23C7.77 8.69 7.5 9.31 7.5 10C7.5 11.38 8.62 12.5 10 12.5C10.69 12.5 11.31 12.23 11.77 11.77M14.95 14.95C13.55 15.95 11.85 16.67 10 16.67C5 16.67 2.5 10 2.5 10C3.37 8.22 4.69 6.69 6.27 5.57M10 3.33C11.1 3.33 12.15 3.56 13.12 3.97" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                </div>
                <?php if ( $infyp_provider['has_key'] ) : ?>
                <p class="infyp-key-keep-hint">
                    <?php esc_html_e( 'A key is already saved — leave empty to keep it.', 'infy-ai-image-seo-optimizer' ); ?>
                </p>
                <?php endif; ?>
                <div class="infyp-api-card-actions">
                    <a href="<?php echo esc_url( $infyp_provider['key_url'] ); ?>" target="_blank" rel="noopener" class="infyp-get-key-link">
                        <?php echo esc_html( $infyp_provider['key_cta'] ); ?>
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                            <path d="M6 3H3V13H13V10M9 3H13V7M13 3L7 9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>
                    <button type="button" class="infyp-wizard-test-key" data-provider="<?php echo esc_attr( $infyp_provider_key ); ?>">
                        <?php esc_html_e( 'Test Key', 'infy-ai-image-seo-optimizer' ); ?>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php
        /**
         * Fires after the built-in API key cards in the Setup Wizard.
         *
         * Extensions can render additional API key input fields here. Their
         * output stays visible below the provider tabs regardless of which
         * provider pane is open.
         *
         * @since 1.0.0
         */
        do_action( 'infyp_after_api_key_fields' );
        ?>

        <div class="infyp-api-notice" id="infyp-api-notice">
            <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                <path d="M10 18C14.4183 18 18 14.4183 18 10C18 5.58172 14.4183 2 10 2C5.58172 2 2 5.58172 2 10C2 14.4183 5.58172 18 10 18Z" stroke="currentColor" stroke-width="1.5"/>
                <path d="M10 6V10M10 14H10.01" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
            </svg>
            <span><?php esc_html_e( 'Enter at least one API key to continue. Your keys are encrypted and stored securely.', 'infy-ai-image-seo-optimizer' ); ?></span>
        </div>
    </div>
</div>
