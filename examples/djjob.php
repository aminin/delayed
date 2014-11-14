<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/HelloWorldJob.php';

use AMinin\Delayed\Worker;
use AMinin\Delayed\Backend\PdoMysql;

PdoMysql::configureWithOptions(array(
    'driver'   => 'mysql',
    'host'     => '127.0.0.1',
    'dbname'   => 'djjob',
    'user'     => 'root',
    'password' => 'topsecret',
));

Worker::setRepository(new PdoMysql);

$worker = new \AMinin\Delayed\Worker(array(
    'queue' => isset($_SERVER['QUEUE']) ? $_SERVER['QUEUE'] : 'default',
));

$worker->start();
