<?php

require __DIR__ . '/config.php';

use AMinin\Delayed\Job;
Job::enqueue(new HelloWorldJob('Foo Bar'));
