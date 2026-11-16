<?php
// SAMPLE. Copy this folder to config/ beside the web root (../config/ from here, never inside it)
// and edit the copy. File paths below are relative to the web root, whatever the host calls it.
// Storefront settings. Nothing secret lives here: the storefront only talks to the API.

return [
    'api' => [
        'driver'       => 'http',                     // the backend API (the demo branch uses "mock")
        'base_url'     => 'http://localhost:8082',    // where the API runs
        'store_domain' => 'vitafoamplace.ng',         // sent as X-Store-Domain: which store this storefront is
        'timeout'      => 10,
    ],

    'storefront' => [
        'url'           => 'http://localhost:8080',   // this site's own address (canonical links, payment callbacks)
        'cookie_prefix' => 'rw_',
    ],
];
