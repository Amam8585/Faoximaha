<?php

declare(strict_types=1);

$stateRoot = getenv('FAOXIMA_STATE_ROOT') ?: '/var/lib/faoxima-provisioner';
$webRoot = getenv('FAOXIMA_WEB_ROOT') ?: '/var/www/faoxima';
$lockRoot = getenv('FAOXIMA_LOCK_ROOT') ?: '/run/lock/faoxima-provisioner';
$php = getenv('FAOXIMA_PHP_BINARY') ?: '/usr/bin/php8.3';
$concurrencyRaw = getenv('FAOXIMA_SCHEDULER_CONCURRENCY') ?: '4';
$concurrency = ctype_digit($concurrencyRaw) ? (int) $concurrencyRaw : 0;
if ($concurrency < 1 || $concurrency > 16) {
    fwrite(STDERR, "FAOXIMA_SCHEDULER_CONCURRENCY must be between 1 and 16\n");
    exit(2);
}

/** @var list<array{name:string,root:string,cron:string}> $queue */
$queue = [];
foreach (glob($stateRoot . '/bot_*.json') ?: [] as $stateFile) {
    $name = basename($stateFile, '.json');
    if (!preg_match('/\Abot_[1-9][0-9]{0,8}\z/D', $name)) continue;
    $stateStat = lstat($stateFile);
    if ($stateStat === false || ($stateStat['mode'] & 0170000) !== 0100000 || ($stateStat['mode'] & 0027) !== 0
        || (function_exists('posix_geteuid') && $stateStat['uid'] !== posix_geteuid())) {
        error_log("[faoxima-scheduler:{$name}] refusing unsafe state file"); continue;
    }
    $state = json_decode((string) file_get_contents($stateFile), true);
    if (!is_array($state) || ($state['state'] ?? '') !== 'active') continue;
    $root = $webRoot . '/' . $name;
    if (is_link($root) || !is_dir($root) || realpath($root) !== $root || dirname($root) !== $webRoot) continue;
    $cron = $root . '/cron/cron.php';
    if (!is_file($cron) || is_link($cron)) continue;
    $queue[] = ['name'=>$name, 'root'=>$root, 'cron'=>$cron];
}

/** @var array<string,array{process:resource,pipes:array,lock:resource,deadline:float}> $running */
$running = [];
while ($queue !== [] || $running !== []) {
    while ($queue !== [] && count($running) < $concurrency) {
        $job = array_shift($queue); $name = $job['name']; $lockFile = $lockRoot . '/' . $name . '.lock';
        if (is_link($lockFile)) continue;
        $lock = fopen($lockFile, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) { if (is_resource($lock)) fclose($lock); continue; }
        $command = implode(' ', array_map('escapeshellarg', [$php, $job['cron']]));
        $process = proc_open($command, [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $job['root'], null, ['bypass_shell'=>true]);
        if (!is_resource($process)) { flock($lock,LOCK_UN);fclose($lock);continue; }
        stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
        $running[$name]=['process'=>$process,'pipes'=>$pipes,'lock'=>$lock,'deadline'=>microtime(true)+240];
        usleep(100000); // stagger starts without serializing running jobs
    }
    foreach ($running as $name => $job) {
        stream_get_contents($job['pipes'][1]);$stderr=stream_get_contents($job['pipes'][2]);if($stderr!=='')error_log("[faoxima-scheduler:{$name}] ".trim($stderr));
        $status=proc_get_status($job['process']);$finished=!$status['running'];
        if(!$finished&&microtime(true)>=$job['deadline']){proc_terminate($job['process'],15);usleep(250000);if(proc_get_status($job['process'])['running'])proc_terminate($job['process'],9);$finished=true;}
        if(!$finished)continue;
        fclose($job['pipes'][1]);fclose($job['pipes'][2]);proc_close($job['process']);flock($job['lock'],LOCK_UN);fclose($job['lock']);unset($running[$name]);
    }
    if ($running !== []) usleep(50000);
}
