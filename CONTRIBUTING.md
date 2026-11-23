# Contributing

Thanks for your interest. Bug reports, optimizations and small, focused pull requests are welcome.

## Before you start

- For support with using the plugin, use the
  [WordPress.org support forum](https://wordpress.org/support/plugin/infy-ai-image-seo-optimizer/).
- For a larger change, open an issue first so we can agree on the approach.
- Security problems: see [SECURITY.md](SECURITY.md), not the issue tracker.

## How changes get in

- `main` is the released state, with one commit per released version. Open pull requests against
  `main`.
- Development and review of upcoming versions happen in the maintainers' working repository.
  When your change is accepted, the maintainers integrate it there, with you credited as the
  author. It reaches this repository with the next release commit, and your pull request is
  closed with a reference to that release instead of being merged directly.
- Keep a pull request to one topic and say what you tested (WordPress and PHP version, provider).

## Checks

- Code follows the WordPress Coding Standards. `composer install` installs PHPCS with WPCS and
  PHPCompatibilityWP.
- `npm run build` must succeed, and committed `dist/` bundles must match their sources.
- Keep PHP 8.0 compatibility and the minimum WordPress version from `readme.txt`.
- Prefix new PHP classes with `INFYP_` (namespace `IPAIS`) and new hooks and options with
  `infyp_`. User-facing strings use the text domain `infy-ai-image-seo-optimizer`.
- Do not change version numbers or `CHANGELOG.md` release sections; that happens when a release
  is prepared. Describe your change in the pull request instead.

## Distribution

The plugin is distributed through the WordPress.org plugin directory (SVN). This repository is
the source; releases are built with `python3 build/build.py` and published to WordPress.org by
the maintainers. Merging a pull request does not release it.

## Licence

By contributing you agree that your contribution is licensed under GPL-2.0-or-later, the licence
of this plugin.
