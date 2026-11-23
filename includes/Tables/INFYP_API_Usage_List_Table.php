<?php
/**
 * API Usage List Table - AI Provider Call Logs
 *
 * Extends WP_List_Table to display a sortable, filterable, and paginated
 * table of all AI provider API calls made by the plugin. Shown on the
 * API Usage admin submenu page.
 *
 * Columns:
 * - Date/Time: When the API call was made (sortable, default DESC)
 * - Provider: AI provider name (sortable, filterable)
 * - Model: Specific AI model used
 * - Status: Success/Error/Rate Limited badges (sortable, filterable)
 * - Response Time: Call duration in seconds
 * - Est. Cost: Estimated cost in dollars
 * - Image: Thumbnail of the processed image
 * - Error: Truncated error message (if any)
 *
 * Filters:
 * - Provider dropdown (Google, OpenAI, Anthropic, extensible via filter)
 * - Status dropdown (Success, Error, Rate Limited)
 * - Date range dropdown (24h, 7d, 30d, All Time)
 *
 * Data Source:
 * - Custom table: {$wpdb->prefix}infyp_api_usage
 * - Written by INFYP_API_Usage_Tracker
 *
 * @package   IPAIS\Tables
 * @since 1.0.0
 * @author    InfyPress
 * @license   GPL-2.0-or-later
 *
 * @see       IPAIS\Core\INFYP_API_Usage_Tracker For data storage
 * @see       IPAIS\Admin\INFYP_API_Usage_Page For page rendering
 */

namespace IPAIS\Tables;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

/**
 * API Usage List Table Class
 *
 * Extends WP_List_Table to display API usage logs with filtering,
 * sorting, and pagination support.
 *
 * Screen Option:
 * - api_usage_per_page (default: 20)
 *
 * @package   IPAIS\Tables
 * @since 1.0.0
 */
class INFYP_API_Usage_List_Table extends \WP_List_Table {

    /**
     * Constructor. Sets up the table with singular, plural, and AJAX properties.
     *
     * @since 1.0.0
     */
    public function __construct() {
        parent::__construct([
            'singular' => __('API Call', 'infy-ai-image-seo-optimizer'),
            'plural'   => __('API Calls', 'infy-ai-image-seo-optimizer'),
            'ajax'     => false
        ]);
    }

    /**
     * Defines the columns that will be displayed in the table.
     *
     * @since 1.0.0
     * @return array An associative array of column slugs to titles.
     */
    public function get_columns() {
        return [
            'timestamp' => __('Date/Time', 'infy-ai-image-seo-optimizer'),
            'provider' => __('Provider', 'infy-ai-image-seo-optimizer'),
            'model' => __('Model', 'infy-ai-image-seo-optimizer'),
            'status' => __('Status', 'infy-ai-image-seo-optimizer'),
            'response_time' => __('Response Time', 'infy-ai-image-seo-optimizer'),
            'cost' => __('Est. Cost', 'infy-ai-image-seo-optimizer'),
            'image' => __('Image', 'infy-ai-image-seo-optimizer'),
            'error' => __('Error', 'infy-ai-image-seo-optimizer'),
        ];
    }

    /**
     * Defines which columns are sortable.
     *
     * @since 1.0.0
     * @return array An associative array of sortable columns.
     */
    protected function get_sortable_columns() {
        return [
            'timestamp' => ['timestamp', true],
            'provider' => ['provider', false],
            'status' => ['status', false],
            'cost' => ['cost', false],
        ];
    }

    /**
     * Prepares the list of items for display.
     *
     * Retrieves API usage data from the database, applies filters,
     * handles sorting and pagination.
     *
     * @since 1.0.0
     * @return void
     */
    public function prepare_items() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- This is a read-only admin list table display
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'infyp_api_usage';
        $per_page = $this->get_items_per_page('api_usage_per_page', 20);
        $current_page = $this->get_pagenum();
        
        // Handle filters
        $where_clauses = ['1=1'];
        $where_values = [];
        
        if (!empty($_REQUEST['provider'])) {
            $where_clauses[] = 'provider = %s';
            $where_values[] = sanitize_text_field(wp_unslash($_REQUEST['provider']));
        }
        
        if (!empty($_REQUEST['status'])) {
            $where_clauses[] = 'status = %s';
            $where_values[] = sanitize_text_field(wp_unslash($_REQUEST['status']));
        }
        
        if (!empty($_REQUEST['date_range'])) {
            $days = intval(wp_unslash($_REQUEST['date_range']));
            $where_clauses[] = 'timestamp >= DATE_SUB(NOW(), INTERVAL %d DAY)';
            $where_values[] = $days;
        }
        
        $where = implode(' AND ', $where_clauses);
        
