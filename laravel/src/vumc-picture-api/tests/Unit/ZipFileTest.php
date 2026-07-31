<?php

namespace Tests\Unit;

use App\Support\ZipFile;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

class ZipFileTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/picture-zip-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory, 0755, true);
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->temporaryDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_it_creates_and_extracts_an_archive(): void
    {
        $source = $this->temporaryDirectory.'/source';
        $destination = $this->temporaryDirectory.'/destination';
        $archive = $this->temporaryDirectory.'/files.zip';

        mkdir($source.'/nested', 0755, true);
        file_put_contents($source.'/root.txt', 'root');
        file_put_contents($source.'/nested/file.txt', 'nested');

        ZipFile::createFromDirectory($archive, $source);
        ZipFile::extract($archive, $destination);

        $this->assertSame('root', file_get_contents($destination.'/root.txt'));
        $this->assertSame('nested', file_get_contents($destination.'/nested/file.txt'));
    }

    public function test_it_rejects_parent_directory_traversal(): void
    {
        $archivePath = $this->temporaryDirectory.'/unsafe.zip';
        $archive = new ZipArchive();
        $archive->open($archivePath, ZipArchive::CREATE);
        $archive->addFromString('../escape.txt', 'unsafe');
        $archive->close();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsafe path');

        ZipFile::extract($archivePath, $this->temporaryDirectory.'/destination');
    }
}
