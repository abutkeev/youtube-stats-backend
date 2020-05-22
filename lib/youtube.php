<?php
require_once __DIR__ . '/../etc/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

class Youtube
{
    private $client;

    public function __construct()
    {
        $client = new Google_Client();
        $client->setDeveloperKey(Config::YOUTUBE_KEY);

        $this->client = new Google_Service_YouTube($client);
    }

    public function getChannelVideoList($channel_id, $max = 50, $page = null)
    {
        $params = [
            'order' => 'date',
            'type' => 'video',
            'maxResults' => $max,
            'channelId' => $channel_id,
        ];

        if (!is_null($page)) {
            $params['pageToken'] = $page;
        }

        $response = $this->client->search->listSearch('snippet', $params);

        $result = [
            'next_page' => $response->nextPageToken,
            'videos' => array(),
        ];

        foreach ($response->items as $video) {
            $result['videos'][$video->id->videoId] = get_object_vars($video->snippet);
        }
        return $result;
    }

    public function getVideosStats(array $video_ids)
    {
        $params = [
            'id' => implode(',', $video_ids),
        ];

        $response = $this->client->videos->listVideos('statistics', $params);

        $result = array();
        foreach ($response->items as $video) {
            $stats = array_map('intval', get_object_vars($video->statistics));
            $result[$video->id] = $stats;
        }
        return $result;
    }
}
