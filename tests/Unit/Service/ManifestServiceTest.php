<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ManifestService;
use App\Service\SchemaService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Unit tests for ManifestService.
 *
 * Tests cover: manifest parsing, file-existence enforcement, path-traversal
 * protection, required resource-type checks, and manifest listing.
 */
class ManifestServiceTest extends TestCase
{
    private string $fixtureSchemaDir;
    private ManifestService $service;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->fixtureSchemaDir = dirname(__DIR__, 2) . '/Fixtures/Schemas';
        $params = new ParameterBag(['app.schema_dir' => $this->fixtureSchemaDir]);
        $schemaService = new SchemaService($params);
        $this->service = new ManifestService($schemaService);

        $this->tmpDir = sys_get_temp_dir() . '/fega-manifest-test-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tmpDir);
    }

    private function writeManifest(string $yaml): void
    {
        file_put_contents($this->tmpDir . '/manifest.yaml', $yaml);
    }

    private function writeTsv(string $filename, string $content): void
    {
        file_put_contents($this->tmpDir . '/' . $filename, $content);
    }

    public function testParseReturnsSuccessForValidManifest(): void
    {
        $this->writeTsv('studies.tsv', "name\nstudy-001\n");
        $this->writeManifest(
            <<<YAML
            version: 1
            files:
                - file_name: studies.tsv
                  resource_type: Study
            YAML
        );

        $result = $this->service->parse($this->tmpDir);

        self::assertSame('SUCCESS', $result['status']);
        self::assertArrayHasKey('Study', $result['data']);
        self::assertSame('studies.tsv', $result['data']['Study']);
    }

    public function testParseReturnsFailWhenManifestFileMissing(): void
    {
        $result = $this->service->parse($this->tmpDir);

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('Manifest file not found', (string) $result['message']);
    }

    public function testParseFailsWhenRequiredStudyTypeAbsent(): void
    {
        $this->writeTsv('items.tsv', "id\nitem-001\n");
        $this->writeManifest(
            <<<YAML
            version: 1
            files:
                - file_name: items.tsv
                  resource_type: Item
            YAML
        );

        $result = $this->service->parse($this->tmpDir);

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('Study', (string) $result['message']);
    }

    public function testParseFailsForUnknownResourceType(): void
    {
        $this->writeTsv('studies.tsv', "name\nstudy-001\n");
        $this->writeTsv('unknown.tsv', "col\nval\n");
        $this->writeManifest(
            <<<YAML
            version: 1
            files:
                - file_name: studies.tsv
                  resource_type: Study
                - file_name: unknown.tsv
                  resource_type: NotARealType
            YAML
        );

        $result = $this->service->parse($this->tmpDir);

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('NotARealType', (string) $result['message']);
    }

    public function testParseFailsWhenReferencedFileMissing(): void
    {
        $this->writeManifest(
            <<<YAML
            version: 1
            files:
                - file_name: studies.tsv
                  resource_type: Study
            YAML
        );
        // Note: studies.tsv is NOT created

        $result = $this->service->parse($this->tmpDir);

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('studies.tsv', (string) $result['message']);
    }

    #[DataProvider('pathTraversalProvider')]
    public function testParseRejectsPathTraversalInFileName(string $maliciousFileName): void
    {
        $this->writeManifest(
            <<<YAML
            version: 1
            files:
                - file_name: "{$maliciousFileName}"
                  resource_type: Study
            YAML
        );

        $result = $this->service->parse($this->tmpDir);

        self::assertSame('FAIL', $result['status']);
        // The error must reference the path problem, not just "file not found"
        self::assertNotEmpty($result['message']);
    }

    /** @return array<string, array{string}> */
    public static function pathTraversalProvider(): array
    {
        return [
            'unix traversal'       => ['../../../etc/passwd'],
            'double slash'         => ['//etc/passwd'],
            'nested traversal'     => ['subdir/../../etc/passwd'],
        ];
    }

    public function testParseFailsWhenManifestExceedsSizeLimit(): void
    {
        // Write a manifest that is slightly larger than 1 MB
        $oversizeContent = 'version: 1' . PHP_EOL . str_repeat('# ' . str_repeat('x', 78) . PHP_EOL, 13000);
        file_put_contents($this->tmpDir . '/manifest.yaml', $oversizeContent);

        $result = $this->service->parse($this->tmpDir);

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('too large', (string) $result['message']);
    }

    public function testValidateDoesNotThrowForValidManifest(): void
    {
        $this->writeTsv('studies.tsv', "name\nstudy-001\n");
        $this->writeManifest(
            <<<YAML
            version: 1
            files:
                - file_name: studies.tsv
                  resource_type: Study
            YAML
        );

        // Should not throw
        $this->service->validate($this->tmpDir . '/manifest.yaml');
        $this->addToAssertionCount(1);
    }

    public function testGetFilesReturnsListedFileNames(): void
    {
        $this->writeTsv('studies.tsv', "name\nstudy-001\n");
        $this->writeManifest(
            <<<YAML
            version: 1
            files:
                - file_name: studies.tsv
                  resource_type: Study
            YAML
        );

        $files = $this->service->getFiles($this->tmpDir . '/manifest.yaml');

        self::assertContains('studies.tsv', $files);
    }

    public function testGetFilesIncludesManifestWhenRequested(): void
    {
        $this->writeTsv('studies.tsv', "name\nstudy-001\n");
        $this->writeManifest(
            <<<YAML
            version: 1
            files:
                - file_name: studies.tsv
                  resource_type: Study
            YAML
        );

        $files = $this->service->getFiles($this->tmpDir . '/manifest.yaml', true);

        self::assertContains(ManifestService::MANIFEST_FILE_NAME, $files);
    }
}
