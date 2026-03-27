<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\SchemaService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

/**
 * Unit tests for SchemaService.
 *
 * All tests operate against the fixture schemas in tests/Fixtures/Schemas/ so
 * they remain independent of the production schemas which change frequently.
 */
class SchemaServiceTest extends TestCase
{
    private string $fixtureSchemaDir;
    private SchemaService $service;

    protected function setUp(): void
    {
        $this->fixtureSchemaDir = dirname(__DIR__, 2) . '/Fixtures/Schemas';
        $params = new ParameterBag(['app.schema_dir' => $this->fixtureSchemaDir]);
        $this->service = new SchemaService($params);
    }

    public function testGetResourceTypesReturnsSortedListOfSchemaNames(): void
    {
        $types = $this->service->getResourceTypes();

        self::assertIsArray($types);
        self::assertContains('Study', $types);
        self::assertContains('Item', $types);

        // Must be alphabetically sorted
        $sorted = $types;
        sort($sorted);
        self::assertSame($sorted, $types);
    }

    public function testGetResourceTypesFromEmptyDirReturnsEmptyArray(): void
    {
        $emptyDir = sys_get_temp_dir() . '/fega-empty-schemas-' . bin2hex(random_bytes(4));
        mkdir($emptyDir, 0700, true);

        try {
            $params = new ParameterBag(['app.schema_dir' => $emptyDir]);
            $svc = new SchemaService($params);
            self::assertSame([], $svc->getResourceTypes());
        } finally {
            rmdir($emptyDir);
        }
    }

    public function testIsResourceTypeReturnsTrueForExistingSchema(): void
    {
        self::assertTrue($this->service->isResourceType('Study'));
        self::assertTrue($this->service->isResourceType('Item'));
    }

    public function testIsResourceTypeReturnsFalseForMissingSchema(): void
    {
        self::assertFalse($this->service->isResourceType('NonExistentType'));
    }

    public function testGetResourceSchemaReturnsSuccessForExistingSchema(): void
    {
        $result = $this->service->getResourceSchema('Study');

        self::assertSame('SUCCESS', $result['status']);
        self::assertArrayHasKey('schema', $result);
        self::assertIsArray($result['schema']);
    }

    public function testGetResourceSchemaReturnsFailForUnknownType(): void
    {
        $result = $this->service->getResourceSchema('DoesNotExist');

        self::assertSame('FAIL', $result['status']);
    }

    #[DataProvider('maliciousResourceTypeProvider')]
    public function testSanitizeResourceTypeRejectsMaliciousInput(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->isResourceType($input);
    }

    /** @return array<string, array{string}> */
    public static function maliciousResourceTypeProvider(): array
    {
        return [
            'shell injection'  => ['Study; rm -rf /'],
            'path traversal'   => ['../../etc/passwd'],
            'null byte'        => ["Study\0Inject"],
            'forward slash'    => ['Study/Evil'],
            'space'            => ['Study Evil'],
            'angle bracket'    => ['<script>'],
            'semicolon'        => ['Study;Evil'],
        ];
    }

    public function testGetTableSchemaReturnsXResourceSchema(): void
    {
        $tableSchema = $this->service->getTableSchema('Study');

        self::assertIsArray($tableSchema);
        self::assertArrayHasKey('fields', $tableSchema);
        self::assertArrayHasKey('primaryKey', $tableSchema);
    }

    public function testGetTableSchemaReturnsNullForUnknownType(): void
    {
        self::assertNull($this->service->getTableSchema('NonExistent'));
    }

    public function testCheckPrimaryKeyReturnsNoErrorsForUniquePks(): void
    {
        $tableSchema = ['primaryKey' => ['id']];
        $rows = [
            ['lineNumber' => 2, 'data' => ['id' => 'A']],
            ['lineNumber' => 3, 'data' => ['id' => 'B']],
        ];

        self::assertEmpty($this->service->checkPrimaryKey($rows, $tableSchema));
    }

