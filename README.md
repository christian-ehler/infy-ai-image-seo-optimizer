# Infy SEO – Image SEO Optimizer

Source code of the free WordPress plugin **Infy SEO – Image SEO Optimizer**
(slug `infy-ai-image-seo-optimizer`), published on WordPress.org:
<https://wordpress.org/plugins/infy-ai-image-seo-optimizer/>

The plugin uses an AI provider of your choice to write alt text, titles, keywords and
SEO-friendly filenames for the images in a WordPress media library, one at a time or in bulk.
Renamed files get an automatic 301 redirect, and references to the old URL are updated in post
content and in supported page builders.

To install the plugin on a site, use WordPress.org (Plugins → Add New). This repository is for
developers who want to read, build, extend or improve the code.

## What it does

- Generates filenames, alt text, titles and keywords from the image content, in the site
  language or a language you choose.
- Scores each image's SEO fields and shows which ones need work.
- Renames files safely: URL references are updated in post content and page-builder data
  (Gutenberg, Elementor, WPBakery, Visual Composer, Uncode, Divi), and the old URL redirects
  with a 301.
- Works with posts, pages, WooCommerce products and custom post types.
- Logs every optimization and records API usage.
- Optional Google Search Console integration.
- REST API (`infyp/v1`) for programmatic optimization, with hooks for add-ons.

The full feature list, FAQ and privacy notes are in [`readme.txt`](readme.txt).

## AI providers

- **Anthropic** (Claude)
- **Google** (Gemini)
- **OpenAI**
- **WordPress AI Connectors** (WordPress 7.0+): uses the connectors configured under
  Settings → Connectors, no plugin API key needed.

You bring your own API key. Images are sent only to the provider you select. The models
currently offered are listed in [`readme.txt`](readme.txt); they change as providers retire
models.

## Requirements

- WordPress 6.1 or higher
- PHP 8.0 or higher
- An API key for one of the providers above, or an AI connector on WordPress 7.0+

## Architecture in brief

| Path | Contents |
|---|---|
| `infy-ai-image-seo-optimizer.php` | Main file: header, `INFYP_VERSION`, bootstrapping |
| `includes/Core/AI/` | Provider interface and one class per provider, model registry, prompt builder, response validation |
| `includes/Core/` | Optimization service, caching, security, encryption, file renaming, redirects, Search Console |
| `includes/Core/PageBuilders/` | One adapter per page builder for URL updates on rename |
| `includes/Rest/` | REST controllers for `infyp/v1` |
| `includes/Admin/`, `includes/views/` | Admin pages, settings, wizard; templates |
| `includes/Ajax/`, `includes/Tables/`, `includes/Database/`, `includes/Cron/` | AJAX handlers, list tables, DB access, scheduled tasks |
| `assets/css/`, `assets/js/` | Sources and the built `dist/` bundles |
| `languages/` | Translations |
| `build/` | Build and release scripts |

Conventions: PHP namespace `IPAIS`, class prefix `INFYP_`, hook and option prefix `infyp_`,
text domain `infy-ai-image-seo-optimizer`. Classes are loaded by a Composer classmap
autoloader. All entry points (admin AJAX, REST, PHP API) go through one optimization service,
so they behave the same way.

## REST API (`infyp/v1`)

Authenticate with an Application Password, or with the cookie plus `X-WP-Nonce` from a
logged-in browser session.

| Route | Methods | Purpose |
|---|---|---|
| `/images` | GET | List images with their SEO data |
| `/images/{id}` | GET | Read one image |
| `/images/{id}/optimize` | POST | Generate and save all fields |
| `/images/{id}/field/{field}` | POST | Generate and save one field |
| `/settings` | GET, PATCH | Read settings and registered extensions; change generation settings |
| `/settings/scoring` | GET, PATCH, PUT, DELETE | Read and change SEO score weights and parameters |

### Extension hooks

The extension surface is generic: any plugin can use it, and it has no names reserved for a
particular add-on.

