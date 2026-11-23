<?php
/**
 * Public API for extensions.
 *
 * Prefixed helper functions that wrap internal classes and pass every value
 * through apply_filters/do_action. This is the stable public surface for
 * the pro plugin and any third-party extensions.
 *
 * Follows the pattern used by WooCommerce (wc_*), EDD (edd_*), and ACF (acf_*).
 * Every returned value is filterable so extensions can override behavior.
 *
 * All functions are safe to call from any plugin. They return sensible
 * defaults if the free plugin's subsystems are not yet initialized.
 *
 * @package IPAIS\Core
 * @since   1.1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Global Accessor ─────────────────────────────────────────────────────────

if ( ! function_exists( 'infyp' ) ) {
	/**
	 * Returns the main plugin instance.
	 *
	 * This is the primary entry point for extensions. Use this to access
	 * singleton subsystems when you need the object itself (e.g. multiple
	 * method calls). For simple one-shot operations, prefer the infyp_*()
	 * helper functions below.
	 *
	 * @since 1.1.0
	 *
	 * @return \IPAIS\Core\INFYP_Plugin
	 */
	function infyp() {
		return \IPAIS\Core\INFYP_Plugin::instance();
	}
}

// ─── Settings ────────────────────────────────────────────────────────────────

if ( ! function_exists( 'infyp_get_setting' ) ) {
	/**
	 * Get a plugin setting by key.
	 *
	 * Wraps get_option() and passes the value through apply_filters()
	 * so extensions can override any setting. Follows the EDD pattern.
	 *
	 * @since 1.1.0
	 *
	 * @param string $key     Setting key (e.g. 'ai_model', 'batch_size', 'timeout').
	 * @param mixed  $default Default value if setting is not found.
	 * @return mixed
	 */
	function infyp_get_setting( $key, $default = null ) {
		// Check optimization settings first, then prompt settings.
		$opt_settings    = get_option( 'infyp_optimization_settings', [] );
		$prompt_settings = get_option( 'infyp_prompt_settings', [] );

		if ( isset( $opt_settings[ $key ] ) ) {
			$value = $opt_settings[ $key ];
		} elseif ( isset( $prompt_settings[ $key ] ) ) {
			$value = $prompt_settings[ $key ];
		} else {
			$value = $default;
		}

		/**
		 * Filter a plugin setting value.
		 *
		 * @since 1.1.0
		 *
		 * @param mixed  $value   The setting value.
		 * @param string $key     The setting key.
		 * @param mixed  $default The default value.
		 */
		return apply_filters( 'infyp_get_setting', $value, $key, $default );
	}
}

if ( ! function_exists( 'infyp_get_api_key' ) ) {
	/**
	 * Get the API key for a provider.
	 *
	 * @since 1.1.0
	 *
	 * @param string $provider Provider slug: 'openai', 'google', or 'anthropic'.
	 * @return string|null The decrypted API key, or null.
	 */
	function infyp_get_api_key( $provider ) {
		$plugin = infyp();

		if ( $plugin->api_keys ) {
			$key = $plugin->api_keys->get_api_key( $provider );
		} else {
			$key = null;
		}

		/**
		 * Filter the API key for a provider.
		 *
		 * @since 1.1.0
		 *
		 * @param string|null $key      The API key.
		 * @param string      $provider The provider slug.
		 */
		return apply_filters( 'infyp_get_api_key', $key, $provider );
	}
}

if ( ! function_exists( 'infyp_get_active_model' ) ) {
	/**
	 * Get the currently configured AI model.
	 *
	 * @since 1.1.0
	 *
	 * @return string Model identifier as registered in the model registry.
	 */
	function infyp_get_active_model() {
		$model = infyp_get_setting( 'ai_model', INFYP_DEFAULT_MODEL );

		/**
		 * Filter the active AI model.
		 *
		 * @since 1.1.0
		 *
		 * @param string $model The model identifier.
		 */
		return apply_filters( 'infyp_get_active_model', $model );
	}
}