    public function testCheckPrimaryKeyReturnsErrorForDuplicatePk(): void
    {
        $tableSchema = ['primaryKey' => ['id']];
        $rows = [
            ['lineNumber' => 2, 'data' => ['id' => 'DUPE']],
            ['lineNumber' => 3, 'data' => ['id' => 'DUPE']],
        ];

        $errors = $this->service->checkPrimaryKey($rows, $tableSchema);

        self::assertCount(1, $errors);
        self::assertSame(3, $errors[0]['lineNumber']);
        self::assertStringContainsString('Duplicate primary key', $errors[0]['message']);
    }

    public function testCheckPrimaryKeyWithNoPrimaryKeyDefinitionReturnsEmpty(): void
    {
        $tableSchema = [];
        $rows = [
            ['lineNumber' => 2, 'data' => ['id' => 'X']],
        ];

        self::assertEmpty($this->service->checkPrimaryKey($rows, $tableSchema));
    }

    public function testCheckUniqueKeysReturnsNoErrorsForUniqueValues(): void
    {
        $tableSchema = ['uniqueKeys' => [['label']]];
        $rows = [
            ['lineNumber' => 2, 'data' => ['label' => 'Alpha']],
            ['lineNumber' => 3, 'data' => ['label' => 'Beta']],
        ];

        self::assertEmpty($this->service->checkUniqueKeys($rows, $tableSchema));
    }

    public function testCheckUniqueKeysReturnsErrorForDuplicateUniqueValues(): void
    {
        $tableSchema = ['uniqueKeys' => [['label']]];
        $rows = [
            ['lineNumber' => 2, 'data' => ['label' => 'DUPE']],
            ['lineNumber' => 3, 'data' => ['label' => 'DUPE']],
        ];

        $errors = $this->service->checkUniqueKeys($rows, $tableSchema);

        self::assertCount(1, $errors);
        self::assertStringContainsString('Duplicate unique key', $errors[0]['message']);
    }

    public function testCheckForeignKeysPassesWhenReferencedResourceNotYetValidated(): void
    {
        // Item has a FK to Study, but if Study is not in validatedData yet,
        // the check is skipped (not an error).
        $rows = [
            ['lineNumber' => 2, 'data' => ['id' => 'i1', 'study_name' => 'ghost-study']],
        ];

        $errors = $this->service->checkForeignKeys($rows, 'Item', []);
        self::assertEmpty($errors);
    }

    public function testCheckForeignKeysPassesWhenReferenceExists(): void
    {
        $validatedStudies = [
            ['lineNumber' => 2, 'data' => ['name' => 'existing-study']],
        ];

        $rows = [
            ['lineNumber' => 3, 'data' => ['id' => 'i1', 'study_name' => 'existing-study']],
        ];

        $errors = $this->service->checkForeignKeys($rows, 'Item', ['Study' => $validatedStudies]);
        self::assertEmpty($errors);
    }

    public function testCheckForeignKeysReturnsErrorForMissingReference(): void
    {
        $validatedStudies = [
            ['lineNumber' => 2, 'data' => ['name' => 'known-study']],
        ];

        $rows = [
            ['lineNumber' => 3, 'data' => ['id' => 'i1', 'study_name' => 'unknown-study']],
        ];

        $errors = $this->service->checkForeignKeys($rows, 'Item', ['Study' => $validatedStudies]);
        self::assertNotEmpty($errors);
    }

    public function testComputeValidationOrderPlacesIndependentResourcesFirst(): void
    {
        // Item depends on Study through FK, so Study must come first.
        $resources = ['Item' => 'items.tsv', 'Study' => 'studies.tsv'];
        $order = $this->service->computeValidationOrder($resources);

        self::assertContains('Study', $order);
        self::assertContains('Item', $order);
        self::assertLessThan(
            array_search('Item', $order),
            array_search('Study', $order),
            'Study must be validated before Item'
        );
    }

    public function testExtractFieldTypesReturnsFieldTypeMap(): void
    {
        $schema = [
            'fields' => [
                ['name' => 'id', 'type' => 'string'],
                ['name' => 'count', 'type' => 'integer'],
                ['name' => 'tags', 'type' => 'list'],
                ['name' => 'plain'], // no type key → defaults to string
            ],
        ];

        $types = $this->service->extractFieldTypes($schema);

        self::assertSame('string', $types['id']);
        self::assertSame('integer', $types['count']);
        self::assertSame('list', $types['tags']);
        self::assertSame('string', $types['plain']);
    }
}
