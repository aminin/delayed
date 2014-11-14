This system is mostly a port of delayed_job: http://github.com/collectiveidea/delayed_job

See: https://github.com/aminin/delayed

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
