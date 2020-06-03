<?php
require_once __DIR__ . '/../etc/config.php';
require_once __DIR__ . '/../lib/youtube.php';
require_once __DIR__ . '/../lib/database.php';
require_once __DIR__ . '/../lib/logger.php';

class Backend
{
    private $youtube;
    private $db;

    private function format_success_result($data)
    {
        return [
            'result' => 'success',
            'data' => $data,
        ];
    }

    public function __construct()
    {
        Logger::init('backend.php');
        $this->db = new Database();
        $this->youtube = new Youtube();
    }

    public function call(array $args)
    {
        try {
            if (count($args) == 0 || !$args[0]) {
                throw new Exception('no method specified', 400);
            }

            $method = array_shift($args);
            Logger::log(LOG_INFO, 'calling method', $method, $args);
            switch ($method) {
                case 'channel':
                    return $this->format_success_result($this->callChannel($args));
                    break;
                case 'channels':
                    return $this->format_success_result($this->callChannels($args));
                    break;
                case 'callback':
                    return $this->callCallback($args);
                    break;
                default:
                    throw new Exception('unknown method ' . $method, 404);
                    break;
            }
        } catch (Exception $e) {
            $result = [
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
                'args' => $args,
            ];
            Logger::log(LOG_ERR, 'got exception in call method:', $result, $e->getTrace());
            $result['result'] = 'error';
            return $result;
        }
    }

    public function callChannels(array $args)
    {
        if (empty($args)) {
            return $this->db->getChannels();
        }

        Logger::log(LOG_ERR, 'invalid channels args', $args);
        return [
            'result' => 'error',
            'message' => 'invalid args',
            'code' => 404,
            'args' => $args,
        ];
    }

    public function callCallback(array $args)
    {
        if (count($args) == 2 && $args[0] == 'youtube' && $args[1] == 'push') {
            if (empty($_GET)) {
                return $this->saveVideo();
            } else {
                return $this->verifySubscription($_GET);
            }
        }

        Logger::log(LOG_ERR, 'unknown callback', $args);
        return [
            'result' => 'error',
            'message' => 'unknown callback',
            'code' => 404,
            'args' => $args,
        ];
    }

    public function verifySubscription($args)
    {
        Logger::log(LOG_INFO, 'verifing subscription', $args);
        $response['raw'] = true;
        if ($args['hub_mode'] == 'subscribe') {
            $topic = parse_url($args['hub_topic']);
            if ($topic['host'] == 'www.youtube.com' && $topic['path'] = '/xml/feeds/videos.xml') {
                $topic_args = [];
                parse_str($topic['query'], $topic_args);
                $channel_id = $topic_args['channel_id'];
                $expiration = time() + intval($args['hub_lease_seconds']);
                $this->db->saveSubscriptionExpiration($channel_id, $expiration);
                Logger::log(LOG_INFO, 'approving subscription for channel', $channel_id, 'expired at', date(DATE_ISO8601, $expiration));
                $response['data'] = $args['hub_challenge'];
            } else {
                Logger::log(LOG_ERR, 'invalid topic', $topic);
                $response['code'] = 403;
                $response['data'] = 'invalid topic';
            }
        }
        return $response;
    }

    public function saveVideo()
    {
        $video_ids = [];
        $raw_data = file_get_contents('php://input');
        if (!$data = simplexml_load_string($raw_data)) {
            Logger::log(LOG_ERR, 'xml parsing failed', $raw_data);
            throw new Exception('xml parsing failed', 400);
        }
        foreach ($data->xpath('//yt:videoId') as $value) {
            $video_id = $value->__toString();
            Logger::log(LOG_INFO, 'saving video', $video_id);
            $video = $this->youtube->getVideo($video_id);
            $this->db->saveVideo($video);
            array_push($video_ids, $video_id);
        }
        if (empty($video_ids)) {
            Logger::log(LOG_ERR, 'no videos found', $data);
            throw new Exception('no videos found', 400);
        }
        foreach ($this->youtube->getVideosStats($video_ids) as $id => $stat) {
            $this->db->saveStatistics($id, $stat);
        }
        return $this->format_success_result($video_ids);
    }

    public function callChannel(array $args)
    {
        if (count($args) == 2 && $args[1] == 'video') {
            return $this->getChannelVideoList($args[0]);
        } else {
            throw new Exception('not implemented', 400);
        }
    }

    public function getChannelVideoList($channel_id)
    {
        return $this->db->getChannelVideoList($channel_id, true);
    }

    public function getChannelVideoListDirect($channel_id, $max = 5, $page = null)
    {
        $videos = $this->youtube->getChannelVideoList($channel_id, $max, $page);
        $stats = $this->youtube->getVideosStats(array_keys($videos['videos']));

        foreach ($stats as $id => $stat) {
            $videos['videos'][$id]['statistics'] = $stat;
        }
        return $videos;
    }
}
