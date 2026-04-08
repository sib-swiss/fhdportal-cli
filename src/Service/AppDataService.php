<?php

namespace App\Service;

use Symfony\Component\Filesystem\Path;

class AppDataService
{
    private string $appName = 'fega';
    private string $schemaDir;

    /**
     * Sensitive root directories that must never be used as a schema store.
     */
    private const SENSITIVE_ROOTS = ['/etc', '/proc', '/sys', '/dev', '/boot', '/bin', '/sbin', '/usr/bin', '/usr/sbin'];

    public function __construct(string $schemaDir = '')
    {
        $this->schemaDir = $schemaDir;
    }

    public function getSchemaDirectory(): string
    {
        if ($this->schemaDir !== '') {
            $this->assertSafeSchemaDir($this->schemaDir);
            return $this->schemaDir;
        }

        // Fall back to platform-specific directory
        $appDataDir = $this->getAppDataDirectory();
        return Path::join($appDataDir, 'schemas');
    }

    /**
     * Reject FEGA_SCHEMA_DIR values that point at sensitive system directories.
     *
     * @throws \RuntimeException if the path resolves to a known-sensitive location
     */
    private function assertSafeSchemaDir(string $path): void
    {
        // Use realpath if the directory already exists, otherwise evaluate the raw path
        $resolved = realpath($path) ?: $path;
        // Normalize to forward slashes for cross-platform consistency
        $resolved = str_replace('\\', '/', rtrim($resolved, '/\\'));

        foreach (self::SENSITIVE_ROOTS as $root) {
            // Check against both the raw root and its realpath
            $rawRoot = rtrim($root, '/');
            $resolvedRoot = str_replace('\\', '/', rtrim(realpath($root) ?: $root, '/'));

            foreach ([$rawRoot, $resolvedRoot] as $checkRoot) {
                if ($resolved === $checkRoot || str_starts_with($resolved, $checkRoot . '/')) {
                    throw new \RuntimeException(
                        "FEGA_SCHEMA_DIR '$path' points to a sensitive system directory and cannot be used."
                    );
                }
            }
        }
    }

    public function getAppDataDirectory(): string
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => Path::join(getenv('HOME'), 'Library', 'Application Support', $this->appName),
            'Windows' => Path::join(getenv('LOCALAPPDATA'), $this->appName),
            default => Path::join(getenv('HOME'), ".{$this->appName}")
        };
    }

    public function getCacheDirectory(): string
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => Path::join(getenv('HOME'), 'Library', 'Caches', $this->appName),
            'Windows' => Path::join(getenv('TEMP'), $this->appName . '-cache'),
            default => Path::join(sys_get_temp_dir(), $this->appName . '-cache')
        };
    }
}