if ( ! function_exists( 'infyp_get_language' ) ) {
	/**
	 * Get the configured language for AI prompts.
	 *
	 * @since 1.1.0
	 *
	 * @return string Language name (e.g. 'English').
	 */
	function infyp_get_language() {
		$lang = infyp_get_setting( 'language', 'English' );

		/**
		 * Filter the AI prompt language.
		 *
		 * @since 1.1.0
		 *
		 * @param string $lang The language name.
		 */
		return apply_filters( 'infyp_get_language', $lang );
	}
}

if ( ! function_exists( 'infyp_get_provider_for_model' ) ) {
	/**
	 * Get the provider slug for a given AI model.
	 *
	 * @since 1.1.0
	 *
	 * @param string $model Model identifier.
	 * @return string|null Provider slug ('openai', 'google', 'anthropic') or null.
	 */
	function infyp_get_provider_for_model( $model ) {
		$plugin = infyp();

		if ( $plugin->api_keys ) {
			$provider = $plugin->api_keys->get_provider_for_model( $model );
		} else {
			$provider = null;
		}

		/**
		 * Filter the provider for a given model.
		 *
		 * @since 1.1.0
		 *
		 * @param string|null $provider The provider slug.
		 * @param string      $model    The model identifier.
		 */
		return apply_filters( 'infyp_get_provider_for_model', $provider, $model );
	}
}

// ─── Image Queries ───────────────────────────────────────────────────────────

if ( ! function_exists( 'infyp_get_image_ids' ) ) {
	/**
	 * Get image attachment IDs matching given arguments.
	 *
	 * Wraps INFYP_Image_List_Table::get_images() so extensions never
	 * import the table class directly.
	 *
	 * @since 1.1.0
	 *
	 * @param array $args {
	 *     Optional. Query arguments.
	 *
	 *     @type int    $per_page         Images per page. Default -1 (all).
	 *     @type int    $page_number      Page number. Default 1.
	 *     @type string $search_term      Search term. Default ''.
	 *     @type string $orderby          Order by field. Default 'date'.
	 *     @type string $order            Order direction. Default 'desc'.
	 *     @type string $post_type_filter Post type filter. Default null.
	 * }
	 * @return array Array of image IDs.
	 */
	function infyp_get_image_ids( $args = [] ) {
		$defaults = [
			'per_page'         => -1,
			'page_number'      => 1,
			'search_term'      => '',
			'orderby'          => 'date',
			'order'            => 'desc',
			'post_type_filter' => null,
		];
		$args = wp_parse_args( $args, $defaults );

		/**
		 * Filter the image query arguments.
		 *
		 * @since 1.1.0
		 *
		 * @param array $args The query arguments.
		 */
		$args = apply_filters( 'infyp_image_query_args', $args );

		$table = new \IPAIS\Tables\INFYP_Image_List_Table();
		$result = $table->get_images(
			$args['per_page'],
			$args['page_number'],
			$args['search_term'],
			$args['orderby'],
			$args['order'],
			null,
			$args['post_type_filter']
		);

		$ids = [];
		if ( ! empty( $result['items'] ) ) {
			$ids = wp_list_pluck( $result['items'], 'ID' );
		}

		/**
		 * Filter the returned image IDs.
		 *
		 * @since 1.1.0
		 *
		 * @param array $ids  The image IDs.
		 * @param array $args The query arguments used.
		 */
		return apply_filters( 'infyp_get_image_ids', $ids, $args );
	}
}

if ( ! function_exists( 'infyp_get_image_count' ) ) {
	/**
	 * Get total count of image attachments matching arguments.
	 *
	 * @since 1.1.0
	 *
	 * @param array $args Optional. Same arguments as infyp_get_image_ids().
	 * @return int Image count.
	 */
	function infyp_get_image_count( $args = [] ) {
		$defaults = [
			'per_page'         => -1,
			'page_number'      => 1,
			'search_term'      => '',
			'orderby'          => 'date',
			'order'            => 'desc',
			'post_type_filter' => null,
		];
		$args = wp_parse_args( $args, $defaults );

		$table  = new \IPAIS\Tables\INFYP_Image_List_Table();
		$result = $table->get_images(
			$args['per_page'],
			$args['page_number'],
			$args['search_term'],
			$args['orderby'],
			$args['order'],
			null,
			$args['post_type_filter']
		);

		$count = isset( $result['total'] ) ? (int) $result['total'] : 0;

		/**
		 * Filter the image count.
		 *
		 * @since 1.1.0
		 *
		 * @param int   $count The image count.
		 * @param array $args  The query arguments used.
		 */
		return apply_filters( 'infyp_get_image_count', $count, $args );
	}
}

