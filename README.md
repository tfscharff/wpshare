# wpshare

A WordPress plugin that controls what a site shares with search engines through its sitemap.
Built for the Wallace Library site (library.wheatoncollege.edu), usable by any site.

## Status

Not written yet. See `CLAUDE.md` for the agreed background and the open questions.

## Install

Download the zip from the latest [release](https://github.com/tfscharff/wpshare/releases), then in
wp-admin go to Plugins → Add New → Upload Plugin. To update, upload the new zip and choose
**Replace current**.

## Development

- Plugin tests: `php tests/run.php` (stub harness, no WordPress needed); `php -l wpshare.php`.
- Every commit to `main` is a release: bump `Version:` and `WPSHARE_VERSION`, add a changelog line,
  commit, push, then `bin/release.sh X.Y.Z "notes"` (add `--prerelease` for unfinished builds).

## Changelog
