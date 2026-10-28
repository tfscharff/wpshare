# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

**wpshare**, shown as **Sitemap**: a single-file WordPress plugin (`wpshare.php`) for sharing
responsibility for a site. Built for the Wallace Library site (library.wheatoncollege.edu, WP Engine
behind Cloudflare), usable by any site. It also controls the public XML sitemap, replacing a stopgap
in the theme (see below). Repo: https://github.com/tfscharff/wpshare (public, GPLv2, like the
sibling plugins).

The sibling plugins are `wphours`, `wprooms`, `wpmeet`, `wpfloors` and `wpa11y`, all in
`C:\Users\thoma\Documents\`. Copy their conventions, especially `wpfloors\CLAUDE.md` (single-file
plugin, stub test harness) and `wpa11y\bin\release.sh` (release script).

Name everything `wpshare`: the file, the zip folder, the `wpshare_` PHP prefix, the option, any
REST namespace and CSS classes. Never use an `mcw` prefix, even though older sister plugins do.

## Status

1.0.0 (2026-10-28), installed live the same day. The theme stopgap filters are removed, so
wpshare alone now shapes `/wp-sitemap.xml` (verified: only the page sitemap; users and foyer
sitemaps 404). wpa11y 1.5.0 calls `wpshare_notify` when its email setting is "each page's owner".

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
  Both were removed on 2026-10-28 once wpshare 1.0.0 was installed. Don't add sitemap code back
  to the theme.
- The user decided on a plugin rather than an off-the-shelf one (Yoast, Rank Math): those bring a
  lot of SEO machinery the site would never use.
- For a human-readable sitemap page, core's **Page List** block already exists. Ask before
  building an HTML sitemap into the plugin.

## Agreed design (settled with the user 2026-10-28)

- **Purpose:** tools for sharing responsibility for the site. The display name is **Sitemap**
  (the user dropped "Collaborate"). Code names stay `wpshare`.
- **Sitemap screen** (wp-admin → Sitemap, slug `wpshare`): every page except trash, **including
  drafts, pending, scheduled and private**, as a tree, for staff to work on. These must never
  reach the public XML sitemap. Server-rendered forms posting to `admin-post.php`, with no JS.
- **One owner per page** (user's choice; no inheritance from parents), in post meta
  `_wpshare_owner`. Owners are users with `edit_pages`. "Mark reviewed" stores
  `_wpshare_reviewed` (date) and `_wpshare_reviewed_by`.
- **Reminders:** the user sets the dates in Settings. `MM-DD` repeats yearly, `YYYY-MM-DD` happens
  once. 02-29 is refused. An hourly WP-Cron `wpshare_check` sends one email per owner, and catches
  up on missed days through `wpshare_state`. Unowned pages go to the fallback address, or nowhere.
- **wpa11y surface:** `do_action( 'wpshare_notify', $post_id, [source, subject, message, url] )`
  emails the page's owner, or the fallback. wpshare doesn't deduplicate; the caller decides when
  to send. Documented in README. Keep this contract stable: wpa11y depends on it.
- **Public XML sitemap:** settings for which public post types are listed (default: only `page`),
  and the users sitemap off. That matches the stopgap.
- Every page is listed. `prt-edits` isn't excluded.
- Options: `wpshare` (settings, written only through `wpshare_sanitize()`), `wpshare_state`, and
  `wpshare_log` (the last 50 emails, shown in Settings).
- Not built, so ask first: robots.txt, noindex, Open Graph, an HTML sitemap, per-page
  XML exclusions.

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
  plugin there.
- Never import `mysql.sql` anywhere. Never enter passwords; the user logs in.
- Keep designs simple and explainable. Do exactly what was asked, and raise any conflict instead of
  redesigning. Be concise with the user.