// ─── AI Optimization ─────────────────────────────────────────────────────────

if ( ! function_exists( 'infyp_optimize_field' ) ) {
	/**
	 * Run AI optimization on a single image field.
	 *
	 * Wraps INFYP_AI_Optimizer::optimize_single_field() so extensions
	 * never import the static class directly.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $image_id     Attachment ID.
	 * @param string $field_type   Field to optimize ('alt_text', 'title', 'filename', 'caption', 'description').
	 * @param array  $options      {
	 *     Optional. Extra options.
	 *
	 *     @type array  $existing_keywords Existing keywords for context.
	 *     @type string $custom_prompt     Custom prompt addon text.
	 * }
	 * @return array|WP_Error Optimization result or error.
	 */
	function infyp_optimize_field( $image_id, $field_type, $options = [] ) {
		$keywords = isset( $options['existing_keywords'] ) ? $options['existing_keywords'] : null;
		$prompt   = isset( $options['custom_prompt'] ) ? $options['custom_prompt'] : '';

		$result = \IPAIS\Core\INFYP_AI_Optimizer::optimize_single_field(
			$image_id,
			$field_type,
			$keywords,
			$prompt
		);

		/**
		 * Filter the single-field optimization result.
		 *
		 * @since 1.1.0
		 *
		 * @param array|WP_Error $result     The optimization result.
		 * @param int            $image_id   The attachment ID.
		 * @param string         $field_type The field type.
		 * @param array          $options    The options passed.
		 */
		return apply_filters( 'infyp_run_single_field_optimization', $result, $image_id, $field_type, $options );
	}
}

if ( ! function_exists( 'infyp_optimize_image' ) ) {
	/**
	 * Run full AI optimization on an image (all fields).
	 *
	 * Wraps INFYP_AI_Optimizer::generate_seo_data().
	 *
	 * @since 1.1.0
	 *
	 * @param int    $image_id          Attachment ID.
	 * @param array  $existing_keywords Optional. Existing keywords.
	 * @param string $custom_prompt     Optional. Custom prompt addon.
	 * @return array|WP_Error Optimization result with 'keywords', 'filename', 'alt_text', 'title'.
	 */
	function infyp_optimize_image( $image_id, $existing_keywords = null, $custom_prompt = '' ) {
		$result = \IPAIS\Core\INFYP_AI_Optimizer::generate_seo_data(
			$image_id,
			$existing_keywords,
			$custom_prompt
		);

		/**
		 * Filter the full optimization result.
		 *
		 * @since 1.1.0
		 *
		 * @param array|WP_Error $result            The optimization result.
		 * @param int            $image_id          The attachment ID.
		 * @param array|null     $existing_keywords The existing keywords.
		 * @param string         $custom_prompt     The custom prompt.
		 */
		return apply_filters( 'infyp_run_optimization', $result, $image_id, $existing_keywords, $custom_prompt );
	}
}

if ( ! function_exists( 'infyp_optimize_and_save' ) ) {
	/**
	 * Run a full AI optimization on an image AND persist the results.
	 *
	 * The generate-and-save counterpart to infyp_optimize_image(): generates
	 * all fields, applies them (alt text, title, filename rename with content
	 * reference updates, keywords), writes changelog entries, fires the
	 * extension hooks, and invalidates caches — the same pipeline the admin
	 * "Optimize with AI" button uses.
	 *
	 * Wraps INFYP_Optimization_Service::run().
	 *
	 * @since 1.0.6
	 *
	 * @param int   $image_id Attachment ID.
	 * @param array $args     {
	 *     Optional. Arguments.
	 *
	 *     @type string $custom_prompt  Per-image prompt override. Replaces the global
	 *                                  prompt addon and context filters entirely.
	 *     @type bool   $clear_keywords Delete stored keywords first so the AI generates
	 *                                  fresh ones. Default false.
	 *     @type bool   $dry_run        Generate without persisting anything. Default false.
	 *     @type array  $options        Arbitrary options forwarded to callbacks on the
	 *                                  infyp_optimize_prompt filter. Default [].
	 * }
	 * @return array|WP_Error Result envelope ('new_data', 'model', 'dry_run',
	 *                        'prompt_modified') or error.
	 */
	function infyp_optimize_and_save( $image_id, $args = [] ) {
		$result = \IPAIS\Core\INFYP_Optimization_Service::get_instance()->run( (int) $image_id, (array) $args );

		/**
		 * Filter the optimize-and-save result.
		 *
		 * @since 1.0.6
		 *
		 * @param array|WP_Error $result   The result envelope or error.
		 * @param int            $image_id The attachment ID.
		 * @param array          $args     The arguments passed.
		 */
		return apply_filters( 'infyp_run_optimize_and_save', $result, $image_id, $args );
	}
}

