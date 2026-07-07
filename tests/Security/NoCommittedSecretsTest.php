<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Verifies committed .env* files never declare a non-empty secret value
 */
class NoCommittedSecretsTest extends TestCase
{
    public function testTrackedEnvFilesHaveNoNonEmptySecretValues(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['.env', '.env.dev', '.env.test'] as $file) {
            $path = "$root/$file";
            self::assertFileExists($path);

            foreach (explode("\n", file_get_contents($path)) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                if (!preg_match('/^([A-Z0-9_]+)\s*=\s*(.*)$/', $line, $matches)) {
                    continue;
                }

                if (!preg_match('/(?:_SECRET|_TOKEN|_KEY)$/', $matches[1])) {
                    continue;
                }

                $value = trim($matches[2], "'\" \t");
                self::assertSame(
                    '',
                    $value,
                    "Committed file '$file' must not define a non-empty value for '{$matches[1]}'"
                );
            }
        }
    }
}
