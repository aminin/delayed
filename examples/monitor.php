<?php

require __DIR__ . '/config.php';

use AMinin\Delayed\Monitor;

$monitor = new Monitor(array('default' => 2, '*' => 1), '/tmp/delayed_job.log', 'examples/worker.php', __DIR__ . '/..');

if (isset($_SERVER['KILL'])) {
    $monitor->kill = true;
}

$monitor->run();
