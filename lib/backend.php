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
            switch ($method) {
                case 'channel':
                    return $this->format_success_result($this->callChannel($args));
                    break;
                default:
                    throw new Exception('unknown method ' . $method, 400);
                    break;
            }
        } catch (Exception $e) {
            $result = [
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
                'args' => $args,
            ];
            Logger::log(LOG_ERR, 'got exception in call method:', $result);
            $result['result'] = 'error';
            return $result;
        }
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
