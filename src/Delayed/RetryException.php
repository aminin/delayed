<?php

namespace AMinin\Delayed;

class RetryException extends Exception
{

    private $delaySeconds = 7200;

    public function setDelay($delay)
    {
        $this->delaySeconds = $delay;
    }

    public function getDelay()
    {
        return $this->delaySeconds;
    }
}
