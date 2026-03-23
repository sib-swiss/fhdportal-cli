<?php

namespace App\Service;

use Symfony\Component\Yaml\Yaml;
use Exception;

class ManifestService
{
    public const MANIFEST_VERSION = 1;
    public const MANIFEST_FILE_NAME = 'manifest.yaml';
    public const REQUIRED_TYPES = ["Study"];

    private SchemaService $schemaService;

    public function __construct(SchemaService $schemaService)
    {
        $this->schemaService = $schemaService;
    }

    /**
     * Parse and validate a manifest file from a package directory.
     */
    public function parse(string $packageDir): array
    {
        // Get the full path to the manifest file
        $manifestPath = $this->getManifestPath($packageDir);

        try {
            // Load and parse the manifest file
            $manifestData = $this->loadManifestFile($manifestPath);

            // Validate the manifest structure
            $this->validateManifestStructure($manifestData);

            // Process and validate file entries
            $data = $this->processFileEntries($manifestData['files'], $packageDir);

            // Check required resource types
            $this->validateRequiredResourceTypes($data);

            return [
                'status' => 'SUCCESS',
                'message' => null,
                'data' => $data
            ];
        } catch (Exception $e) {
            return [
                'status' => 'FAIL',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Validate a manifest file
     */
    public function validate(string $manifestPath): void
    {
        // Get directory path from manifest path
        $packageDir = dirname($manifestPath);

        // Load and parse the manifest file
        $manifestData = $this->loadManifestFile($manifestPath);

        // Validate the manifest structure
        $this->validateManifestStructure($manifestData);

        // Process and validate file entries
        $this->processFileEntries($manifestData['files'], $packageDir);
    }

    /**
     * Get a list of files referenced in a manifest file
     */
    public function getFiles(string $manifestPath, bool $includeManifestFile = false): array
    {
        // Load and parse the manifest file
        $manifestData = $this->loadManifestFile($manifestPath);

        if (!isset($manifestData['files']) || !is_array($manifestData['files'])) {
            throw new Exception("Invalid manifest file format: no files section found");
        }

        // Extract all referenced file names
        $fileNames = [];
        foreach ($manifestData['files'] as $fileEntry) {
            if (!isset($fileEntry['file_name'])) {
                throw new Exception("Invalid file entry in manifest: missing file_name");
            }
            $fileNames[] = $fileEntry['file_name'];
        }

        // Include the manifest file itself if requested
        if ($includeManifestFile) {
            $fileNames[] = static::MANIFEST_FILE_NAME;
        }

        return $fileNames;
    }

    /**
     * Get the full path to the manifest file
     */
    private function getManifestPath(string $packageDir): string
    {
        return $packageDir . '/' . static::MANIFEST_FILE_NAME;
    }

    /**
     * Load and parse a manifest YAML file
     */
    private function loadManifestFile(string $manifestPath): array
    {
        // Check if the file exists
        if (!file_exists($manifestPath)) {
            throw new Exception("Manifest file not found: $manifestPath");
        }

        // Guard against YAML DoS: reject manifests larger than 1 MB
        if (filesize($manifestPath) > 1024 * 1024) {
            throw new Exception("Manifest file is too large (max 1 MB)");
        }

        // Parse the YAML file
        $manifestData = Yaml::parseFile($manifestPath);

        // Check if the parsing was successful
        if ($manifestData === null) {
            throw new Exception("Failed to parse manifest file");
        }

        return $manifestData;
    }

    /**
     * Validate the basic structure of the manifest data
     */
    private function validateManifestStructure(array $manifestData): void
    {
        if (!isset($manifestData['version']) || !isset($manifestData['files']) || !is_array($manifestData['files'])) {
            throw new Exception("Invalid manifest file format");
        }
    }

    /**
     * Process and validate file entries from the manifest
     */
    private function processFileEntries(array $fileEntries, string $packageDir): array
    {
        $supportedTypes = $this->schemaService->getResourceTypes();
        $data = [];

        foreach ($fileEntries as $fileEntry) {
            $this->validateFileEntry($fileEntry);

            $fileName = $fileEntry['file_name'];
            $resourceType = $fileEntry['resource_type'];

            $this->validateFileExists($packageDir, $fileName);
            $this->validateResourceType($resourceType, $supportedTypes, $fileName);

            $data[$resourceType] = $fileName;
        }

        return $data;
    }

    /**
     * Validate a single file entry structure
     */
    private function validateFileEntry(array $fileEntry): void
    {
        if (!isset($fileEntry['file_name']) || !isset($fileEntry['resource_type'])) {
            throw new Exception("Invalid file entry in manifest: missing required fields");
        }
    }

    /**
     * Check if a file exists in the package directory
     */
    private function validateFileExists(string $packageDir, string $fileName): void
    {
        $resolvedPackageDir = realpath($packageDir);
        $resolvedFilePath   = realpath($packageDir . '/' . $fileName);

        if ($resolvedFilePath === false) {
            throw new Exception("File not found: {$fileName}");
        }

        // Guard against path traversal
        if (
            $resolvedPackageDir === false
            || !str_starts_with($resolvedFilePath, $resolvedPackageDir . DIRECTORY_SEPARATOR)
        ) {
            throw new Exception("File path is outside the package directory: {$fileName}");
        }
    }

    /**
     * Validate the resource type for a file
     */
    private function validateResourceType(string $resourceType, array $supportedTypes, string $fileName): void
    {
        if (empty($resourceType)) {
            throw new Exception("Resource type not specified for file: {$fileName}");
        }

        if (!in_array($resourceType, $supportedTypes)) {
            throw new Exception("Invalid resource type ($resourceType) for file: {$fileName}");
        }
    }

    /**
     * Check if all required resource types are present
     */
    private function validateRequiredResourceTypes(array $data): void
    {
        foreach (static::REQUIRED_TYPES as $type) {
            if (!isset($data[$type])) {
                throw new Exception("Missing required resource type '$type'");
            }
        }
    }
}
