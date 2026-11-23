=== Infy SEO - Image SEO Optimizer ===

Contributors: cehlerdev
Tags: image seo, alt text, filenames, seo, ai
Tested up to: 7.1
Stable tag: 1.0.9
Requires at least: 6.1
Requires PHP: 8.0
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Donate link: https://infypress.com/plugins/image-seo-optimizer/

Optimize your WordPress images with AI-powered alt text, titles, keywords, and SEO-friendly filenames. Boost rankings effortlessly.

== Description ==

**Rank higher on Google Images without touching your media library by hand**. Image SEO Optimizer writes accessible, descriptive alt text, SEO-friendly filenames and titles for your WordPress images, one click per image — using your choice of Gemini, Claude or GPT. Rename safely: every filename change is auto-301-redirected.

The free plugin optimizes one image at a time, from the image list or through its REST API. Bulk optimization of the whole media library, optimization on upload and automatically generated page context for the AI are available in the Pro add-on: [Image SEO Optimizer Pro](https://infypress.com/plugins/image-seo-optimizer/).

= Why Image SEO Optimizer? =

Images are crucial for SEO, but manually optimizing hundreds of images is time-consuming. Our plugin solves this by:

* **Saving Hours of Manual Work** - AI optimizes images in seconds
* **Improving SEO Rankings** - Search engines love properly optimized images
* **Enhancing Accessibility** - AI-generated alt text helps screen readers
* **Smart Filename Management** - SEO-friendly filenames with automatic URL updates across page builders

= Key Features =

**AI-Powered Metadata Generation**

* Generate SEO-friendly filenames automatically
* Create descriptive alt text (accessibility + SEO)
* Generate optimized image titles
* Extract relevant keywords

**Multiple AI Providers**

* **Google Gemini** - Gemini 3.1 Flash-Lite (default), Gemini 3.5 Flash-Lite
* **OpenAI** - GPT-6 Luna
* **Anthropic Claude** - Claude 5.5 Haiku, Claude 4.5 Haiku
* Switch between providers anytime
* Use your own API keys (full control over costs)
* Or, on WordPress 7.0+, use **WordPress AI Connectors** (Settings → Connectors) — no plugin API key required

**Page Builder Integration**

* **Gutenberg** - Full WordPress Block Editor support
* **Elementor** - Complete integration with automatic URL updates
* **WPBakery** - Seamless integration
* **Visual Composer** - Full compatibility
* **Uncode** - Full compatibility
* **Divi** - Full support across page content, Divi Library, and Theme Builder
* Automatic URL updates when renaming files
* No broken images after filename changes

**SEO Features**

* Real-time SEO score calculation (0-100 scale)
* 7-component scoring algorithm (100 points total)
* Detailed score breakdown by field
* Visual indicators for optimization status
* Track optimization progress across your media library

**Smart Dashboard**

* Visual media library overview
* See optimization status at a glance
* Filter by post type and by the page or post an image is used on
* Search filenames, titles, alt text, captions and descriptions
* Sort by SEO score (lowest first by default) or upload date
* Image usage tracking (where images are used)

**Usage Tracking & Analytics**

* API calls, success rate, average response time and estimated cost for the last 30 days
* Usage by provider and model
* Daily usage chart
* Log of every API call

**301 Redirect Protection**

* Automatic 301 redirects when images are renamed
* Preserves SEO value — no broken links from search engines, social shares, or external sites
* Covers all image sizes (thumbnails, crops, originals)
* Smart redirect chain resolution (A→B→C becomes A→C)
* Hit tracking to monitor redirect usage
* Configurable expiry (6, 12, 24 months, or never)
* Zero impact on normal page loads — only activates for old image URLs

**Advanced Features**

* **60+ Languages** - Generate metadata in any supported language
* **Changelog Tracking** - Complete audit trail of all changes
* **Inline Editing** - Edit any AI-generated value afterwards; a filename suggested in the field editor is applied only after you confirm it
* **Encrypted API Keys** - Secure storage using WordPress salts
* **REST API Rate Limit** - The REST API optimize routes allow 500 requests per user per hour (adjustable with the `infyp_rate_limits` filter). Optimizing from the admin screens is not limited

**Developer-Friendly**

* REST API (`infyp/v1`) — optimize images and manage settings programmatically
* 45 action hooks for workflow integration
* 106 filter hooks for customization
* Clean, documented codebase
* WordPress Coding Standards compliant
* Extensible architecture
* Well-documented API

= How It Works =

1. **Install & Activate** - Quick installation from WordPress.org
2. **Add API Key** - Add at least one AI provider API key
3. **Configure Settings** - Choose your preferred AI model and language
4. **Optimize Images** - One click per image in the image list, or one REST API request per image
5. **Monitor Results** - Track SEO scores and usage statistics

= Perfect For =

* **Bloggers** - Optimize featured images and content images in a few clicks
* **Photographers** - Accurate descriptions and descriptive filenames for your photos
* **Agencies** - Manage multiple client sites efficiently
* **Content Creators** - Focus on content, not manual image optimization
* **Anyone with Images** - Every WordPress site needs image SEO

= What Makes Us Different =

Unlike basic image SEO plugins, Image SEO Optimizer:

* **Actually "Sees" Images** - AI vision models understand image content
* **Natural Descriptions** - Not keyword-stuffed robotic text
* **Your Guidance** - A global prompt add-on and optional per-image prompts carry your brand, products and keywords
* **Multilingual Native** - True multilingual support, not translations
* **Smart URL Updates** - Prevents broken images across page builders
* **301 Redirect Protection** - Automatic redirects preserve SEO when renaming files
* **Full Transparency** - You own your API keys, control your costs

== Installation ==

= Automatic Installation (Recommended) =

1. Go to **Plugins → Add New** in WordPress admin
2. Search for "Image SEO Optimizer"
3. Click **Install Now** then **Activate**
4. Navigate to **Image SEO** in the admin menu
5. Add your API key and start optimizing

= Manual Installation =

1. Download the plugin zip file
2. Go to **Plugins → Add New → Upload Plugin**
3. Upload the zip file and activate
4. Configure your settings in **Image SEO → Settings**

= Getting API Keys =

You need at least one AI provider API key (choose one or use multiple). On WordPress 7.0+ you can instead select **WordPress AI (Connectors)** as the connection method on the API Keys page and use the AI connectors configured in **Settings → Connectors** — no plugin API key required.

1. **OpenAI API Key**:
   * Visit [platform.openai.com/api-keys](https://platform.openai.com/api-keys)
   * Create account or sign in
   * Click "Create new secret key"
   * Copy and paste into plugin settings

2. **Google Gemini API Key**:
   * Visit [aistudio.google.com/app/apikey](https://aistudio.google.com/app/apikey)
   * Sign in with Google account
   * Click "Create API key"
   * Copy and paste into plugin settings

3. **Anthropic Claude API Key**:
   * Visit [platform.claude.com](https://platform.claude.com/settings/keys)
   * Create account or sign in
   * Generate API key
   * Copy and paste into plugin settings

= Configuration =

After adding your API key:

1. Go to **Image SEO → Settings**
2. Select your preferred AI provider and model
3. Choose output language (default: English)
4. Configure optional settings (custom prompts, optimization preferences)
5. Save settings
6. Visit **Image SEO → Dashboard** to start optimizing

== Frequently Asked Questions ==

= What is the best AI model for image SEO optimization? =

The plugin uses **Gemini 3.1 Flash-Lite** by default. In our test in November 2026 (20 test images, the plugin's own prompts) these models returned a valid result for every image:

* **Gemini 3.1 Flash-Lite** (Google, default) - average SEO score 99 of 100
* **GPT-6 Luna** (OpenAI) - average SEO score 99 of 100, and the lowest cost of the offered models
* **Claude 5.5 Haiku** (Anthropic) - average SEO score 93 of 100; its alt texts tend to be longer than the recommended 10-16 words

If you set up the plugin with only an OpenAI or only an Anthropic key, the setup wizard selects GPT-6 Luna or Claude 5.5 Haiku. Gemini 3.5 Flash-Lite and Claude 4.5 Haiku can also be selected, and you can switch models at any time in the settings.

= How much does AI image SEO optimization cost? =

The plugin is free. You pay the AI provider directly with your own API key, and provider prices change. A full optimization of one image (keywords, filename, alt text and title) makes up to four AI requests (three when the image already has keywords).

* Measured in November 2026 on 20 test images: about $1.46 per 1,000 images with Gemini 3.1 Flash-Lite
* Measured in November 2026 on 20 test images: about $0.63 per 1,000 images with GPT-6 Luna
* Measured in November 2026 on 20 test images: about $0.99 per 1,000 images with Claude 5.5 Haiku

The **API Usage** page shows an estimate of your own costs; your provider's bill is the exact figure.

= Does AI image optimization affect WordPress site speed? =

No. AI image SEO optimization runs entirely in the WordPress admin area and has zero impact on your frontend page load speed:

* All AI processing happens in the admin dashboard, not on public pages
* The only frontend component is the 301 redirect handler for renamed images — it adds zero overhead to normal page loads
* Efficient caching minimizes database queries
* AI requests are sent from the admin screen in the background, never during a visitor's page load

= How does AI image filename optimization work in WordPress? =

When you optimize an image filename with AI, the plugin:

* Generates an SEO-friendly filename based on image content and your keywords
* Renames the physical file on your server
* Updates all database references and page builder content (Gutenberg, Elementor, WPBakery, Uncode) automatically
* Creates automatic 301 redirects from old URLs to preserve your SEO rankings
* Covers all image sizes (thumbnails, medium, large, etc.)
* Tracks all changes in the optimization changelog

= Does renaming images create automatic 301 redirects for SEO? =

Yes. Every time an image is renamed (manually or via AI optimization), the plugin automatically creates 301 permanent redirects from old URLs to new URLs. This preserves your search engine rankings, keeps social media shares working, and prevents broken images on external sites.

Redirects are served early in the WordPress load for optimal performance. The redirect requires your server to pass requests for missing files through to WordPress — standard Apache and nginx configurations do, but some static-asset rules and CDN setups answer old URLs themselves. The plugin tests this for you and warns you when redirects cannot reach visitors (Redirects screen and Site Health). You can configure expiry (6, 12 or 24 months, or never) and monitor all active redirects from a dedicated management page.

= Does AI image SEO work with Elementor, Gutenberg and WPBakery? =

Yes! Full page builder support is included:

* **Gutenberg** (WordPress Block Editor) - Complete integration
* **Elementor** - Full support for all image widgets
* **WPBakery** - Seamless integration
* **Visual Composer** - Full compatibility
* **Uncode** - Full compatibility
* **Divi** - Full support across page content, Divi Library, and Theme Builder

When you rename an image file, all references in page builder content are updated automatically. Developers can register additional page builders via the `infyp_register_pagebuilder_adapters` hook.

= Is my data secure when using AI for image optimization? =

Yes. Your image data and API keys are completely secure:

* API keys are encrypted using WordPress security salts (PBKDF2)
* Images are sent only to your chosen AI provider (Anthropic, Google or OpenAI), and never to InfyPress
* Nothing is collected by default. There is no analytics and no background tracking
* One optional opt-in exists: if you tick the newsletter box in the setup wizard, site environment data and your account email are sent to InfyPress. It is off unless you tick it, and the full field list is in the Privacy section below
* Open source code, fully auditable

= Can I customize AI prompts for image alt text generation? =

Yes! Custom AI prompts let you control how alt text, titles, and filenames are generated:

* "Focus on product features and benefits"
* "Include brand name 'YourBrand' where relevant"
* "Target keywords: keyword1, keyword2"
* "Use friendly, conversational tone"

You can set a global prompt for all images, and turn on a per-image prompt that you fill in each time you optimize an image.

= How accurate is AI-generated alt text and image metadata? =

AI-generated alt text and image metadata is highly accurate thanks to advanced vision models:

* AI models actually "see" and analyze your image content — objects, scenes, colors, text
* Generates natural, human-like descriptions optimized for both SEO and accessibility
* Includes your target keywords naturally without keyword stuffing
* More consistent than manual alt text writing across large media libraries
* You can edit every AI-generated value afterwards, and every change is recorded in the Optimization Changelog

= Which image formats are supported for AI SEO optimization? =

All standard WordPress image formats are supported:

* **JPEG/JPG** - Full AI analysis and metadata optimization
* **PNG** - Full AI analysis and metadata optimization
* **WebP** - Full AI analysis and metadata optimization
* **GIF** - Full AI analysis and metadata optimization
* **SVG** - Metadata optimization only (no visual AI analysis)

= How does the image SEO scoring algorithm work? =

The image SEO scoring algorithm evaluates 7 components (100 points total):

* **Alt Text Presence** (20 pts) - Descriptive, accessibility-compliant alt text exists
* **Alt Text Quality** (15 pts) - Optimal length (50-125 chars) and keyword inclusion
* **Title Presence** (15 pts) - Meaningful image title exists
* **Title Quality** (10 pts) - Optimized length and keyword usage
* **Filename Quality** (15 pts) - SEO-friendly, descriptive filenames
* **Keyword Placement** (15 pts) - Relevant keywords placed across all fields
* **Content Uniqueness** (10 pts) - Distinct metadata across fields (no duplication)

Scores update in real-time as you optimize. Length values slightly outside the optimal ranges still earn full points within a configurable tolerance. You can customize scoring weights, ranges, and tolerance in Settings.

= Does AI image SEO work with WooCommerce product images? =

Yes! The plugin fully supports WooCommerce and all Custom Post Types. AI image SEO optimization works with:

* WooCommerce product images and gallery images
* Any Custom Post Type registered in WordPress
* Featured images, in-content images, and page builder images

Use custom AI prompts to focus on product-specific keywords like brand names, features, and categories for better e-commerce image SEO.

== Screenshots ==

1. **Dashboard & Image Library** - Image SEO overview with optimization percentage, field-level stats (Keywords, Filenames, Alt Text, Titles), AI model and language selectors, and the full image list with per-field SEO scores and one-click "Optimize with AI" buttons.
2. **Global AI Prompt Add-on** - Add custom instructions that apply to every AI optimization — brand voice, niche keywords, tone, or contextual guidance — across your entire media library.
3. **Per-Image Custom Prompt** - When "Enable custom prompt per image" is on, the Optimize button opens a modal for image-specific instructions (e.g. "a mountain view in California during sunrise, focus on travel and nature keywords").
4. **Post Type Filter** - Dropdown with typed badges for WordPress (built-in types), Woo (WooCommerce), and Custom (your CPTs). Multi-select to filter the image list by one or more post types.
5. **Page & Post Filter** - Narrow the image list to images attached to a specific page, post, product, or custom post type entry.
6. **Full-Text Search** - Search filenames, titles, alt text, captions and descriptions. Instantly surfaces any image matching the term across your full media library.
7. **Keyword Editor** - Edit up to 3 keywords per image with AI assistance and a built-in best-practices panel (1–3 relevant keywords, unique per image, focused on real search terms).
8. **Filename Editor** - Edit filenames with live keyword-usage chips, character and word counters, filename guidelines (lowercase, hyphens, 40–60 chars), and one-click AI optimization.
9. **Filename SEO Score** - Detailed scoring for filenames: Keywords, Characters, Words, and Format — each graded independently against Google's image filename recommendations.
10. **Alt Text Editor** - Edit alt text with keyword coverage, character/word counters, and a built-in best-practices checklist (describe content, 50–125 chars, include keywords, avoid "image of" prefixes).
11. **Title Editor** - Edit image titles with keyword coverage, length feedback, and title-specific SEO guidelines (40–60 chars, include keywords, be descriptive).
12. **Image Usage Tracker** - See exactly where an image appears on your site: featured image, inside post/page content, or inside page-builder elements like Gutenberg or Elementor.
13. **SEO Score Breakdown** - Overall image SEO score plus per-field breakdowns for Alt Text, Filename, and Title, each with sub-scores for Characters, Words, and Keywords Found.
14. **API Keys Configuration** - Multi-provider setup for Google Gemini, Anthropic Claude, and OpenAI GPT. Keys are encrypted with WordPress security salts before storage.
15. **API Usage Dashboard** - Track total API calls, success rate, average response time, and estimated cost, with daily usage charts, per-provider breakdowns, and a full call log.
16. **301 Image Redirects** - Automatic 301 redirects preserve SEO when filenames change. Monitor active redirects, hits, sources, page-builder detection, and redirect status for every image size.
17. **SEO Scoring System** - Configure how the total SEO score is weighted across fields (Alt Text, Filename, Title, Keyword Placement, Content Uniqueness) with Google-guideline-based defaults.
18. **Scoring Configuration & Model Selection** - Fine-tune presence-vs-quality weights per field, enforce a 100% total, and compare the measured cost of the offered Gemini, Claude and GPT vision models.
19. **Banner & Notice Management** - Control promotional banners and in-plugin reminders, reset dismissed notices, rebuild the search index, and configure 301-redirect auto-expire behavior.
20. **Search Console Integration** - Connect to Google Search Console to track image impressions, clicks, CTR, and ranking positions using OAuth Quick Connect or custom credentials.
21. **Optimization Changelog** - Full audit trail: date, AI model, image, field, before/after values, context, and user attribution for every AI-generated change.
22. **Debug Information** - System status overview: WordPress/PHP/plugin version, memory usage, WP_DEBUG state, API key health per provider, recent error log, cache performance, and page-builder activity.

== Changelog ==

= 1.0.9 - 2026-11-23 =
* Pro users: update the Pro add-on to 1.0.3 before or together with this update.
* Fixed: an uploaded image is reported as uploaded once: no longer twice for uploads WordPress 7.2 processes in the browser, and no longer when an image is renamed or its thumbnails are regenerated.
* Fixed: renaming an image whose file name contains capital letters (for example `IMG_4711.jpg`) now redirects the old URL of every image size, not only the main file.
* Fixed: a first run of the setup wizard with only an OpenAI or only an Anthropic key now selects a model of that provider, so "Optimize with AI" works right away.
* Fixed: the setup wizard no longer saves an API key the provider rejects, and a failed "Optimize with AI" shows its error next to the image instead of a browser alert.
* Fixed: the "Invalid", "Saved — not verified" and "Connected" badges on the setup wizard's API key cards are now visible.
* Fixed: a rejected key's provider reply in the setup wizard no longer runs into the next sentence, and a reply that repeats part of the key no longer shows or stores those characters.
* Fixed: uninstalling the plugin removes its settings, user preferences and scheduled events, also when it is uninstalled with WP-CLI (`wp plugin uninstall`).
* Fixed: the REST API rate limit uses a fixed one-hour window from the first request, and a "too many requests" answer tells the client when to retry (`Retry-After` header).
* Fixed: renaming an image used on a Visual Composer page no longer causes a fatal error.
* Fixed: an invalid or cut-off reply from OpenAI no longer causes a fatal error.
* Fixed: Claude models work through the WordPress AI connector, provider errors there show the provider's reason instead of "no connector configured", and their usage costs use the model's own price.
* Fixed: AI renames made through the REST API, WP-CLI or scheduled tasks are labelled "AI" on the Redirects page.
* Fixed: expired temporary data of the plugin is removed completely by the daily cleanup.
* Fixed: a PHP 8.4 deprecation notice in the Search Console connection; the activation check now names the real minimum, PHP 8.0.
* Fixed: "Get Pro" links open the product page directly.
* Changed: GPT-6 Luna replaces GPT-4.1 mini, which OpenAI shuts down on 2027-01-14. Sites using GPT-4.1 mini (also the dated ID `gpt-4.1-mini-2025-04-14`) are switched automatically and see a one-time notice.
* Added: Claude 5.5 Haiku.
* Changed: Gemini 3.1 Flash-Lite stays the default model and now uses Google's current model ID.
* Changed: the cost comparison on the SEO Score page shows the current models with costs measured in November 2026.
* Changed: the readme and setup wizard now describe only what the free plugin does.
* Changed: translations updated for 58 languages.
* Developers: new function `infyp_is_upload_complete( $attachment_id )` and filter of the same name. `infyp_after_image_upload` now fires once per completed upload, after all image sizes are saved. `infyp_apply_field()` accepts an optional `$context` argument. `INFYP_Security_Manager::validate_ajax_request()` is deprecated and will be removed in a later version. The model registry gets `get_canonical_id()`, `get_accepted_model_ids()`, `is_replaced_id()` and `get_default_model_for_provider()`, and the optional config keys `replaces`, `provider_default` and display pricing keys; the new option `infyp_model_migration` records a model move.

= 1.0.8 - 2026-08-17 =
* Fixed: images placed inside post content were missing from the post-type filter. The image list only knew about featured images and images uploaded from within a post, so filtering by Posts showed a handful of images and filtering by a custom post type often showed none at all. Images embedded in blocks, the classic editor and galleries are now indexed and appear under the post type that uses them.
* Fixed: adding or removing an image in a post, or changing a featured image, now updates the filters immediately — no manual index rebuild needed.
* Changed: the Media filter now lists your entire media library. It previously showed only images that were not attached to a post and not used as a featured image.
* Added: the post/page picker and the "used on" filter are now driven by a real usage index, so posts whose images are only embedded in content are listed too.
* Changed: the hourly optimization limit is raised from 100 to 500 per user, and can now be changed — or switched off entirely — with the new `infyp_rate_limits` filter. It applies to the REST API optimize routes; the admin "Optimize with AI" button is not limited.

= 1.0.7 - 2026-08-14 =
* Changed: plugin renamed to "Infy SEO - Image SEO Optimizer". The admin menu now reads "Image SEO" and the dashboard "Image SEO Optimizer". Nothing else changes: same plugin, same settings, same data, same hooks and REST routes.
* Added: redirect delivery check — the plugin now tests whether your server actually lets old image URLs reach its 301 redirects (some server configurations answer missing files themselves). Result on the Redirects page with a "Test redirects now" button, a Site Health test, and a clear warning on plugin screens when redirects cannot reach visitors. The check runs weekly, after the first rename, and whenever your server software changes.

= 1.0.6 - 2026-08-05 =
* Added: REST API (`infyp/v1`) — list images, read SEO data, run full or single-field optimizations, and read/change generation settings and scoring configuration programmatically (Application Password auth). Other plugins can register routes, extend responses and influence generation.
* Added: REST API admin screen with usage counters, a 30-day activity chart, and a filterable log of every programmatic optimization.
* Added: WordPress AI Connectors support (WordPress 7.0+) — use the AI connectors from Settings → Connectors as connection method, no plugin API key required. Existing API-key setups keep working unchanged.
* Added: connector-aware safeguards — a live notice when the selected model has no matching connector, usage logs record the model that actually served each request, and token limits are no longer applied under connectors (they could truncate substituted models into failures).
* Added: completely redesigned setup wizard — a guided, conversational flow with animated steps, accessible progress rail, dark-mode switcher, and isolation from theme CSS so themes can't break its layout.
* Added: length tolerance ("play room") for alt text, title and filename — values slightly outside the optimal range (±1 word, ±15% characters by default, configurable per field) keep the green badge and full SEO score instead of nagging "Too long". Set to 0 for strict checking.
* Added: Gemini 3.5 Flash-Lite model; model dropdowns now list newest models last within each provider.
* Changed: API keys are no longer format-checked on save (providers change key formats) — verification is now a real zero-cost test call, with a Test Key button on the API Keys page and in the wizard.
* Improved: API Usage and REST API tables are fully responsive — labeled cards on phones, contained scrolling on larger screens.
* Fixed: fresh installs on non-English sites generated English metadata until the language setting was re-saved; generation now follows the site language from the start.
* Fixed: "Too long" badges were red in some fields and yellow in others, and table badges could keep a stale style after saving in an edit modal — length issues are now consistently yellow warnings everywhere.
* Fixed: the dashboard "Total Images" card under-reported optimized images whenever the average score dipped below 100%.
* Changed: minimum requirements are now WordPress 6.1 and PHP 8.0, matching APIs and language features the plugin already used.

= 1.0.5 - 2026-05-16 =
* Fixed: a fatal error ("class not found") on sites that updated to 1.0.4 — the new Divi adapter class file was not included in the 1.0.4 package. 1.0.5 ships the complete file set with a regenerated autoloader. If you are on 1.0.4, update to 1.0.5 to restore your site.

= 1.0.4 - 2026-05-16 =
* Added: Divi Builder support — image filenames are now safely renamed across Divi layouts (page content, Divi Library, and Theme Builder templates), with all image URL references updated automatically and Divi's static CSS cache cleared
* Fixed: the Optimization Changelog admin page no longer triggers a fatal error (a build-packaging issue had excluded a required template file from the release)

= 1.0.3 - 2026-05-04 =
* Fixed "Too short / Too long" status text in alt text and title field editors that incorrectly showed "Too short" when the value was actually too long (chars in range but words over the maximum)
* Fixed counter badge spacing in all field-edit modals — the status, character-count, and word-count indicators now render in a clean horizontal 33/33/33 layout (stacks vertically on phone-size screens)
* Fixed pagination button spacing on the Redirects admin page that disappeared a few seconds after page load
* Added: filename editor now auto-replaces spaces with hyphens, auto-lowercases, and collapses consecutive hyphens as you type — and trims leading/trailing hyphens when you save
* Added: "Auto-link characters and words" toggle in SEO Score settings (default on) so editing one threshold (chars or words, min or max) automatically updates the linked counterpart proportionally — chars and words can no longer drift into inconsistent ranges
* Brand badges in the post-type filter (WordPress / Woo / CPT) now stay in English in all locales since they're recognized terms across the WordPress ecosystem
* Internal: existing installs now correctly inherit any new threshold defaults added in future plugin updates (fixes a shallow-merge bug that would silently drop newly-added defaults until users re-saved settings)

= 1.0.2 - 2026-04-25 =
* Fixed a timeout error specific to Gemini 3.1 Flash-Lite by disabling unnecessary "Reasoning" mode for SEO tasks
* Improved API payload efficiency by systematically falling back to smaller image sizes to strictly enforce the 1MB limit
* Optimized API token limits for Gemini to improve response times and prevent runaway generation
* Simplified Google tier to the fast Gemini 3.1 Flash-Lite model (transparent backward compatibility for existing users)

= 1.0.1 - 2026-04-24 =
* Added post-type badges (WordPress / Woo / Custom) in the image list filter dropdown
* Large images (>1MB) now use the WordPress 'large' intermediate size for AI calls, dramatically reducing upload time and failures
* Raised AI request timeout floor to 60 seconds to prevent cURL 28 timeouts on slow networks and cold starts
* Updated screenshots for the WordPress.org listing to reflect current free-plugin UI

= 1.0.0 - 2026-04-17 =
* Initial public release
* AI-powered metadata generation (filenames, alt text, titles, keywords)
* Support for 3 AI providers (Google Gemini 3 Flash, Anthropic Claude 4.5 Haiku, OpenAI GPT-4.1 mini)
* Page builder integration (Gutenberg, Elementor, Uncode, WPBakery)
* WooCommerce & Custom Post Type support
* Automatic filename updates with URL tracking
* Automatic 301 redirects for renamed images
* SEO score calculation with 6-component algorithm
* Usage tracking and analytics dashboard
* Changelog for audit trail
* 60+ language support

== Upgrade Notice ==

= 1.0.9 =
Pro users: update the Pro add-on to 1.0.3 before or together with this update. Upload processing runs once per image (no second run on WordPress 7.2 or after a rename), redirects cover every size of a renamed image, uninstall also cleans up via WP-CLI; GPT-6 Luna replaces GPT-4.1 mini automatically.

= 1.0.6 =
Adds WordPress 7.0 AI Connectors support (no plugin API key needed), a REST API for optimizing images programmatically, a completely redesigned setup wizard, length tolerance for SEO checks, and Gemini 3.5 Flash-Lite support.

= 1.0.5 =
Critical hotfix for a fatal error in 1.0.4. If you updated to 1.0.4, update to 1.0.5 immediately to restore your site.

= 1.0.4 =
Adds Divi Builder support (image filenames safely renamed across Divi page content, Divi Library, and Theme Builder) and fixes a fatal error on the Optimization Changelog admin page.

= 1.0.3 =
Fixes the "Too short / Too long" verdict in field-edit modals, restores counter and Redirects pagination spacing, adds filename auto-slugify (spaces become hyphens) and an Auto-link chars/words threshold setting.

== Privacy & External Services ==

This plugin connects to third-party AI services that you configure with your own API keys. Images are sent to these services for analysis and metadata generation:

**Google Gemini API**
* Service: Google AI image analysis
* Privacy Policy: [https://policies.google.com/privacy](https://policies.google.com/privacy)
* Terms of Service: [https://policies.google.com/terms](https://policies.google.com/terms)
* Gemini API Additional Terms: [https://aistudio.google.com/docs/terms](https://aistudio.google.com/docs/terms)
* When used: When you select Gemini as your AI provider and optimize images, and when you save or test a Gemini API key

**OpenAI API**
* Service: OpenAI image analysis
* Privacy Policy: [https://openai.com/policies/privacy-policy/](https://openai.com/policies/privacy-policy/)
* Terms of Service: [https://openai.com/policies/services-agreement/](https://openai.com/policies/services-agreement/)
* When used: When you select OpenAI as your AI provider and optimize images, and when you save or test an OpenAI API key

**Anthropic Claude API**
* Service: Anthropic Claude image analysis
* Privacy Policy: [https://www.anthropic.com/legal/privacy](https://www.anthropic.com/legal/privacy)
* Terms of Service: [https://www.anthropic.com/legal/commercial-terms](https://www.anthropic.com/legal/commercial-terms)
* When used: When you select Claude as your AI provider and optimize images, and when you save or test an Anthropic API key

**Important Notes:**
* Images are ONLY sent to the service you configure
* You use your own API keys (you control the relationship with the provider)
* No automatic data collection by plugin authors
* No tracking or analytics without your explicit consent
* You can review each provider's privacy policy before using their service

**Optional Newsletter & Telemetry (Opt-in Only)**

During the setup wizard, you can optionally subscribe to our newsletter. If you choose to opt in, we collect the following information to help improve our plugin:

*Environment Data:*
* WordPress version, PHP version, MySQL version
* Server software, memory limit
* Site URL, locale, timezone
* Multisite status, WP_DEBUG status

*Plugin & Theme Data:*
* Image SEO Optimizer plugin version
* Active theme name, version and parent theme
* List of active plugins (names only, up to 30)
* List of installed plugins, active or not (names only, up to 50)
* Detection of common plugins (WooCommerce, Yoast, Rank Math, Elementor)

*Content Statistics:*
* Total media library count
* Image count (images only)
* Post, page, and product counts

*Setup Answers:*
* Website type, content language and library size chosen in the setup wizard

*User Data (from your WordPress account):*
* Email address (for newsletter delivery)
* Display name
* WordPress user ID and role
* Time of subscription

This data is:
* Only collected if you explicitly check the opt-in checkbox
* Sent to: `https://infypress.com/wp-json/ai-products/v1/newsletter/subscribe`
* Used to improve our plugin and send relevant updates
* Never sold to third parties
* You can unsubscribe at any time

For more details, see our [Privacy Policy](https://infypress.com/privacy/).

**Google Search Console Integration**

This plugin includes an optional Google Search Console integration that connects to several Google services:

* *Google OAuth 2.0* — Used to authorize access to your Google Search Console account.
  * Endpoints: `accounts.google.com`, `oauth2.googleapis.com`
  * Data sent: OAuth authorization codes and refresh tokens for token exchange
  * When used: When you connect or reconnect to Google Search Console, and periodically to refresh access tokens
  * Privacy Policy: [https://policies.google.com/privacy](https://policies.google.com/privacy)
  * Terms of Service: [https://policies.google.com/terms](https://policies.google.com/terms)

* *Google Search Console API* — Used to retrieve your site's search performance data, URL inspection results, and sitemap information.
  * Endpoints: `googleapis.com/webmasters/v3`, `searchconsole.googleapis.com/v1`
  * Data sent: OAuth access token, your site URL, date ranges, and URLs for inspection
  * When used: When you view Search Console data within the plugin dashboard
  * Privacy Policy: [https://policies.google.com/privacy](https://policies.google.com/privacy)
  * Terms of Service: [https://policies.google.com/terms](https://policies.google.com/terms)

* *InfyPress OAuth Proxy* — When using the "Quick Connect" method to connect Google Search Console, the authorization flow is routed through our proxy server. This proxy securely holds the Google OAuth client secret so it is never shipped in the plugin code.
  * Endpoint: `infypress.com/wp-json/ai-products/v1/gsc`
  * Data sent: Proxy authorization code, refresh token, and your site URL
  * When used: Only when you use the "Quick Connect" method for Google Search Console (not when using custom credentials)
  * Privacy Policy: [https://infypress.com/privacy/](https://infypress.com/privacy/)

== Developer Information ==

Image SEO Optimizer provides 151 hooks (45 actions and 106 filters) for developers to extend its functionality. Below are the most commonly used hooks; the others can be found by their `do_action()` and `apply_filters()` calls in the plugin source.

= Key Hooks =

**Filters:**

* `infyp_allowed_post_types` - Filter which public post types are shown in the image list. Default: all public post types registered in WordPress.
* `infyp_image_context` - Add custom context to AI prompts (e.g. product data). Parameters: `$prompt_addon`, `$image_id`, `$context_type`.
* `infyp_supported_mime_types` - Filter supported image MIME types. Default: `['image/jpeg', 'image/png', 'image/webp', 'image/gif']`.
* `infyp_register_pagebuilder_adapters` - Register additional page builder adapters. Parameters: `$adapters`.
* `infyp_image_usage_post_ids` - Find additional post IDs where an image is used. Parameters: `$post_ids`, `$image_id`, `$image_url`.
* `infyp_table_columns_after_title` - Add custom columns to the image list table. Parameters: `$columns`.

**Actions:**

* `infyp_after_image_upload` - Fires once for each newly uploaded image, after the image and all its sizes are saved. Parameters: `$attachment_id`, `$metadata`.
* `infyp_filename_updated` - Fires after filename URL references are updated across content. Parameters: `$post_id`, `$url_map`, `$results`.
* `infyp_before_settings_cards` / `infyp_after_settings_cards` - Add custom cards to the Settings page.

= Code Example =

    // Customize which post types appear in the image filter
    add_filter( 'infyp_allowed_post_types', function( $types ) {
        // Remove a type you don't want
        unset( $types['custom_type'] );
        return $types;
    } );

    // Add custom context to AI prompts
    add_filter( 'infyp_image_context', function( $context, $image_id, $type ) {
        $parent_id = wp_get_post_parent_id( $image_id );
        if ( $parent_id && get_post_type( $parent_id ) === 'portfolio' ) {
            $context .= "\nThis image is from a portfolio project.";
        }
        return $context;
    }, 10, 3 );

= Requirements =

* WordPress 6.1 or higher
* PHP 8.0 or higher
* MySQL 5.7 or higher
* At least one AI provider API key (OpenAI, Google Gemini, or Anthropic Claude) — or, on WordPress 7.0+, an AI connector configured under Settings → Connectors
* 128MB PHP memory limit recommended
* cURL or similar for API requests

== Support & Links ==

* [Documentation](https://infypress.com/docs/image-seo-optimizer/)
* [WordPress Support Forum](https://wordpress.org/support/plugin/infy-ai-image-seo-optimizer/)
* [Feature Requests](https://infypress.com/support/)
* [Report Bugs](https://infypress.com/support/)