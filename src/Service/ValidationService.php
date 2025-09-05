<?php

namespace App\Service;

use Symfony\Component\Console\Style\SymfonyStyle;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

use Exception;
use RuntimeException;

class ValidationService
{
    private FileService $fileService;
    private ManifestService $manifestService;
    private SchemaService $schemaService;

    public function __construct(
        FileService $fileService,
        ManifestService $manifestService,
        SchemaService $schemaService
    ) {
        $this->fileService = $fileService;
        $this->manifestService = $manifestService;
        $this->schemaService = $schemaService;
    }

    /**
     * Validate a single resource
     */
    public function validateResource(string $input, string $resourceType, bool $isFilePath = true): array
    {
        $data = null;

        if ($isFilePath) {
            // Input is a file path
            $filePath = $input;
            $extension = $this->fileService->getFileExtension($filePath);
            if ($extension !== 'json') {
                return [
                    'status' => 'FAIL',
                    'message' => "Unsupported file format: $extension"
                ];
            }
            $content = file_get_contents($filePath);
            $data = json_decode($content);
        } else {
            // Input is a JSON string
            $data = json_decode($input);
        }

        // Check if the JSON decoding was successful
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'status' => 'FAIL',
                'message' => 'Invalid JSON data: ' . json_last_error_msg()
            ];
        }

        // Get the resource type schema
        $schemaResult = $this->schemaService->getResourceSchema($resourceType);

        if ($schemaResult['status'] !== 'SUCCESS') {
            return [
                'status' => 'FAIL',
                'message' => 'Validation schema not found',
                'data' => [
                    'resourceType' => $resourceType
                ]
            ];
        }

        try {
            // Get the schema from the result
            $schema = json_decode(json_encode($schemaResult['schema']));

            // Instantiate the validator
            $validator = new Validator();

            // Validate the resource data against the schema
            $result = $validator->validate($data, $schema);

            // Validation failed
            if (!$result->isValid()) {
                $formatter = new ErrorFormatter();
                $formattedErrors = $formatter->formatKeyed($result->error());

                return [
                    'status' => 'FAIL',
                    'message' => 'Schema validation failed',
                    'errors' => $formattedErrors
                ];
            }

            // Validation passed
            return [
                'status' => 'SUCCESS',
                'message' => null,
                'data' => [
                    'resourceType' => $resourceType,
                    'validation' => 'passed'
                ],
                'result' => $data
            ];
        } catch (Exception $e) {
            return [
                'status' => 'FAIL',
                'message' => 'Validation error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Validate a single resource (for tabular validation)
     */
    public function validateModifiedResource(string $input, string $resourceType): array
    {
        // Input is a JSON string
        $data = json_decode($input);

        // Check if the JSON decoding was successful
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'status' => 'FAIL',
                'message' => 'Invalid JSON data: ' . json_last_error_msg()
            ];
        }

        // Get the resource type schema modified for tabular validation
        $schemaResult = $this->schemaService->getModifiedResourceSchema($resourceType);

        if ($schemaResult['status'] !== 'SUCCESS') {
            return [
                'status' => 'FAIL',
                'message' => 'Validation schema not found',
                'data' => [
                    'resourceType' => $resourceType
                ]
            ];
        }

        try {
            // Get the modified schema from the result
            $schema = $schemaResult['schema'];

            // Instantiate the validator
            $validator = new Validator();

            // Validate the resource data against the modified schema
            $result = $validator->validate($data, $schema);

            // Validation failed
            if (!$result->isValid()) {
                $formatter = new ErrorFormatter();
                $formattedErrors = $formatter->formatKeyed($result->error());

                return [
                    'status' => 'FAIL',
                    'message' => 'Schema validation failed',
                    'errors' => $formattedErrors
                ];
            }

            // Validation passed
            return [
                'status' => 'SUCCESS',
                'message' => null,
                'data' => [
                    'resourceType' => $resourceType,
                    'validation' => 'passed'
                ],
                'result' => $data
            ];
        } catch (Exception $e) {
            return [
                'status' => 'FAIL',
                'message' => 'Validation error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Validate all resources in a CSV/TSV file
     */
    public function validateResources(string $filePath, string $resourceType): array
    {
        $extension = $this->fileService->getFileExtension($filePath);

        // Check if the file has a CSV or TSV extension
        if (!in_array($extension, ['csv', 'tsv'])) {
            return [
                'status' => 'FAIL',
                'message' => "Expected a CSV/TSV file, got $extension",
            ];
        }

        // Get the file content
        $content = file_get_contents($filePath);
        $lines = explode("\n", $content);

        // Check if the file has at least a header and one data row
        if (count($lines) < 2) {
            return [
                'status' => 'FAIL',
                'message' => 'File must contain header and at least one data row'
            ];
        }

        // Get the delimiter based on the file format
        $delimiter = $this->fileService->getValueDelimiter($lines[0], $extension);

        // Get the header row
        $header = str_getcsv(array_shift($lines), $delimiter, '"', '\\');
        $header = array_map('trim', $header);

        // Get the table schema for the resource type
        $tableSchema = $this->schemaService->getTableSchema($resourceType) ?? [];

        // Process all rows into a data array
        $dataRows = [];
        $errorReport = [];
        $lineNumber = 1; // Skip the header row

        // Iterate through each line of the file
        foreach ($lines as $line) {
            $lineNumber++;

            // Skip empty lines
            if (trim($line) === '') {
                continue;
            }

            $row = str_getcsv($line, $delimiter, '"', '\\');
            $rowData = [];

            // Convert the row data to an associative array
            foreach ($header as $index => $key) {
                if (isset($row[$index])) {
                    $rowData[trim($key)] = trim($row[$index]);
                }
            }

            // Skip rows with no data
            if (empty($rowData)) {
                continue;
            }

            // Map the row data fields to JSON schema properties
            $rowData = $this->schemaService->mapFields($rowData, $tableSchema, $resourceType);

            // Convert the row data to a JSON string
            $jsonData = json_encode($rowData);

            // Validate the row as a resource
            $validationResult = $this->validateModifiedResource($jsonData, $resourceType);

            // If the validation failed, add to the error report and skip this row
            if ($validationResult['status'] === 'FAIL') {
                $errorReport[$lineNumber] = $validationResult;
                continue;
            }

            $dataRows[] = [
                'lineNumber' => $lineNumber,
                'data' => $rowData
            ];
        }

        // Check constraints
        if ($tableSchema && !empty($dataRows)) {
            // Check primary key constraints
            $primaryKeyErrors = $this->schemaService->checkPrimaryKey($dataRows, $tableSchema);
            foreach ($primaryKeyErrors as $error) {
                $errorReport[$error['lineNumber']] = [
                    'status' => 'FAIL',
                    'message' => $error['message']
                ];
            }

            // Check unique key constraints
            $uniqueKeyErrors = $this->schemaService->checkUniqueKeys($dataRows, $tableSchema);
            foreach ($uniqueKeyErrors as $error) {
                $errorReport[$error['lineNumber']] = [
                    'status' => 'FAIL',
                    'message' => $error['message']
                ];
            }
        }

        // Return results
        if (empty($errorReport)) {
            return [
                'status' => 'SUCCESS',
                'message' => 'All resources validated successfully',
                'resourceType' => $resourceType,
                'totalRows' => $lineNumber - 1,
                'data' => $dataRows // Include processed data for foreign key validation
            ];
        } else {
            return [
                'status' => 'FAIL',
                'message' => count($errorReport) . ' rows failed validation',
                'resourceType' => $resourceType,
                'totalRows' => $lineNumber - 1,
                'errors' => $errorReport
            ];
        }
    }

    /**
     * Validate bundled resources
     */
    public function validateBundle(
        string $packageDir,
        SymfonyStyle $io
    ): array {
        // Initialize validation report structure
        $validationReport = [
            'status' => 'SUCCESS',
            'message' => 'All resources validated successfully',
            'output' => []
        ];

        // Parse the manifest file
        $parseResult = $this->manifestService->parse($packageDir);

        // Check if the parsing was successful
        if ($parseResult['status'] === 'FAIL') {
            $io->error($parseResult['message']);
            throw new RuntimeException($parseResult['message']);
        }

        // Get the manifest data
        $data = $parseResult['data'];

        // Determine the validation order based on foreign key dependencies
        $validationOrder = $this->schemaService->computeValidationOrder($data);

        if ($io->isVerbose()) {
            $io->text("Validation order: " . implode(", ", $validationOrder));
        }

        // Track if any validation failed
        $hasFailures = false;

        // Store validated data for foreign key checks
        $validatedData = [];

        // Validate resources in the determined order
        foreach ($validationOrder as $resourceType) {
            if (!isset($data[$resourceType])) {
                continue; // Skip resources that are not defined in the manifest
            }

            $fileName = $data[$resourceType];

            if ($io->isVerbose()) {
                $io->text("Validating resources from '$fileName' of type '$resourceType'");
            }

            // Get the file path
            $filePath = $packageDir . '/' . $fileName;

            // Validate all resources in the file
            $validationResult = $this->validateResources($filePath, $resourceType);

            // Check foreign key constraints if the validation succeeded
            if ($validationResult['status'] === 'SUCCESS' && isset($validationResult['data'])) {
                $foreignKeyErrors = $this->schemaService->checkForeignKeys(
                    $validationResult['data'],
                    $resourceType,
                    $validatedData
                );

                // Check if there are any foreign key errors
                if (!empty($foreignKeyErrors)) {
                    $validationResult['status'] = 'FAIL';
                    $validationResult['message'] = count($foreignKeyErrors) . ' rows failed foreign key validation';
                    $validationResult['errors'] = [];

                    // Add foreign key errors to the validation result
                    foreach ($foreignKeyErrors as $error) {
                        $validationResult['errors'][$error['lineNumber']] = [
                            'status' => 'FAIL',
                            'message' => $error['message']
                        ];
                    }

                    $hasFailures = true;
                } else {
                    // Store validated data for future foreign key checks
                    $validatedData[$resourceType] = $validationResult['data'];
                }
            }

            // Add the validation result to the consolidated report
            $validationReport['output'][$resourceType] = $validationResult;

            // Update the current status if this validation failed
            if (isset($validationResult['status']) && $validationResult['status'] !== 'SUCCESS') {
                $hasFailures = true;
            }
        }

        // Update the overall status if any validation failed
        if ($hasFailures) {
            $validationReport['status'] = 'FAIL';
            $validationReport['message'] = 'One or more resources failed validation';
        }

        return $validationReport;
    }
}