if ( ! function_exists( 'infyp_apply_optimization' ) ) {
	/**
	 * Persist a raw AI optimization result to an image.
	 *
	 * For callers that already hold a generation result (e.g. from
	 * infyp_optimize_image()): sanitizes it via the shared processing step and
	 * applies it — alt text, title, filename rename with content updates,
	 * keywords — with changelog entries.
	 *
	 * Note: unlike infyp_optimize_and_save(), this does NOT fire
	 * infyp_after_ai_optimization / infyp_image_optimized — extensions expect
	 * those only on the full pipeline.
	 *
	 * @since 1.0.6
	 *
	 * @param int    $image_id Attachment ID.
	 * @param array  $result   Raw AI result (alt_text, title, filename, keywords, …).
	 * @param string $model    AI model name recorded in the changelog.
	 * @return array|WP_Error The processed field data that was applied, or error.
	 */
	function infyp_apply_optimization( $image_id, $result, $model ) {
		$image_id = (int) $image_id;

		if ( ! infyp_is_valid_image( $image_id ) ) {
			return new \WP_Error( 'infyp_invalid_image', esc_html__( 'Invalid image ID.', 'infy-ai-image-seo-optimizer' ) );
		}

		$service = \IPAIS\Core\INFYP_Optimization_Service::get_instance();

		$old_data = [
			'alt_text' => get_post_meta( $image_id, '_wp_attachment_image_alt', true ),
			'title'    => get_the_title( $image_id ),
			'filename' => pathinfo( get_attached_file( $image_id ), PATHINFO_FILENAME ),
		];

		$new_data = $service->process_result( (array) $result, $old_data, $image_id );
		$service->apply( $image_id, $old_data, $new_data, (string) $model );

		infyp_invalidate_cache( 'image', $image_id );

		/**
		 * Filter the applied optimization data.
		 *
		 * @since 1.0.6
		 *
		 * @param array  $new_data The processed data that was applied.
		 * @param int    $image_id The attachment ID.
		 * @param array  $result   The raw AI result passed in.
		 * @param string $model    The AI model name.
		 */
		return apply_filters( 'infyp_apply_optimization_result', $new_data, $image_id, $result, $model );
	}
}

if ( ! function_exists( 'infyp_optimize_field_and_save' ) ) {
	/**
	 * Run a single-field AI optimization AND persist the result.
	 *
	 * The generate-and-save counterpart to infyp_optimize_field(). Filename
	 * optimization is atomic: the physical rename and content-reference
	 * updates happen in the same call.
	 *
	 * Wraps INFYP_Optimization_Service::run_field().
	 *
	 * @since 1.0.6
	 *
	 * @param int    $image_id   Attachment ID.
	 * @param string $field_type Field to optimize (any type allowed by the
	 *                           infyp_allowed_optimize_field_types filter).
	 * @param array  $args       {
	 *     Optional. Arguments.
	 *
	 *     @type array  $keywords            Keyword context. Defaults to stored keywords.
	 *     @type string $custom_prompt       Per-image prompt override.
	 *     @type bool   $dry_run             Generate without persisting. Default false.
	 *     @type bool   $skip_filename_apply Return the proposed filename stem without
	 *                                       renaming. Default false.
	 *     @type array  $options             Arbitrary options forwarded to callbacks on
	 *                                       the infyp_optimize_prompt filter. Default [].
	 * }
	 * @return array|WP_Error Result ('field_type', 'old_value', 'new_value',
	 *                        'applied', 'updated_posts', 'model', 'dry_run',
	 *                        'prompt_modified') or error.
	 */
	function infyp_optimize_field_and_save( $image_id, $field_type, $args = [] ) {
		$result = \IPAIS\Core\INFYP_Optimization_Service::get_instance()->run_field( (int) $image_id, (string) $field_type, (array) $args );

		/**
		 * Filter the optimize-field-and-save result.
		 *
		 * @since 1.0.6
		 *
		 * @param array|WP_Error $result     The result or error.
		 * @param int            $image_id   The attachment ID.
		 * @param string         $field_type The field type.
		 * @param array          $args       The arguments passed.
		 */
		return apply_filters( 'infyp_run_optimize_field_and_save', $result, $image_id, $field_type, $args );
	}
}

