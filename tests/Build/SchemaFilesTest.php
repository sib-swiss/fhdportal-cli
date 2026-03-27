<?php

declare(strict_types=1);

namespace App\Tests\Build;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Build tests — production schema file integrity.
 *
 * Checks that the JSON schema files shipped in config/schemas/ are structurally
 * sound: valid JSON, contain the required JSON Schema keywords, and include the
 * FEGA x-resource envelope used by SchemaService for tabular validation.
 *
 * These tests intentionally do NOT check specific field names or enum values
 * because those change as the API evolves.
 */
class SchemaFilesTest extends TestCase
{
    private string $schemaDir;

    protected function setUp(): void
    {
        $this->schemaDir = dirname(__DIR__, 2) . '/config/schemas';
    }

    public function testSchemaDirExists(): void
    {
        self::assertDirectoryExists($this->schemaDir);
    }

    public function testSchemaDirContainsAtLeastOneJsonFile(): void
    {
        $finder = (new Finder())->files()->in($this->schemaDir)->name('*.json');
        self::assertGreaterThan(0, iterator_count($finder),
            "config/schemas/ must contain at least one .json schema file"
        );
    }

    #[DataProvider('schemaFileProvider')]
    public function testSchemaIsValidJson(string $filePath, string $schemaName): void
    {
        $content = file_get_contents($filePath);
        json_decode($content);

        self::assertSame(JSON_ERROR_NONE, json_last_error(),
            "Schema '{$schemaName}' is not valid JSON: " . json_last_error_msg()
        );
    }

    #[DataProvider('schemaFileProvider')]
    public function testSchemaHasAtLeastOneJsonSchemaKeyword(string $filePath, string $schemaName): void
    {
        $schema = json_decode(file_get_contents($filePath), true);
        $jsonSchemaKeywords = ['type', 'properties', '$schema', 'allOf', 'anyOf', 'oneOf', 'required', 'definitions', '$defs'];

        $hasKeyword = false;
        foreach ($jsonSchemaKeywords as $keyword) {
            if (array_key_exists($keyword, $schema)) {
                $hasKeyword = true;
                break;
            }
        }

        self::assertTrue($hasKeyword,
            "Schema '{$schemaName}' must contain at least one JSON Schema keyword "
            . "(type, properties, \$schema, allOf, …)"
        );
    }

    #[DataProvider('schemaFileProvider')]
    public function testSchemaHasXResourceEnvelope(string $filePath, string $schemaName): void
    {
        $schema = json_decode(file_get_contents($filePath), true);

        self::assertArrayHasKey('x-resource', $schema,
            "Schema '{$schemaName}' is missing the required 'x-resource' envelope"
        );
        self::assertIsArray($schema['x-resource'],
            "Schema '{$schemaName}': 'x-resource' must be an object"
        );
    }

    #[DataProvider('schemaFileProvider')]
    public function testSchemaXResourceContainsSchemaDefinition(string $filePath, string $schemaName): void
    {
        $schema = json_decode(file_get_contents($filePath), true);

        if (!isset($schema['x-resource'])) {
            $this->markTestSkipped("Schema '{$schemaName}' has no x-resource (covered by another test).");
        }

        self::assertArrayHasKey('schema', $schema['x-resource'],
            "Schema '{$schemaName}': 'x-resource' must contain a 'schema' key"
        );
        self::assertIsArray($schema['x-resource']['schema'],
            "Schema '{$schemaName}': 'x-resource.schema' must be an object"
        );
    }

    #[DataProvider('schemaFileProvider')]
    public function testSchemaXResourceDefinitionHasFieldsArray(string $filePath, string $schemaName): void
    {
        $schema = json_decode(file_get_contents($filePath), true);
        $tableSchema = $schema['x-resource']['schema'] ?? null;

        if ($tableSchema === null) {
            $this->markTestSkipped("Schema '{$schemaName}' has no x-resource.schema.");
        }

        self::assertArrayHasKey('fields', $tableSchema,
            "Schema '{$schemaName}': 'x-resource.schema' must have a 'fields' array"
        );
        self::assertIsArray($tableSchema['fields']);
        self::assertNotEmpty($tableSchema['fields'],
            "Schema '{$schemaName}': 'x-resource.schema.fields' must not be empty"
        );
    }

    #[DataProvider('schemaFileProvider')]
    public function testEachFieldHasANameKey(string $filePath, string $schemaName): void
    {
        $schema = json_decode(file_get_contents($filePath), true);
        $fields = $schema['x-resource']['schema']['fields'] ?? [];

        foreach ($fields as $i => $field) {
            self::assertArrayHasKey('name', $field,
                "Schema '{$schemaName}': field #{$i} is missing the required 'name' key"
            );
            self::assertNotEmpty($field['name'],
                "Schema '{$schemaName}': field #{$i} has an empty 'name'"
            );
        }

        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{string, string}> */
    public static function schemaFileProvider(): iterable
    {
        $schemaDir = dirname(__DIR__, 2) . '/config/schemas';
        if (!is_dir($schemaDir)) {
            return;
        }

        $finder = (new Finder())->files()->in($schemaDir)->name('*.json');
        foreach ($finder as $file) {
            $name = $file->getFilenameWithoutExtension();
            yield $name => [$file->getRealPath(), $name];
        }
    }
}
