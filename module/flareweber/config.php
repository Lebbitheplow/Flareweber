<?php

$config = array();
$config['name'] = "FlareWeber";
$config['author'] = "FlareWeber";
$config['ui_admin'] = true;
$config['ui'] = false;
$config['categories'] = "other";
$config['position'] = 500;
$config['version'] = '0.1.0';
$config['settings'] = [];

$config['settings']['autoload_namespace'] = [
    [
        'path' => __DIR__ . '/src/',
        'namespace' => 'FlareWeber\\',
    ],
];

$config['settings']['service_provider'] = [
    \FlareWeber\Providers\FlareWeberServiceProvider::class,
];
