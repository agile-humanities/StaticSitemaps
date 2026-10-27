# Changelog

All notable changes to Static Sitemaps are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/).

## [1.0.0] — 2026-10-27

First public release.

### Added

- **Static XML sitemaps written to disk**, served by the web server with no PHP or database in
  the request path. The entry point is always `sitemap.xml`, as a sitemap index or a single
  `<urlset>`; a collection that outgrows one file is split into an index automatically.
- **Per-site content selection:** pages, items, item sets (off by default) and Advanced Search
  pages (when that module is installed), each showing the number of URLs it adds. Media can be
  published as `<image:image>` and `<video:video>` extensions on their item's entry.
- **Privacy:** private sites, pages, items, item sets and media are never published. Items are
  checked twice (in the query and again before each entry is written), and the run report
  counts the private items withheld.
- **Three layouts:** document root, document root with children in `/sitemaps/`, or Omeka's
  files directory for read-only document roots, with a writability check on the settings page.
- **Atomic generation** as a background job: files are staged and moved into place, the index
  last, so a failed run leaves the previous sitemap intact. A command-line script for cron; the
  job can also be run through Easy Admin's generic task runner.
- **Excluded paths:** prefix rules, with a per-rule count of the URLs each one removed.
- **robots.txt support:** the exact `Sitemap:` line to copy, an optional idempotent append, and
  an audit that reports a missing or wrong declaration and any published URL that robots.txt blocks.
- **Edge caching guidance** for nginx, Apache, Cloudflare and CloudFront in the README.

### Notes

- Requires Omeka S 4. No other module is required.
- Licensed under CeCILL v2.1.

[1.0.0]: https://github.com/agile-humanities/StaticSitemaps/releases/tag/1.0.0
