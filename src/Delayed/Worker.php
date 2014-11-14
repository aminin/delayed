<?php

namespace AMinin\Delayed;

class Worker extends Base
{
    # This is a singleton-ish thing. It wouldn't really make sense to
    # instantiate more than one in a single request (or commandline task)

    public function __construct($options = array())
    {
        $options = array_merge(array(
            "queue" => "default",
            "count" => 0,
            "sleep" => 5,
            "max_attempts" => 5
        ), $options);
        list($this->queue, $this->count, $this->sleep, $this->max_attempts) =
            array($options["queue"], $options["count"], $options["sleep"], $options["max_attempts"]);

        list($hostname, $pid) = array(trim(`hostname`), getmypid());
        $this->name = "host::$hostname pid::$pid";

        if (function_exists("pcntl_signal")) {
            pcntl_signal(SIGTERM, array($this, "handleSignal"));
            pcntl_signal(SIGINT, array($this, "handleSignal"));
        }
    }

    public function handleSignal($signo)
    {
        $signals = array(
            SIGTERM => "SIGTERM",
            SIGINT => "SIGINT"
        );
        $signal = $signals[$signo];

        $this->logWorker("[WORKER] Received received {$signal}... Shutting down", self::INFO);
        $this->releaseLocks();
        die(0);
    }

    public function releaseLocks()
    {
        $this->getRepository()->releaseLocks($this->name);
    }

    /**
     * Returns a new job ordered by most recent first
     * why this?
     *     run newest first, some jobs get left behind
     *     run oldest first, all jobs get left behind
     * @return JobInterface
     */
    public function getNewJob()
    {
        $jobs = $this->getRepository()->getNewJobs($this->queue, $this->name, $this->max_attempts);

        foreach ($jobs as $job) {
            if ($job->acquireLock($this->name)) {
                return $job;
            }
        }

        return false;
    }

    public function start()
    {
        $this->logWorker("[JOB] Starting worker {$this->name} on queue::{$this->queue}", self::INFO);

        $count = 0;
        $job_count = 0;
        try {
            while ($this->count == 0 || $count < $this->count) {
                if (function_exists("pcntl_signal_dispatch")) {
                    pcntl_signal_dispatch();
                }

                $count += 1;
                $job = $this->getNewJob($this->queue);

                if (!$job) {
                    $this->logWorker("[JOB] Failed to get a job, queue::{$this->queue} may be empty", self::DEBUG);
                    sleep($this->sleep);
                    continue;
                }

                $job_count += 1;
                $job->run();
            }
        } catch (Exception $e) {
            $this->logWorker("[JOB] unhandled exception::\"{$e->getMessage()}\"", self::ERROR);
        }

        $this->logWorker("[JOB] worker shutting down after running {$job_count} jobs, over {$count} polling iterations",
            self::INFO);
    }

    protected function logWorker($mesg, $severity = self::CRITICAL)
    {
        $mesg = sprintf('{%s} %s', $this->name, $mesg);
        self::log($mesg, $severity);
    }
}
