<?php
require_once __DIR__ . '/../etc/config.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/../vendor/autoload.php';

class Youtube
{
    private $client;

    public function __construct()
    {
        $client = new Google_Client();
        $client->setDeveloperKey(Config::YOUTUBE_KEY);

        $this->client = new Google_Service_YouTube($client);
        Logger::init('youtube.php');
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
        do {
            $ids = array_splice($video_ids, 0, 50);
            try {
                $params = [
                    'id' => implode(',', $ids),
                ];

                $response = $this->client->videos->listVideos('statistics', $params);

                $result = array();
                foreach ($response->items as $video) {
                    $stats = array_map('intval', get_object_vars($video->statistics));
                    $result[$video->id] = $stats;
                }
                return $result;
            } catch (Exception $ex) {
                Logger::log(LOG_ERR, 'getVideosStats failed', $ex->getMessage(), $video_ids);
                return [];
            }
        } while (!empty($video_ids));
    }

    public function getObject($id, $type, $part = 'snippet')
    {
        $params['id'] = $id;

        switch ($type) {
            case 'channel':
                $response = $this->client->channels->listChannels($part, $params);
                break;
            case 'video':
                $response = $this->client->videos->listVideos($part, $params);
                break;
            default:
                throw new Exception('unknown object type ' + $type);
                break;
        }

        if (count($response['items']) != 1) {
            Logger::log(LOG_ERR, 'wrong items count', $id, $type, $part, $response);
            throw new Exception('wrong items count');
        }

        $object = $response['items'][0];

        $result['id'] = $id;

        if (property_exists($object, 'snippet')) {
            $result = array_merge(['id' => $object['id']], get_object_vars($object['snippet']));
        }

        if (property_exists($object, 'statistics')) {
            $result['statistics'] = get_object_vars($object['statistics']);
        }

        return $result;
    }

    public function channelPushSubscribe($channel_id, $callback_url)
    {
        $s = new Pubsubhubbub\Subscriber\Subscriber('https://pubsubhubbub.appspot.com/subscribe', $callback_url);
        return $s->subscribe('https://www.youtube.com/xml/feeds/videos.xml?channel_id=' . $channel_id);
    }

    public function channelPushUnsubscribe($channel_id, $callback_url)
    {
        $s = new Pubsubhubbub\Subscriber\Subscriber('https://pubsubhubbub.appspot.com/subscribe', $callback_url);
        return $s->unsubscribe('https://www.youtube.com/xml/feeds/videos.xml?channel_id=' . $channel_id);
    }

    public function getVideo($id)
    {
        $params = [
            'id' => $id,
        ];
        $response = $this->client->videos->listVideos('snippet', $params);
        if (count($response->items) != 1) {
            Logger::log(LOG_ERR, 'invalid items count', $response);
            return [];
        }
        return array_merge(['id' => $id], get_object_vars($response->items[0]->snippet));
    }
}
