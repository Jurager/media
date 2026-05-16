# jurager/media

[![Latest Stable Version](https://poser.pugx.org/jurager/media/v/stable)](https://packagist.org/packages/jurager/media)
[![Total Downloads](https://poser.pugx.org/jurager/media/downloads)](https://packagist.org/packages/jurager/media)
[![PHP Version Require](https://poser.pugx.org/jurager/media/require/php)](https://packagist.org/packages/jurager/media)
[![License](https://poser.pugx.org/jurager/media/license)](https://packagist.org/packages/jurager/media)

Polymorphic file and media management for Laravel. Attach images, PDFs, and any other files to any Eloquent model with S3 storage, automatic image conversions (thumbnails, WebP), EXIF stripping, and per-collection constraints.

- Works with any Laravel disk — local, S3, or custom
- Automatic image resizing and format conversion via [Intervention Image](https://image.intervention.io/)
- Async conversion queue with sync fallback
- Download files from remote URLs or base64 strings
- MD5 deduplication for idempotent imports
- CDN and presigned URL support
- No dependency on `jurager/eav` — works standalone

## Requirements

`PHP >= 8.4` and `Laravel 11.x or higher`

## Installation

To install, configure and learn how to use the package, see the [Documentation](docs/_index.md).

## License

Open source, licensed under the [MIT license](LICENSE).
