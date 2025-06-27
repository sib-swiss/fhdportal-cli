<?php

namespace App\Service;

use Symfony\Component\Finder\Finder;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

use Exception;
use RuntimeException;
use stdClass;

class SchemaService
{
    private string $schemaDir;

    public function __construct(ParameterBagInterface $params)
    {
        $this->schemaDir = $params->get('app.schema_dir');
    }

    /**
     * Check if a resource type exists
     */
    public function isResourceType(string $resourceType): bool
    {
        $schemaPath = $this->schemaDir . "/{$resourceType}.json";
        return file_exists($schemaPath);
    }

    /**
     * Get supported resource types
     */
    public function getResourceTypes(): array
    {
        try {
            // Locate all JSON schema files in the schema directory
            $finder = new Finder();
            $finder->files()->in($this->schemaDir)->name('*.json');

            // Extract resource types from file names
            $resourceTypes = [];
            foreach ($finder as $file) {
                $resourceTypes[] = pathinfo($file->getFilename(), PATHINFO_FILENAME);
            }

            // Sort resource types alphabetically
            sort($resourceTypes);

            return $resourceTypes;
        } catch (Exception $e) {
            throw new RuntimeException("Failed to get schema file path info: " . $e->getMessage());
        }
    }

    /**
     * Get the JSON schema of a resource type
     */
    public function getResourceSchema(string $resourceType): array
    {
        $schemaPath = $this->schemaDir . "/{$resourceType}.json";

        $refData = [
            'resourceType' => $resourceType,
            'schemaPath' => $schemaPath
        ];

        // Check if the schema file exists
        if (!file_exists($schemaPath)) {
            return [
                'status' => 'FAIL',
                'message' => 'Schema file not found',
                'data' => $refData
            ];
        }

        // Load the schema file
        $schemaData = file_get_contents($schemaPath);
        $schema = json_decode($schemaData, true);

        // Check if the schema is valid JSON
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'status' => 'FAIL',
                'message' => 'Invalid JSON schema: ' . json_last_error_msg(),
                'data' => $refData
            ];
        }

        return [
            'status' => 'SUCCESS',
            'message' => null,
            'data' => $refData,
            'schema' => $schema
        ];
    }

    /**
     * Get a resource schema modified for tabular validation
     */
    public function getModifiedResourceSchema(string $resourceType): array
    {
        $resourceSchema = $this->getResourceSchema($resourceType);

        // If the resource schema is not valid, return the original schema
        if ($resourceSchema['status'] !== 'SUCCESS') {
            return $resourceSchema;
        }

        $tableSchema = $this->getTableSchema($resourceType);

        if (!$tableSchema || !isset($tableSchema['fields'])) {
            // Unless a table schema is available, return the original schema
            return $resourceSchema;
        }

        $requiredFields = [];
        $schemaOverrides = [];

        // Extract required fields and schema overrides from the table schema
        foreach ($tableSchema['fields'] as $field) {
            if (isset($field['name'])) {
                // Check if the field is required
                if (isset($field['constraints']['required']) && $field['constraints']['required'] === true) {
                    $requiredFields[] = $field['aliasOf'] ?? $field['name'];
                }

                // Check if the field has schema overrides
                if (isset($field['constraints']['jsonSchema'])) {
                    $propertyName = $field['aliasOf'] ?? $field['name'];
                    $schemaOverrides[$propertyName] = $field['constraints']['jsonSchema'];
                }
            }
        }

        // Create a deep copy of the schema
        $modifiedSchema = json_decode(json_encode($resourceSchema['schema']));

        // Override the required constraint
        $this->setProperty($modifiedSchema, 'required', $requiredFields);

        // Apply schema overrides for nested properties
        foreach ($schemaOverrides as $propertyName => $jsonSchemaConstraint) {
            $this->setProperty($modifiedSchema, "properties.$propertyName", $jsonSchemaConstraint);
        }

        // Return the modified schema
        return [
            'status' => 'SUCCESS',
            'message' => null,
            'data' => [
                'resourceType' => $resourceType,
                'schemaPath' => $resourceSchema['data']['schemaPath']
            ],
            'schema' => $modifiedSchema
        ];
    }

    /**
     * Extract a table schema from a resource schema
     */
    public function getTableSchema(string $resourceType): ?array
    {
        $resourceSchema = $this->getResourceSchema($resourceType);

        // Check if the resource schema is valid
        if ($resourceSchema['status'] !== 'SUCCESS') {
            return null;
        }

        $schema = $resourceSchema['schema'];

        // Check if the resource schema has the x-resource extension with a table schema definition
        if (!isset($schema['x-resource']['schema'])) {
            return null;
        }

        return $schema['x-resource']['schema'];
    }

    /**
     * Validate primary key constraints
     */
    public function checkPrimaryKey(array $dataRows, array $tableSchema): array
    {
        // Check if the table schema has a primary key defined
        if (!isset($tableSchema['primaryKey']) || empty($tableSchema['primaryKey'])) {
            return [];
        }

        $primaryKeyFields = $tableSchema['primaryKey'];
        $seenPrimaryKeys = [];
        $errors = [];

        foreach ($dataRows as $row) {
            $rowData = $row['data'];
            $lineNumber = $row['lineNumber'];

            // Extract primary key values
            $primaryKeyValues = [];
            foreach ($primaryKeyFields as $field) {
                if (!isset($rowData[$field])) {
                    $errors[] = [
                        'lineNumber' => $lineNumber,
                        'message' => "Missing primary key field '$field'"
                    ];
                    continue 2; // Skip to the next row
                }
                $primaryKeyValues[] = $rowData[$field];
            }

            // Create a composite key if needed
            $compositeKey = implode('|', $primaryKeyValues);

            // Check if this primary key has been seen before
            if (isset($seenPrimaryKeys[$compositeKey])) {
                $errors[] = [
                    'lineNumber' => $lineNumber,
                    'message' => 'Duplicate primary key: ' . implode(', ', $primaryKeyValues) .
                        ' (first seen at line ' . $seenPrimaryKeys[$compositeKey] . ')'
                ];
            } else {
                $seenPrimaryKeys[$compositeKey] = $lineNumber;
            }
        }

        return $errors;
    }

    /**
     * Validate foreign key constraints
     */
    public function checkForeignKeys(array $dataRows, string $resourceType, array $validatedData): array
    {
        $schema = $this->getTableSchema($resourceType);

        // Check if the schema has foreign keys defined
        if (!$schema || !isset($schema['foreignKeys']) || empty($schema['foreignKeys'])) {
            return [];
        }

        $errors = [];

        foreach ($schema['foreignKeys'] as $foreignKey) {
            // Check if the reference is complete
            if (!$this->isValidForeignKeyDefinition($foreignKey)) {
                continue;
            }

            $referencedResource = $foreignKey['reference']['resource'];

            // Check if the referenced resource is found in the validated data
            if (!isset($validatedData[$referencedResource])) {
                continue;
            }

            // Validate the foreign key against the referenced data
            $foreignKeyErrors = $this->validateForeignKey(
                $dataRows,
                $foreignKey,
                $validatedData[$referencedResource],
                $schema
            );

            // Merge any errors found for this foreign key
            $errors = array_merge($errors, $foreignKeyErrors);
        }

        return $errors;
    }

    /**
     * Validate unique key constraints
     */
    public function checkUniqueKeys(array $dataRows, array $tableSchema): array
    {
        // Check if the table schema has unique keys defined
        if (!isset($tableSchema['uniqueKeys']) || empty($tableSchema['uniqueKeys'])) {
            return [];
        }

        $errors = [];

        foreach ($tableSchema['uniqueKeys'] as $uniqueKeyFields) {
            $seenUniqueKeys = [];

            foreach ($dataRows as $row) {
                $rowData = $row['data'];
                $lineNumber = $row['lineNumber'];

                // Extract unique key values
                $uniqueKeyValues = [];
                foreach ($uniqueKeyFields as $field) {
                    if (!isset($rowData[$field])) {
                        $errors[] = [
                            'lineNumber' => $lineNumber,
                            'message' => "Missing unique key field '$field'"
                        ];
                        continue 2; // Skip to the next row
                    }
                    $uniqueKeyValues[] = $rowData[$field];
                }

                // Create a composite key
                $compositeKey = implode('|', $uniqueKeyValues);

                // Check if this unique key has been seen before
                if (isset($seenUniqueKeys[$compositeKey])) {
                    $errors[] = [
                        'lineNumber' => $lineNumber,
                        'message' => 'Duplicate unique key (' . implode(', ', $uniqueKeyFields) . '): ' .
                            implode(', ', $uniqueKeyValues) . ' (first seen at line ' . $seenUniqueKeys[$compositeKey] . ')'
                    ];
                } else {
                    $seenUniqueKeys[$compositeKey] = $lineNumber;
                }
            }
        }

        return $errors;
    }

    /**
     * Determine the validation order based on foreign key dependencies
     */
    public function computeValidationOrder(array $resources): array
    {
        $dependencies = $this->buildDependencyGraph($resources);
        return $this->topologicalSort($dependencies);
    }

    /**
     * Extract field types from a table schema
     */
    public function extractFieldTypes(array $schema): array
    {
        $fieldTypes = [];
        if (isset($schema['fields'])) {
            foreach ($schema['fields'] as $field) {
                if (isset($field['name'])) {
                    $fieldTypes[$field['name']] = $field['type'] ?? 'string';
                }
            }
        }
        return $fieldTypes;
    }

    /**
     * Set a property on a schema object
     */
    public function setProperty(object $schema, string $property, $value): void
    {
        // Check if the property is nested
        if (strpos($property, '.') !== false) {
            $pathParts = explode('.', $property);
            $current = $schema;

            // Navigate to the parent of the target property
            for ($i = 0; $i < count($pathParts) - 1; $i++) {
                $part = $pathParts[$i];
                if (!isset($current->{$part})) {
                    $current->{$part} = new stdClass();
                }
                $current = $current->{$part};
            }

            // Set the target property
            $nestedProperty = end($pathParts);
            if (is_array($value)) {
                $current->{$nestedProperty} = json_decode(json_encode($value));
            } else {
                $current->{$nestedProperty} = $value;
            }
        } else {
            // Set a simple property
            $schema->{$property} = $value;
        }
    }

    /**
     * Map fields from tabular data to JSON schema properties
     */
    public function mapFields(array $originalData, array $tableSchema): array
    {
        // Check if the table schema has fields defined
        if (!isset($tableSchema['fields']) || empty($tableSchema['fields'])) {
            return $originalData;
        }

        $mappedData = [];

        $propertyNames = [];
        $fieldTypes = [];

        // Map field names to property names and field types
        foreach ($tableSchema['fields'] as $field) {
            if (!isset($field['name'])) {
                continue;
            }
            $fieldName = $field['name'];
            $propertyName = $field['aliasOf'] ?? $fieldName;
            $propertyNames[$fieldName] = $propertyName;
            $fieldTypes[$fieldName] = $field['type'] ?? 'string';
        }

        // Map the original row data using the field name mappings
        foreach ($originalData as $columnName => $value) {
            if (isset($propertyNames[$columnName])) {
                $propertyName = $propertyNames[$columnName];
                $fieldType = $fieldTypes[$columnName] ?? 'string';

                if ($fieldType === 'list') {
                    $mappedData[$propertyName] = $this->splitValue($value);
                } else {
                    $mappedData[$propertyName] = $value;
                }
            } else {
                // Keep unmapped fields as-is
                $mappedData[$columnName] = $value;
            }
        }

        return $mappedData;
    }

    /**
     * Split a value into an array based on delimiters
     */
    private function splitValue($value): array
    {
        if (is_string($value)) {
            // Try to split by semicolon first, then by comma
            if (strpos($value, ';') !== false || strpos($value, ',') !== false) {
                $delimiter = strpos($value, ';') !== false ? ';' : ',';
                return array_map('trim', explode($delimiter, $value));
            } else {
                // If no delimiter is found, return the value as a single-element array
                return [$value];
            }
        } elseif (is_array($value)) {
            // If the value is already an array, return it as-is
            return $value;
        } else {
            // Return a single-element array for other types
            return [$value];
        }
    }

    /**
     * Validate a single foreign key constraint
     */
    private function validateForeignKey(
        array $dataRows,
        array $foreignKey,
        array $referencedData,
        array $schema
    ): array {
        $fields = $foreignKey['fields'];
        $targetFields = $foreignKey['reference']['fields'];
        $referencedResource = $foreignKey['reference']['resource'];

        $isList = $this->isListTypeForeignKey($fields, $schema);
        $targetIndex = $this->buildTargetIndex($referencedData, $targetFields);

        $errors = [];
        foreach ($dataRows as $row) {
            if ($isList) {
                $rowErrors = $this->validateListTypeForeignKey(
                    $row,
                    $fields,
                    $targetIndex,
                    $referencedResource,
                    $targetFields
                );
            } else {
                $rowErrors = $this->validateRegularForeignKey(
                    $row,
                    $fields,
                    $targetFields,
                    $targetIndex,
                    $referencedResource
                );
            }
            $errors = array_merge($errors, $rowErrors);
        }

        return $errors;
    }

    /**
     * Validate a regular foreign key
     */
    private function validateRegularForeignKey(
        array $row,
        array $fields,
        array $targetFields,
        array $targetIndex,
        string $referencedResource
    ): array {
        $errors = [];
        $sourceData = $row['data'];
        $lineNumber = $row['lineNumber'];
        $compositeKey = [];

        foreach ($fields as $field) {
            if (!isset($sourceData[$field])) {
                $errors[] = [
                    'lineNumber' => $lineNumber,
                    'message' => "Missing foreign key field '$field'"
                ];
                // Return early if missing required field
                return $errors;
            }
            $compositeKey[] = $sourceData[$field];
        }

        $foreignKeyValue = implode('|', $compositeKey);

        if (!isset($targetIndex[$foreignKeyValue])) {
            $fieldNames = implode(', ', $fields);
            $fieldValues = implode(', ', $compositeKey);
            $targetFieldNames = implode(', ', $targetFields);

            $errors[] = [
                'lineNumber' => $lineNumber,
                'message' => "Foreign key constraint violation: ($fieldNames) = ($fieldValues) " .
                    "not found in $referencedResource.($targetFieldNames)"
            ];
        }

        return $errors;
    }

    /**
     * Validate a list type foreign key
     */
    private function validateListTypeForeignKey(
        array $row,
        array $fields,
        array $targetIndex,
        string $referencedResource,
        array $targetFields
    ): array {
        $errors = [];
        $sourceData = $row['data'];
        $lineNumber = $row['lineNumber'];

        foreach ($fields as $field) {
            if (!isset($sourceData[$field]) || empty($sourceData[$field])) {
                continue;
            }

            $values = $this->splitValue($sourceData[$field]);

            foreach ($values as $value) {
                if (!isset($targetIndex[$value])) {
                    $errors[] = [
                        'lineNumber' => $lineNumber,
                        'message' => "Foreign key constraint violation: value '$value' in field '$field' " .
                            "does not exist in $referencedResource.{$targetFields[0]}"
                    ];
                }
            }
        }

        return $errors;
    }

    /**
     * Build index of target values for foreign key validation
     */
    private function buildTargetIndex(array $referencedData, array $targetFields): array
    {
        $targetIndex = [];
        foreach ($referencedData as $targetRow) {
            $targetData = $targetRow['data'];
            $compositeKey = [];

            foreach ($targetFields as $field) {
                if (!isset($targetData[$field])) {
                    continue 2; // Skip rows missing required fields
                }
                $compositeKey[] = $targetData[$field];
            }

            $targetIndex[implode('|', $compositeKey)] = true;
        }
        return $targetIndex;
    }

    /**
     * Build a dependency graph from resources and their foreign key relationships
     */
    private function buildDependencyGraph(array $resources): array
    {
        $dependencies = [];
        $resourcesWithoutDependencies = [];

        foreach (array_keys($resources) as $resourceType) {
            $schema = $this->getTableSchema($resourceType);
            if (!$schema || !isset($schema['foreignKeys'])) {
                // No dependencies
                $resourcesWithoutDependencies[] = $resourceType;
                continue;
            }

            // Get foreign key dependencies
            $resourceDependencies = [];
            foreach ($schema['foreignKeys'] as $foreignKey) {
                if (isset($foreignKey['reference']['resource'])) {
                    $referencedResource = $foreignKey['reference']['resource'];
                    if (isset($resources[$referencedResource])) {
                        $resourceDependencies[] = $referencedResource;
                    }
                }
            }

            if (empty($resourceDependencies)) {
                // No valid dependencies
                $resourcesWithoutDependencies[] = $resourceType;
            } else {
                // Add unique dependencies to the graph
                $dependencies[$resourceType] = array_unique($resourceDependencies);
            }
        }

        // Sort resources without dependencies alphabetically
        sort($resourcesWithoutDependencies);

        // Add resources without dependencies to the graph
        foreach ($resourcesWithoutDependencies as $resourceType) {
            $dependencies[$resourceType] = [];
        }

        return $dependencies;
    }

    /**
     * Perform topological sorting on a dependency graph
     */
    private function topologicalSort(array $dependencies): array
    {
        $order = [];
        $visited = [];
        $tempMark = [];

        $visit = function ($node) use (&$visit, &$visited, &$tempMark, &$order, $dependencies) {
            if (isset($tempMark[$node]) && $tempMark[$node]) {
                throw new RuntimeException("Circular dependency detected involving $node");
            }

            if (!isset($visited[$node]) || !$visited[$node]) {
                $tempMark[$node] = true;

                foreach ($dependencies[$node] as $dependency) {
                    $visit($dependency);
                }

                $visited[$node] = true;
                $tempMark[$node] = false;
                $order[] = $node;
            }
        };

        foreach (array_keys($dependencies) as $node) {
            if (!isset($visited[$node]) || !$visited[$node]) {
                $visit($node);
            }
        }

        return $order;
    }

    /**
     * Determine if any of the source fields is a list type
     */
    private function isListTypeForeignKey(array $fields, array $schema): bool
    {
        $fieldTypes = $this->extractFieldTypes($schema);

        foreach ($fields as $field) {
            if (isset($fieldTypes[$field]) && $fieldTypes[$field] === 'list') {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a foreign key definition is valid
     */
    private function isValidForeignKeyDefinition(array $foreignKey): bool
    {
        return isset($foreignKey['fields']) &&
            isset($foreignKey['reference']['resource']) &&
            isset($foreignKey['reference']['fields']);
    }
}
