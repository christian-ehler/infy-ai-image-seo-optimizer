<?php
/**
 * Image Column Renderer - List Table Column Output
 *
 * Handles rendering of individual columns for the image list table.
 * Extracted from INFYP_Image_List_Table to separate column rendering
 * concerns from table query/pagination logic.
 *
 * Each column method returns an HTML string for the cell content.
 * Methods rely on a batch-loaded metadata cache to avoid N+1 queries
 * (set via set_metadata_cache() after INFYP_Image_List_Table::batch_load_metadata()).
 *
 * Columns rendered:
 * - cb: Bulk-select checkbox (capability-gated)
 * - id: Attachment ID linked to Media Library edit screen
 * - thumbnail: 60px lazy-loaded image preview with fallback
 * - keywords: Tag badges with add/edit/clear actions (responsive: full + condensed)
 * - filename: Editable filename field with validation feedback
 * - title: Editable title field with validation feedback
 * - alt_text: Editable alt text field with validation feedback
 * - usage_info: "Check Usage" button to find posts using the image
 * - seo_score: SVG donut chart with percentage and status label
 * - actions: AI optimize button, SEO breakdown button, AI disclaimer
 *
 * Responsive Design:
 * - Each editable field (filename, title, alt_text) has two views:
 *   - Full view (>1248px): Input field + char count + validation badge
 *   - Condensed view (≤1248px): Compact button with status icon + tooltip
 * - Keywords column: Full badge view (>1420px) / compact count button (≤1420px)
 *
 * @package   IPAIS\Tables
 * @since 1.0.0
 * @author    InfyPress
 * @license   GPL-2.0-or-later
 *
 * @see       INFYP_Image_List_Table For the parent table class
 * @see       INFYP_Field_Validator For field validation logic
 * @see       INFYP_SEO_Score_Calculator For score calculation and display data
 */

namespace IPAIS\Tables;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use \IPAIS\Core\INFYP_SEO_Score_Calculator;
use \IPAIS\Core\INFYP_Field_Validator;

/**
 * Image Column Renderer Class
 *
 * Renders individual column HTML for each row in the image list table.
 * Uses a batch-loaded metadata cache to avoid per-row database queries.
 *
 * @package   IPAIS\Tables
 * @since 1.0.0
 */
class INFYP_Image_Column_Renderer {

    /**
     * Batch-loaded metadata cache for visible images
     *
     * Structure: [image_id => ['_wp_attachment_image_alt' => '', '_infyp_keywords' => [], ...]]
     * This eliminates N+1 queries by loading all metadata in one query.
     *
     * @since 1.0.0
     * @var array
     */
    private $metadata_cache = [];

    /**
     * Constructor
     *
     * @since 1.0.0
     * @param array $metadata_cache Batch-loaded metadata cache array from INFYP_Image_List_Table.
     */
    public function __construct( array $metadata_cache = [] ) {
        $this->metadata_cache = $metadata_cache;
    }

    /**
     * Set the metadata cache
     *
     * Allows updating the cache after construction, e.g. after batch_load_metadata()
     * has been called on the list table.
     *
     * @since 1.0.0
     * @param array $metadata_cache The metadata cache array.
     * @return void
     */
    public function set_metadata_cache( array $metadata_cache ) {
        $this->metadata_cache = $metadata_cache;
    }

    /**
     * Get the metadata cache
     *
     * @since 1.0.0
     * @return array The current metadata cache.
     */
    public function get_metadata_cache() {
        return $this->metadata_cache;
    }

    /**
     * Get cached metadata for an image
     *
     * Returns metadata from the batch-loaded cache. Falls back to individual
     * get_post_meta() calls if cache miss (should only happen in edge cases).
     *
     * @since 1.0.0
     * @param int    $image_id The attachment ID
     * @param string $meta_key The meta key to retrieve
     * @param mixed  $default  Default value if not found
     * @return mixed The meta value or default
     */
    private function get_cached_meta( $image_id, $meta_key, $default = '' ) {
        // Check if metadata is cached
        if ( isset( $this->metadata_cache[ $image_id ][ $meta_key ] ) ) {
            $value = $this->metadata_cache[ $image_id ][ $meta_key ];
            return ( $value !== '' && $value !== false ) ? $value : $default;
        }

        // Fallback to individual query (should rarely happen if batch_load_metadata was called)
        $value = get_post_meta( $image_id, $meta_key, true );

        // Store in cache for potential reuse within same request
        if ( ! isset( $this->metadata_cache[ $image_id ] ) ) {
            $this->metadata_cache[ $image_id ] = [];
        }
        $this->metadata_cache[ $image_id ][ $meta_key ] = $value;

        return ( $value !== '' && $value !== false ) ? $value : $default;
    }

