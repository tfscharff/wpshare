# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

**wpshare**: a WordPress plugin (not yet written) for the Wallace Library site
(library.wheatoncollege.edu, WP Engine behind Cloudflare), usable by any site. It controls what the
site shares with search engines through its sitemap, replacing a stopgap in the theme (see below).
Repo: https://github.com/tfscharff/wpshare (public, GPLv2, like the sibling plugins).

The sibling plugins are `wphours`, `wprooms`, `wpmeet`, `wpfloors` and `wpa11y`, all in
`C:\Users\thoma\Documents\`. Copy their conventions, especially `wpfloors\CLAUDE.md` (single-file
plugin, stub test harness) and `wpa11y\bin\release.sh` (release script).

Name everything `wpshare`: the file, the zip folder, the `wpshare_` PHP prefix, the option, any
REST namespace and CSS classes. Never use an `mcw` prefix, even though older sister plugins do.

## Status

2026-10-28: scaffold only. No plugin code yet. The handoff session settles the open questions
below with the user, then builds 1.0.0.

## Background the user has already given

- WordPress core has generated the XML sitemap since 5.5, and it's live:
  - `https://library.wheatoncollege.edu/wp-sitemap.xml`; `/sitemap.xml` 301-redirects to it;
  - `robots.txt` names it on a `Sitemap:` line.
- On 2026-10-28 the core sitemap listed four post types plus users:
  - `page` (the real pages);
  - `foyer_display`, `foyer_channel`, `foyer_slide` (Foyer plugin lobby-screen signage, not meant
    to be landed on from a search);
  - the users sitemap (`wp-sitemap-users-1.xml`), which exposes staff login names.
- The same day, as a stopgap, two filters were added to the end of the theme's `functions.php`
  (`Documents\wheatonlibrary`):
  - `wheatonlibrary_sitemap_post_types` on `wp_sitemaps_post_types`, which drops the three foyer
    types;
  - `wheatonlibrary_sitemap_providers` on `wp_sitemaps_add_provider`, which drops `users`.
  Verified live: the index lists only `wp-sitemap-posts-page-1.xml`, and the users sitemap is 404.
  **When wpshare 1.0.0 is installed and does the same job, remove those two filters from the theme.**
  The theme live-syncs to production: lint the edited file with `php -l` before saving it there.
- The user decided on a plugin rather than an off-the-shelf one (Yoast, Rank Math): those bring a
  lot of SEO machinery the site would never use.
- For a human-readable sitemap page, core's **Page List** block already exists. Ask before
  building an HTML sitemap into the plugin.

## Open questions to settle first (do not assume answers)

1. **Scope.** Confirm wpshare is the sitemap plugin, and what "share" covers: only the XML
   sitemap, or also robots.txt, per-page "hide from search" (noindex), social-share previews
   (Open Graph), or an HTML sitemap shortcode?
2. **Settings or fixed rules?** A wp-admin screen where staff choose which post types and pages
   are listed, or rules in code? Prefer the simplest thing the user can explain to a successor.
3. **Per-page exclusions.** Are there pages that should stay out of the sitemap? Note the site has
   a `prt-edits` page the user has said to leave alone; ask whether it should be listed.
4. **Plugin display name** for the header and the wp-admin menu.

## Build and verify

- Plain PHP and vanilla JS, no dependencies, build step or minification. Copy the `wphours`
  conventions: nowdoc (`<<<'HTML'`) for markup and JS with no PHP variables, config passed to
  inline scripts via `wp_json_encode()`, ES5-style JS.
- Store settings in one option, with every write going through one sanitize function.
- Capability: `edit_pages`, same as the sister plugins, unless the user says otherwise.
- A portable PHP CLI is at `C:\Users\thoma\bin\php\php.exe`. Run `php -l` on every change:
  `C:\Users\thoma\bin\php\php.exe -l wpshare.php`
- Test with a stub harness that mocks the WordPress functions, as in `wpfloors\tests`: stubs in
  `tests/run.php`, cases in `tests/cases.php`, run with
  `C:\Users\thoma\bin\php\php.exe tests/run.php`. Include junk input.
- The core sitemap hooks the stopgap uses are `wp_sitemaps_post_types` and
  `wp_sitemaps_add_provider`; per-page exclusion would go through `wp_sitemaps_posts_query_args`.
- After the user installs it, check the live `/wp-sitemap.xml`, its sub-sitemaps and robots.txt
  with a cache-busting query (`?cb=<random>`): Cloudflare caches aggressively.
- The site must meet WCAG 2.1 AA, and that includes any admin screen.

## Release workflow (every commit is a release)

Commit and push after every change, and every code change ships as a SemVer release. Do this
without asking; only check in if the bump category (patch, minor, major) is ambiguous.

1. Bump `Version:` in the `wpshare.php` header and `WPSHARE_VERSION` to match. 1.0.0 is the first
   usable build; earlier work-in-progress builds are 0.x and released with `--prerelease`.
2. Add a dated line to `## Changelog` in `README.md`: `- **X.Y.Z** (YYYY-MM-DD) — what changed.`
3. Commit (concise, imperative mood) and push to `main`.
4. Run `bin/release.sh X.Y.Z "notes"` (add `--prerelease` for unfinished builds) from Git Bash.
   It checks the version and changelog, builds `build/wpshare-X.Y.Z.zip` with a top-level
   `wpshare/` folder, creates the GitHub release, and copies the zip to `C:\Users\thoma\Downloads`.
   It greps for exact strings, so keep these forms:
   - `define( 'WPSHARE_VERSION', 'X.Y.Z' );` (spacing included);
   - the changelog line starting `- **X.Y.Z**`;
   - a clean, pushed working tree.

   The zip holds only `wpshare.php`, `README.md`, `LICENSE` and `assets/` if it exists (`tests/`
   is left out). Any other file the plugin needs means editing `release.sh` too.
5. The user installs it at Plugins → Add New → Upload Plugin → Replace current.

## Site rules that apply here

- Edit this repo locally, then commit and push. Never edit it through the GitHub API.
- The theme folder `Documents\wheatonlibrary` live-syncs to production. Don't put anything for this
  plugin there (the one exception is removing the stopgap filters above).
- Never import `mysql.sql` anywhere. Never enter passwords; the user logs in.
- Keep designs simple and explainable. Do exactly what was asked, and raise any conflict instead of
  redesigning. Be concise with the user.
