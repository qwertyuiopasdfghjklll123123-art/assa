<?php
/**
 * Copy this file to config.php and fill in your MySQL credentials,
 * or simply run install.php which creates config.php for you.
 */

return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'tokmart',
        'user'    => 'tokmart_user',
        'pass'    => 'change_me',
        'charset' => 'utf8mb4',
    ],
    // The URL path this app is deployed under: '' if it lives at your domain
    // root (https://example.com/), or '/tokmart' if it lives in a subfolder
    // (https://example.com/tokmart/). Get this right or every CSS/JS/image
    // link on the site will 404. install.php fills this in automatically.
    'base_path' => '',
    // Random secret used to key the session/CSRF tokens. install.php generates
    // a unique value automatically; change it if you copy this file by hand.
    'app_secret' => 'change_this_to_a_random_string',
];
