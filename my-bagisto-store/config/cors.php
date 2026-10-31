<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    /*
     * 预检结果缓存时间(秒)。0 = 浏览器每次跨域请求都重新发 OPTIONS,
     * H5 端每个带 Authorization 的 GET 都会多一次预检(本机 php -S 下约 0.6s)。
     * 86400 = 24 小时。小程序端不走 CORS,不受此项影响。
     */
    'max_age' => 86400,

    'supports_credentials' => false,

];
