<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Storage Disk
    |--------------------------------------------------------------------------
    */
    'disk' => env('MEDIA_DISK', 's3'),

    /*
    |--------------------------------------------------------------------------
    | Conversions Disk
    |--------------------------------------------------------------------------
    | Null means same disk as originals.
    */
    'conversions_disk' => env('MEDIA_CONVERSIONS_DISK', null),

    /*
    |--------------------------------------------------------------------------
    | CDN URL
    |--------------------------------------------------------------------------
    | When set, all public URLs will use this base instead of the S3 URL.
    | Example: https://cdn.example.com or https://d1234.cloudfront.net
    */
    'cdn_url' => env('MEDIA_CDN_URL', null),

    /*
    |--------------------------------------------------------------------------
    | Queue Name
    |--------------------------------------------------------------------------
    */
    'queue' => env('MEDIA_QUEUE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Image Driver
    |--------------------------------------------------------------------------
    | Options: 'gd' or 'imagick'.
    */
    'image_driver' => env('MEDIA_IMAGE_DRIVER', 'gd'),

    /*
    |--------------------------------------------------------------------------
    | EXIF Stripping
    |--------------------------------------------------------------------------
    | Strip EXIF metadata (GPS, camera info) from uploaded images.
    | Re-encodes the original before uploading — adds slight overhead.
    */
    'strip_exif' => env('MEDIA_STRIP_EXIF', true),

    /*
    |--------------------------------------------------------------------------
    | Deduplication
    |--------------------------------------------------------------------------
    | When enabled, uploading the same file (same MD5 hash) to the same
    | model+collection returns the existing Media record without re-uploading.
    | Useful for idempotent imports.
    */
    'deduplication' => env('MEDIA_DEDUPLICATION', true),

    /*
    |--------------------------------------------------------------------------
    | URL Download Timeout
    |--------------------------------------------------------------------------
    | Timeout in seconds when downloading files from external URLs.
    */
    'download_timeout' => env('MEDIA_DOWNLOAD_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Allowed Domains for URL Downloads
    |--------------------------------------------------------------------------
    | Restricts addMediaFromUrl() to specific hostnames to prevent SSRF attacks.
    | Use ['*'] to allow all domains (not recommended for user-facing endpoints).
    | Example: ['cdn.example.com', 's3.amazonaws.com', 'storage.googleapis.com']
    */
    'allowed_domains' => env('MEDIA_ALLOWED_DOMAINS')
        ? explode(',', env('MEDIA_ALLOWED_DOMAINS'))
        : [],

    /*
    |--------------------------------------------------------------------------
    | Model Classes
    |--------------------------------------------------------------------------
    */
    'models' => [
        'media' => \Jurager\Media\Models\Media::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Path Generator
    |--------------------------------------------------------------------------
    */
    'path_generator' => \Jurager\Media\Support\PathGenerator::class,

];
