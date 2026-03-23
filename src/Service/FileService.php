<?php

namespace App\Service;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

use InvalidArgumentException;
use RuntimeException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

class FileService
{
    public const TEMP_DIR_PREFIX = 'fega-';

    private Filesystem $filesystem;

    public function __construct(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    /**
     * Get a file extension from a file path
     */
    public function getFileExtension(string $filePath): string
    {
        $pathInfo = pathinfo($filePath);
        if (!isset($pathInfo['extension'])) {
            return '';
        }

        return strtolower($pathInfo['extension']);
    }

    /**
     * Get value delimiter based on file format and content
     */
    public function getValueDelimiter(string $line, string $format): string
    {
        $delimiter = ',';

        if ($format === 'csv') {
            $record = str_getcsv($line, "\t");
            if (count($record) > 2) {
                $delimiter = "\t";
            }
        } elseif ($format === 'tsv') {
            $delimiter = "\t";
        }

        return $delimiter;
    }

    /**
     * Create a ZIP archive of a specified directory
     */
    public function createArchive(string $dirPath, ?string $outputFile = null, bool $flatten = false, ?array $selectedFiles = null): string
    {
        // Determine output path
        $archiveName = basename($dirPath) . '.zip';
        $archivePath = $outputFile ?: getcwd() . '/' . $archiveName;

        // Ensure the output directory exists
        $outputDir = dirname($archivePath);
        if (!is_dir($outputDir)) {
            $this->filesystem->mkdir($outputDir);
        }

        // Create a ZIP file
        $archive = new ZipArchive();
        if ($archive->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            throw new RuntimeException("Failed to create archive: $archivePath");
        }

        if ($selectedFiles !== null) {
            // Add only selected files to the archive
            $resolvedDirPath = realpath($dirPath);
            foreach ($selectedFiles as $fileName) {
                $filePath = $dirPath . '/' . $fileName;

                // Guard against path traversal
                $resolvedFilePath = realpath($filePath);
                if (
                    $resolvedFilePath === false
                    || $resolvedDirPath === false
                    || !str_starts_with($resolvedFilePath, $resolvedDirPath . DIRECTORY_SEPARATOR)
                ) {
                    $archive->close();
                    throw new RuntimeException("File path is outside the package directory: $fileName");
                }

                $archive->addFile($resolvedFilePath, $fileName);
            }
        } else {
            // Get all files from the specified directory
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dirPath),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            // Add the files to the archive
            foreach ($files as $file) {
                // Skip directories
                if ($file->isDir()) {
                    continue;
                }

                $filePath = $file->getRealPath();

                if ($flatten) {
                    // Add file directly to the root of the archive
                    $fileName = basename($filePath);
                    $archive->addFile($filePath, $fileName);
                } else {
                    // Maintain directory structure
                    $relativePath = substr($filePath, strlen($dirPath) + 1);
                    $archive->addFile($filePath, $relativePath);
                }
            }
        }

        // Close the archive
        $archive->close();

        return $archivePath;
    }

    /**
     * Extract a ZIP archive to a temporary directory
     */
    public function extractArchive(string $filePath): string
    {
        // Check if the file exists
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("File not found: $filePath");
        }

        // Check if the file is a ZIP archive
        if ($this->getFileExtension($filePath) !== 'zip') {
            throw new InvalidArgumentException("File must be a ZIP archive: $filePath");
        }

        // Create a temporary directory
        $tempDirPath = $this->createTempDirectory();

        // Open the archive
        $archive = new ZipArchive();
        if ($archive->open($filePath) !== true) {
            $this->filesystem->remove($tempDirPath);
            throw new RuntimeException("Failed to open archive: $filePath");
        }

        // Zip-slip protection: validate every entry before extracting
        $resolvedTempDir = realpath($tempDirPath);
        for ($i = 0; $i < $archive->numFiles; $i++) {
            $entry = $archive->getNameIndex($i);

            // Strip directory components to flatten the structure
            $safeName = basename($entry);
            if ($safeName === '' || $safeName === '.' || $safeName === '..') {
                continue;
            }

            // Construct the destination path for the entry
            $destPath = $resolvedTempDir . DIRECTORY_SEPARATOR . $safeName;

            // Ensure the destination path is within the temporary directory
            if (!str_starts_with($destPath, $resolvedTempDir . DIRECTORY_SEPARATOR)) {
                $archive->close();
                $this->filesystem->remove($tempDirPath);
                throw new RuntimeException("Zip-slip detected in archive entry: $entry");
            }
        }

        // Extract each file individually
        for ($i = 0; $i < $archive->numFiles; $i++) {
            $entry = $archive->getNameIndex($i);
            $safeName = basename($entry);
            if ($safeName === '' || $safeName === '.' || $safeName === '..') {
                continue;
            }
            $entryStream = $archive->getStream($entry);
            if ($entryStream === false) {
                continue;
            }
            $destPath = $tempDirPath . DIRECTORY_SEPARATOR . $safeName;
            file_put_contents($destPath, $entryStream);
            fclose($entryStream);
        }

        $archive->close();

        return $tempDirPath;
    }

    /**
     * Create a temporary directory
     */
    public function createTempDirectory(): string
    {
        $tempDirPath = sys_get_temp_dir() . '/' . static::TEMP_DIR_PREFIX . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($tempDirPath);

        return $tempDirPath;
    }

    /**
     * Remove a temporary directory
     */
    public function removeTempDirectory(string $targetPath): void
    {
        // Check if the target directory is a valid temp directory
        if (!$this->isTempDirectory($targetPath)) {
            throw new InvalidArgumentException(
                "Temporary directory not found: $targetPath"
            );
        }

        try {
            // Remove the target directory
            $this->filesystem->remove(realpath($targetPath));
        } catch (IOExceptionInterface $exception) {
            throw new RuntimeException(
                "Failed to remove directory " . $exception->getPath()
            );
        }
    }

    /**
     * Check if a directory is inside the system temporary directory
     */
    public function isTempDirectory(string $directoryPath): bool
    {
        $systemTempDirPath = realpath(sys_get_temp_dir());
        $tempDirPath = realpath($directoryPath);

        if (($tempDirPath === false) || !is_dir($tempDirPath)
            || !$this->filesystem->exists($tempDirPath)
        ) {
            return false;
        }

        return str_starts_with($tempDirPath, $systemTempDirPath . DIRECTORY_SEPARATOR);
    }
}
