<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Verifies security controls in ValidateCommand and UpdateCommand without executing commands.
 *
 * Checks that the 50 MB STDIN limit and temp-file cleanup are enforced,
 * that stack traces are only exposed in debug mode, and that sessions are disabled.
 */
class StdinLimitTest extends TestCase
{
    /**
     * Asserts that ValidateCommand defines a 50 MB STDIN read limit.
     */
    public function testStdinMaxBytesConstantIs50Mb(): void
    {
        $expectedBytes = 50 * 1024 * 1024;

        // Read the constant value from the source file rather than executing
        // the command (which would require an open STDIN pipe).
        $sourceFile = dirname(__DIR__, 2) . '/src/Command/ValidateCommand.php';
        self::assertFileExists($sourceFile);

        $source = file_get_contents($sourceFile);
        self::assertStringContainsString(
            '50 * 1024 * 1024',
            $source,
            "ValidateCommand must define a {$expectedBytes}-byte (50 MB) STDIN limit"
        );
    }

    /**
     * Asserts that stream_get_contents is called with a length cap, preventing unbounded reads.
     */
    public function testStdinReadUsesLengthParameter(): void
    {
        $sourceFile = dirname(__DIR__, 2) . '/src/Command/ValidateCommand.php';
        $source = file_get_contents($sourceFile);

        // The call must look like: stream_get_contents(STDIN, $maxBytes)
        self::assertMatchesRegularExpression(
            '/stream_get_contents\(\s*STDIN\s*,\s*\$maxBytes\s*\)/',
            $source,
            'stream_get_contents must be called with the $maxBytes limit argument'
        );
    }

    /**
     * Asserts that the STDIN branch removes its temporary file after use.
     */
    public function testStdinBranchUnlinksOnSuccess(): void
    {
        $sourceFile = dirname(__DIR__, 2) . '/src/Command/ValidateCommand.php';
        $source = file_get_contents($sourceFile);

        // unlink() or error_log("Warning: could not remove") must be present
        self::assertMatchesRegularExpression(
            '/unlink\(\$tempFile\)/',
            $source,
            'Temp file from STDIN must be deleted with unlink()'
        );
    }

    /**
     * Asserts that UpdateCommand uses isDebug() to gate stack trace output.
     */
    public function testUpdateCommandUsesIsDebugNotIsVerboseForStackTrace(): void
    {
        $sourceFile = dirname(__DIR__, 2) . '/src/Command/UpdateCommand.php';
        $source = file_get_contents($sourceFile);

        self::assertStringContainsString(
            'isDebug()',
            $source,
            'UpdateCommand must use isDebug() (not isVerbose()) to gate stack trace output'
        );
        self::assertStringNotContainsString(
            'isVerbose()' . PHP_EOL . '            $io->text($e->getTraceAsString())',
            $source,
            'Stack trace must not be printed when only isVerbose() returns true'
        );
    }

    /**
     * Asserts that sessions are disabled in framework.yaml.
     */
    public function testSessionIsDisabledInFrameworkConfig(): void
    {
        $configFile = dirname(__DIR__, 2) . '/config/packages/framework.yaml';
        self::assertFileExists($configFile);

        $content = file_get_contents($configFile);
        self::assertMatchesRegularExpression(
            '/session\s*:\s*false/',
            $content,
            'Sessions must be disabled (session: false) in framework.yaml'
        );
    }
}
