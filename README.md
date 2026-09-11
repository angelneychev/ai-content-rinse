# AI Content Rinse

**Text & Metadata Cleaner for WordPress** by [Angel Neychev](https://angelneychev.eu).

Review copied text character by character, clean supported image metadata in place, and verify the saved files. Processing stays on the WordPress server; no external AI service or API key is used.

## Features

- Inspect post/page titles, content and excerpts with exact red/green change previews.
- Remove configured invisible Unicode characters and normalize em/en dashes to ASCII hyphens.
- Preserve HTML/Gutenberg markup, attributes, code and emoji joiners in structured content.
- Paste plain text or HTML, preview cleanup and copy the result without creating a post.
- Search records, filter findings on the current page and review selected items before batch changes.
- Clean PNG text and caBX C2PA, JPEG comments/XMP and WebP XMP in existing image files.
- Include existing image sizes, saved originals and edit backups, preserving other bytes.
- Reject stale image previews, stage and verify replacements, and scan saved files again.
- Recover post text when subsequent editorial changes would not be overwritten.

## Install

Requires WordPress 6.5+ and PHP 8.1+. Tested locally with WordPress 7.1 and PHP 8.3.

Build the installable archive with `php tools/package.php`, then upload `.build/ai-content-rinse-0.3.3.zip` through Plugins > Add New > Upload Plugin. Open Tools > AI Content Rinse after activation.

A GitHub source download is a development archive. The ZIP produced by the packaging script is the WordPress installation package.

## Scope and data

C2PA recognition currently covers PNG caBX blocks and OpenAI/GPT references inside them; signatures are not validated. Other C2PA containers, visible logos and pixel watermarks are outside this release. EXIF (including GPS), colour profiles and pixel data are retained. Findings are not an AI authorship verdict.

Image cleanup replaces existing files and has no recovery copy. Names, attachment IDs and URLs are retained. Files are processed individually and partial failures are reported. Each file must be local and up to 20 MB, with up to 100 known variants per attachment. Search results and batch selection use pages of 20 records.

Text recovery records are retained on uninstall. Page-builder data is not supported. No automatic cleaning on upload or post save is performed.

## Development

Planned additions are tracked in [ROADMAP.md](ROADMAP.md), one feature per future release.

The runtime files are at the repository root. No JavaScript compilation or vendor dependencies are required. GitHub Actions builds an allowlisted ZIP, checks PHP/JavaScript syntax and runs WordPress Plugin Check.

Integration tests require a disposable WordPress installation with this plugin active and an administrator account named `administrator`. Set `AICR_WP_ROOT` to that installation and run:

```sh
php tests/test-plugin.php
php tests/test-v030.php
```

The tests create and remove their own fixtures. They check text recovery, exact diffs, metadata removal, in-place replacement, stale previews, malformed data and unchanged non-target image bytes.

See [RELEASING.md](RELEASING.md) for the initial WordPress.org review and subsequent releases, and [UPSTREAM.md](UPSTREAM.md) for reference-project tracking.

## License and contact

Copyright 2026 Angel Neychev. GPL-2.0-or-later; see [LICENSE.txt](LICENSE.txt).

Contact: angel.neychev@gmail.com - https://angelneychev.eu
