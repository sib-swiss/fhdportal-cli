<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AppDataService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for AppDataService.
 *
 * Covers platform-specific directory resolution and rejection of FEGA_SCHEMA_DIR
 * values that point to sensitive system directories.
 */
class AppDataServiceTest extends TestCase
{
    private string $originalEnvValue;
    private bool $envWasSet;

    protected function setUp(): void
    {
        $current = getenv('FEGA_SCHEMA_DIR');
        $this->envWasSet = ($current !== false);
        $this->originalEnvValue = $current !== false ? $current : '';
    }

    protected function tearDown(): void
    {
        if ($this->envWasSet) {
            putenv("FEGA_SCHEMA_DIR={$this->originalEnvValue}");
        } else {
            putenv('FEGA_SCHEMA_DIR');
        }
    }

    public function testGetSchemaDirectoryReturnsEnvVarWhenSet(): void
    {
        $customPath = sys_get_temp_dir() . '/my-schemas';
        putenv("FEGA_SCHEMA_DIR={$customPath}");

        $service = new AppDataService();
        self::assertSame($customPath, $service->getSchemaDirectory());
    }

    public function testGetSchemaDirectoryFallsBackToPlatformDirWhenEnvNotSet(): void
    {
        putenv('FEGA_SCHEMA_DIR'); // unset

        $service = new AppDataService();
        $dir = $service->getSchemaDirectory();

        // Must not be empty and must end with "schemas"
        self::assertNotEmpty($dir);
        self::assertStringEndsWith('schemas', $dir);
    }

    #[DataProvider('sensitiveDirProvider')]
    public function testGetSchemaDirectoryRejectsSensitivePath(string $path): void
    {
        putenv("FEGA_SCHEMA_DIR={$path}");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/sensitive system directory/i');

        (new AppDataService())->getSchemaDirectory();
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
        putenv("FEGA_SCHEMA_DIR={$safePath}");

        $service = new AppDataService();
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
        $service = new AppDataService();
        self::assertNotEmpty($service->getCacheDirectory());
    }
}
