<?php

namespace App\Command;

use App\Service\FileService;
use App\Service\ValidationService;
use Symfony\Component\Console\{
    Attribute\AsCommand,
    Command\Command,
    Input\InputArgument,
    Input\InputInterface,
    Input\InputOption,
    Output\OutputInterface,
    Style\SymfonyStyle
};

use Exception;

#[AsCommand(
    name: 'validate',
    description: 'Validate one or more files containing FEGA metadata',
)]
class ValidateCommand extends Command
{
    private ValidationService $validationService;
    private FileService $fileService;

    public function __construct(ValidationService $validationService, FileService $fileService)
    {
        parent::__construct();
        $this->validationService = $validationService;
        $this->fileService = $fileService;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('target-path', InputArgument::REQUIRED, 'File or directory path')
            ->addOption('resource-type', 't', InputOption::VALUE_OPTIONAL, 'Type of the resource', 'SubmissionBundle')
            ->addOption('output-format', 'f', InputOption::VALUE_OPTIONAL, 'Output format (json or text)', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Get the input and output interfaces
        $io = new SymfonyStyle($input, $output);

        // Validate the target path
        $targetPath = realpath($input->getArgument('target-path'));
        if ($targetPath === false) {
            $io->error("Invalid target path: " . $input->getArgument('target-path'));
            return Command::INVALID;
        }

        // Get the options
        $resourceType = $input->getOption('resource-type');
        $outputFormat = $input->getOption('output-format');
        $isVerbose = $io->isVerbose();

        // Display the configuration details
        if ($isVerbose) {
            $io->title('FEGA Metadata Validation');
            $io->section('Configuration');
            $io->listing([
                "Target path: $targetPath",
                "Resource type: $resourceType",
                "Output format: $outputFormat"
            ]);
        }

        try {
            $validationResult = [];

            // Execute the appropriate validation logic based on the target path type
            if (is_dir($targetPath)) {
                $validationResult = $this->validateBundle($targetPath, $io, false);
            } else if (is_file($targetPath)) {
                $fileExtension = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));

                switch ($fileExtension) {
                    case 'zip':
                        $validationResult = $this->validateBundle($targetPath, $io);
                        break;
                    case 'csv':
                    case 'tsv':
                        $validationResult = $this->validationService->validateResources($targetPath, $resourceType);
                        break;
                    case 'json':
                        $validationResult = $this->validationService->validateResource($targetPath, $resourceType, false);
                        break;
                    default:
                        $io->error("Unsupported file type: $fileExtension");
                        return Command::INVALID;
                }
            } else {
                $io->error("Invalid target path type");
                return Command::INVALID;
            }

            return $this->outputResult($validationResult, $outputFormat, $io);
        } catch (Exception $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }

    private function validateBundle(string $targetPath, SymfonyStyle $io, bool $isArchive = true): array
    {
        if ($isArchive) {
            // Extract the archive to a temporary directory
            $targetPath = $this->fileService->extractArchive($targetPath);

            if ($io->isVerbose()) {
                $io->section('Extracting Files');
                $io->text("Location: $targetPath");
            }
        }

        if ($io->isVerbose()) {
            $io->section('Processing Submission Bundle');
        }

        try {
            // Validate the bundle
            return $this->validationService->validateBundle($targetPath, $io);
        } finally {
            if ($isArchive) {
                // Delete the temporary directory
                $this->fileService->removeTempDirectory($targetPath);
            }
        }
    }

    private function outputResult(array $result, string $outputFormat, SymfonyStyle $io): int
    {
        if ($io->isVerbose()) {
            $io->section('Validation Results');
        }

        // Determine the output format and display the results accordingly
        if ($outputFormat === 'json') {
            $io->writeln(json_encode($result, JSON_PRETTY_PRINT));
        } elseif ($outputFormat === 'text') {
            $this->outputAsText($result, $io);
        } else {
            $io->error("Invalid output format: $outputFormat");
            return Command::INVALID;
        }

        return $result['status'] === 'SUCCESS' ? Command::SUCCESS : Command::FAILURE;
    }

    private function outputAsText(array $result, SymfonyStyle $io): void
    {
        if ($result['status'] === 'SUCCESS') {
            $this->outputSuccessResult($result, $io);
            return;
        }

        $this->outputFailureResult($result, $io);
    }

