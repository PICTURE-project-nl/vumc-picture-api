<?php

namespace App\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

final class ZipFile
{
    public static function extract(string $archivePath, string $destination): void
    {
        $archive = self::open($archivePath);

        try {
            if (! is_dir($destination) && ! mkdir($destination, 0755, true) && ! is_dir($destination)) {
                throw new RuntimeException("Unable to create ZIP destination: {$destination}");
            }

            for ($index = 0; $index < $archive->numFiles; $index++) {
                $name = str_replace('\\', '/', (string) $archive->getNameIndex($index));
                $segments = explode('/', $name);

                if ($name === '' || str_starts_with($name, '/') || in_array('..', $segments, true)) {
                    throw new RuntimeException("Unsafe path found in ZIP archive: {$name}");
                }
            }

            if (! $archive->extractTo($destination)) {
                throw new RuntimeException("Unable to extract ZIP archive: {$archivePath}");
            }
        } finally {
            $archive->close();
        }
    }

    public static function createFromDirectory(string $archivePath, string $sourceDirectory): void
    {
        if (! is_dir($sourceDirectory)) {
            throw new RuntimeException("ZIP source directory does not exist: {$sourceDirectory}");
        }

        $archive = new ZipArchive();
        $result = $archive->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($result !== true) {
            throw new RuntimeException("Unable to create ZIP archive: {$archivePath} ({$result})");
        }

        $sourceDirectory = rtrim($sourceDirectory, DIRECTORY_SEPARATOR);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        try {
            foreach ($iterator as $item) {
                $relativePath = substr($item->getPathname(), strlen($sourceDirectory) + 1);
                $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);

                if ($item->isDir()) {
                    $archive->addEmptyDir($relativePath);
                } elseif (! $archive->addFile($item->getPathname(), $relativePath)) {
                    throw new RuntimeException("Unable to add file to ZIP archive: {$relativePath}");
                }
            }
        } finally {
            if (! $archive->close()) {
                throw new RuntimeException("Unable to finalize ZIP archive: {$archivePath}");
            }
        }
    }

    private static function open(string $archivePath): ZipArchive
    {
        $archive = new ZipArchive();
        $result = $archive->open($archivePath);

        if ($result !== true) {
            throw new RuntimeException("Unable to open ZIP archive: {$archivePath} ({$result})");
        }

        return $archive;
    }
}
