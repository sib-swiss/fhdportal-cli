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
        $isWindows = PHP_OS_FAMILY === 'Windows';

        $baseDir = match (PHP_OS_FAMILY) {
            'Darwin' => Path::join(getenv('HOME'), 'Library', 'Caches', $this->appName),
            'Windows' => Path::join(getenv('LOCALAPPDATA') ?: getenv('TEMP'), $this->appName . '-cache'),
            default => Path::join(getenv('XDG_CACHE_HOME') ?: Path::join(getenv('HOME'), '.cache'), $this->appName),
        };

        // Scope to the current OS user so the path cannot be predicted or
        // pre-planted by another local user (CWE-377/379/427).
        $cacheDir = $isWindows ? $baseDir : Path::join($baseDir, (string) $this->currentUid());

        $this->ensurePrivateDirectory($cacheDir, $isWindows);

        return $cacheDir;
    }

    private function currentUid(): ?int
    {
        if (function_exists('posix_getuid')) {
            return posix_getuid();
        }
        if (function_exists('getmyuid') && getmyuid() !== false) {
            return (int) getmyuid();
        }
        return null;
    }

    /**
     * @throws \RuntimeException if the directory cannot be created privately
     *                            or is not owned by the current user
     */
    private function ensurePrivateDirectory(string $dir, bool $isWindows): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create private cache directory: $dir");
        }

        if ($isWindows) {
            return; // POSIX ownership is not meaningful here
        }

        @chmod($dir, 0700);

        $uid = $this->currentUid();
        $stat = @stat($dir);
        if ($uid !== null && ($stat === false || $stat['uid'] !== $uid)) {
            throw new \RuntimeException("Refusing to use cache directory not owned by current user: $dir");
        }
    }
}