if ( ! function_exists( 'infyp_apply_field' ) ) {
	/**
	 * Persist a single field value to an image.
	 *
	 * For 'filename' this performs the full rename: physical file, attachment
	 * metadata, content reference rewriting, changelog, and cache invalidation.
	 *
	 * Wraps INFYP_Optimization_Service::apply_field().
	 *
	 * @since 1.0.6
	 * @since 1.0.9 Optional $context, e.g. [ 'source' => 'ai' ] to label the
	 *              redirect of an AI-driven rename.
	 *
	 * @param int    $image_id   Attachment ID.
	 * @param string $field_type One of 'alt_text', 'title', 'filename', or an
	 *                           extension-registered field type.
	 * @param mixed  $value      The value to persist.
	 * @param array  $context    Optional. Persistence context passed to
	 *                           INFYP_Optimization_Service::apply_field()
	 *                           ('source', 'label', 'model', 'log_context').
	 * @return array|WP_Error Result ('new_value', 'old_value', 'unchanged',
	 *                        'updated_posts') or error.
	 */
	function infyp_apply_field( $image_id, $field_type, $value, $context = [] ) {
		$result = \IPAIS\Core\INFYP_Optimization_Service::get_instance()->apply_field( (int) $image_id, (string) $field_type, $value, (array) $context );

		/**
		 * Filter the apply-field result.
		 *
		 * @since 1.0.6
		 *
		 * @param array|WP_Error $result     The result or error.
		 * @param int            $image_id   The attachment ID.
		 * @param string         $field_type The field type.
		 * @param mixed          $value      The value passed in.
		 */
		return apply_filters( 'infyp_run_apply_field', $result, $image_id, $field_type, $value );
	}
}

