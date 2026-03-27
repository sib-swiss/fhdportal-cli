<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\FileService;
use App\Service\ManifestService;
use App\Service\SchemaService;
use App\Service\ValidationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Unit tests for ValidationService.
 *
 * Uses the fixture schemas (tests/Fixtures/Schemas/) and fixture data files
 * (tests/Fixtures/) so results are independent of production schema changes.
 */
class ValidationServiceTest extends TestCase
{
    private string $fixtureSchemaDir;
    private ValidationService $service;
    private string $fixtureJsonDir;
    private string $fixtureTsvDir;
    private string $fixtureBundleDir;

    protected function setUp(): void
    {
        $fixturesRoot = dirname(__DIR__, 2) . '/Fixtures';
        $this->fixtureSchemaDir = $fixturesRoot . '/Schemas';
        $this->fixtureJsonDir = $fixturesRoot . '/Json';
        $this->fixtureTsvDir = $fixturesRoot . '/Tsv';
        $this->fixtureBundleDir = $fixturesRoot . '/Bundle';

        $params = new ParameterBag(['app.schema_dir' => $this->fixtureSchemaDir]);
        $schemaService = new SchemaService($params);
        $manifestService = new ManifestService($schemaService);
        $fileService = new FileService(new Filesystem());

        $this->service = new ValidationService($fileService, $manifestService, $schemaService);
    }

    public function testValidateResourceSucceedsForValidJsonFile(): void
    {
        $result = $this->service->validateResource(
            $this->fixtureJsonDir . '/valid_study.json',
            'Study'
        );

        self::assertSame('SUCCESS', $result['status']);
    }

    public function testValidateResourceFailsForInvalidJsonFile(): void
    {
        $result = $this->service->validateResource(
            $this->fixtureJsonDir . '/invalid_study_missing_name.json',
            'Study'
        );

        self::assertSame('FAIL', $result['status']);
        self::assertArrayHasKey('errors', $result);
    }

    public function testValidateResourceFailsForMalformedJson(): void
    {
        $result = $this->service->validateResource(
            $this->fixtureJsonDir . '/malformed.json',
            'Study'
        );

        self::assertSame('FAIL', $result['status']);
    }

    public function testValidateResourceFailsForUnsupportedExtension(): void
    {
        $result = $this->service->validateResource('/path/to/file.xml', 'Study');

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('xml', strtolower((string) $result['message']));
    }

    public function testValidateResourceFailsForUnknownResourceType(): void
    {
        $result = $this->service->validateResource(
            $this->fixtureJsonDir . '/valid_study.json',
            'NonExistentType'
        );

        self::assertSame('FAIL', $result['status']);
    }

    public function testValidateResourceSucceedsForValidJsonString(): void
    {
        $json = json_encode(['name' => 'inline-study-001']);
        $result = $this->service->validateResource($json, 'Study', false);

        self::assertSame('SUCCESS', $result['status']);
    }

    public function testValidateResourceFailsForEmptyJsonString(): void
    {
        $result = $this->service->validateResource('{}', 'Study', false);

        // An empty object is missing required fields → FAIL
        self::assertSame('FAIL', $result['status']);
    }

    public function testValidateResourcesSucceedsForValidTsvFile(): void
    {
        $result = $this->service->validateResources(
            $this->fixtureTsvDir . '/valid_studies.tsv',
            'Study'
        );

        self::assertSame('SUCCESS', $result['status']);
        self::assertGreaterThan(0, $result['totalRows']);
    }

    public function testValidateResourcesFailsForDuplicatePrimaryKey(): void
    {
        $result = $this->service->validateResources(
            $this->fixtureTsvDir . '/duplicate_primary_key.tsv',
            'Study'
        );

        self::assertSame('FAIL', $result['status']);
        // Confirm it identifies the duplicate
        $messages = implode(' ', array_column($result['errors'] ?? [], 'message'));
        self::assertStringContainsString('Duplicate primary key', $messages);
    }

    public function testValidateResourcesFailsForMissingRequiredField(): void
    {
        $result = $this->service->validateResources(
            $this->fixtureTsvDir . '/missing_required_field.tsv',
            'Study'
        );

        self::assertSame('FAIL', $result['status']);
    }

    public function testValidateResourcesFailsForWrongExtension(): void
    {
        $result = $this->service->validateResources('/path/to/file.json', 'Study');

        self::assertSame('FAIL', $result['status']);
    }

    public function testValidateBundleSucceedsForValidBundleDirectory(): void
    {
        $io = $this->makeNullIo();
        $result = $this->service->validateBundle($this->fixtureBundleDir . '/valid', $io);

        self::assertSame('SUCCESS', $result['status']);
        self::assertArrayHasKey('output', $result);
    }

    public function testValidateBundleFailsWhenManifestMissesRequiredStudy(): void
    {
        $io = $this->makeNullIo();

        $this->expectException(\RuntimeException::class);
        $this->service->validateBundle($this->fixtureBundleDir . '/no_required_study', $io);
    }

    public function testValidateBundleThrowsForMissingManifest(): void
    {
        $io = $this->makeNullIo();

        $this->expectException(\RuntimeException::class);
        $this->service->validateBundle(sys_get_temp_dir() . '/nonexistent-bundle-dir', $io);
    }

    public function testValidateModifiedResourceSucceedsForValidJson(): void
    {
        $json = json_encode(['name' => 'modified-resource-001']);
        $result = $this->service->validateModifiedResource($json, 'Study');

        self::assertSame('SUCCESS', $result['status']);
    }

    public function testValidateModifiedResourceFailsForInvalidJson(): void
    {
        $result = $this->service->validateModifiedResource('not-json', 'Study');

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('Invalid JSON', (string) $result['message']);
    }

    private function makeNullIo(): SymfonyStyle
    {
        return new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
    }
}