    /**
     * Checkbox column for bulk selection
     *
     * @param object $item The current item
     * @return string
     */
    public function column_cb( $item ) {
        // Security: Only show checkboxes if user has proper capabilities
        if ( ! current_user_can( 'upload_files' ) ) {
            return '';
        }

        return sprintf(
            '<input type="checkbox" name="image[]" value="%s" class="infyp-image-checkbox" data-image-id="%s" />',
            esc_attr( $item->ID ),
            esc_attr( $item->ID )
        );
    }

    /**
     * Renders the 'id' column.
     *
     * Displays the attachment ID as a link to the Media Library edit screen.
     *
     * @since 1.0.0
     *
     * @param object $item The current WP_Post attachment object.
     * @return string HTML link to the attachment edit page.
     */
    public function column_id( $item ) {
        $edit_link = get_edit_post_link( $item->ID );
        return sprintf(
            '<a href="%s" title="%s" class="infyp-image-id">%s</a>',
            esc_url( $edit_link ),
            esc_attr__( 'Edit in Media Library', 'infy-ai-image-seo-optimizer' ),
            esc_html( $item->ID )
        );
    }

    /**
     * Renders the 'thumbnail' column.
     *
     * Shows a 60x60 lazy-loaded thumbnail via wp_get_attachment_image() with
     * srcset and sizes attributes for optimal responsive loading. Falls back
     * to the WordPress default media icon if the thumbnail cannot be generated.
     *
     * @since 1.0.0
     *
     * @param object $item The current WP_Post attachment object.
     * @return string HTML thumbnail wrapped in a preview link.
     */
    public function column_thumbnail( $item ) {
        $edit_link = get_edit_post_link( $item->ID );

        // Get alt text from batch-loaded metadata cache (avoids full image data fetch)
        $alt_text = $this->get_cached_meta( $item->ID, '_wp_attachment_image_alt', '' );

        // Use wp_get_attachment_image() for proper thumbnail handling
        // This uses WordPress's 'thumbnail' size (150x150) and includes:
        // - Proper srcset for retina/responsive displays
        // - Internal caching at postmeta level
        // - Automatic fallback handling
        // - Never loads full-size original images
        //
        // PERFORMANCE: The 'sizes' attribute is critical - it tells the browser
        // exactly what display size is needed (60px), so it picks the smallest
        // appropriate image from srcset (150w for 1x, 300w for 2x retina).
        // Without this, browser might download larger images unnecessarily.
        $image_html = wp_get_attachment_image(
            $item->ID,
            'thumbnail', // WordPress thumbnail size (150x150), NOT original
            false,       // Not an icon
            array(
                'loading'  => 'lazy',
                'decoding' => 'async',
                'alt'      => $alt_text,
                'class'    => 'infyp-table-thumbnail',
                'style'    => 'width: 60px; height: 60px; object-fit: cover;',
                'sizes'    => '60px', // Tell browser we only need 60px display size
            )
        );

        // Fallback if thumbnail generation fails (corrupted image, missing file, etc.)
        if ( empty( $image_html ) ) {
            $image_html = sprintf(
                '<img src="%s" width="60" height="60" alt="%s" class="infyp-table-thumbnail infyp-no-thumbnail" loading="lazy" />',
                esc_url( admin_url( 'images/media-default.png' ) ),
                esc_attr__( 'No thumbnail available', 'infy-ai-image-seo-optimizer' )
            );
        }

        return sprintf(
            '<a href="%s" class="infyp-thumbnail-preview" data-id="%d" title="%s">%s</a>',
            esc_url( $edit_link ),
            $item->ID,
            esc_attr__( 'Click to preview', 'infy-ai-image-seo-optimizer' ),
            $image_html
        );
    }

