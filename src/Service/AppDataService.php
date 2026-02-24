<?php

namespace App\Service;

use Symfony\Component\Filesystem\Path;

class AppDataService
{
    private string $appName = 'fega';

    public function getSchemaDirectory(): string
    {
        // Check if an environment variable is set
        $envSchemaDir = getenv('FEGA_SCHEMA_DIR');
        if ($envSchemaDir !== false && $envSchemaDir !== '') {
            return $envSchemaDir;
        }

        // Fall back to platform-specific directory
        $appDataDir = $this->getAppDataDirectory();
        return Path::join($appDataDir, 'schemas');
    }

    public function getAppDataDirectory(): string
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => Path::join(getenv('HOME'), 'Library', 'Application Support', ".{$this->appName}"),
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
