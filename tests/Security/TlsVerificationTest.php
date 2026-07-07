<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Verifies TLS certificate/host verification is enabled and UpdateCommand
 * enforces an https:// scheme on the configured API base URL
 */
class TlsVerificationTest extends TestCase
{
    public function testFrameworkConfigDoesNotDisableTlsVerification(): void
    {
        $configFile = dirname(__DIR__, 2) . '/config/packages/framework.yaml';
        self::assertFileExists($configFile);

        $content = file_get_contents($configFile);

        self::assertDoesNotMatchRegularExpression(
            '/verify_peer\s*:\s*false/',
            $content,
            'framework.yaml must not disable verify_peer'
        );
        self::assertDoesNotMatchRegularExpression(
            '/verify_host\s*:\s*false/',
            $content,
            'framework.yaml must not disable verify_host'
        );
        self::assertMatchesRegularExpression(
            '/verify_peer\s*:\s*true/',
            $content,
            'framework.yaml must enable verify_peer'
        );
        self::assertMatchesRegularExpression(
            '/verify_host\s*:\s*true/',
            $content,
            'framework.yaml must enable verify_host'
        );
    }

    public function testUpdateCommandEnforcesHttpsScheme(): void
    {
        $sourceFile = dirname(__DIR__, 2) . '/src/Command/UpdateCommand.php';
        self::assertFileExists($sourceFile);

        $source = file_get_contents($sourceFile);

        self::assertStringContainsString(
            'PHP_URL_SCHEME',
            $source,
            'UpdateCommand must inspect the URL scheme of the configured API base URL'
        );
        self::assertStringContainsString(
            "!== 'https'",
            $source,
            'UpdateCommand must reject non-https API base URLs'
        );
    }
}
