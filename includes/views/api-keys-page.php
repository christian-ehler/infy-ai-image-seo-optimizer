<?php
/**
 * API keys page view - Redesigned with individual save buttons and Shadow Gradient theme
 * 
 * @package INFYP
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Use the secure API Key Manager to get masked keys
$infyp_api_key_manager = \IPAIS\Core\INFYP_API_Key_Manager::get_instance();
$infyp_masked_keys = $infyp_api_key_manager->get_all_api_keys(true); // true = mask keys for display
$infyp_key_status = $infyp_api_key_manager->get_api_key_status();

$infyp_google_key = isset($infyp_masked_keys['google']) ? $infyp_masked_keys['google'] : '';
$infyp_openai_key = isset($infyp_masked_keys['openai']) ? $infyp_masked_keys['openai'] : '';
$infyp_anthropic_key = isset($infyp_masked_keys['anthropic']) ? $infyp_masked_keys['anthropic'] : '';
?>
<div class="wrap infyp-api-keys-page" id="infyp-api-keys-wrap">
    <h1><?php esc_html_e( 'API Keys', 'infy-ai-image-seo-optimizer' ); ?><?php echo wp_kses_post( apply_filters('infyp_admin_header_badge', '') ); ?></h1>
    
    <div id="infyp-api-keys-notice" style="display: none;"></div>
    
    <!-- Overview Card -->
    <div class="infyp-card infyp-api-overview">
        <div class="infyp-card-header">
            <h3><?php esc_html_e('API Configuration Status', 'infy-ai-image-seo-optimizer'); ?></h3>
        </div>
        <div class="infyp-card-body">
            <div class="infyp-api-status-grid">
                <!-- Google Status -->
                <div class="infyp-api-status-item">
                    <div class="infyp-api-status-info">
                        <h4><?php esc_html_e('Google Gemini', 'infy-ai-image-seo-optimizer'); ?></h4>
                        <?php if ($infyp_key_status['google']): ?>
                            <span class="infyp-badge infyp-badge-success">
                                <span class="dashicons dashicons-yes-alt"></span>
                                <?php esc_html_e('Configured', 'infy-ai-image-seo-optimizer'); ?>
                            </span>
                        <?php else: ?>
                            <span class="infyp-badge infyp-badge-warning">
                                <span class="dashicons dashicons-warning"></span>
                                <?php esc_html_e('Not Configured', 'infy-ai-image-seo-optimizer'); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Anthropic Status -->
                <div class="infyp-api-status-item">
                    <div class="infyp-api-status-info">
                        <h4><?php esc_html_e('Anthropic Claude', 'infy-ai-image-seo-optimizer'); ?></h4>
                        <?php if ($infyp_key_status['anthropic']): ?>
                            <span class="infyp-badge infyp-badge-success">
                                <span class="dashicons dashicons-yes-alt"></span>
                                <?php esc_html_e('Configured', 'infy-ai-image-seo-optimizer'); ?>
                            </span>
                        <?php else: ?>
                            <span class="infyp-badge infyp-badge-warning">
                                <span class="dashicons dashicons-warning"></span>
                                <?php esc_html_e('Not Configured', 'infy-ai-image-seo-optimizer'); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- OpenAI Status -->
                <div class="infyp-api-status-item">
                    <div class="infyp-api-status-info">
                        <h4><?php esc_html_e('OpenAI GPT', 'infy-ai-image-seo-optimizer'); ?></h4>
                        <?php if ($infyp_key_status['openai']): ?>
                            <span class="infyp-badge infyp-badge-success">
                                <span class="dashicons dashicons-yes-alt"></span>
                                <?php esc_html_e('Configured', 'infy-ai-image-seo-optimizer'); ?>
                            </span>
                        <?php else: ?>
                            <span class="infyp-badge infyp-badge-warning">
                                <span class="dashicons dashicons-warning"></span>
                                <?php esc_html_e('Not Configured', 'infy-ai-image-seo-optimizer'); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- API Keys Form -->
    <form id="infyp-api-keys-form" method="post" action="options.php">
        <?php settings_fields('infyp_api_keys_settings_group'); ?>

        <?php
        $infyp_wp_ai_available  = \IPAIS\Core\AI\INFYP_WP_AI_Support::is_available();
        $infyp_wp_ai_configured = \IPAIS\Core\AI\INFYP_WP_AI_Support::is_configured();
        $infyp_connection_method = \IPAIS\Core\AI\INFYP_WP_AI_Support::get_connection_method();
        $infyp_wp_ai_selected   = ( 'wp_ai' === $infyp_connection_method );
        $infyp_wp_ai_connected  = array_values( array_filter(
            \IPAIS\Core\AI\INFYP_WP_AI_Support::get_provider_states(),
            static function ( $infyp_provider_state ) {
                return ! empty( $infyp_provider_state['connected'] );
            }
        ) );
        ?>
        <!-- Connection Method Card -->
        <div class="infyp-card infyp-connection-method-card">
            <div class="infyp-card-header">
                <h3><?php esc_html_e('Connection Method', 'infy-ai-image-seo-optimizer'); ?></h3>
            </div>
            <div class="infyp-card-body">
                <p class="infyp-form-description">
                    <?php esc_html_e('Choose how the plugin talks to AI services: with your own provider API keys, or through the AI connectors configured in WordPress itself (WordPress 7.0+).', 'infy-ai-image-seo-optimizer'); ?>
                </p>
                <div class="infyp-form-group">
                    <label for="infyp_connection_method" class="infyp-form-label">
                        <?php esc_html_e('Connection method', 'infy-ai-image-seo-optimizer'); ?>
                    </label>
                    <select id="infyp_connection_method"
                            name="infyp_connection_method"
                            class="infyp-form-control infyp-connection-method-select">
                        <option value="api_keys" <?php selected( ! $infyp_wp_ai_selected ); ?>>
                            <?php esc_html_e('Plugin API keys — you provide provider keys below (works on every WordPress version)', 'infy-ai-image-seo-optimizer'); ?>
                        </option>
                        <option value="wp_ai" <?php selected( $infyp_wp_ai_selected ); ?> <?php disabled( ! $infyp_wp_ai_available ); ?>>
                            <?php esc_html_e('WordPress AI (Connectors) — no plugin API key needed (WordPress 7.0+)', 'infy-ai-image-seo-optimizer'); ?>
                        </option>
                    </select>
                    <?php if ( ! $infyp_wp_ai_available ) : ?>
                        <p class="infyp-form-description infyp-wp-ai-state">
                            <span class="dashicons dashicons-info"></span>
                            <?php
                            printf(
                                /* translators: %s: current WordPress version */
                                esc_html__('Requires WordPress 7.0 or later. Your site runs WordPress %s.', 'infy-ai-image-seo-optimizer'),
                                esc_html( get_bloginfo('version') )
                            );
                            ?>
                        </p>
                    <?php else : ?>
                        <p class="infyp-form-description infyp-wp-ai-state" id="infyp-wp-ai-state">
                            <span class="infyp-wp-ai-providers" id="infyp-wp-ai-providers">
                                <?php if ( empty( $infyp_wp_ai_connected ) ) : ?>
                                    <?php esc_html_e('No connector configured.', 'infy-ai-image-seo-optimizer'); ?>
                                <?php else : ?>
                                    <?php foreach ( $infyp_wp_ai_connected as $infyp_provider_state ) : ?>
                                        <span class="infyp-badge infyp-badge-success infyp-wp-ai-provider-badge"><?php echo esc_html( $infyp_provider_state['name'] ); ?></span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </span>
                            <a href="<?php echo esc_url( \IPAIS\Core\AI\INFYP_WP_AI_Support::connectors_admin_url() ); ?>">
                                <?php
                                echo esc_html(
                                    empty( $infyp_wp_ai_connected )
                                        ? __( 'Configure an AI connector in Settings → Connectors', 'infy-ai-image-seo-optimizer' )
                                        : __( 'Manage connectors', 'infy-ai-image-seo-optimizer' )
                                );
                                ?>
                            </a>
                        </p>
                    <?php endif; ?>
                </div>
                <p class="infyp-form-description infyp-wp-ai-keys-note" <?php echo $infyp_wp_ai_selected ? '' : 'style="display:none;"'; ?>>
                    <span class="dashicons dashicons-info"></span>
                    <?php esc_html_e('API keys below are not used while WordPress AI is selected; they are kept for switching back.', 'infy-ai-image-seo-optimizer'); ?>
                </p>
            </div>
        </div>

        <!-- Google API Key Card -->
        <div class="infyp-card infyp-provider-card<?php echo $infyp_wp_ai_selected ? ' infyp-deemphasized' : ''; ?>">
            <div class="infyp-card-header">
                <h3>
                    <?php esc_html_e('Google Gemini API', 'infy-ai-image-seo-optimizer'); ?>
                </h3>
            </div>
            <div class="infyp-card-body">
                <div class="infyp-form-group">
                    <label for="infyp_google_api_key" class="infyp-form-label">
                        <?php esc_html_e('API Key', 'infy-ai-image-seo-optimizer'); ?>
                    </label>
                    <p class="infyp-form-description">
                        <?php esc_html_e('Enter your Google Gemini API key. You can obtain one from the Google AI Studio.', 'infy-ai-image-seo-optimizer'); ?>
                        <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener">
                            <?php esc_html_e('Get API Key', 'infy-ai-image-seo-optimizer'); ?> 
                            <span class="dashicons dashicons-external"></span>
                        </a>
                    </p>
                    <input type="password"
                           id="infyp_google_api_key"
                           name="infyp_api_keys[google]"
                           value=""
                           class="infyp-form-control infyp-api-key-input"
                           placeholder="<?php echo esc_attr($infyp_google_key ? $infyp_google_key : __('Enter your Google API key', 'infy-ai-image-seo-optimizer')); ?>"
                           autocomplete="new-password">
                    <?php if ($infyp_google_key): ?>
                        <p class="infyp-key-hint">
                            <span class="dashicons dashicons-lock"></span>
                            <?php esc_html_e('Your API key is encrypted and stored securely. Leave empty to keep the existing key.', 'infy-ai-image-seo-optimizer'); ?>
                        </p>
                    <?php endif; ?>
                </div>
                    <button type="button" class="button button-secondary infyp-save-individual" data-provider="google">
                        <?php esc_html_e('Save Gemini Key', 'infy-ai-image-seo-optimizer'); ?>
                    </button>
                    <button type="button" class="button infyp-test-key" data-provider="google">
                        <?php esc_html_e('Test Key', 'infy-ai-image-seo-optimizer'); ?>
                    </button>
            </div>
        </div>
        
        <!-- Anthropic API Key Card -->
        <div class="infyp-card infyp-provider-card<?php echo $infyp_wp_ai_selected ? ' infyp-deemphasized' : ''; ?>">
            <div class="infyp-card-header">
                <h3>
                    <?php esc_html_e('Anthropic Claude API', 'infy-ai-image-seo-optimizer'); ?>
                </h3>
            </div>
            <div class="infyp-card-body">
                <div class="infyp-form-group">
                    <label for="infyp_anthropic_api_key" class="infyp-form-label">
                        <?php esc_html_e('API Key', 'infy-ai-image-seo-optimizer'); ?>
                    </label>
                    <p class="infyp-form-description">
                        <?php esc_html_e('Enter your Anthropic API key. You can obtain one from the Anthropic console.', 'infy-ai-image-seo-optimizer'); ?>
                        <a href="https://platform.claude.com/settings/keys" target="_blank" rel="noopener">
                            <?php esc_html_e('Get API Key', 'infy-ai-image-seo-optimizer'); ?> 
                            <span class="dashicons dashicons-external"></span>
                        </a>
                    </p>
                    <input type="password"
                           id="infyp_anthropic_api_key"
                           name="infyp_api_keys[anthropic]"
                           value=""
                           class="infyp-form-control infyp-api-key-input"
                           placeholder="<?php echo esc_attr($infyp_anthropic_key ? $infyp_anthropic_key : __('Enter your Anthropic API key', 'infy-ai-image-seo-optimizer')); ?>"
                           autocomplete="new-password">
                    <?php if ($infyp_anthropic_key): ?>
                        <p class="infyp-key-hint">
                            <span class="dashicons dashicons-lock"></span>
                            <?php esc_html_e('Your API key is encrypted and stored securely. Leave empty to keep the existing key.', 'infy-ai-image-seo-optimizer'); ?>
                        </p>
                    <?php endif; ?>
                </div>
                    <button type="button" class="button button-secondary infyp-save-individual" data-provider="anthropic">
                        <?php esc_html_e('Save Anthropic Key', 'infy-ai-image-seo-optimizer'); ?>
                    </button>
                    <button type="button" class="button infyp-test-key" data-provider="anthropic">
                        <?php esc_html_e('Test Key', 'infy-ai-image-seo-optimizer'); ?>
                    </button>
            </div>
        </div>

        <!-- OpenAI API Key Card -->
        <div class="infyp-card infyp-provider-card<?php echo $infyp_wp_ai_selected ? ' infyp-deemphasized' : ''; ?>">
            <div class="infyp-card-header">
                <h3>
                    <?php esc_html_e('OpenAI GPT API', 'infy-ai-image-seo-optimizer'); ?>
                </h3>
            </div>
            <div class="infyp-card-body">
                <div class="infyp-form-group">
                    <label for="infyp_openai_api_key" class="infyp-form-label">
                        <?php esc_html_e('API Key', 'infy-ai-image-seo-optimizer'); ?>
                    </label>
                    <p class="infyp-form-description">
                        <?php esc_html_e('Enter your OpenAI API key. You can obtain one from the OpenAI platform.', 'infy-ai-image-seo-optimizer'); ?>
                        <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">
                            <?php esc_html_e('Get API Key', 'infy-ai-image-seo-optimizer'); ?>
                            <span class="dashicons dashicons-external"></span>
                        </a>
                    </p>
                    <input type="password"
                           id="infyp_openai_api_key"
                           name="infyp_api_keys[openai]"
                           value=""
                           class="infyp-form-control infyp-api-key-input"
                           placeholder="<?php echo esc_attr($infyp_openai_key ? $infyp_openai_key : __('Enter your OpenAI API key', 'infy-ai-image-seo-optimizer')); ?>"
                           autocomplete="new-password">
                    <?php if ($infyp_openai_key): ?>
                        <p class="infyp-key-hint">
                            <span class="dashicons dashicons-lock"></span>
                            <?php esc_html_e('Your API key is encrypted and stored securely. Leave empty to keep the existing key.', 'infy-ai-image-seo-optimizer'); ?>
                        </p>
                    <?php endif; ?>
                </div>
                    <button type="button" class="button button-secondary infyp-save-individual" data-provider="openai">
                        <?php esc_html_e('Save OpenAI Key', 'infy-ai-image-seo-optimizer'); ?>
                    </button>
                    <button type="button" class="button infyp-test-key" data-provider="openai">
                        <?php esc_html_e('Test Key', 'infy-ai-image-seo-optimizer'); ?>
                    </button>
            </div>
        </div>
        
        <?php
        /**
         * Fires after the built-in API key cards on the API Keys page.
         *
         * Extensions registering custom AI providers can use this hook to render
         * additional API key input fields. Each card should follow the same HTML
         * structure as the built-in cards (infyp-card with data-provider attribute).
         *
         * @since 1.0.0
         */
        do_action( 'infyp_after_api_key_fields' );
        ?>

        <!-- Save All Button -->
        <div class="infyp-form-actions">
            <?php submit_button( __( 'Save All API Keys', 'infy-ai-image-seo-optimizer' ), 'primary large', 'submit', false, ['id' => 'infyp-save-all-keys'] ); ?>
        </div>
    </form>

	<?php if (current_user_can('manage_options') && defined('WP_DEBUG') && WP_DEBUG): ?>
		<div class="infyp-card infyp-debug-section">
			<div class="infyp-card-header">
				<h3><?php esc_html_e('Encryption Status (Debug)', 'infy-ai-image-seo-optimizer'); ?></h3>
			</div>
			<div class="infyp-card-body">
				<?php
				$infyp_encryption_service = \IPAIS\Core\INFYP_Encryption_Service::get_instance();
				$infyp_test_results = $infyp_encryption_service->test_encryption();
				?>
				<ul class="infyp-debug-list">
					<li>
						<span class="infyp-debug-label"><?php esc_html_e('OpenSSL Available:', 'infy-ai-image-seo-optimizer'); ?></span>
						<span class="infyp-debug-status <?php echo esc_attr($infyp_test_results['openssl_available'] ? 'success' : 'error'); ?>">
							<?php if ($infyp_test_results['openssl_available']): ?>
								<span class="dashicons dashicons-yes-alt"></span>
							<?php else: ?>
								<span class="dashicons dashicons-dismiss"></span>
							<?php endif; ?>
						</span>
					</li>
					<li>
						<span class="infyp-debug-label"><?php esc_html_e('Encryption Working:', 'infy-ai-image-seo-optimizer'); ?></span>
						<span class="infyp-debug-status <?php echo esc_attr($infyp_test_results['encryption_working'] ? 'success' : 'error'); ?>">
							<?php if ($infyp_test_results['encryption_working']): ?>
								<span class="dashicons dashicons-yes-alt"></span>
							<?php else: ?>
								<span class="dashicons dashicons-dismiss"></span>
							<?php endif; ?>
						</span>
					</li>
					<li>
						<span class="infyp-debug-label"><?php esc_html_e('Decryption Working:', 'infy-ai-image-seo-optimizer'); ?></span>
						<span class="infyp-debug-status <?php echo esc_attr($infyp_test_results['decryption_working'] ? 'success' : 'error'); ?>">
							<?php if ($infyp_test_results['decryption_working']): ?>
								<span class="dashicons dashicons-yes-alt"></span>
							<?php else: ?>
								<span class="dashicons dashicons-dismiss"></span>
							<?php endif; ?>
						</span>
					</li>
					<li>
						<span class="infyp-debug-label"><?php esc_html_e('Key Generation:', 'infy-ai-image-seo-optimizer'); ?></span>
						<span class="infyp-debug-status <?php echo esc_attr($infyp_test_results['key_generation'] ? 'success' : 'error'); ?>">
							<?php if ($infyp_test_results['key_generation']): ?>
								<span class="dashicons dashicons-yes-alt"></span>
							<?php else: ?>
								<span class="dashicons dashicons-dismiss"></span>
							<?php endif; ?>
						</span>
					</li>
				</ul>
			</div>
		</div>
	<?php endif; ?>
</div>