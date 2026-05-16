---
title: Media
weight: 1
---

# jurager/media

Polymorphic file and media management for Laravel. Attach images, PDFs, and any other files to Eloquent models with S3 storage, automatic image conversions, EXIF stripping, and per-collection upload constraints.

**Requirements:** PHP ^8.4 · Laravel 11–13 · intervention/image ^3.0

## Contents

- [Installation](installation.md) — Composer, migrations, configuration, model override
- [Quickstart](quickstart.md) — Make your first model media-capable
- [Uploading](uploading.md) — Upload from request, URL, or base64; collection constraints
- [Collections](collections.md) — Named collections, single-file, MIME type and size restrictions
- [Conversions](conversions.md) — Define thumbnail sizes, formats, fit modes, and queue behaviour
- [Retrieving](retrieving.md) — Get files, URLs, CDN, presigned URLs, reordering
- [Advanced](advanced.md) — Events, deduplication, EXIF stripping, path generator, Artisan commands, EAV integration
