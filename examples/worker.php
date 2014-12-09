<?php

require __DIR__ . '/config.php';

use AMinin\Delayed\Worker;

$worker = new Worker(array(
    'queue' => isset($_SERVER['QUEUE']) ? $_SERVER['QUEUE'] : 'default',
));

$worker->start();
