# Delayed

Delayed allows PHP web applications to process long-running tasks asynchronously. It is a PHP 
port of [delayed_job]

Like delayed_job, Delayed uses a `jobs` table for persisting and tracking pending, in-progress, and failed jobs.

[delayed_job]: http://github.com/collectiveidea/delayed_job

## Requirements

* PHP >= 5.3

## Basic setup

Create `jobs` table of the following structure:

```
CREATE TABLE `jobs` (
`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
`handler` TEXT NOT NULL,
`queue` VARCHAR(255) NOT NULL DEFAULT 'default',
`attempts` INT UNSIGNED NOT NULL DEFAULT 0,
`run_at` DATETIME NULL,
`locked_at` DATETIME NULL,
`locked_by` VARCHAR(255) NULL,
`failed_at` DATETIME NULL,
`last_error` TEXT NULL,
`created_at` DATETIME NOT NULL
) ENGINE = INNODB DEFAULT CHARSET = utf8;
```

Tell Delayed how to connect to your database:

```php
// Somewhere in your application config
use AMinin\Delayed\Job;
use AMinin\Delayed\Backend\PdoMysql;

PdoMysql::configureWithOptions(array(
    'driver'=> 'mysql',
    'host'=> '127.0.0.1',
    'dbname'=> 'djjob',
    'user'=> 'root',
    'password'=> 'topsecret',
));

Job::setRepository(new PdoMysql);
```

## Usage

Jobs are PHP objects that respond to a method `perform`. Jobs are serialized and stored in the database.

```php
class HelloWorldJob {
    public function __construct($name) {
        $this->name = $name;
    }
    public function perform() {
        echo "Hello {$this->name}!\n";
    }
}

Job::enqueue(new HelloWorldJob("delayed_job"));
```

Unlike delayed_job, Delayed does not have the concept of task priority (not yet at least). Instead, it supports multiple
queues. By default, jobs are placed on the "default" queue. You can specifiy an alternative queue like:

```php
Job::enqueue(new SignupEmailJob("dev@example.com"), "email");
```

```php
class Email {
    public function __construct($recipient) {
        $this->recipient = $recipient;
    }
    public function send() {
        ...do some expensive work to build the email: geolocation, etc..
        ...use mail api to send this email
    }
    public function perform() {
        $this->send();
    }
    public function sendLater() {
        Job::enqueue($this, "email");
    }
}
```

Because `Email` has a `perform` method, all instances of the email class are also jobs.

## Running the jobs

Running a worker is as simple as:

```php
use AMinin\Delayed\Worker;

$worker = new Worker(array(
    'queue' => isset($_SERVER['QUEUE']) ? $_SERVER['QUEUE'] : 'default',
));

$worker->start();
```

The best way to manage Delayed workers is to use your favorite process monitoring tool (e.g.: [god], [upstart] or [runit])

[god]: http://godrb.com/
[upstart]: http://upstart.ubuntu.com/
[runit]: http://smarden.org/runit/
