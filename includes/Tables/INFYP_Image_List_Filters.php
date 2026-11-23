<?php
/**
 * Image list table filter and sort UI controls.
 *
 * Handles rendering of post-type filters, page/post filters, SEO score sort,
 * and upload date sort controls for the image list table admin page.
 *
 * @package    IPAIS
 * @subpackage Tables
 * @since 1.0.0
 * @author     InfyPress
 * @license    GPL-2.0-or-later
 */

namespace IPAIS\Tables;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use \IPAIS\Core\INFYP_Cache_Manager;

/**
 * Class INFYP_Image_List_Filters
 *
 * Handles filter and sort UI rendering for the image list table.
 * Extracted from INFYP_Image_List_Table to separate concerns.
 *
 * Renders:
 * - Post type filter (multi-select token dropdown)
 * - Page/post filter (AJAX-powered search)
 * - SEO score sort control
 * - Upload date sort control
 *
 * @since 1.0.0
 * @package IPAIS\Tables
 */
class INFYP_Image_List_Filters {

	/**
	 * Cache manager instance
	 *
	 * @var INFYP_Cache_Manager
	 */
	private $cache;

	/**
	 * Constructor.
	 *
	 * @param INFYP_Cache_Manager $cache Cache manager instance.
	 */
	public function __construct( INFYP_Cache_Manager $cache ) {
		$this->cache = $cache;
	}

	/**
	 * Render the post type filter UI standalone.
	 *
	 * Called from main-page.php to output the multi-select token dropdown
	 * for filtering images by post type.
	 *
	 * @since 1.0.0
	 */
	public function render_post_type_filter_standalone() {
		$this->render_post_type_filter();
	}