if ( ! function_exists( 'infyp_query_images' ) ) {
	/**
	 * Query images safely from any context (REST, CLI, cron).
	 *
	 * Unlike infyp_get_image_ids(), this calls the query builder directly —
	 * no WP_List_Table (which fatals outside wp-admin), no $_REQUEST
	 * fall-through, a hard per_page cap, and no write side effects (the
	 * SEO-score backfill is skipped; the infyp_batch_cache_scores cron
	 * handles backfill instead).
	 *
	 * @since 1.0.6
	 *
	 * @param array $args {
	 *     Optional. Query arguments.
	 *
	 *     @type int        $per_page         Images per page. Default 20, capped at 200.
	 *     @type int        $page_number      Page number (1-based). Default 1.
	 *     @type string     $search_term      Search term. Default ''.
	 *     @type string     $orderby          Sort field ('date', 'title', 'ID', 'modified',
	 *                                        'seo_score'). Default 'date'.
	 *     @type string     $order            'asc' or 'desc'. Default 'desc'.
	 *     @type array|null $cursor           Cursor from a previous result for keyset
	 *                                        pagination: ['id' => int, 'value' => string,
	 *                                        'direction' => 'next'|'prev']. Default null.
	 *     @type string     $post_type_filter Explicit post type filter. Default 'all'
	 *                                        (never read from the request).
	 *     @type int        $parent_post      Restrict to images attached to this post. Default 0.
	 *     @type int        $author           Restrict to attachments uploaded by this user ID.
	 *                                        Default 0 (no restriction).
	 * }
	 * @return array {
	 *     @type \WP_Post[] $items  Result posts.
	 *     @type int        $total  Total count for the view.
	 *     @type array|null $cursor Next/prev cursor data when the sort supports cursors.
	 * }
	 */
	function infyp_query_images( $args = [] ) {
		$defaults = [
			'per_page'         => 20,
			'page_number'      => 1,
			'search_term'      => '',
			'orderby'          => 'date',
			'order'            => 'desc',
			'cursor'           => null,
			'post_type_filter' => 'all',
			'parent_post'      => 0,
			'author'           => 0,
		];
		$args = wp_parse_args( $args, $defaults );

		// Hard cap: this is the API for programmatic callers; unbounded reads
		// belong to the keyset iterator (infyp_get_unoptimized_image_ids) instead.
		$args['per_page']    = min( 200, max( 1, (int) $args['per_page'] ) );
		$args['page_number'] = max( 1, (int) $args['page_number'] );

		/**
		 * Filter the REST-safe image query arguments.
		 *
		 * @since 1.0.6
		 *
		 * @param array $args The parsed query arguments.
		 */
		$args = apply_filters( 'infyp_query_images_args', $args );

		$builder = new \IPAIS\Tables\INFYP_Image_Query_Builder();
		$result  = $builder->get_images(
			$args['per_page'],
			$args['page_number'],
			$args['search_term'],
			$args['orderby'],
			$args['order'],
			$args['cursor'],
			(string) $args['post_type_filter'],
			(int) $args['parent_post'],
			[
				'skip_score_backfill' => true,
				'force_cursor'        => null !== $args['cursor'],
				'author'              => (int) $args['author'],
			]
		);

		/**
		 * Filter the REST-safe image query result.
		 *
		 * @since 1.0.6
		 *
		 * @param array $result Result array ('items', 'total', 'cursor').
		 * @param array $args   The query arguments used.
		 */
		return apply_filters( 'infyp_query_images_result', $result, $args );
	}
}

if ( ! function_exists( 'infyp_get_unoptimized_image_ids' ) ) {
	/**
	 * Get the next batch of image IDs that have never been AI-optimized.
	 *
	 * Keyset pagination on the primary key: cheap at any library size and
	 * resumable — pass the last ID of each batch back as $after_id. A bulk
	 * job using this iterator is self-terminating, because optimizing an
	 * image writes the _infyp_has_been_optimized meta this query excludes.
	 *
	 * Wraps INFYP_Image_Query_Builder::get_unoptimized_image_ids().
	 *
	 * @since 1.0.6
	 *
	 * @param int $after_id Return only IDs greater than this. Default 0 (start).
	 * @param int $limit    Maximum IDs to return. Default 100, clamped to 1-1000.
	 * @return int[] Ascending attachment IDs.
	 */
	function infyp_get_unoptimized_image_ids( $after_id = 0, $limit = 100 ) {
		$builder = new \IPAIS\Tables\INFYP_Image_Query_Builder();
		$ids     = $builder->get_unoptimized_image_ids( (int) $after_id, (int) $limit );

		/**
		 * Filter the unoptimized image ID batch.
		 *
		 * @since 1.0.6
		 *
		 * @param int[] $ids      The attachment IDs.
		 * @param int   $after_id The keyset cursor used.
		 * @param int   $limit    The batch size requested.
		 */
		return apply_filters( 'infyp_unoptimized_image_ids', $ids, $after_id, $limit );
	}
}

if ( ! function_exists( 'infyp_get_image_base64' ) ) {
	/**
	 * Get base64-encoded image data for AI API calls.
	 *
	 * Wraps INFYP_AI_Optimizer::get_cached_base64().
	 *
	 * @since 1.1.0
	 *
	 * @param int $image_id Attachment ID.
	 * @return string|WP_Error Base64 string or error.
	 */
	function infyp_get_image_base64( $image_id ) {
		$base64 = \IPAIS\Core\INFYP_AI_Optimizer::get_cached_base64( $image_id );

		/**
		 * Filter the base64 image data.
		 *
		 * @since 1.1.0
		 *
		 * @param string|WP_Error $base64   The base64 data.
		 * @param int             $image_id The attachment ID.
		 */
		return apply_filters( 'infyp_get_image_base64', $base64, $image_id );
	}
}

