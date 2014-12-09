<?php

namespace AMinin\Delayed;

class Monitor extends Base
{
    const DEFAULT_WORKERS_COUNT = 5;
    const DEFAULT_WORKERS_ETIME = 3600;

    private $queues = array();
    private $logFileName;
    private $scriptFileName;
    private $projectRootDir;
    public $kill = false;

    public function __construct($queues, $logFileName, $scriptFileName, $projectRootDir)
    {
        $this->queues = $queues;
        $this->scriptFileName = $scriptFileName;
        $this->logFileName = $logFileName;
        $this->projectRootDir = $projectRootDir;
    }

    public function run()
    {
        $this->killWithoutQueue();

        foreach ($this->queues as $queue => $workersCount) {
            $this->handleQueue($queue, $workersCount);
        }
        $this->detectSilentlyFailedJobs();
    }

    public function handleQueue($queue, $workersCount = self::DEFAULT_WORKERS_COUNT)
    {
        $processList = $this->getProcessList("| grep '$queue'");
        $processCount = count($processList);
        $this->log("$processCount workers are alive");

        if ($this->kill) {
            $this->killAll($processList);
            return;
        }

        $processCount -= $this->killByEtime($processList);
        $processCount -= $this->killOnUpdateVersion($processList);

        while ($processCount < $workersCount) {
            $this->spawnWorker($queue);
            $processCount++;
        }
    }

    /**
     * Returns process list as an array of hashes
     *
     *   array(
     *     array('pid' => 123, 'etime' => '01:55:15', 'time' => 6915),
     *     array('pid' => 124, 'etime' => '55:15', 'time' => 3315),
     *   )
     *
     * @param string $extraGrep
     * @return array
     */
    public function getProcessList($extraGrep = '')
    {
        $command = "ps -aeo pid,etime,command | grep {$this->scriptFileName} $extraGrep | grep -v grep";
        $djJobWorkers = shell_exec($command);
        print_r($djJobWorkers);
        $workerProcesses = array_map(
            function ($processLine) {
                // 1234 05:15 php djjob.php
                preg_match('~^(\d+)\s+((\d\d:)?(\d\d):(\d\d))~', trim($processLine), $m);
                return array(
                    'pid' => $m[1],
                    'etime' => $m[2],
                    'time' => $m[3] * 3600 + $m[4] * 60 + $m[5]
                );
            },
            array_filter(explode("\n", trim($djJobWorkers)), function ($line) {
                return !empty($line);
            })
        );
        return $workerProcesses;
    }

    public function spawnWorker($queue)
    {
        $this->log("spawn new worker on queue: $queue");
        chdir($this->projectRootDir);
        if (empty($dir) || empty($this->logFileName)) {
            $this->logFileName = '/dev/null';
        }
        // Queue is passed through environment variable, while --queue is used only for grep
        shell_exec("QUEUE='$queue' php {$this->scriptFileName} --queue='$queue' >> {$this->logFileName} &");
    }

    /**
     * Terminates workers running more than hour, in order to avoid memory leaks
     *
     * @param array $processList
     * @return int
     */
    public function killByEtime($processList)
    {
        $killedProcessCount = 0;
        foreach ($processList as $processInfo) {
            if ($processInfo['time'] > self::DEFAULT_WORKERS_ETIME) {
                $this->log("killing by etime {$processInfo['pid']}");
                posix_kill($processInfo['pid'], SIGTERM);
                $killedProcessCount++;
            }
        }
        return $killedProcessCount;
    }

    /**
     * Terminates workers started before last version update
     *
     * @param array $processList
     * @return int
     */
    public function killOnUpdateVersion($processList)
    {
        $lastVersionDate = $this->getVersionDate();
        if (empty($lastVersionDate)) {
            return 0;
        }
        $now = new \DateTime();
        $lastVersionDate = new \DateTime($lastVersionDate);
        $diff = $now->diff($lastVersionDate);
        $diffSec =
            ($diff->y * 365 * 24 * 60 * 60) +
            ($diff->m * 30 * 24 * 60 * 60) +
            ($diff->d * 24 * 60 * 60) +
            ($diff->h * 60 * 60) +
            ($diff->i * 60) +
            $diff->s;
        $killedProcessCount = 0;
        foreach ($processList as $processInfo) {
            if ($processInfo['time'] > $diffSec) {
                $this->log("killing on update version {$processInfo['pid']}");
                posix_kill($processInfo['pid'], SIGTERM);
                $killedProcessCount++;
            } else {
                //$this->log("PID {$processInfo['pid']}: etime: {$processInfo['time']} < lastVersionTime: {$diffSec}");
            }
        }
        return $killedProcessCount;
    }

    /**
     * Kills workers running without queue (e.g.: started manually)
     */
    public function killWithoutQueue()
    {
        $processList = $this->getProcessList("| grep -v 'queue'");
        $this->killAll($processList);
    }

    public function killAll($processList)
    {
        foreach ($processList as $processInfo) {
            $this->log("killing {$processInfo['pid']}");
            posix_kill($processInfo['pid'], SIGTERM);
        }
    }

    public function getVersionDate()
    {
        chdir($this->projectRootDir);
        $versionDate = shell_exec("git log --pretty=format:'%ci' -1");
        return trim($versionDate);
    }

    public function detectSilentlyFailedJobs($queue = '*')
    {
        $jobs = $this->getRepository()->getLockedJobs($queue);
        $host = gethostname();
        $this->log(sprintf('detecting silently failed jobs on %s', $host));
        $found = 0;
        foreach ($jobs as $job) {
            if (!preg_match('~host::(?P<host>\S+) pid::(?P<pid>\d+)~', $job->getLockedBy(), $m)) {
                $this->log($job->getLockedBy());
                continue;
            }
            if ($m['host'] == $host && false === posix_getpgid($m['pid'])) { // хост наш и воркер мёртв
                $found++;
                $this->log(sprintf("JOB[%d] locked by (%s) is silently failed\n", $job->getId(), $job->getLockedBy()));
                $job->finishWithError('silently failed');
            }
        }
        $this->log(sprintf('detected %d silently failed jobs on %s', $found, $host));
    }
}
