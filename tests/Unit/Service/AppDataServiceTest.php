<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AppDataService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Unit tests for AppDataService.
 *
 * Covers platform-specific directory resolution and rejection of FEGA_SCHEMA_DIR
 * values that point to sensitive system directories.
 */
class AppDataServiceTest extends TestCase
{
    public function testGetSchemaDirectoryReturnsEnvVarWhenSet(): void
    {
        $customPath = sys_get_temp_dir() . '/my-schemas';

        $service = new AppDataService($customPath);
        self::assertSame($customPath, $service->getSchemaDirectory());
    }

    public function testGetSchemaDirectoryFallsBackToPlatformDirWhenEnvNotSet(): void
    {
        $service = new AppDataService('');
        $dir = $service->getSchemaDirectory();

        // Must not be empty and must end with "schemas"
        self::assertNotEmpty($dir);
        self::assertStringEndsWith('schemas', $dir);
    }

    #[DataProvider('sensitiveDirProvider')]
    public function testGetSchemaDirectoryRejectsSensitivePath(string $path): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/sensitive system directory/i');

        (new AppDataService($path))->getSchemaDirectory();
    }

    /** @return array<string, array{string}> */
    public static function sensitiveDirProvider(): array
    {
        return [
            '/etc'          => ['/etc'],
            '/etc/passwd'   => ['/etc/passwd'],
            '/proc'         => ['/proc'],
            '/sys'          => ['/sys'],
            '/dev'          => ['/dev'],
            '/boot'         => ['/boot'],
            '/bin'          => ['/bin'],
            '/sbin'         => ['/sbin'],
            '/usr/bin'      => ['/usr/bin'],
            '/usr/sbin'     => ['/usr/sbin'],
            '/etc/subdirectory' => ['/etc/subdirectory'],
        ];
    }

    public function testGetSchemaDirectoryAllowsNormalUserPath(): void
    {
        $safePath = sys_get_temp_dir() . '/fega-schemas-test-' . bin2hex(random_bytes(4));

        $service = new AppDataService($safePath);
        // Should NOT throw
        self::assertSame($safePath, $service->getSchemaDirectory());
    }

    public function testGetAppDataDirectoryReturnsNonEmptyString(): void
    {
        $service = new AppDataService();
        self::assertNotEmpty($service->getAppDataDirectory());
    }

    public function testGetCacheDirectoryReturnsNonEmptyString(): void
    {
        [$restore, $tmpHome] = $this->withIsolatedHome();
        try {
            $service = new AppDataService();
            self::assertNotEmpty($service->getCacheDirectory());
        } finally {
            $restore();
            (new Filesystem())->remove($tmpHome);
        }
    }

    public function testGetCacheDirectoryCreatesPrivateModeDirectory(): void
    {
        [$restore, $tmpHome] = $this->withIsolatedHome();
        try {
            $dir = (new AppDataService())->getCacheDirectory();
            self::assertDirectoryExists($dir);
            self::assertStringStartsWith($tmpHome, $dir);
            if (PHP_OS_FAMILY !== 'Windows') {
                self::assertSame(0700, fileperms($dir) & 0777);
            }
        } finally {
            $restore();
            (new Filesystem())->remove($tmpHome);
        }
    }

    public function testGetCacheDirectoryIsScopedPerUserOnPosix(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('uid scoping does not apply on Windows');
        }

        [$restore, $tmpHome] = $this->withIsolatedHome();
        try {
            $dir = (new AppDataService())->getCacheDirectory();
            $uid = function_exists('posix_getuid') ? posix_getuid() : getmyuid();
            self::assertStringEndsWith((string) $uid, $dir);
        } finally {
            $restore();
            (new Filesystem())->remove($tmpHome);
        }
    }

    public function testGetCacheDirectoryReChmodsAPreExistingLooselyPermissionedDirectory(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('mode check does not apply on Windows');
        }

        [$restore, $tmpHome] = $this->withIsolatedHome();
        try {
            $service = new AppDataService();
            $dir = $service->getCacheDirectory();
            chmod($dir, 0777); // simulate a pre-planted/loosely-permissioned dir

            $dir2 = $service->getCacheDirectory();
            self::assertSame($dir, $dir2);
            self::assertSame(0700, fileperms($dir2) & 0777);
        } finally {
            $restore();
            (new Filesystem())->remove($tmpHome);
        }
    }

    /** @return array{0: callable, 1: string} */
    private function withIsolatedHome(): array
    {
        $tmpHome = sys_get_temp_dir() . '/fega-home-test-' . bin2hex(random_bytes(6));
        mkdir($tmpHome, 0700, true);

        $originalHome = getenv('HOME');
        $originalXdg = getenv('XDG_CACHE_HOME');

        putenv('HOME=' . $tmpHome);
        putenv('XDG_CACHE_HOME'); // unset, so the Linux default falls back to $HOME/.cache

        $restore = function () use ($originalHome, $originalXdg) {
            putenv($originalHome === false ? 'HOME' : "HOME=$originalHome");
            putenv($originalXdg === false ? 'XDG_CACHE_HOME' : "XDG_CACHE_HOME=$originalXdg");
        };

        return [$restore, $tmpHome];
    }
}
