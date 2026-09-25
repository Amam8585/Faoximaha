<?php

declare(strict_types=1);

namespace Faoxima\Provisioning;

class CommandRunner
{
    /** @param list<string> $arguments */
    public function run(array $arguments, ?string $cwd = null, int $timeoutSeconds = 120): string
    {
        return $this->execute($arguments, null, null, $cwd, $timeoutSeconds);
    }

    /** @param list<string> $arguments */
    public function runWithInput(array $arguments, string $inputFile, int $timeoutSeconds = 120): string
    {
        if (!is_file($inputFile) || !is_readable($inputFile)) {
            throw new ProvisioningException("Cannot read command input file: {$inputFile}");
        }
        return $this->execute($arguments, $inputFile, null, null, $timeoutSeconds);
    }

    /** @param list<string> $arguments */
    public function runToFile(array $arguments, string $outputFile, int $timeoutSeconds = 120): void
    {
        $this->execute($arguments, null, $outputFile, null, $timeoutSeconds);
    }

    /** @param list<string> $arguments */
    private function execute(array $arguments, ?string $inputFile, ?string $outputFile, ?string $cwd, int $timeoutSeconds): string
    {
        if ($arguments === []) {
            throw new ProvisioningException('An empty command cannot be executed.');
        }

        $command = implode(' ', array_map('escapeshellarg', $arguments));
        $descriptor = [0 => $inputFile === null ? ['pipe', 'r'] : ['file', $inputFile, 'r'],
            1 => $outputFile === null ? ['pipe', 'w'] : ['file', $outputFile, 'x'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptor, $pipes, $cwd, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new ProvisioningException('Could not start command: ' . $arguments[0]);
        }

        if ($inputFile === null) { fclose($pipes[0]); }

        if ($outputFile === null) { stream_set_blocking($pipes[1], false); }
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            if ($outputFile === null) { $stdout .= stream_get_contents($pipes[1]); }
            $stderr .= stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($process, 15);
                usleep(250_000);
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process, 9);
                }
                if ($outputFile === null) { fclose($pipes[1]); }
                fclose($pipes[2]);
                proc_close($process);
                throw new ProvisioningException("Command timed out after {$timeoutSeconds}s: {$arguments[0]}");
            }
            usleep(20_000);
        } while (true);

        if ($outputFile === null) { $stdout .= stream_get_contents($pipes[1]); }
        $stderr .= stream_get_contents($pipes[2]);
        if ($outputFile === null) { fclose($pipes[1]); }
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        // proc_get_status can consume the real status on some PHP versions.
        if ($exitCode === -1 && isset($status['exitcode']) && $status['exitcode'] >= 0) {
            $exitCode = $status['exitcode'];
        }
        if ($exitCode !== 0) {
            $message = trim($stderr) ?: trim($stdout);
            throw new ProvisioningException("Command failed ({$exitCode}): {$arguments[0]}: {$message}");
        }
        return $stdout;
    }
}
