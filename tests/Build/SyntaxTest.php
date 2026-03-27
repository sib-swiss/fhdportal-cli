<?php

declare(strict_types=1);

namespace App\Tests\Build;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Build tests — PHP syntax and autoloading.
 *
 * Verifies that all source files parse without syntax errors and that the
 * Composer autoloader can instantiate the service layer classes.
 */
class SyntaxTest extends TestCase
{
    private string $srcDir;

    protected function setUp(): void
    {
        $this->srcDir = dirname(__DIR__, 2) . '/src';
    }

    #[DataProvider('phpFileProvider')]
    public function testPhpFileParsesWithoutSyntaxError(string $filePath): void
    {
        // php -l exits with code 0 on success, 255 on syntax error
        exec(PHP_BINARY . ' -l ' . escapeshellarg($filePath) . ' 2>&1', $output, $exitCode);

        self::assertSame(
            0,
            $exitCode,
            "Syntax error in {$filePath}:\n" . implode("\n", $output)
        );
    }

    /** @return iterable<string, array{string}> */
    public static function phpFileProvider(): iterable
    {
        $srcDir = dirname(__DIR__, 2) . '/src';
        $finder = (new Finder())->files()->in($srcDir)->name('*.php');

        foreach ($finder as $file) {
            $relPath = str_replace($srcDir . '/', '', $file->getRealPath());
            yield $relPath => [$file->getRealPath()];
        }
    }

    #[DataProvider('serviceClassProvider')]
    public function testServiceClassIsAutoloadable(string $fqcn): void
    {
        self::assertTrue(
            class_exists($fqcn),
            "Class '{$fqcn}' is not autoloadable. Check composer.json autoload config."
        );
    }

    /** @return array<string, array{string}> */
    public static function serviceClassProvider(): array
    {
        return [
            'FileService'       => ['App\Service\FileService'],
            'AppDataService'    => ['App\Service\AppDataService'],
            'SchemaService'     => ['App\Service\SchemaService'],
            'ManifestService'   => ['App\Service\ManifestService'],
            'ValidationService' => ['App\Service\ValidationService'],
        ];
    }

    #[DataProvider('commandClassProvider')]
    public function testCommandClassIsAutoloadable(string $fqcn): void
    {
        self::assertTrue(
            class_exists($fqcn),
            "Command class '{$fqcn}' is not autoloadable."
        );
    }

    /** @return array<string, array{string}> */
    public static function commandClassProvider(): array
    {
        return [
            'ValidateCommand'  => ['App\Command\ValidateCommand'],
            'UpdateCommand'    => ['App\Command\UpdateCommand'],
            'TemplateCommand'  => ['App\Command\TemplateCommand'],
            'DocumentCommand'  => ['App\Command\DocumentCommand'],
            'BundleCommand'    => ['App\Command\BundleCommand'],
        ];
    }

    public function testConsoleBinFileExists(): void
    {
        $consolePath = dirname(__DIR__, 2) . '/bin/console';
        self::assertFileExists($consolePath);
    }

    public function testConsoleBinIsExecutable(): void
    {
        $consolePath = dirname(__DIR__, 2) . '/bin/console';
        self::assertTrue(
            is_executable($consolePath),
            'bin/console must be executable (run chmod +x bin/console)'
        );
    }

    public function testComposerLockFileExists(): void
    {
        self::assertFileExists(dirname(__DIR__, 2) . '/composer.lock');
    }

    public function testVendorAutoloadFileExists(): void
    {
        self::assertFileExists(dirname(__DIR__, 2) . '/vendor/autoload.php');
    }

    #[DataProvider('fixtureSchemaProvider')]
    public function testFixtureSchemaIsValidJson(string $path): void
    {
        $content = file_get_contents($path);
        $decoded = json_decode($content, true);

        self::assertSame(
            JSON_ERROR_NONE,
            json_last_error(),
            "Fixture schema '{$path}' is not valid JSON: " . json_last_error_msg()
        );
        self::assertIsArray($decoded);
    }

    /** @return iterable<string, array{string}> */
    public static function fixtureSchemaProvider(): iterable
    {
        $schemaDir = dirname(__DIR__) . '/Fixtures/Schemas';
        $finder = (new Finder())->files()->in($schemaDir)->name('*.json');

        foreach ($finder as $file) {
            yield $file->getFilename() => [$file->getRealPath()];
        }
    }
}
