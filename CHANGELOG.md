# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the plugin uses
[Semantic Versioning](https://semver.org/).

The user-facing changelog in `readme.txt` (shown on WordPress.org) covers the same versions in
the same words; this file can add developer-relevant detail. Versions before 1.0.8 were released
before this repository existed and have no git tag.

## [Unreleased]

## [1.0.9] - 2026-11-23

### Added
- Claude 5.5 Haiku model (Anthropic). Claude 4.5 Haiku stays selectable.
- GPT-6 Luna model (OpenAI), the successor of GPT-4.1 mini (see Changed).
- Developers: `infyp_is_upload_complete( $attachment_id )` returns whether an image upload is
  complete, with all image sizes saved. The `infyp_is_upload_complete` filter
  (`$complete`, `$attachment_id`) can change the answer.
- Developers: `infyp_apply_field()` accepts an optional fourth argument `$context`;
  `[ 'source' => 'ai' ]` or `[ 'source' => 'manual' ]` sets the source label of the redirect
  created by a filename change.
- Developers: `INFYP_Security_Manager::get_rate_limit_retry_after()` and
  `INFYP_Redirect_Manager::set_rename_source()`.
- Developers: model registry methods `get_canonical_id()`, `get_accepted_model_ids()`,
  `is_replaced_id()` and `get_default_model_for_provider()`; optional model config keys
  `replaces`, `provider_default` and the display-only pricing keys `input_per_million`,
  `output_per_million` and `measured_per_image`.
- Developers: option `infyp_model_migration` records a saved-model move and drives the one-time
  notice.

### Changed
- Pro users need the Pro add-on 1.0.3 with this version: update Pro before or together with
  this update.
- GPT-6 Luna replaces GPT-4.1 mini, which OpenAI shuts down on 2027-01-14. Sites using GPT-4.1
  mini are switched to GPT-6 Luna once and see a one-time notice. Other saved models are not
  changed.
- Gemini 3.1 Flash-Lite stays the default model and now calls Google's generally available model
  ID.
- The settings form, the settings save in the admin and REST `PATCH /settings` accept retired
  model IDs and store the current model. The REST `ai_model` enum lists the current models.
- The cost comparison on the SEO Score page shows the current models with costs measured in
  November 2026.
- Readme, setup wizard and admin texts describe only what the free plugin does; model names and
  costs from the November 2026 measurement.
- Translations updated for 58 languages.
- Developers: for uploads that WordPress 7.2 processes in the browser, `infyp_after_image_upload`
  now fires in the finalize request, once the saved metadata lists all image sizes.
- Development: build and release tooling (quality check script, build that ships only listed
  root files, SVN pre-commit check); `composer.json` requires PHP 8.0 like the plugin header.

### Deprecated
- Developers: `INFYP_Security_Manager::validate_ajax_request()`. It was never hooked; it now only
  passes through, triggers `_deprecated_function()` and no longer rate limits. It will be removed
  in a later version.

### Fixed
- `infyp_after_image_upload` fires once per completed upload, after all image sizes are saved.
  It no longer fires twice for uploads that WordPress 7.2 processes in the browser, and no longer
  fires when an image is renamed or its thumbnails are regenerated.
- Renaming an image whose file name contains capital letters redirects the old URL of every
  image size listed in the attachment metadata, not only the main file.
- Setup wizard: a first run with only an OpenAI or only an Anthropic key selects a model of that
  provider.
- A retired model ID saved on a site is no longer sent to the provider; its successor is used.
- Uninstall removes the plugin's prefixed options, user settings and scheduled events, also when
  the plugin is uninstalled with WP-CLI (`wp plugin uninstall`), which skipped the cleanup
  before. The generic screen-option names `images_per_page`, `changelog_entries_per_page` and
  `api_usage_per_page` are kept, as other plugins may use them.
- REST API rate limit: the one-hour window is fixed and starts with the first request; later
  requests no longer extend it. 429 responses carry a `Retry-After` header with the seconds until
  the window ends. A counter stored by 1.0.8 starts a fresh window.
- AI renames made through the REST API, WP-CLI or scheduled tasks are labelled "AI" instead of
  "manual" on the Redirects page.
- Renaming an image used on a Visual Composer page no longer causes a fatal error.
- An invalid or truncated reply from OpenAI no longer causes a fatal error.
- WordPress AI connector: Claude models work (the response schema no longer contains keywords
  Anthropic rejects); a provider error is shown with the provider's reason instead of "No AI
  connector is configured"; usage costs of connector requests use the model's own price.
- Expired plugin transients are removed completely by the daily maintenance.
- PHP 8.4 deprecation notice from the Search Console client.
- The activation check and its message state the actual minimum, PHP 8.0.
- "Get Pro" links open the product page directly.
- First run: the setup wizard no longer saves an API key the provider rejects, and no longer says
  the site is ready when no key is stored for the selected model or the WordPress AI connector is
  not set up. A failed "Optimize with AI" shows the reason next to the button instead of a
  browser alert.
- Setup wizard: the "Invalid", "Saved — not verified" and "Connected" badges on the API key
  cards are now visible.
- Setup wizard: a rejected key's provider reply no longer runs into the next sentence, and a
  reply that repeats part of the key no longer shows or stores those characters.
- Sites that had saved the dated GPT-4.1 mini model ID (`gpt-4.1-mini-2025-04-14`) now also see
  the one-time notice about the move to GPT-6 Luna.

## [1.0.8] - 2026-08-17

### Added
- The post/page picker and the "used on" filter are driven by a real usage index, so posts whose
  images are only embedded in content are listed too.
- `infyp_rate_limits` filter to change or switch off the hourly optimization limit. It applies to
  the REST optimize routes only; the admin "Optimize with AI" button is not limited.

### Changed
- The Media filter lists the entire media library. It previously showed only images that were
  not attached to a post and not used as a featured image.
- The hourly optimization limit is raised from 100 to 500 per user.

### Fixed
- Images placed inside post content were missing from the post-type filter. Images embedded in
  blocks, the classic editor and galleries are now indexed and appear under the post type that
  uses them.
- Adding or removing an image in a post, or changing a featured image, updates the filters
  immediately; no manual index rebuild is needed.

## [1.0.7] - 2026-08-14

### Added
- Redirect delivery check: tests whether the server lets old image URLs reach the plugin's 301
  redirects. Result on the Redirects page with a "Test redirects now" button, a Site Health
  test, and a warning on plugin screens when redirects cannot reach visitors. Runs weekly, after
  the first rename, and whenever the server software changes.

### Changed
- Plugin renamed to "Infy SEO - Image SEO Optimizer". The admin menu reads "Image SEO" and the
  dashboard "Image SEO Optimizer". Settings, data, hooks and REST routes are unchanged.

## [1.0.6] - 2026-08-05

### Added
- REST API (`infyp/v1`): list images, read SEO data, run full or single-field optimizations,
  read and change generation settings and the scoring configuration (Application Password
  auth). Other plugins can register routes, extend responses and influence generation.
- REST API admin screen with usage counters, a 30-day activity chart and a filterable log of
  every programmatic optimization.
- WordPress AI Connectors support (WordPress 7.0+) as a connection method; no plugin API key
  required. Existing API-key setups keep working.
- Connector-aware safeguards: a notice when the selected model has no matching connector, usage
  logs record the model that served each request, and token limits are not applied under
  connectors.
- Redesigned setup wizard with a guided flow, accessible progress rail, dark-mode switcher and
  isolation from theme CSS.
- Length tolerance for alt text, title and filename (±1 word, ±15 % characters by default,
  configurable per field; 0 for strict checking).
- Gemini 3.5 Flash-Lite model; model dropdowns list the newest models last within each provider.

### Changed
- API keys are no longer format-checked on save; verification is a zero-cost test call, with a
  Test Key button on the API Keys page and in the wizard.
- API Usage and REST API tables are responsive (labelled cards on phones, contained scrolling on
  larger screens).
- Minimum requirements are now WordPress 6.1 and PHP 8.0.

### Fixed
- Fresh installs on non-English sites generated English metadata until the language setting was
  re-saved; generation now follows the site language from the start.
- "Too long" badges were red in some fields and yellow in others, and table badges could keep a
  stale style after saving in an edit modal; length issues are now consistently yellow warnings.
- The dashboard "Total Images" card under-reported optimized images whenever the average score
  dipped below 100 %.

## [1.0.5] - 2026-05-16

### Fixed
- Fatal error ("class not found") on sites that updated to 1.0.4: the Divi adapter class file was
  missing from the 1.0.4 package. 1.0.5 ships the complete file set with a regenerated
  autoloader.

## [1.0.4] - 2026-05-16

### Added
- Divi Builder support: image filenames are renamed safely across Divi layouts (page content,
  Divi Library, Theme Builder templates), image URL references are updated and Divi's static CSS
  cache is cleared.

### Fixed
- The Optimization Changelog admin page no longer triggers a fatal error (a required template
  file was missing from the release package).

## [1.0.3] - 2026-05-04

### Added
- The filename editor replaces spaces with hyphens, lowercases and collapses consecutive hyphens
  while typing, and trims leading/trailing hyphens on save.
- "Auto-link characters and words" toggle in SEO Score settings (default on): editing one
  threshold updates the linked counterpart proportionally.

### Changed
- Brand badges in the post-type filter (WordPress / Woo / CPT) stay in English in all locales.

### Fixed
- "Too short / Too long" status in the alt text and title editors showed "Too short" when the
  value was too long (characters in range but words over the maximum).
- Counter badge spacing in all field-edit modals.
- Pagination button spacing on the Redirects admin page.
- Existing installs inherit new threshold defaults added in later updates (shallow-merge bug).

## [1.0.2] - 2026-04-25

### Changed
- Google tier simplified to Gemini 3.1 Flash-Lite (existing settings keep working).
- Images are sent at progressively smaller sizes to stay within the 1 MB API limit.
- Gemini token limits tuned to improve response times.

### Fixed
- Timeout specific to Gemini 3.1 Flash-Lite, by disabling the reasoning mode for SEO tasks.

## [1.0.1] - 2026-04-24

### Added
- Post-type badges (WordPress / Woo / Custom) in the image list filter dropdown.

### Changed
- Images over 1 MB use the WordPress `large` intermediate size for AI calls.
- AI request timeout floor raised to 60 seconds.
- WordPress.org screenshots updated.

## [1.0.0] - 2026-04-17

### Added
- Initial public release: AI-generated filenames, alt text, titles and keywords; three AI
  providers; page-builder integration (Gutenberg, Elementor, Uncode, WPBakery); WooCommerce and
  custom post type support; filename updates with URL tracking and automatic 301 redirects; SEO
  score; usage tracking dashboard; optimization changelog; 60+ languages.

[Unreleased]: https://github.com/christian-ehler/infy-ai-image-seo-optimizer/compare/v1.0.9...HEAD
[1.0.9]: https://github.com/christian-ehler/infy-ai-image-seo-optimizer/compare/v1.0.8...v1.0.9
[1.0.8]: https://github.com/christian-ehler/infy-ai-image-seo-optimizer/releases/tag/v1.0.8
[1.0.7]: https://plugins.trac.wordpress.org/browser/infy-ai-image-seo-optimizer/tags/1.0.7
[1.0.6]: https://plugins.trac.wordpress.org/browser/infy-ai-image-seo-optimizer/tags/1.0.6
[1.0.5]: https://plugins.trac.wordpress.org/browser/infy-ai-image-seo-optimizer/tags/1.0.5
[1.0.4]: https://plugins.trac.wordpress.org/browser/infy-ai-image-seo-optimizer/tags/1.0.4
[1.0.3]: https://plugins.trac.wordpress.org/browser/infy-ai-image-seo-optimizer/tags/1.0.3
[1.0.2]: https://plugins.trac.wordpress.org/browser/infy-ai-image-seo-optimizer/tags/1.0.2
[1.0.1]: https://plugins.trac.wordpress.org/browser/infy-ai-image-seo-optimizer/tags/1.0.1
[1.0.0]: https://plugins.trac.wordpress.org/browser/infy-ai-image-seo-optimizer/tags/1.0.0
