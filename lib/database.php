<?php
require_once __DIR__ . '/../etc/config.php';
require_once __DIR__ . '/logger.php';

class Database
{
    private $db;

    public function __construct()
    {
        Logger::init('database.php');
        $dboptions = array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4');
        $this->db = new PDO('mysql:dbname=' . Config::DB_NAME . ';host=' . Config::DB_HOST, Config::DB_LOGIN, Config::DB_PASSWORD, $dboptions);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function saveVideo(array $video)
    {
        try {
            $data = [
                'id' => $video['id'],
                'channel_id' => $video['channelId'],
                'title' => $video['title'],
                'description' => $video['description'],
                'created' => date_timestamp_get(date_create($video['publishedAt'])),
            ];

            $this->db->beginTransaction();

            $this->db->prepare('REPLACE INTO videos (id, channel_id, title, description, created) ' .
                'VALUES (:id, :channel_id, :title, :description, FROM_UNIXTIME(:created))')->execute($data);

            if (array_key_exists('thumbnails', $video)) {
                $this->saveTumbnails($video['id'], get_object_vars($video['thumbnails']));
            }
            $this->db->commit();
            if (array_key_exists('statistics', $video)) {
                $this->saveStatistics($video['id'], get_object_vars($video['statistics']));
            }

        } catch (PDOException $ex) {
            Logger::log(LOG_ERR, 'database excaption', $ex->getMessage(), $video, array_keys($video));
            throw $ex;
        }
    }

    public function saveStatistics($owner_id, array $statistics)
    {
        $this->db->beginTransaction();
        $sth = $this->db->prepare('REPLACE INTO statistics (owner_id, counter, value) VALUES (:owner_id, :counter, :value)');
        $sth_history = $this->db->prepare('INSERT INTO statistics_history (owner_id, counter, value) ' .
            'VALUES (:owner_id, :counter, :value)');
        foreach ($statistics as $counter => $value) {
            $data = [
                'owner_id' => $owner_id,
                'counter' => $counter,
                'value' => intval($value),
            ];
            $sth->execute($data);
            $sth_history->execute($data);
        }
        $this->db->commit();
    }

    public function saveChannel(array $channel)
    {
        $data = [
            'id' => $channel['id'],
            'title' => $channel['title'],
            'url' => $channel['customUrl'],
            'description' => $channel['description'],
            'created' => date_timestamp_get(date_create($channel['publishedAt'])),
        ];
        $this->db->beginTransaction();
        $this->db->prepare('REPLACE INTO channels (id, title, url, description, created) ' .
            'VALUES (:id, :title, :url, :description, FROM_UNIXTIME(:created))')->execute($data);
        if (array_key_exists('thumbnails', $channel)) {
            $this->saveTumbnails($channel['id'], get_object_vars($channel['thumbnails']));
        }
        $this->db->commit();
        if (array_key_exists('statistics', $channel)) {
            $this->saveStatistics($channel['id'], get_object_vars($channel['statistics']));
        }
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

    public function getStatistics($id)
    {
        $sth = $this->db->prepare('SELECT counter, value FROM statistics WHERE owner_id = :owner_id');
        $sth->execute(['owner_id' => $id]);
        $result = [];
        while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
            $result[$row['counter']] = intval($row['value']);
        }
        return $result;
    }

    public function getChannelVideoList($channel_id, $details = false)
    {
        $sth = $this->db->prepare('SELECT id, channel_id AS channelId, title, description, UNIX_TIMESTAMP(created) AS created ' .
            'FROM videos WHERE channel_id = :channel_id ORDER BY created DESC');
        $sth->execute(['channel_id' => $channel_id]);
        $videos = [];
        while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
            $id = $row['id'];
            $row['publistedAt'] = gmdate(DATE_ISO8601, $row['created']);
            $videos[$id] = $row;
            if ($details) {
                $videos[$id]['thumbnails'] = $this->getThumbnails($id);
                $videos[$id]['statistics'] = $this->getStatistics($id);
            }
        }
        return [
            'videos' => $videos,
        ];
    }

    public function getVideosStatsLastTime()
    {
        $sth = $this->db->prepare('SELECT owner_id, unix_timestamp(max(time)) AS last_timestamp FROM statistics_history GROUP BY owner_id');
        $sth->execute();

        $result = [];
        while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
            $result[$row['owner_id']] = $row['last_timestamp'];
        }
        return $result;
    }
}
