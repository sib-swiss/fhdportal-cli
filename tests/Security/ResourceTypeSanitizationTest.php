<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Service\SchemaService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

/**
 * Verifies that SchemaService rejects resource type strings containing characters outside the safe [A-Za-z0-9_-] set.
 */
class ResourceTypeSanitizationTest extends TestCase
{
    private SchemaService $service;

    protected function setUp(): void
    {
        $fixtureSchemaDir = dirname(__DIR__) . '/Fixtures/Schemas';
        $params = new ParameterBag(['app.schema_dir' => $fixtureSchemaDir]);
        $this->service = new SchemaService($params);
    }

    #[DataProvider('unsafeResourceTypeProvider')]
    public function testUnsafeResourceTypeIsRejected(string $input, string $reason): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/[Ii]nvalid resource type/');

        $this->service->isResourceType($input);
    }

    /** @return array<string, array{string, string}> */
    public static function unsafeResourceTypeProvider(): array
    {
        return [
            'shell semicolon'       => ['Study; cat /etc/passwd', 'shell command injection'],
            'shell pipe'            => ['Study | evil', 'pipe injection'],
            'shell backtick'        => ['Study`whoami`', 'backtick injection'],
            'dollar variable'       => ['Study$HOME', 'variable expansion'],
            'path traversal dots'   => ['../../../etc/passwd', 'directory traversal'],
            'forward slash'         => ['Study/Evil', 'path separator'],
            'backslash'             => ['Study\\Evil', 'backslash separator'],
            'null byte'             => ["Study\x00Evil", 'null byte injection'],
            'newline'               => ["Study\nEvil", 'newline injection'],
            'carriage return'       => ["Study\rEvil", 'CR injection'],
            'spaces'                => ['Study Evil', 'space in name'],
            'angle brackets'        => ['<script>alert(1)</script>', 'HTML/XSS injection'],
            'single quote'          => ["Study'Evil", 'SQL-style injection'],
            'double quote'          => ['Study"Evil', 'quote injection'],
        ];
    }

    #[DataProvider('safeResourceTypeProvider')]
    public function testSafeResourceTypeDoesNotThrow(string $input): void
    {
        // Should not throw InvalidArgumentException even if the type does not exist
        try {
            $this->service->isResourceType($input);
        } catch (InvalidArgumentException $e) {
            self::fail("Safe resource type '{$input}' was unexpectedly rejected: " . $e->getMessage());
        }

        $this->addToAssertionCount(1);
    }

    /** @return array<string, array{string}> */
    public static function safeResourceTypeProvider(): array
    {
        return [
            'simple name'           => ['Study'],
            'camel case'            => ['MolecularExperiment'],
            'with digits'           => ['Resource2024'],
            'with hyphen'           => ['My-Resource'],
            'with underscore'       => ['My_Resource'],
            'all lowercase'         => ['dataset'],
            'all uppercase'         => ['DATASET'],
        ];
    }

    public function testGetResourceSchemaAlsoRejectsMaliciousInput(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->getResourceSchema('../../../etc/passwd');
    }

    public function testGetTableSchemaDoesNotThrowForValidType(): void
    {
        // Valid type that exists → should return array or null, never throw
        $result = $this->service->getTableSchema('Study');
        self::assertIsArray($result);
    }
}
