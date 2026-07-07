<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Integration tests for the `document` console command.
 *
 * The kernel boots in the "test" environment, which points FEGA_SCHEMA_DIR at
 * tests/Fixtures/Schemas/ — including XssFixture.json, a schema deliberately
 * containing HTML/script payloads.
 */
class DocumentCommandTest extends KernelTestCase
{
    private CommandTester $tester;
    private string $outputDir;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        $this->tester = new CommandTester($application->find('document'));

        $this->outputDir = sys_get_temp_dir() . '/fega-doc-test-' . bin2hex(random_bytes(6));
        mkdir($this->outputDir, 0700, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->outputDir);
    }

    public function testHtmlOutputEscapesScriptTagFromSchemaDescription(): void
    {
        $outputFile = $this->outputDir . '/schemas.html';
        $exitCode = $this->tester->execute(['--output-format' => 'html', '--output-file' => $outputFile]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFileExists($outputFile);

        $html = file_get_contents($outputFile);
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testHtmlOutputKeepsPrimaryKeyUnderlineFormatting(): void
    {
        $outputFile = $this->outputDir . '/schemas.html';
        $this->tester->execute(['--output-format' => 'html', '--output-file' => $outputFile]);

        self::assertStringContainsString('<u>', file_get_contents($outputFile));
    }

    public function testMarkdownOutputEscapesHtmlFromSchemaDescription(): void
    {
        $outputFile = $this->outputDir . '/schemas.md';
        $exitCode = $this->tester->execute(['--output-format' => 'md', '--output-file' => $outputFile]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('&lt;script&gt;', file_get_contents($outputFile));
    }
}
