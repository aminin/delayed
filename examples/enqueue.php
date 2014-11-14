<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/HelloWorldJob.php';

use AMinin\Delayed\Job;
use AMinin\Delayed\Backend\PdoMysql;

PdoMysql::configureWithOptions(array(
    'driver'   => 'mysql',
    'host'     => '127.0.0.1',
    'dbname'   => 'djjob',
    'user'     => 'root',
    'password' => 'topsecret',
));

Job::setRepository(new PdoMysql);

Job::enqueue(new HelloWorldJob('Foo Bar'));
