<?php

require_once __DIR__ . '/../etc/config.php';
require_once __DIR__ . '/../lib/backend.php';

$backend = new Backend();

header('Access-Control-Allow-Origin: *');

print json_encode(
    $backend->call(
        explode('/', preg_replace('#^.+/backend/#', '', $_SERVER['REQUEST_URI']))
    )
);
