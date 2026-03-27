<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\FileService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use ZipArchive;

/**
 * Unit tests for FileService.
 */
class FileServiceTest extends TestCase
{
    private FileService $service;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->service = new FileService(new Filesystem());
        $this->tmpDir = sys_get_temp_dir() . '/fega-test-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            (new Filesystem())->remove($this->tmpDir);
        }
    }

    public function testGetFileExtensionReturnsLowercaseExtension(): void
    {
        self::assertSame('json', $this->service->getFileExtension('/path/to/file.JSON'));
        self::assertSame('tsv', $this->service->getFileExtension('data/records.TSV'));
        self::assertSame('zip', $this->service->getFileExtension('bundle.zip'));
    }

    public function testGetFileExtensionReturnsEmptyStringWhenNoExtension(): void
    {
        self::assertSame('', $this->service->getFileExtension('/path/no-extension'));
        self::assertSame('', $this->service->getFileExtension('README'));
    }

    public function testGetValueDelimiterReturnsTabulatorForTsvFormat(): void
    {
        self::assertSame("\t", $this->service->getValueDelimiter('col1\tcol2\tcol3', 'tsv'));
    }

    public function testGetValueDelimiterReturnsTabWhenHeaderHasManyTabColumns(): void
    {
        // csv auto-detect: if splitting by tab yields >2 tokens, use tab
        $header = "a\tb\tc\td";
        self::assertSame("\t", $this->service->getValueDelimiter($header, 'csv'));
    }

    public function testGetValueDelimiterReturnsCommaForCsvWithFewTabTokens(): void
    {
        self::assertSame(',', $this->service->getValueDelimiter('a,b,c', 'csv'));
    }

    public function testCreateTempDirectoryReturnsUniqueDirectoryInsideSystemTemp(): void
    {
        $dir1 = $this->service->createTempDirectory();
        $dir2 = $this->service->createTempDirectory();

        try {
            self::assertDirectoryExists($dir1);
            self::assertDirectoryExists($dir2);
            self::assertNotSame($dir1, $dir2);
            self::assertStringStartsWith(sys_get_temp_dir(), $dir1);
            self::assertStringContainsString(FileService::TEMP_DIR_PREFIX, basename($dir1));
        } finally {
            (new Filesystem())->remove([$dir1, $dir2]);
        }
    }

    public function testIsTempDirectoryReturnsTrueForValidTempDir(): void
    {
        $dir = $this->service->createTempDirectory();
        try {
            self::assertTrue($this->service->isTempDirectory($dir));
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    public function testIsTempDirectoryReturnsFalseForNonTempDir(): void
    {
        // The project root is not a temp directory
        self::assertFalse($this->service->isTempDirectory(dirname(__DIR__, 3)));
    }

    public function testIsTempDirectoryReturnsFalseForNonExistentPath(): void
    {
        self::assertFalse($this->service->isTempDirectory('/tmp/this-path-does-not-exist-' . uniqid()));
    }

    public function testRemoveTempDirectoryDeletesDirectory(): void
    {
        $dir = $this->service->createTempDirectory();
        self::assertDirectoryExists($dir);

        $this->service->removeTempDirectory($dir);

        self::assertDirectoryDoesNotExist($dir);
    }

    public function testRemoveTempDirectoryThrowsForNonTempPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->removeTempDirectory(dirname(__DIR__, 3));
    }

    public function testCreateArchiveProducesValidZipFile(): void
    {
        // Populate a source directory with files
        file_put_contents($this->tmpDir . '/alpha.txt', 'content-a');
        file_put_contents($this->tmpDir . '/beta.txt', 'content-b');

        $archivePath = $this->tmpDir . '/output.zip';
        $result = $this->service->createArchive($this->tmpDir, $archivePath);

        self::assertSame($archivePath, $result);
        self::assertFileExists($archivePath);

        $zip = new ZipArchive();
        self::assertSame(true, $zip->open($archivePath) === true);
        self::assertSame(2, $zip->numFiles);
        $zip->close();
    }

    public function testCreateArchiveWithSelectedFilesRejectsTraversalPath(): void
    {
        file_put_contents($this->tmpDir . '/safe.txt', 'safe');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/outside the package directory/i');

        $this->service->createArchive(
            $this->tmpDir,
            $this->tmpDir . '/out.zip',
            false,
            ['../outside.txt']
        );
    }

    public function testExtractArchiveExtractsFilesIntoTempDir(): void
    {
        // Build a clean zip
        $srcDir = $this->tmpDir . '/src';
        mkdir($srcDir);
        file_put_contents($srcDir . '/data.txt', 'hello');

        $archivePath = $this->service->createArchive($srcDir, $this->tmpDir . '/test.zip', true);

        $extractedDir = $this->service->extractArchive($archivePath);

        try {
            self::assertDirectoryExists($extractedDir);
            self::assertFileExists($extractedDir . '/data.txt');
            self::assertSame('hello', file_get_contents($extractedDir . '/data.txt'));
        } finally {
            $this->service->removeTempDirectory($extractedDir);
        }
    }

    public function testExtractArchiveThrowsForNonZipFile(): void
    {
        $textFile = $this->tmpDir . '/not-an-archive.txt';
        file_put_contents($textFile, 'plain text');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->extractArchive($textFile);
    }

    public function testExtractArchiveThrowsForNonExistentFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->extractArchive('/tmp/does-not-exist-' . uniqid() . '.zip');
    }

    public function testExtractArchiveProtectsAgainstZipSlip(): void
    {
        // Craft a ZIP archive whose single entry has a path-traversal name.
        $archivePath = $this->tmpDir . '/zipslip.zip';
        $zip = new ZipArchive();
        $zip->open($archivePath, ZipArchive::CREATE);
        // The entry name uses path traversal; the FileService must stop this.
        $zip->addFromString('../../evil.txt', 'malicious content');
        $zip->close();

        // extractArchive must either succeed by stripping the traversal,
        // or throw a RuntimeException. Either way, no file should escape
        // the temporary directory.
        $systemTemp = realpath(sys_get_temp_dir());
        $evilPath = dirname($systemTemp) . '/evil.txt';

        try {
            $extractedDir = $this->service->extractArchive($archivePath);
            // If extraction succeeded, the file must be inside the temp dir
            self::assertFileDoesNotExist($evilPath);
            $this->service->removeTempDirectory($extractedDir);
        } catch (\RuntimeException $e) {
            // Rejection is equally valid
            self::assertStringContainsString('zip-slip', strtolower($e->getMessage()));
        }
    }
}
