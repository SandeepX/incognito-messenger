<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
         * MinIO is the primary disk for every user upload (avatars, message
         * attachments). It speaks the S3 API, so Laravel's s3 driver works
         * unchanged. The bucket is private: files are only reachable through
         * short-lived signed URLs generated after a backend authorization check.
         */
        'minio' => [
            'driver' => 's3',
            'key' => env('MINIO_ACCESS_KEY', 'minioadmin'),
            'secret' => env('MINIO_SECRET_KEY', 'minioadmin'),
            'region' => env('MINIO_REGION', 'us-east-1'),
            'bucket' => env('MINIO_BUCKET', 'incognito'),
            'endpoint' => env('MINIO_ENDPOINT', 'http://minio:9000'),
            // MinIO always uses path-style URLs (bucket as the first path segment).
            'use_path_style_endpoint' => true,
            'url' => env('MINIO_URL'),
            'visibility' => 'private',
            'throw' => true,
            'report' => true,
        ],

        /*
         * Same bucket, but signed with the browser-visible endpoint. Laravel's
         * S3 temporaryUrl() signs against the disk's endpoint host; the normal
         * `minio` disk must use the internal Docker hostname for uploads, so
         * URL generation uses this sibling disk instead.
         */
        'minio-public' => [
            'driver' => 's3',
            'key' => env('MINIO_ACCESS_KEY', 'minioadmin'),
            'secret' => env('MINIO_SECRET_KEY', 'minioadmin'),
            'region' => env('MINIO_REGION', 'us-east-1'),
            'bucket' => env('MINIO_BUCKET', 'incognito'),
            'endpoint' => env('MINIO_URL', env('MINIO_ENDPOINT', 'http://minio:9000')),
            'use_path_style_endpoint' => true,
            'visibility' => 'private',
            'throw' => true,
            'report' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
