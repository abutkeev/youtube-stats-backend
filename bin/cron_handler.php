<?php
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/cron.php';

$log_option = 0;

if ($argc == 2) {
    switch ($argv[1]) {
        case 'debug':
            Logger::debug(true);
        case 'console':
            $log_option = LOG_PERROR;
            break;
        default:
    }
}

Logger::init(basename(__FILE__), $log_option);

$cron = new Cron();
$cron->run();
