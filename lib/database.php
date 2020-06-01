<?php
require_once __DIR__ . '/../etc/config.php';

class Database
{
    private $db;

    public function __construct()
    {
        $dboptions = array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4');
        $this->db = new PDO('mysql:dbname=' . Config::DB_NAME . ';host=' . Config::DB_HOST, Config::DB_LOGIN, Config::DB_PASSWORD, $dboptions);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function saveVideo(array $video)
    {
        try {
            $thumbnails = [];
            if (array_key_exists('thumbnails', $video)) {
                $thumbnails = $video['thumbnails'];
                unset($video['thumbnails']);
            }
            unset($video['liveBroadcastContent']);
            unset($video['channelTitle']);
            unset($video['tags']);
            unset($video['localized']);
            unset($video['categoryId']);
            unset($video['defaultAudioLanguage']);
            unset($video['defaultLanguage']);

            $video['publishedAt'] = date_timestamp_get(date_create($video['publishedAt']));

            $this->db->beginTransaction();

            $sth = $this->db->prepare('REPLACE INTO videos (id, channel_id, title, description, published_at) ' .
                'VALUES (:id, :channelId, :title, :description, FROM_UNIXTIME(:publishedAt))');
            $sth->execute($video);

            $this->saveTumbnails($video['id'], get_object_vars($thumbnails));
            $this->db->commit();
        } catch (PDOException $ex) {
            Logger::log(LOG_ERR, 'database excaption', $ex->getMessage(), $video, array_keys($video));
            throw $ex;
        }
    }

    public function saveVideoStatistics($video_id, array $statistics)
    {
        $this->db->beginTransaction();
        $sth = $this->db->prepare('REPLACE INTO video_statistics (video_id, counter, value) VALUES (:video_id, :counter, :value)');
        $sth_history = $this->db->prepare('INSERT INTO video_statistics_history (video_id, counter, value) ' .
            'VALUES (:video_id, :counter, :value)');
        foreach ($statistics as $counter => $value) {
            $data = [
                'video_id' => $video_id,
                'counter' => $counter,
                'value' => $value,
            ];
            $sth->execute($data);
            $sth_history->execute($data);
        }
        $this->db->commit();
    }

    public function saveTumbnails($owner_id, array $thumbnails)
    {
        $sth = $this->db->prepare('REPLACE INTO thumbnails (owner_id, quolity, url, width, height) ' .
            'VALUES (:owner_id, :quolity, :url, :width, :height)');
        foreach ($thumbnails as $quolity => $data) {
            $data = get_object_vars($data);
            $data['owner_id'] = $owner_id;
            $data['quolity'] = $quolity;
            $sth->execute($data);
        }

    }

    public function saveSubscriptionExpiration($channel_id, $expiration)
    {
        $this->db->prepare('REPLACE INTO channel_subscription_expiration (channel_id, expiration) ' .
            'VALUES (:channel_id, FROM_UNIXTIME(:expiration))')->execute(['channel_id' => $channel_id, 'expiration' => $expiration]);
    }

    public function getThumbnails($id)
    {
        $sth = $this->db->prepare('SELECT quolity, url, width, height FROM thumbnails WHERE owner_id = :owner_id');
        $sth->execute(['owner_id' => $id]);
        $result = [];
        while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
            $result[$row['quolity']] = [
                'url' => $row['url'],
                'width' => intval($row['width']),
                'height' => intval($row['height']),
            ];
        }
        return $result;
    }

    public function getVideosStatistics($id)
    {
        $sth = $this->db->prepare('SELECT counter, value FROM video_statistics WHERE video_id = :video_id');
        $sth->execute(['video_id' => $id]);
        $result = [];
        while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
            $result[$row['counter']] = intval($row['value']);
        }
        return $result;
    }

    public function getChannelVideoList($channel_id, $details = false)
    {
        $sth = $this->db->prepare('SELECT id, channel_id AS channelId, title, description, UNIX_TIMESTAMP(published_at) AS published_at_timestamp ' .
            'FROM videos WHERE channel_id = :channel_id ORDER BY published_at DESC');
        $sth->execute(['channel_id' => $channel_id]);
        $videos = [];
        while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
            $id = $row['id'];
            $row['publistedAt'] = gmdate(DATE_ISO8601, $row['published_at_timestamp']);
            $videos[$id] = $row;
            if ($details) {
                $videos[$id]['thumbnails'] = $this->getThumbnails($id);
                $videos[$id]['statistics'] = $this->getVideosStatistics($id);
            }
        }
        return [
            'videos' => $videos,
        ];
    }

    public function getVideosStatsLastTime()
    {
        $sth = $this->db->prepare('SELECT video_id, unix_timestamp(max(time)) AS last_timestamp FROM video_statistics_history GROUP BY video_id');
        $sth->execute();

        $result = [];
        while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
            $result[$row['video_id']] = $row['last_timestamp'];
        }
        return $result;
    }
}
