<?php
// SAMPLE. Copy this folder to config/ beside the web root (../config/ from here, never inside it)
// and edit the copy. File paths below are relative to the web root, whatever the host calls it.
// Storefront settings. Nothing secret lives here: the storefront only talks to the API.

return [
    'api' => [
        'driver'       => 'http',                     // the backend API
        'base_url'     => 'http://localhost:8082',    // where the API runs
        'client_key'   => '',                         // this app's key: clients.storefront in the API's config/app.php
        'timeout'      => 10,
        'cache_ttl'    => 60,                         // seconds to keep store info, categories, brands, banners, guide pages (0 = off)
    ],

    'storefront' => [
        'cookie_prefix' => 'rw_',
    ],
];
