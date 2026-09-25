<?php

declare(strict_types=1);

namespace Faoxima\Provisioning;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class AtomicFilesystem
{
    public static function write(string $path, string $contents, int $mode, ?string $owner = null, ?string $group = null): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            throw new ProvisioningException("Parent directory does not exist: {$directory}");
        }
        $temporary = $directory . '/.' . basename($path) . '.tmp-' . bin2hex(random_bytes(8));
        $handle = fopen($temporary, 'xb');
        if ($handle === false) {
            throw new ProvisioningException("Cannot create temporary file for {$path}");
        }
        try {
            if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) {
                throw new ProvisioningException("Cannot write temporary file for {$path}");
            }
            if (function_exists('fsync')) { fsync($handle); }
        } finally {
            fclose($handle);
        }
        $ownershipOk = ($owner === null || chown($temporary, $owner)) && ($group === null || chgrp($temporary, $group));
        if (!$ownershipOk || !chmod($temporary, $mode) || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new ProvisioningException("Cannot atomically replace {$path}");
        }
    }

    public static function removeTree(string $path, string $allowedParent): void
    {
        $parent = realpath(dirname($path));
        if ($parent === false || $parent !== realpath($allowedParent) || is_link($path)) {
            throw new ProvisioningException("Refusing unsafe recursive deletion: {$path}");
        }
        if (!is_dir($path)) { return; }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isLink() || $item->isFile() ? unlink($item->getPathname()) : rmdir($item->getPathname());
        }
        if (!rmdir($path)) {
            throw new ProvisioningException("Cannot remove directory {$path}");
        }
    }
}
