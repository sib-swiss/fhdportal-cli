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

    public function testMapFieldsWithDotNotationBuildsNestedObject(): void
    {
        $tableSchema = $this->service->getTableSchema('NestingFixture');
        $input = [
            'title'                => 'run-001',
            'dimension_extents.x'  => '1024',
            'dimension_extents.y'  => '768',
        ];

        $result = $this->service->mapFields($input, $tableSchema, 'NestingFixture');

        self::assertSame('run-001', $result['title']);
        self::assertIsArray($result['dimension_extents']);
        self::assertSame(1024, $result['dimension_extents']['x']);
        self::assertSame(768, $result['dimension_extents']['y']);
        self::assertArrayNotHasKey('dimension_extents.x', $result);
    }

    public function testMapFieldsWithBracketNotationBuildsArray(): void
    {
        $tableSchema = $this->service->getTableSchema('NestingFixture');
        $input = [
            'title'                               => 'exp-001',
            'channels[0].channel_content'         => 'OPAL 620',
            'channels[0].channel_biological_entity' => 'CD11c',
            'channels[1].channel_content'         => 'OPAL 520',
            'channels[1].channel_biological_entity' => 'CD163',
        ];

        $result = $this->service->mapFields($input, $tableSchema, 'NestingFixture');

        self::assertIsArray($result['channels']);
        self::assertCount(2, $result['channels']);
        self::assertSame('OPAL 620', $result['channels'][0]['channel_content']);
        self::assertSame('CD11c', $result['channels'][0]['channel_biological_entity']);
        self::assertSame('OPAL 520', $result['channels'][1]['channel_content']);
        self::assertStringStartsWith('[', json_encode($result['channels']));
    }

    public function testMapFieldsWithDeepNestingBuildsDeepObject(): void
    {
        $tableSchema = $this->service->getTableSchema('NestingFixture');
        $input = [
            'title'                       => 'run-deep',
            'size_description.x.value'    => '14.76',
            'size_description.x.unit'     => 'µm',
        ];

        $result = $this->service->mapFields($input, $tableSchema, 'NestingFixture');

        self::assertIsArray($result['size_description']);
        self::assertIsArray($result['size_description']['x']);
        self::assertSame(14.76, $result['size_description']['x']['value']);
        self::assertSame('µm', $result['size_description']['x']['unit']);
    }

    public function testMapFieldsPlainFieldsUnaffectedByUnflattening(): void
    {
        $tableSchema = $this->service->getTableSchema('Study');
        $input = ['name' => 'study-001', 'description' => 'A study'];

        $result = $this->service->mapFields($input, $tableSchema, 'Study');

        self::assertSame('study-001', $result['name']);
        self::assertSame('A study', $result['description']);
        self::assertCount(2, $result);
    }

    #[DataProvider('validColumnNameProvider')]
    public function testIsValidColumnNameAcceptsValidNames(string $name): void
    {
        self::assertTrue($this->service->isValidColumnName($name));
    }

    /** @return array<string, array{string}> */
    public static function validColumnNameProvider(): array
    {
        return [
            'plain'                   => ['title'],
            'underscore'              => ['study_id'],
            'hyphen'                  => ['format-and-compression'],
            'structured dot'          => ['dimension_extents.x'],
            'structured deep dot'     => ['size_description.x.value'],
            'structured bracket'      => ['channels[0].channel_content'],
            'bracket only'            => ['arr[0]'],
            'max index'               => ['arr[9999].field'],
            'single char'             => ['x'],
        ];
    }

    #[DataProvider('invalidColumnNameProvider')]
    public function testIsValidColumnNameRejectsInvalidNames(string $name): void
    {
        self::assertFalse($this->service->isValidColumnName($name));
    }

    /** @return array<string, array{string}> */
    public static function invalidColumnNameProvider(): array
    {
        return [
            'empty'            => [''],
            'path traversal'   => ['../etc/passwd'],
            'spaces'           => ['name with spaces'],
            'index too large'  => ['arr[99999].x'],
            'template inject'  => ['${injection}'],
            'leading dot'      => ['.field'],
            'trailing dot'     => ['field.'],
            'bracket start'    => ['[0].field'],
            'digit start'      => ['0field'],
        ];
    }

    public function testFindColumnArrayIndexGapsReturnsNullForSequentialIndices(): void
    {
        $header = [
            'title',
            'channels[0].channel_content',
            'channels[0].channel_biological_entity',
            'channels[1].channel_content',
            'channels[1].channel_biological_entity',
            'channels[2].channel_content',
        ];

        self::assertNull($this->service->findColumnArrayIndexGaps($header));
    }

    public function testFindColumnArrayIndexGapsReturnsErrorForGap(): void
    {
        $header = [
            'title',
            'channels[0].channel_content',
            'channels[2].channel_content',
        ];

        $error = $this->service->findColumnArrayIndexGaps($header);

        self::assertNotNull($error);
        self::assertStringContainsString('channels', $error);
        self::assertStringContainsString('1', $error);
    }
}