    /**
     * Renders the 'keywords' column.
     *
     * Displays keyword badges with add/edit/clear actions. Has two responsive layouts:
     * - Full view (>1420px): Individual keyword badges + edit/clear buttons
     * - Condensed view (≤1420px): Compact button showing keyword count
     *
     * When no keywords are set, shows an "Add Keywords" placeholder button.
     *
     * @since 1.0.0
     *
     * @param object $item The current WP_Post attachment object.
     * @return string HTML for keyword badges or add-keywords placeholder.
     */
    public function column_keywords($item) {
        // Use batch-loaded cache (avoids N+1 queries)
        $keywords = $this->get_cached_meta( $item->ID, '_infyp_keywords', [] );
        $keyword_count = is_array($keywords) ? count($keywords) : 0;

        if (empty($keywords) || !is_array($keywords)) {
            // Show clickable "Add keywords" button when no keywords
            return '<div class="infyp-keywords-wrapper infyp-keywords-empty" data-image-id="' . esc_attr($item->ID) . '">
                <button class="infyp-keyword-badge placeholder infyp-add-keywords-trigger" data-image-id="' . esc_attr($item->ID) . '">
                    <span class="dashicons dashicons-plus"></span> ' . esc_html__('Add Keywords', 'infy-ai-image-seo-optimizer') . '
                </button>
                <div class="infyp-keywords-help">
                    <span class="dashicons dashicons-info"></span>
                    <span class="infyp-tooltip">' . esc_html__('Click to add keywords manually or generate with AI', 'infy-ai-image-seo-optimizer') . '</span>
                </div>
            </div>';
        }

        // Display actual keywords with manage buttons
        // Full view (shown > 1420px): keyword badges + edit/clear buttons
        // Condensed view (shown <= 1420px): compact button with count
        $html = '<div class="infyp-keywords-wrapper infyp-keywords-filled" data-image-id="' . esc_attr($item->ID) . '">';

        // Full view - keyword badges (hidden on small screens via CSS)
        $html .= '<div class="infyp-keywords-list infyp-keywords-full-view">';
        foreach ($keywords as $keyword) {
            $html .= '<span class="infyp-keyword-badge">' . esc_html($keyword) . '</span>';
        }
        $html .= '<button class="infyp-keyword-badge placeholder infyp-add-keywords-trigger" data-image-id="' . esc_attr($item->ID) . '" title="' . esc_attr__('Edit keywords', 'infy-ai-image-seo-optimizer') . '">
            <span class="dashicons dashicons-edit"></span> ' . esc_html__('Edit Keywords', 'infy-ai-image-seo-optimizer') . '
        </button>';
        $html .= '<button class="infyp-keyword-badge infyp-clear-keywords-trigger" data-image-id="' . esc_attr($item->ID) . '" title="' . esc_attr__('Clear keywords', 'infy-ai-image-seo-optimizer') . '">
            <span class="dashicons dashicons-trash"></span>
        </button>';
        $html .= '</div>';

        // Condensed view - compact button with count (shown on small screens via CSS)
        $html .= '<div class="infyp-keywords-condensed-view">';
        $html .= '<button class="button button-small infyp-add-keywords-trigger infyp-keywords-btn infyp-has-keywords" data-image-id="' . esc_attr($item->ID) . '">';
        $html .= '<span class="dashicons dashicons-edit"></span>';
        $html .= '<span class="infyp-btn-text">' . esc_html__('Keywords', 'infy-ai-image-seo-optimizer') . '</span>';
        $html .= '<span class="infyp-keyword-count">' . esc_html($keyword_count) . '</span>';
        $html .= '</button>';
        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }

    /**
     * Renders the 'filename' column.
     *
     * Shows an editable (readonly until clicked) filename input with character count
     * and validation feedback from INFYP_Field_Validator. Responsive: full view
     * with input + badges, condensed view with icon-only button + tooltip.
     *
     * @since 1.0.0
     *
     * @param object $item The current WP_Post attachment object.
     * @return string HTML for the filename field with validation badges.
     */
    public function column_filename($item) {
        // Use batch-loaded cache instead of get_attached_file() (avoids individual DB query)
        $attached_file = $this->get_cached_meta( $item->ID, '_wp_attached_file', '' );
        $filename_part = $attached_file ? pathinfo($attached_file, PATHINFO_FILENAME) : '';
        // Ensure filename_part is never null
        $filename_part = $filename_part ?? '';
        $char_count = mb_strlen($filename_part, 'UTF-8');

        // Get keywords from batch-loaded cache (avoids N+1 query)
        $keywords = $this->get_cached_meta( $item->ID, '_infyp_keywords', [] );

        // Use the new validator
        $validation = INFYP_Field_Validator::validate_filename( $filename_part, $keywords );

        // Get dynamic parameters for display
        $params = INFYP_SEO_Score_Calculator::get_scoring_params();
        $filename_params = $params['filename'];

        $status_class = $validation['class'];
        // Contains safe HTML (dashicon span) — each part individually escaped.
        $status_text = '<span class="dashicons dashicons-' . esc_attr($validation['icon']) . '"></span> ' . esc_html($validation['text']);

        // Build tooltip for condensed view (escaped at insertion point).
        $tooltip = sprintf(
            /* translators: 1: filename, 2: character count, 3: status text */
            __( 'Filename: %1$s (%2$d chars) - %3$s', 'infy-ai-image-seo-optimizer' ),
            esc_html( $filename_part ),
            $char_count,
            wp_strip_all_tags( $validation['text'] )
        );

        return sprintf(
            '<div class="infyp-field-wrapper">
                <!-- Full view (hidden at ≤1248px) -->
                <div class="infyp-field-full-view">
                    <input type="text" class="infyp-editable-field infyp-filename-trigger" data-field="filename" data-image-id="%1$s" data-original-value="%2$s" value="%2$s" style="width: 100%%; cursor: pointer;" readonly />
                    <div class="infyp-badge-container">
                        <div class="infyp-field-feedback char-count">%3$d | %4$d %5$s</div>
                        <div class="infyp-field-feedback %6$s">
                            %7$s
                        </div>
                    </div>
                </div>
                <!-- Condensed view (shown at ≤1248px) -->
                <div class="infyp-field-condensed-view">
                    <button type="button" class="button button-small infyp-filename-trigger infyp-field-compact-btn %6$s" data-field="filename" data-image-id="%1$s" data-original-value="%2$s" title="%8$s">
                        <span class="dashicons dashicons-%9$s"></span>
                    </button>
                </div>
            </div>',
            esc_attr( $item->ID ),
            esc_attr( $filename_part ),
            $char_count,
            intval( $filename_params['max_chars'] ),
            esc_html__( 'chars', 'infy-ai-image-seo-optimizer' ),
            esc_attr( $status_class ),
            $status_text, // Contains safe HTML (dashicon span).
            esc_attr( $tooltip ),
            esc_attr( $validation['icon'] )
        );
    }

    /**
     * Renders the 'title' column.
     *
     * Shows an editable title input with character count and validation feedback.
     * Validation checks for keyword inclusion, length, and duplication with alt text.
     * Responsive: full view with input + badges, condensed view with icon button.
     *
     * @since 1.0.0
     *
     * @param object $item The current WP_Post attachment object.
     * @return string HTML for the title field with validation badges.
     */
    public function column_title($item) {
        // Ensure title is never null
        $title = $item->post_title ?? '';
        $char_count = mb_strlen($title, 'UTF-8');

        // Get alt text and keywords from batch-loaded cache (avoids N+1 queries)
        $alt_text = $this->get_cached_meta( $item->ID, '_wp_attachment_image_alt', '' );
        $keywords = $this->get_cached_meta( $item->ID, '_infyp_keywords', [] );

        // Use the new validator
        $validation = INFYP_Field_Validator::validate_title( $title, $alt_text, $keywords );

        // Get dynamic parameters for display
        $params = INFYP_SEO_Score_Calculator::get_scoring_params();
        $title_params = $params['title'];

        $status_class = $validation['class'];
        // Contains safe HTML (dashicon span) — each part individually escaped.
        $status_text = '<span class="dashicons dashicons-' . esc_attr($validation['icon']) . '"></span> ' . esc_html($validation['text']);

        // Build tooltip for condensed view (escaped at insertion point).
        $tooltip = sprintf(
            /* translators: 1: title, 2: character count, 3: status text */
            __( 'Title: %1$s (%2$d chars) - %3$s', 'infy-ai-image-seo-optimizer' ),
            esc_html( $title ),
            $char_count,
            wp_strip_all_tags( $validation['text'] )
        );

        return sprintf(
            '<div class="infyp-field-wrapper">
                <!-- Full view (hidden at ≤1248px) -->
                <div class="infyp-field-full-view">
                    <input type="text" class="infyp-editable-field infyp-title-trigger" data-field="title" data-image-id="%1$s" data-original-value="%2$s" value="%2$s" style="width: 100%%; cursor: pointer;" readonly />
                    <div class="infyp-badge-container">
                        <div class="infyp-field-feedback char-count">%3$d | %4$d %5$s</div>
                        <div class="infyp-field-feedback %6$s">
                            %7$s
                        </div>
                    </div>
                </div>
                <!-- Condensed view (shown at ≤1248px) -->
                <div class="infyp-field-condensed-view">
                    <button type="button" class="button button-small infyp-title-trigger infyp-field-compact-btn %6$s" data-field="title" data-image-id="%1$s" data-original-value="%2$s" title="%8$s">
                        <span class="dashicons dashicons-%9$s"></span>
                    </button>
                </div>
            </div>',
            esc_attr( $item->ID ),
            esc_attr( $title ),
            $char_count,
            intval( $title_params['max_chars'] ),
            esc_html__( 'chars', 'infy-ai-image-seo-optimizer' ),
            esc_attr( $status_class ),
            $status_text, // Contains safe HTML (dashicon span).
            esc_attr( $tooltip ),
            esc_attr( $validation['icon'] )
        );
    }

