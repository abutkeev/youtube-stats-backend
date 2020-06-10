<?php
require_once __DIR__ . '/../etc/config.php';
require_once __DIR__ . '/../lib/youtube.php';
require_once __DIR__ . '/../lib/database.php';
require_once __DIR__ . '/../lib/logger.php';

class Cron
{
    private $youtube;
    private $db;

    public function __construct()
    {
        Logger::init(basename(__FILE__));
        $this->db = new Database();
        $this->youtube = new Youtube();
    }

    public function shouldSaveVideoStatistics($id, $created, $last_updated)
    {
        $age = time() - $created;
        $update_age = round((time() - $last_updated) / 60);

        Logger::log(LOG_DEBUG, 'checking video', ['id' => $id, 'age' => $age, 'update_age' => $update_age,
            'created' => date(DATE_W3C, $created), 'last_updated' => date(DATE_W3C, $last_updated)]);

        if ($age > 86400 * 14 && $update_age < 60 * 24) {
            Logger::log(LOG_DEBUG, 'video', $id, 'published more then 2 weeks ago, saving stats each day');
            return false;
        }

        if ($age > 86400 * 7 && $update_age < 60) {
            Logger::log(LOG_DEBUG, 'video', $id, 'published more then week ago, saving stats each hour');
            return false;
        }

        if ($age > 86400 && $update_age < 15) {
            Logger::log(LOG_DEBUG, 'video', $id, 'published more then day ago, saving stats each 15 mins');
            return false;
        }

        if ($update_age < 5) {
            Logger::log(LOG_DEBUG, 'video', $id, 'published less then day ago, saving stats each 5 mins');
            return false;
        }

        return true;
    }

    public function shouldSaveChannelStatistics($id, $last_updated)
    {
        $update_age = round((time() - $last_updated) / 60);
        if ($update_age < 60) {
            Logger::log(LOG_DEBUG, 'channel', $id, 'updated less then hour ago, skipping');
            return false;
        }

        return true;
    }

    public function shouldSaveStatistics($id, $type, $created, $last_updated)
    {
        switch ($type) {
            case 'channel':
                return $this->shouldSaveChannelStatistics($id, $last_updated);
            case 'video':
                return $this->shouldSaveVideoStatistics($id, $created, $last_updated);
            default:
                return true;
        }
    }

    public function saveStatistics($type)
    {
        $this->db->begin();
        try {
            $last_updated = $this->db->getStatisticsUpdateTimestamps();
            foreach ($this->db->getObjectCreated($type) as $id => $created) {
                if ($this->shouldSaveStatistics($id, $type, $created, $last_updated[$id])) {
                    try {
                        Logger::log(LOG_INFO, 'saving statistics for', $type, $id);
                        $channel = $this->youtube->getObject($id, $type, 'statistics');
                        $this->db->saveStatistics($id, $channel['statistics']);
                    } catch (Exception $ex) {
                        Logger::log(LOG_ERR, 'got exception in saveStatistics', $type, $ex->getMessage());
                    }
                }
            }
        } catch (Exception $ex) {
            Logger::log(LOG_ERR, 'got exception in saveStatistics', $type, $ex->getMessage());
        }
        $this->db->commit();
    }

    public function updateChannelsSubscriptions()
    {
        $this->db->begin();
        try {
            foreach ($this->db->getChannelsSubscriptionExpiration() as $channel_id => $expiration) {
                if ($expiration - time() < 86400) {
                    Logger::log(LOG_INFO, 'updating subscription for', $channel_id);
                    $this->youtube->channelPushSubscribe($channel_id, Config::YOUTUBE_PUSH_CALLBACK);
                }
            }
        } catch (Exception $ex) {
            Logger::log(LOG_ERR, 'got exception in', __FUNCTION__, $ex->getMessage());
        }
        $this->db->commit();
    }

    public function run()
    {
        $this->saveStatistics('channel');
        $this->saveStatistics('video');
        $this->updateChannelsSubscriptions();
    }

}