    /**
     * Output successful validation results
     */
    private function outputSuccessResult(array $result, SymfonyStyle $io): void
    {
        $io->success($result['message'] ?? 'Validation successful');

        // Display the summary details
        if (isset($result['totalRows'])) {
            $io->text(sprintf('Total rows processed: %d', $result['totalRows']));
        }
        if (isset($result['study_id'])) {
            $io->text(sprintf('Study ID: %s', $result['study_id']));
        }
        if (isset($result['resourceType'])) {
            $io->text(sprintf('Resource type: %s', $result['resourceType']));
        }
        if (isset($result['data']) && is_array($result['data'])) {
            foreach ($result['data'] as $key => $value) {
                if (!is_array($value) && !is_object($value)) {
                    $io->text(sprintf('%s: %s', $key, $value));
                }
            }
        }
    }

    /**
     * Output failed validation results
     */
    private function outputFailureResult(array $result, SymfonyStyle $io): void
    {
        $io->error($result['message'] ?? 'Validation failed');

        // Display the resource type if available
        if (isset($result['resourceType'])) {
            $io->note(sprintf('Resource type: %s', $result['resourceType']));
        }

        // Process single resource validation errors
        if (isset($result['errors']) && is_array($result['errors'])) {
            $this->outputSingleResourceErrors($result['errors'], $io);
        }

        // Process bundle validation errors
        if (isset($result['output']) && is_array($result['output'])) {
            $this->outputBundleErrors($result['output'], $io);
        }
    }

    /**
     * Output errors for single resource validation
     */
    private function outputSingleResourceErrors(array $errors, SymfonyStyle $io): void
    {
        if (isset($errors['errors'])) {
            $this->displayErrors($errors['errors'], $io);
        } else {
            // Group the errors by their structure and content
            $groupedErrors = $this->groupSimilarErrors($errors);
            $this->outputGroupedErrors($groupedErrors, $io, true);
        }
    }

    /**
     * Output errors for bundle validation components
     */
    private function outputBundleErrors(array $output, SymfonyStyle $io): void
    {
        foreach ($output as $componentType => $componentResult) {
            // Skip the successful components
            if (!isset($componentResult['status']) || $componentResult['status'] === 'SUCCESS') {
                continue;
            }

            $io->section(sprintf('%s validation errors', $componentType));
            $this->outputComponentErrors($componentResult, $io);
        }
    }

    /**
     * Output errors for a specific component
     */
    private function outputComponentErrors(array $componentResult, SymfonyStyle $io): void
    {
        if (isset($componentResult['errors']) && is_array($componentResult['errors'])) {
            if ($this->hasNumericKeys($componentResult['errors'])) {
                // Group the errors by their structure and content
                $groupedErrors = $this->groupSimilarErrors($componentResult['errors']);
                $this->outputGroupedErrors($groupedErrors, $io, false, '   ');
            } else {
                $this->displayErrors($componentResult['errors'], $io);
            }
        } elseif (isset($componentResult['data'])) {
            $this->outputComponentDataErrors($componentResult['data'], $io);
        } else {
            $io->text($componentResult['message'] ?? 'Error details not available');
        }
    }

    /**
     * Output component data errors (in JSON format)
     */
    private function outputComponentDataErrors(string $data, SymfonyStyle $io): void
    {
        try {
            $errorData = json_decode($data, true);
            if (is_array($errorData) && isset($errorData['errors'])) {
                $this->displayErrors($errorData['errors'], $io);
            } else {
                $io->text($data);
            }
        } catch (\Exception $e) {
            $io->text($data);
        }
    }