    /**
     * Renders the 'alt_text' column.
     *
     * Shows an editable alt text input with character count and validation feedback.
     * Includes safety guards to prevent output during admin_notices, unrelated AJAX
     * requests, or on non-plugin admin pages (protects against early-call side effects).
     *
     * @since 1.0.0
     *
     * @param object $item The current WP_Post attachment object.
     * @return string HTML for the alt text field with validation badges, or empty string if context is invalid.
     */
    public function column_alt_text($item) {
        // Check if we're in a valid context
        if (!is_object($item) || !isset($item->ID)) {
            return '';
        }

        // Don't output during admin_notices
        if (doing_action('admin_notices') || doing_action('all_admin_notices')) {
            return '';
        }

        // Don't output if we're in an AJAX request (unless rendering table data)
        if (wp_doing_ajax() && !doing_action('wp_ajax_infyp_render_table_row') && !doing_action('wp_ajax_infyp_get_table_data')) {
            return '';
        }

        // Additional safety: check if we're not on our plugin page
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && strpos($screen->id, 'ai-image-seo-optimizer') === false && !doing_action('infyp_before_table_display')) {
            return '';
        }

        // Get alt text and keywords from batch-loaded cache (avoids N+1 queries)
        $alt_text = $this->get_cached_meta( $item->ID, '_wp_attachment_image_alt', '' );
        $char_count = mb_strlen($alt_text, 'UTF-8');

        $keywords = $this->get_cached_meta( $item->ID, '_infyp_keywords', [] );

        // Use the new validator
        $validation = INFYP_Field_Validator::validate_alt_text( $alt_text, $keywords );

        $status_class = $validation['class'];
        // Contains safe HTML (dashicon span) — each part individually escaped.
        $status_text = '<span class="dashicons dashicons-' . esc_attr($validation['icon']) . '"></span> ' . esc_html($validation['text']);

        // Get dynamic parameters for display
        $params = INFYP_SEO_Score_Calculator::get_scoring_params();
        $alt_params = $params['alt_text'];

        // Build tooltip for condensed view (escaped at insertion point).
        $tooltip = sprintf(
            /* translators: 1: alt text, 2: character count, 3: status text */
            __( 'Alt Text: %1$s (%2$d chars) - %3$s', 'infy-ai-image-seo-optimizer' ),
            esc_html( $alt_text ),
            $char_count,
            wp_strip_all_tags( $validation['text'] )
        );

        // Build HTML string directly without output buffering
        $html = '<div class="infyp-field-wrapper">';

        // Full view (hidden at ≤1248px)
        $html .= '<div class="infyp-field-full-view">';
        $html .= '<input type="text" class="infyp-editable-field infyp-alt-text-trigger" ';
        $html .= 'data-field="alt_text" ';
        $html .= 'data-image-id="' . esc_attr($item->ID) . '" ';
        $html .= 'data-original-value="' . esc_attr($alt_text) . '" ';
        $html .= 'value="' . esc_attr($alt_text) . '" ';
        $html .= 'style="width: 100%; cursor: pointer;" ';
        $html .= 'readonly />';
        $html .= '<div class="infyp-badge-container">';
        $html .= '<div class="infyp-field-feedback char-count">' . esc_html($char_count) . ' | ' . esc_html($alt_params['max_chars']) . ' ' . esc_html__('chars', 'infy-ai-image-seo-optimizer') . '</div>';
        $html .= '<div class="infyp-field-feedback ' . esc_attr($status_class) . '">';
        $html .= $status_text; // Contains HTML for dashicons
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';

