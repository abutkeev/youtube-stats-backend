<?php
require_once __DIR__ . '/../lib/youtube.php';
require_once __DIR__ . '/../lib/database.php';
require_once __DIR__ . '/../lib/logger.php';

$log_option = 0;

if ($argc < 2) {
    fwrite(STDERR, 'Usage: php ' . $argv[0] . " <channel> [debug]\n");
    exit(255);
}

if ($argc == 3 && $argv[2] == 'debug') {
    $log_option = LOG_PERROR;
    Logger::debug(true);
}

Logger::init('save_channel.php', $log_option);

$db = new Database();
$youtube = new Youtube();

$channel_id = $argv[1];

$channel = $youtube->getObject($channel_id, 'channel', 'snippet,statistics');
Logger::log(LOG_DEBUG, 'channel:', $channel);
$db->saveChannel($channel);

// $result = $youtube->channelPushSubscribe($channel_id, Config::YOUTUBE_PUSH_CALLBACK);
// Logger::log(LOG_DEBUG, 'subscribe to', $channel_id, $result);
