<?php

namespace AMinin\Delayed\Backend;

use AMinin\Delayed\Base;
use AMinin\Delayed\Job;
use AMinin\Delayed\JobInterface;
use \Exception;
use AMinin\Delayed\RepositoryInterface;

class PdoMysql extends Base implements RepositoryInterface
{
    private static $db = null;
    protected static $jobsTable = "";

    private static $dsn = "";
    private static $user = "";
    private static $password = "";
    private static $retries = 3; //default retries

    public static function configureWithOptions(array $options, $jobsTable = 'jobs')
    {
        if (!isset($options['driver'])) {
            throw new Exception("Please provide the database driver used in configure options array.");
        }
        if (!isset($options['user'])) {
            throw new Exception("Please provide the database user in configure options array.");
        }
        if (!isset($options['password'])) {
            throw new Exception("Please provide the database password in configure options array.");
        }

        self::$user = $options['user'];
        self::$password = $options['password'];
        self::$jobsTable = $jobsTable;

        self::$dsn = $options['driver'] . ':';
        foreach ($options as $key => $value) {
            // skips options already used
            if ($key == 'driver' || $key == 'user' || $key == 'password') {
                continue;
            }

            // searches for retries
            if ($key == 'retries') {
                self::$retries = (int)$value;
                continue;
            }

            self::$dsn .= $key . '=' . $value . ';';
        }
    }

    public static function setConnection(\PDO $db)
    {
        self::$db = $db;
    }

    protected static function getConnection()
    {
        if (self::$db === null) {
            try {
                self::$db = new \PDO(self::$dsn, self::$user, self::$password);
                self::$db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                self::$db->prepare("SET NAMES 'utf8'")->execute();
            } catch (\PDOException $e) {
                throw new Exception("DJJob couldn't connect to the database. PDO said [{$e->getMessage()}]");
            }
        }
        return self::$db;
    }

    /**
     * @param $sql
     * @param array $params
     * @return array
     * @throws Exception
     */
    public static function runQuery($sql, $params = array())
    {
        for ($attempts = 0; $attempts < self::$retries; $attempts++) {
            try {
                $stmt = self::getConnection()->prepare($sql);
                $stmt->execute($params);

                $ret = array();
                if ($stmt->rowCount()) {
                    // calling fetchAll on a result set with no rows throws a
                    // "general error" exception
                    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                        $ret [] = $r;
                    }
                }

                $stmt->closeCursor();
                return $ret;
            } catch (\PDOException $e) {
                // Catch "MySQL server has gone away" error.
                if ($e->errorInfo[1] == 2006) {
                    self::$db = null;
                } // Throw all other errors as expected.
                else {
                    throw $e;
                }
            }
        }