// ─── Validation ──────────────────────────────────────────────────────────────

if ( ! function_exists( 'infyp_is_valid_image' ) ) {
	/**
	 * Validate that an attachment ID is a valid image.
	 *
	 * Wraps INFYP_Security_Manager::validate_attachment_id().
	 *
	 * @since 1.1.0
	 *
	 * @param int $image_id Attachment ID to validate.
	 * @return bool True if valid image attachment.
	 */
	function infyp_is_valid_image( $image_id ) {
		$plugin = infyp();

		if ( $plugin->security ) {
			$is_valid = $plugin->security->validate_attachment_id( $image_id );
		} else {
			// Fallback: basic WordPress check.
			$is_valid = wp_attachment_is_image( $image_id );
		}

		/**
		 * Filter whether an image ID is valid.
		 *
		 * @since 1.1.0
		 *
		 * @param bool $is_valid Whether the image is valid.
		 * @param int  $image_id The attachment ID.
		 */
		return apply_filters( 'infyp_validate_image_id', $is_valid, $image_id );
	}
}

if ( ! function_exists( 'infyp_is_upload_complete' ) ) {
	/**
	 * Whether the upload of an attachment is complete.
	 *
	 * False while a newly uploaded image still waits for its sub-sizes: from
	 * the creation of the attachment until `infyp_after_image_upload` has
	 * fired. With client-side media processing that spans several requests,
	 * so code that acts on a new image from a separate request (an AJAX call
	 * started by the browser, a scheduled job) can ask this first and try
	 * again later. True for every other existing attachment, including one
	 * whose upload was abandoned and whose pending marker has expired. False
	 * for an ID that is not an attachment.
	 *
	 * @since 1.0.9
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool True when the upload is complete.
	 */
	function infyp_is_upload_complete( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		$complete = $attachment_id > 0
			&& 'attachment' === get_post_type( $attachment_id )
			&& ! \IPAIS\Core\INFYP_Cache_Manager::is_upload_pending( $attachment_id );

		/**
		 * Filter whether the upload of an attachment is complete.
		 *
		 * @since 1.0.9
		 *
		 * @param bool $complete      Whether the upload is complete.
		 * @param int  $attachment_id The attachment ID.
		 */
		return (bool) apply_filters( 'infyp_is_upload_complete', $complete, $attachment_id );
	}
}

if ( ! function_exists( 'infyp_user_can' ) ) {
	/**
	 * Check if the current user has capability for a plugin action.
	 *
	 * Wraps INFYP_Security_Manager::check_capability_for_action().
	 *
	 * @since 1.1.0
	 *
	 * @param string $action The action to check (e.g. 'optimize', 'edit_field', 'manage_settings').
	 * @return bool True if user has the required capability.
	 */
	function infyp_user_can( $action ) {
		$plugin = infyp();

		if ( $plugin->security ) {
			$has_cap = $plugin->security->check_capability_for_action( $action );
		} else {
			// Fallback: require upload_files capability.
			$has_cap = current_user_can( 'upload_files' );
		}

		/**
		 * Filter the capability check.
		 *
		 * @since 1.1.0
		 *
		 * @param bool   $has_cap Whether the user has the capability.
		 * @param string $action  The action being checked.
		 */
		return apply_filters( 'infyp_check_capability', $has_cap, $action );
	}
}

// ─── Changelog / Logging ─────────────────────────────────────────────────────

if ( ! function_exists( 'infyp_log_change' ) ) {
	/**
	 * Log a field change to the changelog.
	 *
	 * Wraps INFYP_Changelog::add_entry() so extensions never import the class.
	 *
	 * @since 1.1.0
	 *
	 * @param int         $image_id  Attachment ID.
	 * @param string      $field     Field name (e.g. 'alt_text', 'title', 'caption').
	 * @param string      $old_value Previous value.
	 * @param string      $new_value New value.
	 * @param string|null $source    Source of the change (e.g. 'manual', 'ai', 'pro'). Optional.
	 * @return void
	 */
	function infyp_log_change( $image_id, $field, $old_value, $new_value, $source = null ) {
		/**
		 * Fires before a field change is logged.
		 *
		 * @since 1.1.0
		 *
		 * @param int         $image_id  The attachment ID.
		 * @param string      $field     The field name.
		 * @param string      $old_value The old value.
		 * @param string      $new_value The new value.
		 * @param string|null $source    The change source.
		 */
		do_action( 'infyp_log_field_change', $image_id, $field, $old_value, $new_value, $source );

		\IPAIS\Database\INFYP_Changelog::add_entry( $image_id, $field, $old_value, $new_value, $source );
	}
}

