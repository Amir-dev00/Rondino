<?php
/**
 * Copy to local.php and fill in values.
 * local.php must stay outside the public web root and out of Git.
 */
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'rondino_simple',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    // Used only for one-time web setup on cPanel. Leave empty on local Windows after start.bat setup.
    'setup_token' => '',
    'app_name' => 'روندینو',
    'auto_refresh_minutes' => 30,
    'http_timeout' => 25,
    'http_user_agent' => 'RondinoSimple/1.0 (+private research team)',
];
