<?php
require_once __DIR__ . '/../etc/config.php';
require_once __DIR__ . '/logger.php';

class Database
{
    const DATE_JS = 'Y-m-d\TH:i:s\Z';

    private $db;

    private function get_timestamps($id_name, $timestamp_name, $table_name)
    {
        $sth = $this->db->
            prepare("SELECT $id_name, UNIX_TIMESTAMP(MAX($timestamp_name)) AS $timestamp_name FROM $table_name GROUP BY $id_name FOR UPDATE");
        $sth->execute();
        $result = [];
        while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
            $result[$row[$id_name]] = intval($row[$timestamp_name]);
        }
        return $result;

    }

    private function select($table, array $rows, array $options = [])
    {
        $query = ['SELECT'];
        $types = [];
        if (array_key_exists('types', $options) && is_array($options['types'])) {
            $types = $options['types'];
        }

        $typed_rows = [];
        foreach ($rows as $row) {
            if (array_key_exists($row, $types)) {
                switch ($types[$row]) {
                    case 'time':
                        array_push($typed_rows, "UNIX_TIMESTAMP($row) AS $row");
                        continue 2;
                    default:
                        throw new Exception('unknown type ' . $types[$row]);
                }
            }
            array_push($typed_rows, $row);
        }
        $query[] = implode(', ', $typed_rows);
        $query[] = "FROM $table";

        $in_args = [];
        $where = [];
        if (array_key_exists('where', $options)) {
            foreach ($options['where'] as $left => $right) {
                array_push($where, "$left = :$left");
                $in_args[$left] = $right;
            }
        }

        if (!empty($where)) {
            $query[] = 'WHERE ' . implode(' AND ', $where);
        }

        if (array_key_exists('limit', $options)) {
            $query[] = 'LIMIT ' . intval($options['limit']);
        }

        $query_string = implode(' ', $query);
        Logger::log(LOG_INFO, 'SQL query:', $query_string, $in_args);

        $sth = $this->db->prepare($query_string);
        $sth->execute($in_args);
        return $sth;
    }

    public function __construct()
    {
        Logger::init('database.php');
        $dboptions = array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4');
        $this->db = new PDO('mysql:dbname=' . Config::DB_NAME . ';host=' . Config::DB_HOST, Config::DB_LOGIN, Config::DB_PASSWORD, $dboptions);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function begin()
    {
        $this->db->beginTransaction();
    }

    public function commit()
    {
        $this->db->commit();
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

            $this->db->prepare('INSERT INTO videos_history (id, channel_id, title, description, created) ' .
                'VALUES (:id, :channel_id, :title, :description, FROM_UNIXTIME(:created))')->execute($data);

            if (array_key_exists('thumbnails', $video)) {
                $this->saveTumbnails($video['id'], get_object_vars($video['thumbnails']));
            }
            $this->db->commit();
            if (array_key_exists('statistics', $video)) {
                $this->saveStatistics($video['id'], get_object_vars($video['statistics']));
            }

        } catch (PDOException $ex) {
            Logger::log(LOG_ERR, 'database exception', $ex->getMessage(), $video, array_keys($video));
            throw $ex;
        }
    }

    public function saveStatistics($owner_id, array $statistics)
    {
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
            $this->saveStatistics($channel['id'], $channel['statistics']);
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

    public function getStatisticsUpdateTimestamps()
    {
        return $this->get_timestamps('owner_id', 'modified', 'statistics');
    }

    public function getStatisticsHistory($id)
    {
        $sth = $this->select('statistics_history',
            ['counter', 'time', 'value'],
            [
                'where' => ['owner_id' => $id],
                'types' => ['time' => 'time'],
            ]);
        $result = [];
        while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
            $row['time'] = gmdate(self::DATE_JS, $row['time']);
            $row['value'] = intval($row['value']);
            array_push($result, $row);
        }
        return $result;
    }

    public function getChannels()
    {
        $sth = $this->db->prepare('SELECT id, title, url AS customUrl, description, UNIX_TIMESTAMP(created) AS created FROM channels');
        $sth->execute();
        $result = [];
        while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
            $id = $row['id'];
            $row['publistedAt'] = gmdate(self::DATE_JS, $row['created']);
            $row['thumbnails'] = $this->getThumbnails($id);
            $row['statistics'] = $this->getStatistics($id);
            $result[$id] = $row;
        }
        return $result;
    }

    public function getChannel($channel_id)
    {
        $sth = $this->db->prepare('SELECT id, title, url AS customUrl, description, UNIX_TIMESTAMP(created) AS created FROM channels ' .
            'WHERE id = :channel_id');
        $sth->execute(['channel_id' => $channel_id]);
        while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
            $id = $row['id'];
            if ($id == $channel_id) {
                $row['publishedAt'] = gmdate(self::DATE_JS, $row['created']);
                $row['thumbnails'] = $this->getThumbnails($id);
                $row['statistics'] = $this->getStatistics($id);
                return $row;
            }
        }
        return false;
    }

    public function getVideo($id)
    {
        $sth = $this->select('videos',
            ['id', 'channel_id AS channelId', 'title', 'description', 'created'],
            [
                'where' => ['id' => $id],
                'types' => ['created' => 'time'],
            ]
        );
        while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
            if ($row['id'] == $id) {
                $row['publishedAt'] = gmdate(self::DATE_JS, $row['created']);
                $row['thumbnails'] = $this->getThumbnails($id);
                $row['statistics'] = $this->getStatistics($id);
                return $row;
            }
        }
        return false;
    }

    public function getChannelVideoList($channel_id, $details = false)
    {
        $sth = $this->db->prepare('SELECT id, channel_id AS channelId, title, description, UNIX_TIMESTAMP(created) AS created ' .
            'FROM videos WHERE channel_id = :channel_id ORDER BY created DESC');
        $sth->execute(['channel_id' => $channel_id]);
        $videos = [];
        while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
            $id = $row['id'];
            $row['publishedAt'] = gmdate(self::DATE_JS, $row['created']);
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

    public function getObjectCreated($type)
    {
        switch ($type) {
            case 'channel':
                return $this->get_timestamps('id', 'created', 'channels');
            case 'video':
                return $this->get_timestamps('id', 'created', 'videos');
            default:
                throw new Exception('unknown object '+$type);
        }
    }

    public function getChannelsSubscriptionExpiration()
    {
        return $this->get_timestamps('channel_id', 'expiration', 'channel_subscription_expiration');
    }
}