    /**
     * Output grouped errors with line information and error counts
     */
    private function outputGroupedErrors(array $groupedErrors, SymfonyStyle $io, bool $useSection = true, string $indent = ''): void
    {
        foreach ($groupedErrors as $groupInfo) {
            // Count the total occurrences (number of lines with this error)
            $lineCount = count($groupInfo['lines']);
            $countText = $lineCount > 1 ? sprintf(' (%d errors)', $lineCount) : '';

            if (count($groupInfo['lines']) === 1) {
                $lineText = sprintf('%sLine %d:', $indent, $groupInfo['lines'][0]);
            } else {
                $lineText = sprintf('%sLines %d - %d:', $indent, min($groupInfo['lines']), max($groupInfo['lines']));
            }

            if ($useSection) {
                $io->section(trim($lineText));
            } else {
                $io->text($lineText);
            }

            if (isset($groupInfo['error']['errors']) && is_array($groupInfo['error']['errors'])) {
                $this->displayErrors($groupInfo['error']['errors'], $io, $indent . '   ', $countText);
            } elseif (isset($groupInfo['error']['message'])) {
                $io->text($indent . '   ' . $groupInfo['error']['message'] . $countText);
            }
        }
    }

    /**
     * Helper method to display formatted validation errors
     */
    private function displayErrors(array $errors, SymfonyStyle $io, string $indent = " ", string $countText = ""): void
    {
        foreach ($errors as $key => $error) {
            if (is_string($error)) {
                $io->text("$indent- $error$countText");
                continue;
            }

            if (is_numeric($key) && is_array($error)) {
                $propertyPath = $error['property'] ?? $error['propertyPath'] ?? 'unknown';
                $errorMessage = $error['message'] ?? $error['error'] ?? 'Unknown error';
                $io->text("$indent<fg=yellow>$propertyPath</>: $errorMessage$countText");
            } else if (is_string($key) && !is_array($error)) {
                $io->text("$indent<fg=yellow>$key</>: $error$countText");
            } else if (is_string($key) && is_array($error)) {
                // For nested error structures, show the count text next to the key
                $hasOnlyStringValues = true;
                foreach ($error as $value) {
                    if (!is_string($value)) {
                        $hasOnlyStringValues = false;
                        break;
                    }
                }

                if ($hasOnlyStringValues && $countText) {
                    // This is likely the final level - show count here
                    $io->text("$indent<fg=yellow>$key</>:$countText");
                    $this->displayErrors($error, $io, "$indent  ");
                } else {
                    // This has nested structure - calculate count for this level
                    $errorCount = $this->countNestedErrors($error);
                    $keyCountText = $errorCount > 1 ? sprintf(" (%d errors)", $errorCount) : "";
                    $io->text("$indent<fg=yellow>$key</>:$keyCountText");
                    $this->displayErrors($error, $io, "$indent  ");
                }
            }
        }
    }

    /**
     * Count nested errors recursively
     */
    private function countNestedErrors($error): int
    {
        if (is_string($error)) {
            return 1;
        }

        if (!is_array($error)) {
            return 1;
        }

        $count = 0;
        foreach ($error as $key => $value) {
            if (is_numeric($key) && is_array($value)) {
                // This is a structured error object
                $count += 1;
            } else if (is_string($key) && is_array($value)) {
                // This is a nested error group
                $count += $this->countNestedErrors($value);
            } else if (is_string($value)) {
                // This is a simple string error
                $count += 1;
            } else {
                $count += 1;
            }
        }

        return $count > 0 ? $count : 1;
    }

    /**
     * Check whether an array has numeric keys
     */
    private function hasNumericKeys(array $array): bool
    {
        foreach (array_keys($array) as $key) {
            if (is_numeric($key)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Create a hash for an error
     */
    private function getErrorHash($error): string
    {
        if (is_array($error)) {
            // For simple error arrays, serialize them
            if (isset($error['errors']) && is_array($error['errors'])) {
                return md5(json_encode($error['errors']));
            }
            return md5(json_encode($error));
        }

        // For simple string errors
        return md5((string)$error);
    }

    /**
     * Group similar errors by their content
     */
    private function groupSimilarErrors(array $errors): array
    {
        $groups = [];

        foreach ($errors as $lineNumber => $error) {
            if (!is_numeric($lineNumber)) {
                // Skip non-numeric keys
                continue;
            }

            $errorHash = $this->getErrorHash($error);

            if (!isset($groups[$errorHash])) {
                $groups[$errorHash] = [
                    'lines' => [],
                    'error' => $error
                ];
            }

            $groups[$errorHash]['lines'][] = $lineNumber;
        }

        // Sort the groups by the minimum line number in each group
        usort($groups, function ($a, $b) {
            return min($a['lines']) <=> min($b['lines']);
        });

        return $groups;
    }
}
