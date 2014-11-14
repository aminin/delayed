<?php

// Sometimes posix functions are unavailable, so require this file to define them
if (!function_exists('posix_kill')) {
    function posix_kill($pid, $sig = SIGTERM)
    {
        shell_exec("kill $pid -$sig");
    }
}

if (!function_exists('posix_getpgid')) {
    function posix_getpgid($pid)
    {
        return file_exists("/proc/$pid") ? $pid : false;
    }
}
