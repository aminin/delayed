<?php

namespace AMinin\Delayed;

interface RepositoryInterface
{
    /**
     * @param string $queue
     * @return JobInterface[]
     */
    public function getLockedJobs($queue = null);

    /**
     * @param string $queue
     * @param string $workerName
     * @param int $maxAttempts
     * @return JobInterface[]
     */
    public function getNewJobs($queue = null, $workerName = null, $maxAttempts = 5);
    public function delete($id);
    public function enqueue($handler, $queue = null, $run_at = null);
    public function releaseLock($id);
    public function releaseLocks($workerName);
    public function acquireLock($id, $workerName);
    public function save(JobInterface $job);
}