        // Condensed view (shown at ≤1248px)
        $html .= '<div class="infyp-field-condensed-view">';
        $html .= '<button type="button" class="button button-small infyp-alt-text-trigger infyp-field-compact-btn ' . esc_attr($status_class) . '" ';
        $html .= 'data-field="alt_text" ';
        $html .= 'data-image-id="' . esc_attr($item->ID) . '" ';
        $html .= 'data-original-value="' . esc_attr($alt_text) . '" ';
        $html .= 'title="' . esc_attr($tooltip) . '">';
        $html .= '<span class="dashicons dashicons-' . esc_attr($validation['icon']) . '"></span>';
        $html .= '</button>';
        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }

    /**
     * Renders the 'usage_info' column.
     *
     * Shows a "Check Usage" button that triggers an AJAX lookup to find
     * which posts, pages, and page builder content reference this image.
     *
     * @since 1.0.0
     *
     * @param object $item The current WP_Post attachment object.
     * @return string HTML button for checking image usage.
     */
    public function column_usage_info($item) {
        return sprintf(
            '<div class="infyp-usage-details">
                <button type="button" class="button button-large infyp-check-usage" data-id="%d" title="%s">
                    <span class="dashicons dashicons-visibility"></span>
                    <span class="infyp-usage-text">%s</span>
                </button>
            </div>',
            $item->ID,
            esc_attr__( 'Check where this image is used', 'infy-ai-image-seo-optimizer' ),
            esc_html__( 'Check Usage', 'infy-ai-image-seo-optimizer' )
        );
    }

    /**
     * Renders the 'seo_score' column.
     *
     * Displays an SVG donut chart with the image's SEO score percentage and
     * a color-coded status label (e.g., "Good", "Needs Work", "Optimized").
     * Uses cached scores from batch-loaded metadata; recalculates inline if
     * the cached score is 0 (which is never legitimate).
     *
     * Special case: 100% scores get hardcoded "Optimized" label and full ring.
     *
     * @since 1.0.0
     *
     * @param object $item The current WP_Post attachment object.
     * @return string HTML SVG donut chart with score and status label.
     */
    public function column_seo_score($item) {
        // Use cached score from batch-loaded metadata (avoids 5-7 DB queries per row)
        $percentage = intval( $this->get_cached_meta( $item->ID, '_infyp_seo_score', 0 ) );

        // A score of 0 is never legitimate (uniqueness alone awards 10+ points).
        // Recalculate inline so the correct score displays immediately.
        if ( 0 === $percentage ) {
            $result     = INFYP_SEO_Score_Calculator::calculate_score( $item->ID );
            $percentage = intval( $result['percentage'] );
            // Update internal cache so other columns (e.g. column_actions) see the fresh value.
            $this->metadata_cache[ $item->ID ]['_infyp_seo_score'] = $percentage;
        }

        $score_data = INFYP_SEO_Score_Calculator::get_display_data_from_percentage( $percentage );

        // Special handling for 100% score — hardcoded "100%" text, "Optimized" label.
        if ( $score_data['percentage'] == 100 ) {
            return sprintf(
                '<div class="infyp-seo-score-wrapper" data-image-id="%d">
                    <div class="infyp-score-circle small">
                        <svg class="infyp-score-svg" viewBox="0 0 36 36">
                            <path class="infyp-score-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"></path>
                            <path class="infyp-score-progress %s" stroke-dasharray="100, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"></path>
                        </svg>
                        <div class="infyp-score-text">
                            <span class="infyp-score-number">100%%</span>
                        </div>
                    </div>
                    <span class="infyp-score-label %s">%s</span>
                </div>',
                $item->ID,
                esc_attr( $score_data['status_class'] ),
                esc_attr( $score_data['status_class'] ),
                esc_html__( 'Optimized', 'infy-ai-image-seo-optimizer' )
            );
        }

        return sprintf(
            '<div class="infyp-seo-score-wrapper" data-image-id="%d">
                <div class="infyp-score-circle small">
                    <svg class="infyp-score-svg" viewBox="0 0 36 36">
                        <path class="infyp-score-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"></path>
                        <path class="infyp-score-progress %s" stroke-dasharray="%d, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"></path>
                    </svg>
                    <div class="infyp-score-text">
                        <span class="infyp-score-number">%d%%</span>
                    </div>
                </div>
                <span class="infyp-score-label %s">%s</span>
            </div>',
            $item->ID,
            esc_attr( $score_data['status_class'] ),
            intval( $score_data['percentage'] ),
            intval( $score_data['percentage'] ),
            esc_attr( $score_data['status_class'] ),
            esc_html( $score_data['status'] )
        );
    }

    /**
     * Renders the 'actions' column.
     *
     * Shows an "Optimize with AI" (or "Re-optimize") button, an SEO Breakdown
     * button that reveals the score component details, and an AI disclaimer tooltip.
     * Button style changes based on whether the image has been previously optimized.
     *
     * @since 1.0.0
     *
     * @param object $item The current WP_Post attachment object.
     * @return string HTML for action buttons and AI disclaimer.
     */
    public function column_actions($item) {
        // Get optimization status from batch-loaded cache (avoids N+1 query)
        $has_been_optimized = $this->get_cached_meta( $item->ID, '_infyp_has_been_optimized', false );
        $optimize_icon = $has_been_optimized
            ? '<span class="dashicons dashicons-yes-alt"></span>'
            : '<span class="infyp-optimize-icon"></span>';
        $optimize_text = $has_been_optimized
            ? __( 'Re-optimize', 'infy-ai-image-seo-optimizer' )
            : __( 'Optimize with AI', 'infy-ai-image-seo-optimizer' );
        $button_class = $has_been_optimized ? 'button-secondary infyp-has-icon' : 'button-primary';

        // Get current score from cache for the breakdown button (avoids duplicate calculate_score())
        $percentage = intval( $this->get_cached_meta( $item->ID, '_infyp_seo_score', 0 ) );

        return sprintf(
            '<div class="infyp-actions-wrapper">
                <button class="button %s button-large infyp-optimize-ai" data-id="%d" title="%s">%s<span class="infyp-btn-text">%s</span></button>
                <button class="button button-secondary button-small infyp-check-seo-breakdown" data-id="%d" data-score="%d" title="%s"><span class="dashicons dashicons-chart-bar"></span><span class="infyp-btn-text">%s</span></button>
                <div class="infyp-ai-disclaimer">
                    <span class="dashicons dashicons-info"></span>
                    <span class="infyp-disclaimer-text">%s</span>
                    <span class="infyp-tooltip">%s</span>
                </div>
            </div>',
            esc_attr( $button_class ),
            $item->ID,
            esc_attr( $optimize_text ),
            $optimize_icon, // Contains safe HTML (dashicon span).
            esc_html( $optimize_text ),
            $item->ID,
            intval( $percentage ),
            esc_attr__( 'Click to see what\'s needed for 100% score', 'infy-ai-image-seo-optimizer' ),
            esc_html__( 'SEO Breakdown', 'infy-ai-image-seo-optimizer' ),
            esc_html__( 'AI Disclaimer', 'infy-ai-image-seo-optimizer' ),
            esc_html__( 'AI can make mistakes. Please double-check responses.', 'infy-ai-image-seo-optimizer' )
        );
    }

    /**
     * Renders the default content for unrecognized columns.
     *
     * Allows extensions to handle custom columns via the
     * 'infyp_table_column_{$column_name}' filter. Returns empty string
     * if no extension handles the column.
     *
     * @since 1.0.0
     *
     * @param object $item        The current WP_Post attachment object.
     * @param string $column_name The column slug.
     * @return string HTML from extension filter or empty string.
     */
    public function column_default( $item, $column_name ) {
        // Allow extensions to handle custom columns
        $custom_content = apply_filters( 'infyp_table_column_' . $column_name, '', $item );
        if ( ! empty( $custom_content ) ) {
            // Use extended KSES rules that allow form elements needed for
            // editable table columns (input, button) in addition to post HTML.
            $allowed = wp_kses_allowed_html( 'post' );
            $allowed['input'] = [
                'type'                => true,
                'class'               => true,
                'data-field'          => true,
                'data-image-id'       => true,
                'data-original-value' => true,
                'value'               => true,
                'style'               => true,
                'readonly'            => true,
                'title'               => true,
            ];
            $allowed['button'] = [
                'type'                => true,
                'class'               => true,
                'data-field'          => true,
                'data-image-id'       => true,
                'data-original-value' => true,
                'title'               => true,
                'aria-label'          => true,
            ];
            return wp_kses( $custom_content, $allowed );
        }
        return '';
    }
}