| Hook | Type | Use |
|---|---|---|
| `infyp_rest_routes` | action | Register your own routes on the namespace (receives the namespace string) |
| `infyp_optimize_prompt` | filter | Change the prompt; read the free-form `options` object sent to the optimize routes |
| `infyp_rest_image_response` | filter | Add fields to the image resource |
| `infyp_rest_images_query_args`, `infyp_rest_images_collection` | filter | Adjust the collection query or result |
| `infyp_rest_optimize_permission` | filter | Restrict optimize calls |
| `infyp_rest_after_optimize` | action | React to a successful optimization |
| `infyp_rest_settings_response` | filter | Add fields to the settings resource |
| `infyp_rest_extensions` | filter | Advertise your add-on in `GET /settings` |
| `infyp_rest_scoring_response`, `infyp_rest_scoring_pre_save` | filter | Read or adjust the scoring configuration |
| `infyp_rest_scoring_updated`, `infyp_scores_invalidated` | action | React to scoring changes |
| `infyp_rest_admin_page_sections` | action | Add a section to the REST API admin page |
| `infyp_allowed_optimize_field_types` | filter | Add optimizable field types (with `infyp_optimize_field_result` and `infyp_save_extended_field_result`) |

PHP callers can use `infyp_optimize_and_save()`, `infyp_optimize_field_and_save()` and
`infyp_query_images()`, which accept the same `options` array as the REST routes. Further
hooks (post types, prompt context, MIME types, page-builder adapters, upload and rename events)
are listed under "Developer Information" in [`readme.txt`](readme.txt).

## Building

Requirements: PHP 8.0+, Composer, Node.js with npm, Python 3.

```sh
composer install          # dev tools; the build regenerates the production autoloader
npm install
npm run build             # CSS and JS bundles into assets/*/dist/
python3 build/build.py    # production zip: build/infy-ai-image-seo-optimizer-<version>.zip
```

`python3 build/build.py --help` lists the other modes (`--dev`, `--submit`, `--css-only`,
`--clean`, `--bump`). The build stops if the version numbers in the plugin header,
`INFYP_VERSION` and `Stable tag` do not match.

## Versions and releases

The plugin follows [Semantic Versioning](https://semver.org/):

- **Patch** (`1.0.x`): bug fixes, compatibility updates, model updates that keep settings
  working.
- **Minor** (`1.x.0`): new features, new hooks, new REST routes or fields; backwards compatible.
- **Major** (`x.0.0`): removes or changes a public hook, function, REST route or stored data
  format, or raises the minimum WordPress or PHP version beyond what the previous minor
  supported.

The distribution channel is the WordPress.org plugin directory (SVN). This repository holds the
source of each released version:

- `main` is the released state. It gets one commit per released version, made after
  WordPress.org serves that version.
- Day-to-day development and review happen in the maintainers' working repository, so you will
  not see work-in-progress branches here.
- Pull requests against `main` are welcome. An accepted change is integrated by the maintainers
  and appears here with the next release commit; the pull request is then closed with a
  reference to that release. See [`CONTRIBUTING.md`](CONTRIBUTING.md).

Each version is tagged `vX.Y.Z` and has a GitHub Release with the shipped zip and the changelog
section. Tags are never moved. The change history is in [`CHANGELOG.md`](CHANGELOG.md).

## Issues, support and security

- Bugs and feature requests for developers: GitHub issues on this repository.
- Support for using the plugin: the
  [WordPress.org support forum](https://wordpress.org/support/plugin/infy-ai-image-seo-optimizer/).
- Security problems: do not open a public issue. See [`SECURITY.md`](SECURITY.md).

Contributions: see [`CONTRIBUTING.md`](CONTRIBUTING.md).

## Licence

GPL-2.0-or-later, see [`LICENSE.txt`](LICENSE.txt). Bundled third-party code: Chart.js 4.5.1
(`assets/js/vendor/`), MIT licence.
