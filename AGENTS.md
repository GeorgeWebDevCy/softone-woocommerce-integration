# Softone WooCommerce Integration – Agent Guidelines

Welcome! This repository contains a WordPress plugin that bridges a WooCommerce store with a SoftOne ERP instance. Use this document as a quick reference while performing future tasks in this project.

## Repository layout
- `softone-woocommerce-integration.php` boots the plugin, registers hooks, and loads the `admin/`, `includes/`, and `public/` modules.
- `admin/` contains wp-admin settings screens (API tester, log viewers, configuration pages).
- `includes/` hosts shared business logic such as API clients, synchronisation jobs, and helpers.
- `public/` collects any front-end hooks that run on the WooCommerce storefront.
- `tests/` stores lightweight regression scripts and sanity checks that mirror historic bug fixes.

## Coding conventions
- Follow the [WordPress PHP Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/).
  - Use tabs for indentation, brace placement matching the existing files, and snake_case function names unless a class requires camelCase methods.
  - Escape output using WordPress helpers (`esc_html__`, `esc_url`, `wp_kses_post`, etc.).
  - Use translation functions for user-facing strings.
- Avoid introducing new external dependencies unless absolutely necessary; prefer WordPress core utilities.
- When touching existing classes, mirror the surrounding docblock and hook documentation style.
- Keep compatibility with PHP 7.4 and WooCommerce 7.5+.

## Testing expectations
- Automated test coverage is minimal; when changing synchronisation logic include manual steps or targeted regression scripts under `tests/` if feasible.
- If a change affects the plugin bootstrap or Composer-managed code, run `composer install` to ensure autoloaders remain valid.
- Document any manual verification performed in your final notes.

## Documentation
- Plugin readme content lives in `README.txt`. Update both the description and changelog when the behaviour changes in a user-visible way.
- Localisation strings are stored within the code; keep them wrapped in translation helpers for `.po` file generation.

## Pull request summaries
- Provide a concise summary focused on behavioural changes and mention any manual/automated testing that supports the update.
- Call out any follow-up tasks or assumptions that reviewers should be aware of.

Thanks for contributing!
