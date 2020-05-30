<?php

require_once __DIR__ . '/../etc/config.php';
require_once __DIR__ . '/../lib/backend.php';

$backend = new Backend();

header('Access-Control-Allow-Origin: *');

$path = explode('?', preg_replace('#^.+/backend/#', '', $_SERVER['REQUEST_URI']))[0];
$args = explode('/', $path);

$response = $backend->call($args);
if (array_key_exists('raw', $response) && $response['raw']) {
    if (array_key_exists('code', $response)) {
        http_response_code($response['code']);
    }
    print $response['data'];
} else {
    print json_encode($response);
}
