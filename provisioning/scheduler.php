<?php

declare(strict_types=1);

$stateRoot = '/var/lib/faoxima-provisioner';
$webRoot = '/var/www/faoxima';
$lockRoot = '/run/lock/faoxima-provisioner';
$php = '/usr/bin/php8.3';
$sudo = '/usr/bin/sudo';

foreach (glob($stateRoot . '/bot_*.json') ?: [] as $stateFile) {
    $name = basename($stateFile, '.json');
    if (!preg_match('/\Abot_[1-9][0-9]{0,8}\z/D', $name)) { continue; }
    $state = json_decode((string) file_get_contents($stateFile), true);
    if (!is_array($state) || ($state['state'] ?? '') !== 'active') { continue; }
    $root = realpath($webRoot . '/' . $name);
    $instances = realpath($webRoot . '/.instances');
    if ($root === false || $instances === false || dirname($root) !== $instances || !str_starts_with(basename($root), $name . '-')) { continue; }
    $cron = $root . '/cron/cron.php';
    if (!is_file($cron)) { continue; }
    $lock = fopen($lockRoot . '/' . $name . '.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) { if (is_resource($lock)) fclose($lock); continue; }
    try {
        $command = [$sudo, '-n', '-u', 'fx_' . $name, '--', $php, $cron];
        $escaped = implode(' ', array_map('escapeshellarg', $command));
        $process = proc_open($escaped, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        if (!is_resource($process)) { continue; }
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $deadline = microtime(true) + 240;
        do {
            stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            if ($stderr !== '') { error_log("[faoxima-scheduler:{$name}] " . trim($stderr)); }
            $status = proc_get_status($process);
            if (!$status['running']) { break; }
            if (microtime(true) >= $deadline) { proc_terminate($process, 15); usleep(250000); proc_terminate($process, 9); break; }
            usleep(50000);
        } while (true);
        fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
    } finally {
        flock($lock, LOCK_UN); fclose($lock);
    }
}
