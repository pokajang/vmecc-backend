<?php

return [
    'run_id' => env('E2E_RUN_ID'),
    'run_root' => env('E2E_RUN_ROOT'),
    'lock_root' => env('E2E_LOCK_ROOT'),
    'download_root' => env('E2E_DOWNLOAD_ROOT'),
    'minimum_free_bytes' => (int) env('E2E_MINIMUM_FREE_BYTES', 1073741824),
    'apache' => [
        'root' => env('E2E_APACHE_ROOT'),
        'php_module' => env('E2E_APACHE_PHP_MODULE'),
        'php_ini_directory' => env('E2E_PHP_INI_DIRECTORY'),
    ],
    'database' => [
        'name' => env('E2E_ALLOWED_DATABASE'),
        'host' => env('E2E_ALLOWED_DB_HOST'),
        'username' => env('E2E_ALLOWED_DB_USERNAME'),
    ],
];
