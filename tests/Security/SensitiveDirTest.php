<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Service\AppDataService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verifies that AppDataService refuses FEGA_SCHEMA_DIR values that resolve to known-sensitive system directories.
 */
class SensitiveDirTest extends TestCase
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

    #[DataProvider('exactSensitiveRootProvider')]
    public function testExactSensitiveRootIsRejected(string $path): void
    {
        putenv("FEGA_SCHEMA_DIR={$path}");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/sensitive system directory/i');

        (new AppDataService())->getSchemaDirectory();
    }

    /** @return array<string, array{string}> */
    public static function exactSensitiveRootProvider(): array
    {
        return [
            '/etc'     => ['/etc'],
            '/proc'    => ['/proc'],
            '/sys'     => ['/sys'],
            '/dev'     => ['/dev'],
            '/boot'    => ['/boot'],
            '/bin'     => ['/bin'],
            '/sbin'    => ['/sbin'],
            '/usr/bin' => ['/usr/bin'],
            '/usr/sbin' => ['/usr/sbin'],
        ];
    }

    #[DataProvider('sensitiveDirSubpathProvider')]
    public function testSensitiveSubpathIsRejected(string $path): void
    {
        putenv("FEGA_SCHEMA_DIR={$path}");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/sensitive system directory/i');

        (new AppDataService())->getSchemaDirectory();
    }

    /** @return array<string, array{string}> */
    public static function sensitiveDirSubpathProvider(): array
    {
        return [
            '/etc/ssh'           => ['/etc/ssh'],
            '/etc/cron.d'        => ['/etc/cron.d'],
            '/proc/self'         => ['/proc/self'],
            '/dev/null'          => ['/dev/null'],
            '/sys/kernel'        => ['/sys/kernel'],
            '/bin/schemas'       => ['/bin/schemas'],
        ];
    }

    #[DataProvider('safeDirProvider')]
    public function testSafeDirIsNotRejected(string $path): void
    {
        putenv("FEGA_SCHEMA_DIR={$path}");

        try {
            $result = (new AppDataService())->getSchemaDirectory();
            self::assertSame($path, $result);
        } catch (RuntimeException $e) {
            self::fail("Safe path '{$path}' was unexpectedly rejected: " . $e->getMessage());
        }
    }

    /** @return array<string, array{string}> */
    public static function safeDirProvider(): array
    {
        return [
            'tmp subdir'        => ['/tmp/my-schemas'],
            'home subdir'       => ['/home/user/schemas'],
            // /etc-adjacent names must NOT be treated as /etc
            '/etcetera'         => ['/etcetera'],
            '/etc-backup'       => ['/etc-backup'],
            '/procdata'         => ['/procdata'],
        ];
    }

    public function testEmptyEnvVarFallsThroughToPlatformDefault(): void
    {
        putenv('FEGA_SCHEMA_DIR=');

        $service = new AppDataService();
        $dir = $service->getSchemaDirectory();

        self::assertNotEmpty($dir);
        self::assertStringEndsWith('schemas', $dir);
    }
}
