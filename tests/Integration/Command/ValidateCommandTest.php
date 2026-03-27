<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\ValidateCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Integration tests for the `validate` console command.
 *
 * The kernel boots in the "test" environment, which points FEGA_SCHEMA_DIR at
 * tests/Fixtures/Schemas/ so results are independent of production schemas.
 */
class ValidateCommandTest extends KernelTestCase
{
    private CommandTester $tester;
    private string $fixtureBundleDir;
    private string $fixtureJsonDir;
    private string $fixtureTsvDir;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        $application = new Application($kernel);
        $command = $application->find('validate');
        $this->tester = new CommandTester($command);

        $fixturesRoot = dirname(__DIR__, 2) . '/Fixtures';
        $this->fixtureBundleDir = $fixturesRoot . '/Bundle';
        $this->fixtureJsonDir = $fixturesRoot . '/Json';
        $this->fixtureTsvDir = $fixturesRoot . '/Tsv';
    }

    public function testValidateCommandIsRegistered(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        self::assertTrue($application->has('validate'));
    }

    public function testReturnsInvalidExitCodeForNonExistentPath(): void
    {
        $exitCode = $this->tester->execute(
            ['target-path' => '/this/path/does/not/exist/' . uniqid()]
        );

        self::assertSame(Command::INVALID, $exitCode);
    }

    public function testValidJsonFileReturnsSuccess(): void
    {
        $exitCode = $this->tester->execute([
            'target-path' => $this->fixtureJsonDir . '/valid_study.json',
            '--resource-type' => 'Study',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    public function testInvalidJsonFileReturnsFailure(): void
    {
        $exitCode = $this->tester->execute([
            'target-path' => $this->fixtureJsonDir . '/invalid_study_missing_name.json',
            '--resource-type' => 'Study',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
    }

    public function testJsonOutputFormatProducesValidJsonForSuccess(): void
    {
        $this->tester->execute([
            'target-path' => $this->fixtureJsonDir . '/valid_study.json',
            '--resource-type' => 'Study',
            '--output-format' => 'json',
        ]);

        $output = $this->tester->getDisplay();
        $decoded = json_decode($output, true);

        self::assertNotNull($decoded, 'Expected valid JSON output, got: ' . $output);
        self::assertSame('SUCCESS', $decoded['status'] ?? null);
    }

    public function testJsonOutputFormatProducesValidJsonForFailure(): void
    {
        $this->tester->execute([
            'target-path' => $this->fixtureJsonDir . '/invalid_study_missing_name.json',
            '--resource-type' => 'Study',
            '--output-format' => 'json',
        ]);

        $output = $this->tester->getDisplay();
        $decoded = json_decode($output, true);

        self::assertNotNull($decoded, 'Expected valid JSON output, got: ' . $output);
        self::assertSame('FAIL', $decoded['status'] ?? null);
    }

    public function testValidTsvFileReturnsSuccess(): void
    {
        $exitCode = $this->tester->execute([
            'target-path' => $this->fixtureTsvDir . '/valid_studies.tsv',
            '--resource-type' => 'Study',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    public function testTsvWithDuplicatePkReturnsFailure(): void
    {
        $exitCode = $this->tester->execute([
            'target-path' => $this->fixtureTsvDir . '/duplicate_primary_key.tsv',
            '--resource-type' => 'Study',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
    }

    public function testValidBundleDirectoryReturnsSuccess(): void
    {
        $exitCode = $this->tester->execute([
            'target-path' => $this->fixtureBundleDir . '/valid',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    public function testBundleMissingRequiredStudyReturnsFailure(): void
    {
        $exitCode = $this->tester->execute([
            'target-path' => $this->fixtureBundleDir . '/no_required_study',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
    }

    public function testValidBundleZipReturnsSuccess(): void
    {
        // Build a ZIP from the valid bundle fixture directory
        $zipPath = sys_get_temp_dir() . '/fega-test-bundle-' . bin2hex(random_bytes(4)) . '.zip';

        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $bundleDir = $this->fixtureBundleDir . '/valid';

        foreach (['manifest.yaml', 'studies.tsv'] as $file) {
            $zip->addFile($bundleDir . '/' . $file, $file);
        }
        $zip->close();

        try {
            $exitCode = $this->tester->execute(['target-path' => $zipPath]);
            self::assertSame(Command::SUCCESS, $exitCode);
        } finally {
            @unlink($zipPath);
        }
    }

    public function testUnsupportedFileTypeReturnsInvalid(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'fega_') . '.xml';
        rename(str_replace('.xml', '', $tmpFile), $tmpFile);
        file_put_contents($tmpFile, '<root/>');

        try {
            $exitCode = $this->tester->execute([
                'target-path' => $tmpFile,
                '--resource-type' => 'Study',
            ]);
            self::assertSame(Command::INVALID, $exitCode);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testVerboseFlagDoesNotLeakStackTraces(): void
    {
        $this->tester->execute(
            [
                'target-path' => $this->fixtureJsonDir . '/valid_study.json',
                '--resource-type' => 'Study',
            ],
            ['verbosity' => \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE]
        );

        $output = $this->tester->getDisplay();
        // A stack trace would contain "Stack trace:" or "#0 "
        self::assertStringNotContainsString('#0 ', $output, 'Stack trace must not appear in verbose (-v) output');
        self::assertStringNotContainsString('Stack trace:', $output);
    }
}