	/**
	 * Render the page/post filter dropdown (called from main-page.php)
	 *
	 * Outputs the HTML shell for the AJAX-powered page/post filter.
	 * The dropdown results are populated dynamically via JS.
	 *
	 * @since 1.0.0
	 */
	public function render_page_filter() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI state
		$parent_post = isset( $_REQUEST['parent_post'] ) ? absint( $_REQUEST['parent_post'] ) : 0;
		$active_title = '';
		if ( $parent_post > 0 ) {
			$active_title = get_the_title( $parent_post );
			if ( empty( $active_title ) ) {
				$active_title = __( '(no title)', 'infy-ai-image-seo-optimizer' );
			}
		}
		?>
		<div class="infyp-page-filter-wrapper" id="infyp-page-filter-wrapper">
			<?php if ( $parent_post > 0 ) : ?>
				<div class="infyp-page-filter-active" id="infyp-page-filter-active">
					<span class="dashicons dashicons-admin-page"></span>
					<span class="infyp-page-filter-active-title" title="<?php echo esc_attr( $active_title ); ?>"><?php echo esc_html( $active_title ); ?></span>
					<button type="button" class="infyp-page-filter-clear-btn" id="infyp-page-filter-clear" aria-label="<?php esc_attr_e( 'Clear filter', 'infy-ai-image-seo-optimizer' ); ?>">
						<span class="dashicons dashicons-no-alt"></span>
					</button>
				</div>
			<?php endif; ?>
			<div class="infyp-page-filter-display" id="infyp-page-filter-display" <?php echo esc_attr( $parent_post > 0 ? 'style=display:none;' : '' ); ?>>
				<span class="infyp-page-filter-icon dashicons dashicons-admin-page"></span>
				<input type="text"
				       id="infyp-page-filter-input"
				       class="infyp-page-filter-input"
				       placeholder="<?php esc_attr_e( 'Filter by page or post...', 'infy-ai-image-seo-optimizer' ); ?>"
				       autocomplete="off" />
			</div>
			<input type="hidden" name="parent_post" id="infyp-parent-post-value" value="<?php echo esc_attr( $parent_post ); ?>" />
			<div class="infyp-page-filter-dropdown" id="infyp-page-filter-dropdown" style="display:none;"></div>
		</div>
		<?php
	}

	/**
	 * Render the SEO score sort toggle button (called from main-page.php)
	 *
	 * @since 1.0.0
	 */
	public function render_seo_sort_control() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI state
		$current_orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'seo_score';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_order = isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'asc';

		$direction = '';
		if ( $current_orderby === 'seo_score' ) {
			$direction = strtolower( $current_order ) === 'asc' ? 'asc' : 'desc';
		}

		$is_active = $direction !== '';
		?>
		<div class="infyp-seo-sort-wrapper">
			<button type="button"
			        id="infyp-seo-sort-btn"
			        class="infyp-seo-sort-btn<?php echo esc_attr( $is_active ? ' active' : '' ); ?>"
			        data-direction="<?php echo esc_attr( $direction ); ?>"
			        title="<?php esc_attr_e( 'Sort by SEO Score', 'infy-ai-image-seo-optimizer' ); ?>">
				<span class="dashicons dashicons-sort"></span>
				<span class="infyp-seo-sort-label"><?php esc_html_e( 'SEO Score', 'infy-ai-image-seo-optimizer' ); ?></span>
				<span class="infyp-seo-sort-arrow"><?php
					if ( $direction === 'desc' ) {
						echo '&#9662;'; // ▾
					} elseif ( $direction === 'asc' ) {
						echo '&#9652;'; // ▴
					}
				?></span>
			</button>
		</div>
		<?php
	}

	/**
	 * Render the upload date sort toggle button (called from main-page.php)
	 *
	 * Three states: desc (newest first) -> asc (oldest first) -> inactive (back to default SEO sort)
	 *
	 * @since 1.0.0
	 */
	public function render_date_sort_control() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI state
		$current_orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'seo_score';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_order = isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'asc';

		$direction = '';
		if ( $current_orderby === 'date' || $current_orderby === 'post_date' ) {
			$direction = strtolower( $current_order ) === 'asc' ? 'asc' : 'desc';
		}

		$is_active = $direction !== '';
		?>
		<div class="infyp-date-sort-wrapper">
			<button type="button"
			        id="infyp-date-sort-btn"
			        class="infyp-date-sort-btn<?php echo esc_attr( $is_active ? ' active' : '' ); ?>"
			        data-direction="<?php echo esc_attr( $direction ); ?>"
			        title="<?php esc_attr_e( 'Sort by Upload Date', 'infy-ai-image-seo-optimizer' ); ?>">
				<span class="dashicons dashicons-calendar-alt"></span>
				<span class="infyp-date-sort-label"><?php esc_html_e( 'Upload Date', 'infy-ai-image-seo-optimizer' ); ?></span>
				<span class="infyp-date-sort-arrow"><?php
					if ( $direction === 'desc' ) {
						echo '&#9662;'; // ▾
					} elseif ( $direction === 'asc' ) {
						echo '&#9652;'; // ▴
					}
				?></span>
			</button>
		</div>
		<?php
	}

	/**
	 * Render the post type filter UI.
	 *
	 * Builds the multi-select token dropdown with all allowed post types,
	 * parses the current URL filter state, and outputs the interactive
	 * HTML controls including search input and option list.
	 *
	 * @since 1.0.0
	 */
	private function render_post_type_filter() {
		// Build filter options from all allowed post types
		$allowed_types = INFYP_Image_Query_Builder::get_allowed_post_types();
		$all_post_type_objects = get_post_types( [ 'public' => true ], 'objects' );

		$filter_options = [];

		// Add 'media' first (special UI filter for unattached images)
		if ( isset( $allowed_types['media'] ) ) {
			$filter_options['media'] = [
				'label'    => __( 'Media', 'infy-ai-image-seo-optimizer' ),
				'enabled'  => true,
				'category' => 'wordpress',
			];
		}

		$woo_post_types = [ 'product', 'product_variation', 'shop_order', 'shop_order_refund', 'shop_coupon', 'shop_webhook' ];

		// Add all other allowed post types with their registered labels
		foreach ( $allowed_types as $slug => $slug_value ) {
			if ( $slug === 'media' ) {
				continue; // Already added above
			}

			if ( isset( $all_post_type_objects[ $slug ] ) ) {
				$type_obj = $all_post_type_objects[ $slug ];

				if ( $type_obj->_builtin ) {
					$category = 'wordpress';
				} elseif ( in_array( $slug, $woo_post_types, true ) ) {
					$category = 'woo';
				} else {
					$category = 'custom';
				}

				$filter_options[ $slug ] = [
					'label'    => $type_obj->labels->name,
					'enabled'  => true,
					'category' => $category,
				];
			}
		}

		/**
		 * Filter the post type filter options displayed in the list table UI.
		 *
		 * @since 1.0.0
		 * @param array $filter_options Current filter options array.
		 */
		$filter_options = apply_filters( 'infyp_post_type_filter_options', $filter_options );

		// Get current filter from URL - supports multiple delimiters for flexibility
		$selected_types = [];
		$current_filter = 'all'; // Default value

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading URL parameters for display filtering only
		if (isset($_REQUEST['post_type_filter'])) {
			$current_filter = sanitize_text_field(wp_unslash($_REQUEST['post_type_filter']));
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			if ($current_filter === 'all') {
				$selected_types = ['all'];
			} else if (strpos($current_filter, '+') !== false) {
				// Plus-separated values (cleanest format)
				$selected_types = array_map('sanitize_key', explode('+', $current_filter));
			} else if (strpos($current_filter, ',') !== false) {
				// Comma-separated values (backwards compatibility)
				$selected_types = array_map('sanitize_key', explode(',', $current_filter));
			} else {
				// Single type selected
				$selected_types = [sanitize_key($current_filter)];
			}
		} else {
			// No filter specified, default to all
			$selected_types = ['all'];
		}

		// Validate selected types against available filter options
		if (!in_array('all', $selected_types)) {
			$valid_types = [];
			foreach ($selected_types as $type) {
				if (isset($filter_options[$type]) && $filter_options[$type]['enabled']) {
					$valid_types[] = $type;
				}
			}

			// If no valid types remain after validation, default to 'all'
			if (empty($valid_types)) {
				$selected_types = ['all'];
				$_REQUEST['post_type_filter'] = 'all';
			} else {
				$selected_types = $valid_types;
				$_REQUEST['post_type_filter'] = implode('+', $valid_types);
			}
		}
		?>

		<div class="infyp-post-type-filter-wrapper">
			<div class="infyp-filter-token-list infyp-post-type-tokens" id="infyp-post-type-tokens">
				<?php if (in_array('all', $selected_types) || empty($selected_types)) : ?>
					<span class="infyp-filter-token infyp-all-token" data-type="all">
						<?php esc_html_e('All', 'infy-ai-image-seo-optimizer'); ?>
						<button type="button" class="infyp-filter-token-remove" aria-label="<?php esc_attr_e('Clear filter', 'infy-ai-image-seo-optimizer'); ?>">×</button>
					</span>
				<?php else : ?>
					<?php foreach ($selected_types as $type) :
						if (isset($filter_options[$type])) : ?>
					<span class="infyp-filter-token" data-type="<?php echo esc_attr($type); ?>">
						<?php echo esc_html($filter_options[$type]['label']); ?>
						<button type="button" class="infyp-filter-token-remove" aria-label="<?php esc_attr_e('Remove filter', 'infy-ai-image-seo-optimizer'); ?>">×</button>
					</span>
					<?php endif;
					endforeach; ?>
				<?php endif; ?>

				<input type="text"
				       id="infyp-post-type-input"
				       class="infyp-filter-input"
				       placeholder="<?php esc_attr_e('Add post type filter...', 'infy-ai-image-seo-optimizer'); ?>"
				       autocomplete="off"
				       role="combobox"
				       aria-autocomplete="list"
				       aria-expanded="false">
			</div>

			<!-- Hidden input to store actual filter value -->
			<input type="hidden" name="post_type_filter" id="infyp-post-type-filter-value" value="<?php echo esc_attr($current_filter); ?>">

			<!-- Dropdown for post type selection -->
			<div class="infyp-post-type-dropdown" id="infyp-post-type-dropdown" style="display: none;">
				<div class="infyp-dropdown-search">
					<input type="text"
					       class="infyp-dropdown-search-input"
					       id="infyp-dropdown-search"
					       placeholder="<?php esc_attr_e('Search post types...', 'infy-ai-image-seo-optimizer'); ?>">
				</div>
				<div class="infyp-dropdown-options">
					<div class="infyp-dropdown-option infyp-option-all" data-value="all">
						<span><?php esc_html_e('All', 'infy-ai-image-seo-optimizer'); ?></span>
						<span class="infyp-selected-check dashicons dashicons-yes-alt" style="display: none;"></span>
					</div>
					<?php
					// Reorder options to show Media Library right after All
					$ordered_options = [];
					if (isset($filter_options['media'])) {
						$ordered_options['media'] = $filter_options['media'];
					}
					foreach ($filter_options as $key => $option) {
						if ($key !== 'media') {
							$ordered_options[$key] = $option;
						}
					}
					foreach ($ordered_options as $value => $option) :
						// Skip hidden options in the dropdown
						if (isset($option['hidden']) && $option['hidden']) {
							continue;
						}
						?>
						<div class="infyp-dropdown-option"
						     data-value="<?php echo esc_attr($value); ?>"
						     data-label="<?php echo esc_attr($option['label']); ?>"
						     <?php if (isset($option['description'])) : ?>
						     title="<?php echo esc_attr($option['description']); ?>"
						     <?php endif; ?>>
							<span><?php echo esc_html($option['label']); ?></span>
							<?php
							// Show category badge for typed options
							if ( isset( $option['category'] ) ) {
								// Brand/category labels are intentionally NOT translatable —
								// "WordPress", "Woo", and "CPT" are recognized acronyms/brand names
								// that must remain identical across all locales.
								if ( $option['category'] === 'wordpress' ) {
									echo '<span class="infyp-category-badge infyp-badge-wordpress">WordPress</span>';
								} elseif ( $option['category'] === 'woo' ) {
									echo '<span class="infyp-category-badge infyp-badge-woo">Woo</span>';
								} elseif ( $option['category'] === 'custom' ) {
									echo '<span class="infyp-category-badge infyp-badge-custom">CPT</span>';
								}

								/**
								 * Render custom category badges for extension-registered post types.
								 *
								 * @since 1.0.0
								 * @param array  $option The filter option array.
								 * @param string $slug   The post type slug.
								 */
								do_action( 'infyp_render_filter_category_badge', $option, $value );
							}
							?>
							<span class="infyp-selected-check dashicons dashicons-yes-alt" style="display: none;"></span>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}
}
