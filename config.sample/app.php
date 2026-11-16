<?php
// SAMPLE. Copy this folder to config/ beside the web root (../config/ from here, never inside it)
// and edit the copy. File paths below are relative to the web root, whatever the host calls it.
// Storefront settings, demo: runs on mock data, no API needed.

return [
    'api' => [
        'driver'       => 'mock',                     // "mock" = mock/MockApi.php; "http" = the API at base_url (main)
        'base_url'     => 'http://localhost:8082',
        'store_domain' => 'vitafoamplace.ng',
        'timeout'      => 10,
    ],

    'mock' => [
        'seed'          => 'mock/data.json',          // committed demo data
        // Demo writes land here; delete the file to reset. Running the CMS demo too? Point both at the
        // same file (an absolute path, or VITAFOAM_MOCK_STATE) so changes made in the CMS show here.
        'state'         => getenv('VITAFOAM_MOCK_STATE') ?: 'mock/runtime/db.json',
        'demo_password' => 'demo1234',               // every demo customer and staff account
        'uploads_dir'   => 'assets/uploads',
        'uploads_url'   => 'http://localhost:8080/assets/uploads',
    ],

    'storefront' => [
        'url'           => 'http://localhost:8080',   // this site's own address (canonical links, payment callbacks)
        'cookie_prefix' => 'rw_',
    ],
];
