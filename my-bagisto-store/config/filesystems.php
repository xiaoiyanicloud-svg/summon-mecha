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

    'default' => env('FILESYSTEM_DISK', 'public'),

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
            'root'   => storage_path('app'),
            'serve'  => true,
            'throw'  => false,
        ],

        'private' => [
            'driver' => 'local',
            'root'   => storage_path('app/private'),
            'serve'  => true,
            'throw'  => false,
        ],

        'public' => [
            'driver'     => 'local',
            'root'       => storage_path('app/public'),
            'url'        => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw'      => false,
        ],

        's3' => [
            'driver'                  => 's3',
            'key'                     => env('AWS_ACCESS_KEY_ID'),
            'secret'                  => env('AWS_SECRET_ACCESS_KEY'),
            'region'                  => env('AWS_DEFAULT_REGION'),
            'bucket'                  => env('AWS_BUCKET'),
            'url'                     => env('AWS_URL'),
            'endpoint'                => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw'                   => false,
        ],

        /*
        | 腾讯云 COS（技能包对象存储）—— v3.4 步骤 3
        |
        | ⚠ 为什么是 COS 而不是上方的 s3：S3 驱动（league/flysystem-aws-s3-v3）依赖
        |   aws/aws-sdk-php，而它被本项目的 composer 安全策略**全部拦下**（多个 advisory）。
        |   项目对这类拦截的一贯态度是"不绕过"。COS 驱动的依赖链干净，且与本项目既有的
        |   腾讯云栈（TencentCloudSigner / ASR / TTS）一致。
        |
        | 驱动 'cos' 由 AppServiceProvider 注册（overtrue/flysystem-cos 是纯适配器，
        | 不自带 Laravel 驱动注册）。
        |
        | ⚠ 技能包走这个盘时，它必须是**私读**的：不要开 bucket 公有读，
        |   下载一律经 SkillController 的鉴权流式接口。
        */
        'cos' => [
            'driver'     => 'cos',
            'app_id'     => env('COS_APP_ID'),
            'secret_id'  => env('COS_SECRET_ID'),
            'secret_key' => env('COS_SECRET_KEY'),
            'region'     => env('COS_REGION', 'ap-guangzhou'),
            'bucket'     => env('COS_BUCKET'),
            'prefix'     => env('COS_PREFIX', ''),
            'use_https'  => env('COS_USE_HTTPS', true),
            'signed_url' => false,
            'throw'      => false,
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
