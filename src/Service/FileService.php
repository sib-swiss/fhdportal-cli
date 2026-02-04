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
            foreach ($selectedFiles as $fileName) {
                $filePath = $dirPath . '/' . $fileName;

                // Check if the file exists
                if (!file_exists($filePath)) {
                    throw new RuntimeException("File not found: $fileName");
                }

                $archive->addFile($filePath, $fileName);
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

        // Copy the archive file to the temporary directory
        $tempFilePath = $tempDirPath . '/' . basename($filePath);
        $this->filesystem->copy($filePath, $tempFilePath);

        // Extract the archive file
        $cmd = "unzip -o -q -j " . escapeshellarg($tempFilePath) . " -d " . escapeshellarg($tempDirPath);
        exec($cmd, $output, $returnCode);

        // Check if the extraction was successful
        if ($returnCode !== 0) {
            throw new RuntimeException("Failed to extract archive file: $filePath");
        }

        return $tempDirPath;
    }

    /**
     * Create a temporary directory
     */
    public function createTempDirectory(): string
    {
        $tempDirPath = sys_get_temp_dir() . '/' . static::TEMP_DIR_PREFIX . uniqid();
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

        return strpos($tempDirPath, $systemTempDirPath) === 0;
    }
}
