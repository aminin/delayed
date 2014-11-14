<?php

namespace AMinin\Delayed;

interface JobInterface
{
    /**#@+
     * Properties
     */
    /**
     * @return int|null
     */
    public function getId();

    /**
     * @return int
     */
    public function getAttempts();

    /**
     * @return \DateTime
     */
    public function getCreatedAt();

    /**
     * @return \DateTime
     */
    public function getFailedAt();

    /**
     * @return mixed
     */
    public function getHandler();

    /**
     * @return string
     */
    public function getLastError();

    /**
     * @return \DateTime
     */
    public function getLockedAt();

    /**
     * @return string
     */
    public function getLockedBy();

    /**
     * @return \DateTime
     */
    public function getRunAt();

    /**
     * @return string
     */
    public function getQueue();
    /**#@-*/

    /**#@+
     * Actions
     */
    /**
     * @param string $workerName
     * @return bool
     */
    public function acquireLock($workerName);
    public function fail();
    public function finishWithError($error);
    public function retryLater($delay);
    public function run();
    public function save();
    /**#@-*/
}