        throw new Exception("DJJob exhausted retries connecting to database");
    }

    /**
     * @param string $sql
     * @param array $params
     * @return int
     * @throws Exception
     */
    public static function runUpdate($sql, $params = array())
    {
        for ($attempts = 0; $attempts < self::$retries; $attempts++) {
            try {
                $stmt = self::getConnection()->prepare($sql);
                $stmt->execute($params);
                return $stmt->rowCount();
            } catch (\PDOException $e) {
                // Catch "MySQL server has gone away" error.
                if ($e->errorInfo[1] == 2006) {
                    self::$db = null;
                } // Throw all other errors as expected.
                else {
                    throw $e;
                }
            }
        }

        throw new Exception("DJJob exhausted retries connecting to database");
    }

    /**
     * @param string $queue
     * @return \AMinin\Delayed\JobInterface[]
     * @throws Exception
     */
    public function getLockedJobs($queue = null)
    {
        $ignoreQueue = empty($queue) || $queue == '*';
        $lockedJobs = self::runQuery(
            '
            SELECT * FROM ' . self::$jobsTable . '
            WHERE (queue = ? OR ?) AND (locked_at IS NOT NULL)
            LIMIT 100
            ',
            array($queue, $ignoreQueue)
        );
        return array_map(
            function($jobParams) {
                return new Job($jobParams);
            },
            $lockedJobs
        );
    }

    public function delete($id) {
        $this->runUpdate('DELETE FROM ' . self::$jobsTable . ' WHERE id = ?', [$id]);
    }

    public function releaseLock($id) {
        $this->runUpdate('UPDATE ' . self::$jobsTable . ' SET locked_at = NULL, locked_by = NULL WHERE id = ?', [$id]);
    }

    public function releaseLocks($workerName) {
        $this->runUpdate("
            UPDATE " . self::$jobsTable . "
            SET locked_at = NULL, locked_by = NULL
            WHERE locked_by = ?",
            array($workerName)
        );
    }

    public static function status($queue = null)
    {
        $ignoreQueue = empty($queue) || $queue == '*';
        $rs = self::runQuery("
            SELECT COUNT(*) as total, COUNT(failed_at) as failed, COUNT(locked_at) as locked
            FROM " . self::$jobsTable . "
            WHERE queue = ? OR ?
        ", array($queue, $ignoreQueue));
        $rs = $rs[0];

        $failed = $rs["failed"];
        $locked = $rs["locked"];
        $total = $rs["total"];
        $outstanding = $total - $locked - $failed;

        return array(
            "outstanding" => $outstanding,
            "locked" => $locked,
            "failed" => $failed,
            "total" => $total
        );
    }

    public function acquireLock($id, $workerName)
    {
        return $this->runUpdate(
            '
            UPDATE ' . self::$jobsTable . '
            SET    locked_at = NOW(), locked_by = ?
            WHERE  id = ? AND (locked_at IS NULL OR locked_by = ?) AND failed_at IS NULL
            ',
            array($workerName, $id, $workerName)
        );
    }

    public function enqueue($handler, $queue = null, $run_at = null)
    {
        empty($run_at) || $this->syncMysqlTimezoneWithPhp();

        $affected = $this->runUpdate(
            "INSERT INTO " . self::$jobsTable . " (handler, queue, run_at, created_at) VALUES(?, ?, ?, NOW())",
            array(serialize($handler), (string)$queue, $run_at)
        );

        if ($affected < 1) {
            self::log("[JOB] failed to enqueue new job", self::ERROR);
            return false;
        }

        return self::getConnection()->lastInsertId(); // return the job ID, for manipulation later
    }

    public function save(JobInterface $job) {
        empty($run_at) || $this->syncMysqlTimezoneWithPhp();
        if ($job->getId()) {
            return $this->update($job);
        } else {
            return $this->insert($job);
        }
    }

    public function insert(JobInterface $job) {
        $table = self::$jobsTable;
        $arguments = $this->prepareArguments($job);
        $this->runUpdate(
            "
            INSERT INTO $table
            (attempts, failed_at, handler, last_error, locked_at, locked_by, queue, run_at, created_at)
            VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ",
            $arguments
        );
        return self::getConnection()->lastInsertId();
    }

    public function update(JobInterface $job) {
        $table = self::$jobsTable;
        $arguments = $this->prepareArguments($job);
        $arguments[] = $job->getId();
        return $this->runUpdate(
            "
            UPDATE $table
            SET
              attempts = ?, failed_at = ?, handler = ?, last_error = ?,
              locked_at = ?, locked_by = ?, queue = ?, run_at = ?
            WHERE id = ?
            ",
            $arguments
        );
    }

    public function syncMysqlTimezoneWithPhp()
    {
        /** @see date_default_timezone_get(); */
        $date = new \DateTime();
        self::runQuery('SET LOCAL time_zone = ?', array($date->format('P')));
    }

    private function prepareArguments(JobInterface $job) {
        $format = 'Y-m-d H:i:s';
        $prepareDateTime = function($dateTime) use ($format) {
            return ($dateTime instanceof \DateTime) ? $dateTime->format($format) : $dateTime;
        };
        return array(
            $job->getAttempts(),
            $prepareDateTime($job->getFailedAt()),
            serialize($job->getHandler()),
            $job->getLastError(),
            $prepareDateTime($job->getLockedAt()),
            $job->getLockedBy(),
            $job->getQueue(),
            $prepareDateTime($job->getRunAt()),
        );
    }

    /**
     * @param string $queue
     * @param string $workerName
     * @param int $maxAttempts
     * @return JobInterface[]
     */
    public function getNewJobs($queue = null, $workerName = null, $maxAttempts = 5)
    {
        $table = self::$jobsTable;
        $ignoreQueue = $queue == '*';
        # we can grab a locked job if we own the lock
        $rs = $this->runQuery(
            "
            SELECT *
            FROM   $table
            WHERE  (queue = ? OR ?)
            AND    (run_at IS NULL OR NOW() >= run_at)
            AND    (locked_at IS NULL OR locked_by = ?)
            AND    failed_at IS NULL
            AND    attempts < ?
            ORDER BY created_at DESC
            LIMIT  10
            ",
            array($queue, $ignoreQueue, $workerName, $maxAttempts)
        );

        // randomly order the 10 to prevent lock contention among workers
        shuffle($rs);

        return array_map(function($jobAttributes) { return new Job($jobAttributes); }, $rs);
    }
}
