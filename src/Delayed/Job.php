<?php

namespace AMinin\Delayed;

class Job extends Base implements JobInterface
{
    public static $performRatherThanEnqueue = false;
    public static $defaultQueue = 'default';
    public static $delayBeforeRetryAfterError = 60;

    protected $id;
    protected $attempts = 0;
    protected $createdAt;
    protected $failedAt;
    protected $handler;
    protected $lastError;
    protected $lockedAt;
    protected $lockedBy;
    protected $maxAttempts = 5;
    protected $queue;
    protected $runAt;

    public function __construct($options = array())
    {
        foreach ($options as $optionName => $optionValue) {
            foreach ([$optionName, self::camelize($optionName)] as $propertyName) {
                if (property_exists($this, $propertyName)) {
                    $this->$propertyName = $optionValue;
                }
            }
        }
    }

    public function run()
    {
        # pull the handler from the db
        $handler = $this->getHandler();
        if (!is_object($handler)) {
            $msg = "[JOB] bad handler for job::{$this->id}";
            $this->finishWithError($msg);
            return false;
        }
        # run the handler
        try {
            $handler->perform();
            # cleanup
            $this->finish();
            return true;
        } catch (RetryException $e) {
            # attempts hasn't been incremented yet.
            $attempts = $this->getAttempts() + 1;

            $msg = "Caught DJRetryException \"{$e->getMessage()}\" on attempt $attempts/{$this->maxAttempts}.";

            if ($attempts == $this->maxAttempts) {
                $msg = "[JOB] job::{$this->id} $msg Giving up.";
                $this->finishWithError($msg);
            } else {
                $this->logJob("[JOB] job::{$this->id} $msg Try again in {$e->getDelay()} seconds.", self::WARN);
                $this->retryLater($e->getDelay());
            }
            return false;
        } catch (Exception $e) {
            $this->finishWithError($e->getMessage(), $handler);
            return false;
        }
    }

    public function acquireLock($workerName)
    {
        $this->logJob("[JOB] attempting to acquire lock for job::{$this->id} on {$workerName}", self::INFO);

        $lock = $this->getRepository()->acquireLock($this->id, $workerName);

        if (!$lock) {
            $this->logJob("[JOB] failed to acquire lock for job::{$this->id}", self::INFO);
        } else {
            $this->lockedAt = new \DateTime('now');
            $this->lockedBy = $workerName;
        }

        return (bool)$lock;
    }

    public function releaseLock($performUpdate = true)
    {
        $this->lockedAt = null;
        $this->lockedBy = null;
        $performUpdate && $this->getRepository()->releaseLock($this->id);
    }

    public function finish()
    {
        $this->getRepository()->delete($this->id);
        $this->logJob("[JOB] completed job::{$this->id}", self::INFO);
    }

    public function finishWithError($error)
    {
        $this->lastError = $error;
        $this->logJob($error, self::ERROR);
        $this->logJob("[JOB] failure in job::{$this->id}", self::ERROR);
        $this->releaseLock(false);
        $this->retryLater(self::$delayBeforeRetryAfterError);
    }

    public function fail() {
        $this->failedAt = new \DateTime('now');
        $this->getRepository()->save($this);
    }

    public function retryLater($delay)
    {
        $this->attempts++;
        $this->runAt = (new \DateTime('now'))->add(new \DateInterval(sprintf('PT%dS', $delay)));
        $this->releaseLock(false);
        $this->getRepository()->save($this);
    }

    public static function enqueue($handler, $queue = null, $run_at = null)
    {
        $queue = $queue ?: self::$defaultQueue;

        if (self::$performRatherThanEnqueue) {
            $handler->perform();
            return 0;
        }

        $job = new self(array(
            'handler' => $handler,
            'queue' => $queue,
            'run_at' => $run_at,
        ));
        return $job->save();
    }

    public function save() {
        return $this->getRepository()->save($this);
    }

    protected function logJob($mesg, $severity = self::CRITICAL)
    {
        $mesg = sprintf('{%s} %s', $this->lockedBy, $mesg);
        self::log($mesg, $severity);
    }

    /**#@+
     * Properties
     */
    public function getId()
    {
        return $this->id;
    }

    public function getAttempts()
    {
        return $this->attempts;
    }

    public function getCreatedAt()
    {
        $this->toDateTime('createdAt');
        return $this->createdAt;
    }

    public function getFailedAt()
    {
        $this->toDateTime('failedAt');
        return $this->failedAt;
    }

    public function getHandler()
    {
        if (is_string($this->handler)) {
            $this->handler = unserialize($this->handler);
        }
        return $this->handler;
    }

    public function getLastError()
    {
        return $this->lastError;
    }

    public function getLockedAt()
    {
        $this->toDateTime('lockedAt');
        return $this->lockedAt;
    }

    public function getLockedBy()
    {
        return $this->lockedBy;
    }

    public function getRunAt()
    {
        $this->toDateTime('runAt');
        return $this->runAt;
    }

    public function getQueue()
    {
        return $this->queue;
    }
    /**#@-*/

    protected function toDateTime($field)
    {
        if (isset($this->$field) && !($this->$field instanceof \DateTime)) {
            $this->$field = new \DateTime($this->$field);
        }
    }
}
