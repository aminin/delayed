<?php

namespace AMinin\Delayed;

use AMinin\Delayed\RepositoryInterface;

class Base
{
    // error severity levels
    const CRITICAL = 4;
    const    ERROR = 3;
    const     WARN = 2;
    const     INFO = 1;
    const    DEBUG = 0;

    private static $logLevel = self::DEBUG;
    private static $jobRepository = null;

    public static function setLogLevel($const)
    {
        self::$logLevel = $const;
    }

    protected static function log($mesg, $severity = self::CRITICAL)
    {
        if ($severity >= self::$logLevel) {
            printf("[%s] %s\n", date('c'), $mesg);
        }
    }

    /**
     * @return RepositoryInterface
     */
    protected function getRepository()
    {
        return self::$jobRepository;
    }

    /**
     * @param RepositoryInterface $jobRepository
     */
    public static function setRepository(RepositoryInterface $jobRepository)
    {
        self::$jobRepository = $jobRepository;
    }

    protected function camelize($scored) {
        return lcfirst(implode('', array_map(function($a) { return ucfirst(strtolower($a)); }, explode('_', $scored))));
    }
}