        // Count total items
        $sql_count = "SELECT COUNT(*) FROM $table_name WHERE $where";
        if (!empty($where_values)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query template with placeholders, prepared below
            $sql_count = $wpdb->prepare($sql_count, $where_values);
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared above with wpdb->prepare(), table name from $wpdb->prefix is safe, list table data doesn't need caching
        $total_items = $wpdb->get_var($sql_count);
        
        // Get items
        $allowed_orderby = [ 'timestamp', 'provider', 'status', 'cost' ];
        $orderby_raw = isset($_REQUEST['orderby']) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'timestamp';
        $orderby = in_array( $orderby_raw, $allowed_orderby, true ) ? $orderby_raw : 'timestamp';
        $order = isset($_REQUEST['order']) && in_array(strtoupper(sanitize_text_field(wp_unslash($_REQUEST['order']))), ['ASC', 'DESC']) ? sanitize_text_field(wp_unslash($_REQUEST['order'])) : 'DESC';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        
        $sql = "SELECT * FROM $table_name WHERE $where ORDER BY $orderby $order LIMIT %d OFFSET %d";
        $sql_values = array_merge($where_values, [$per_page, ($current_page - 1) * $per_page]);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query template with placeholders prepared via wpdb->prepare(), table name from $wpdb->prefix is safe, orderby validated by sanitize_sql_orderby(), order validated against whitelist, list table data doesn't need caching
        $this->items = $wpdb->get_results($wpdb->prepare($sql, $sql_values), ARRAY_A);
        
        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page' => $per_page
        ]);
        
        $this->_column_headers = $this->get_column_info();
    }

    /**
     * Displays extra table navigation controls.
     *
     * Renders filter dropdowns for provider, status, and date range.
     *
     * @since 1.0.0
     * @param string $which The location of the extra table nav (top or bottom).
     * @return void
     */
    protected function extra_tablenav($which) {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- This is a read-only filter form in admin
        if ($which === 'top') {
            ?>
            <div class="alignleft actions">
                <select name="provider" id="filter-by-provider">
                    <option value=""><?php esc_html_e('All Providers', 'infy-ai-image-seo-optimizer'); ?></option>
                    <?php
                    $current_provider = isset($_REQUEST['provider']) ? sanitize_text_field(wp_unslash($_REQUEST['provider'])) : '';

                    /** This filter is documented in INFYP_API_Usage_List_Table::get_provider_display_names() */
                    $filter_providers = self::get_provider_display_names();

                    foreach ($filter_providers as $slug => $label) {
                        printf(
                            '<option value="%s" %s>%s</option>',
                            esc_attr($slug),
                            selected($current_provider, $slug, false),
                            esc_html($label)
                        );
                    }
                    ?>
                </select>
                
                <select name="status" id="filter-by-status">
                    <option value=""><?php esc_html_e('All Statuses', 'infy-ai-image-seo-optimizer'); ?></option>
                    <option value="success" <?php selected(isset($_REQUEST['status']) && sanitize_text_field(wp_unslash($_REQUEST['status'])) === 'success'); ?>><?php esc_html_e('Success', 'infy-ai-image-seo-optimizer'); ?></option>
                    <option value="error" <?php selected(isset($_REQUEST['status']) && sanitize_text_field(wp_unslash($_REQUEST['status'])) === 'error'); ?>><?php esc_html_e('Error', 'infy-ai-image-seo-optimizer'); ?></option>
                    <option value="rate_limited" <?php selected(isset($_REQUEST['status']) && sanitize_text_field(wp_unslash($_REQUEST['status'])) === 'rate_limited'); ?>><?php esc_html_e('Rate Limited', 'infy-ai-image-seo-optimizer'); ?></option>
                </select>
                
                <select name="date_range" id="filter-by-date">
                    <option value=""><?php esc_html_e('All Time', 'infy-ai-image-seo-optimizer'); ?></option>
                    <option value="1" <?php selected(isset($_REQUEST['date_range']) && sanitize_text_field(wp_unslash($_REQUEST['date_range'])) === '1'); ?>><?php esc_html_e('Last 24 Hours', 'infy-ai-image-seo-optimizer'); ?></option>
                    <option value="7" <?php selected(isset($_REQUEST['date_range']) && sanitize_text_field(wp_unslash($_REQUEST['date_range'])) === '7'); ?>><?php esc_html_e('Last 7 Days', 'infy-ai-image-seo-optimizer'); ?></option>
                    <option value="30" <?php selected(isset($_REQUEST['date_range']) && sanitize_text_field(wp_unslash($_REQUEST['date_range'])) === '30'); ?>><?php esc_html_e('Last 30 Days', 'infy-ai-image-seo-optimizer'); ?></option>
                </select>
                
                <?php submit_button(__('Filter', 'infy-ai-image-seo-optimizer'), '', 'filter_action', false); ?>
            </div>
            <?php
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    /**
     * Renders the timestamp column.
     *
     * @since 1.0.0
     * @param array $item The current row item data.
     * @return string Formatted date/time string.
     */
    public function column_timestamp($item) {
        return esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $item['timestamp'] ) ) );
    }

    /**
     * Renders the provider column with a human-readable display name.
     *
     * @since 1.0.0
     * @param array $item The current row item data.
     * @return string Provider display name.
     */
    public function column_provider($item) {
        $providers = self::get_provider_display_names();
        return esc_html($providers[$item['provider']] ?? ucfirst($item['provider']));
    }

    /**
     * Returns the provider slug → display name map.
     *
     * Builds the map from built-in defaults, merges display names from
     * registered AI providers, and allows extensions to add or override
     * names via the `infyp_provider_display_names` filter.
     *
     * @since 1.0.0
     * @return array Associative array of slug => display name.
     */
    public static function get_provider_display_names() {
        $names = [
            'google'    => 'Google',
            'openai'    => 'OpenAI',
            'anthropic' => 'Anthropic',
            // Static entry (not only via the instance merge below) so
            // historical rows keep their label after a WordPress downgrade.
            'wordpress' => 'WordPress AI',
        ];

        // Merge display names from registered AI providers.
        if (class_exists('\\IPAIS\\Core\\AI\\INFYP_AI_Provider_Manager')) {
            $manager = \IPAIS\Core\AI\INFYP_AI_Provider_Manager::get_instance();
            foreach ($manager->get_registered_providers() as $slug => $instance) {
                if (!isset($names[$slug])) {
                    $names[$slug] = $instance->get_name();
                }
            }
        }

        /**
         * Filters the provider slug → display name map.
         *
         * Used across the API Usage list table, usage page view, and chart labels.
         * Extensions registering custom providers should hook here to provide
         * a proper display name for their provider slug.
         *
         * @since 1.0.0
         * @param array $names Associative array of provider slug => display name.
         */
        return apply_filters('infyp_provider_display_names', $names);
    }

    /**
     * Renders the model column.
     *
     * @since 1.0.0
     * @param array $item The current row item data.
     * @return string Model name in code formatting.
     */
    public function column_model($item) {
        return '<code>' . esc_html($item['model']) . '</code>';
    }

    /**
     * Renders the status column with color-coded badges.
     *
     * @since 1.0.0
     * @param array $item The current row item data.
     * @return string Status badge HTML.
     */
    public function column_status($item) {
        $status_classes = [
            'success' => 'notice-success',
            'error' => 'notice-error',
            'rate_limited' => 'notice-warning'
        ];
        
        $status_labels = [
            'success' => __('Success', 'infy-ai-image-seo-optimizer'),
            'error' => __('Error', 'infy-ai-image-seo-optimizer'),
            'rate_limited' => __('Rate Limited', 'infy-ai-image-seo-optimizer')
        ];
        
        $class = $status_classes[$item['status']] ?? '';
        $status_text = $status_labels[$item['status']] ?? ucfirst($item['status']);
        $retry_text = $item['retry_count'] > 0 ? sprintf(' (%d %s)', $item['retry_count'], _n('retry', 'retries', $item['retry_count'], 'infy-ai-image-seo-optimizer')) : '';
        
        return sprintf(
            '<span class="infyp-status %s">%s%s</span>',
            esc_attr($class),
            esc_html($status_text),
            esc_html($retry_text)
        );
    }

    /**
     * Renders the response time column.
     *
     * @since 1.0.0
     * @param array $item The current row item data.
     * @return string Response time in seconds or em-dash if not available.
     */
    public function column_response_time($item) {
        if ($item['response_time']) {
            /* translators: %s: Response time in seconds */
            return esc_html( sprintf( __( '%.2fs', 'infy-ai-image-seo-optimizer' ), $item['response_time'] ) );
        }
        return '—';
    }

    /**
     * Renders the estimated cost column.
     *
     * @since 1.0.0
     * @param array $item The current row item data.
     * @return string Formatted cost in dollars or em-dash if not available.
     */
    public function column_cost($item) {
        if ($item['cost']) {
            /* translators: %s: Cost in dollars */
            return esc_html( sprintf( __( '$%s', 'infy-ai-image-seo-optimizer' ), number_format( $item['cost'], 4 ) ) );
        }
        return '—';
    }

    /**
     * Renders the image column with thumbnail and edit link.
     *
     * @since 1.0.0
     * @param array $item The current row item data.
     * @return string Image thumbnail HTML or em-dash if not available.
     */
    public function column_image($item) {
        if ($item['image_id']) {
            // Use thumbnail size with sizes attribute for optimal loading
            // sizes="40px" tells browser to pick smallest appropriate srcset image
            $thumb = wp_get_attachment_image(
                $item['image_id'],
                'thumbnail',
                false,
                array(
                    'loading'  => 'lazy',
                    'decoding' => 'async',
                    'style'    => 'width: 40px; height: 40px; object-fit: cover;',
                    'sizes'    => '40px',
                )
            );
            if ($thumb) {
                return sprintf(
                    '<a href="%s" target="_blank">%s</a>',
                    esc_url(get_edit_post_link($item['image_id'])),
                    $thumb
                );
            }
        }
        return '—';
    }

    /**
     * Renders the error column with truncated message.
     *
     * @since 1.0.0
     * @param array $item The current row item data.
     * @return string Truncated error message or em-dash if no error.
     */
    public function column_error($item) {
        if ($item['error_message']) {
            return sprintf(
                '<span class="infyp-error-message" title="%s">%s</span>',
                esc_attr($item['error_message']),
                esc_html(wp_trim_words($item['error_message'], 10))
            );
        }
        return '—';
    }
}