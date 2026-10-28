# Sitemap (wpshare)

A WordPress plugin for sharing responsibility for a site.

- **Sitemap screen** (wp-admin → Sitemap): every page except those in the trash, drafts included,
  as a tree. Each page has one owner, and a **Mark reviewed** button records who checked it and when.
  Filter by All, My pages or No owner.
- **Reminders**: on dates you choose (MM-DD every year, or YYYY-MM-DD once), each owner gets one
  email listing their pages. Pages without an owner go to a fallback address, if one is set.
- **Notices**: other plugins (such as wpa11y) can email a page's owner about that page.
- **Public XML sitemap** (`/wp-sitemap.xml`): choose which post types it lists, and keep the users
  sitemap (staff login names) off. It only ever lists published content.
- Sitemap → Settings also shows the last 50 emails sent.

Everyone who can edit pages (`edit_pages`) can use both screens.

## Install

Download the zip from the latest [release](https://github.com/tfscharff/wpshare/releases), then in
wp-admin go to Plugins → Add New → Upload Plugin. To update, upload the new zip and choose
**Replace current**.

Reminders run from WP-Cron, hourly, so they go out on the first visit to the site on a reminder
date. If the site has no visits that day, they go out on the next visit.

## For other plugins: sending a notice

```php
do_action( 'wpshare_notify', $post_id, array(
	'source'  => 'wpa11y',                     // short slug, shown in the log
	'subject' => 'Accessibility issues found', // plain text; the site name is prefixed
	'message' => "Plain-text body.\nNew lines are kept.",
	'url'     => 'https://…',                  // optional link to the details
) );
```

- Use `do_action()`, so nothing breaks while this plugin is inactive.
- The email goes to the page's owner. A page with no owner, or any post that isn't a page, goes to
  the fallback address in Settings, or nowhere if that's empty.
- Every notice is sent, so the caller decides when a notice is worth sending (for example, only
  when new issues appear).
- `wpshare_owner( $post_id )` returns the owner's user ID, or 0. Check `function_exists()` first.

## Development

- Plugin tests: `php tests/run.php` (stub harness, no WordPress needed); `php -l wpshare.php`.
- Every commit to `main` is a release: bump `Version:` and `WPSHARE_VERSION`, add a changelog line,
  commit, push, then `bin/release.sh X.Y.Z "notes"` (add `--prerelease` for unfinished builds).

## Changelog

- **1.0.0** (2026-10-28) — First release: Sitemap screen with page owners and reviews, reminder emails on chosen dates, the `wpshare_notify` surface for other plugins, and public XML sitemap settings.