// ─── Image Usage ─────────────────────────────────────────────────────────────

if ( ! function_exists( 'infyp_find_image_usage' ) ) {
	/**
	 * Find where an image is used across the site.
	 *
	 * Wraps INFYP_Image_Usage::find_usage().
	 *
	 * @since 1.1.0
	 *
	 * @param int $image_id Attachment ID.
	 * @return array Array of usage data objects.
	 */
	function infyp_find_image_usage( $image_id ) {
		$usage = \IPAIS\Core\INFYP_Image_Usage::find_usage( $image_id );

		/**
		 * Filter the image usage results.
		 *
		 * @since 1.1.0
		 *
		 * @param array $usage    The usage data.
		 * @param int   $image_id The attachment ID.
		 */
		return apply_filters( 'infyp_find_image_usage', $usage, $image_id );
	}
}

// ─── API Usage Tracking ──────────────────────────────────────────────────────

if ( ! function_exists( 'infyp_track_api_call' ) ) {
	/**
	 * Track an AI API call for usage statistics.
	 *
	 * Wraps INFYP_API_Usage_Tracker::track_api_call().
	 *
	 * @since 1.1.0
	 *
	 * @param string   $provider Provider slug ('openai', 'google', 'anthropic').
	 * @param string   $model    Model identifier.
	 * @param string   $status   'success' or 'error'.
	 * @param array    $data     Optional. Extra data (endpoint, tokens_used, response_time, etc.).
	 * @param int|null $image_id Optional. Attachment ID.
	 * @return void
	 */
	function infyp_track_api_call( $provider, $model, $status, $data = [], $image_id = null ) {
		/**
		 * Fires when an API call is tracked.
		 *
		 * @since 1.1.0
		 *
		 * @param string   $provider The provider slug.
		 * @param string   $model    The model identifier.
		 * @param string   $status   The call status.
		 * @param array    $data     Extra tracking data.
		 * @param int|null $image_id The attachment ID.
		 */
		do_action( 'infyp_track_api_usage', $provider, $model, $status, $data, $image_id );

		$plugin = infyp();

		if ( $plugin->api_usage ) {
			$plugin->api_usage->track_api_call( $provider, $model, $status, $data, $image_id );
		}
	}
}

// ─── Cache ───────────────────────────────────────────────────────────────────

if ( ! function_exists( 'infyp_invalidate_cache' ) ) {
	/**
	 * Invalidate plugin caches.
	 *
	 * Wraps INFYP_Cache_Manager methods so extensions never import the singleton.
	 *
	 * @since 1.1.0
	 *
	 * @param string   $type       Cache type: 'image', 'settings', or 'all'.
	 * @param int|null $identifier Optional. Image ID when type is 'image'.
	 * @return void
	 */
	function infyp_invalidate_cache( $type, $identifier = null ) {
		$plugin = infyp();

		if ( ! $plugin->cache ) {
			return;
		}

		switch ( $type ) {
			case 'image':
				if ( $identifier ) {
					$plugin->cache->invalidate_image_cache( $identifier );

					/**
					 * Fires after an image cache is invalidated.
					 *
					 * @since 1.1.0
					 *
					 * @param int $identifier The attachment ID.
					 */
					do_action( 'infyp_invalidate_image_cache', $identifier );
				}
				break;

			case 'settings':
				$plugin->cache->invalidate_settings_cache();
				break;

			case 'all':
				$plugin->cache->clear_all_cache();

				/**
				 * Fires after all caches are invalidated.
				 *
				 * @since 1.1.0
				 */
				do_action( 'infyp_invalidate_all_caches' );
				break;
		}
	}
}
