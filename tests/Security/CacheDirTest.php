<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the PHAR stub no longer uses a predictable, world-readable
 * shared-tmp cache directory, and that AppDataService enforces a private,
 * per-user cache directory instead.
 */
class CacheDirTest extends TestCase
{
    public function testStubDoesNotUseSharedPredictableCacheDir(): void
    {
        $sourceFile = dirname(__DIR__, 2) . '/bin/console.stub';
        self::assertFileExists($sourceFile);

        $source = file_get_contents($sourceFile);

        self::assertStringNotContainsString(
            "sys_get_temp_dir() . '/fega-cache'",
            $source,
            'console.stub must not use a predictable shared-tmp cache directory'
        );
        self::assertStringContainsString(
            'getCacheDirectory',
            $source,
            'console.stub must delegate cache-directory creation to AppDataService::getCacheDirectory()'
        );
    }

    public function testAppDataServiceEnforcesPrivateDirectoryMode(): void
    {
        $sourceFile = dirname(__DIR__, 2) . '/src/Service/AppDataService.php';
        self::assertFileExists($sourceFile);

        $source = file_get_contents($sourceFile);

        self::assertStringContainsString(
            '0700',
            $source,
            'AppDataService must create the cache directory with private (0700) permissions'
        );
        self::assertMatchesRegularExpression(
            '/posix_getuid|getmyuid/',
            $source,
            'AppDataService must scope the cache directory to the current user'
        );
    }
}
