<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Service\AppDataService;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

#[AsCommand(
    name: 'update',
    description: 'Update the local JSON schemas',
)]
class UpdateCommand extends Command
{
    private string $apiBaseUrl;
    private string $schemaDir;

    private HttpClientInterface $httpClient;
    private Filesystem $filesystem;
    private AppDataService $appDataService;

    public function __construct(
        ParameterBagInterface $params,
        HttpClientInterface $httpClient,
        Filesystem $filesystem,
        AppDataService $appDataService
    ) {
        parent::__construct();
        $this->apiBaseUrl = rtrim($params->get('api.base_url'), '/');
        $this->httpClient = $httpClient;
        $this->filesystem = $filesystem;
        $this->appDataService = $appDataService;

        // Use platform-specific directory
        $this->schemaDir = $this->appDataService->getSchemaDirectory();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Updating JSON schemas from FEGA API');

        try {
            // Fetch the latest schemas from the API
            $io->section('Fetching schemas from API');
            $schemasUrl = $this->apiBaseUrl . '/schemas';
            $io->text("Requesting schemas from: $schemasUrl");

            $response = $this->httpClient->request('GET', $schemasUrl, [
                'timeout' => 30,
                'max_duration' => 60,
            ]);
            $content = $response->getContent();
            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('Failed to parse API response: ' . json_last_error_msg());
            }

            if (!is_array($data)) {
                throw new RuntimeException('API response must be a JSON object, got: ' . gettype($data));
            }

            // Extract the schemas
            $schemas = [];
            foreach ($data as $resourceType => $schema) {
                if (isset($schema['data_schema'])) {
                    $schemas[$resourceType] = $schema['data_schema'];
                }
            }

            if (empty($schemas)) {
                throw new RuntimeException('No valid schemas found in the API response');
            }

            $io->success('Retrieved the latest schemas from FHDportal');

            // Delete existing schema files
            $io->section('Deleting existing schema files');
            $this->deleteExistingSchemas($io);

            // Create new schema files
            $io->section('Creating new schema files');
            $this->createSchemaFiles($schemas, $io);

            $io->success('Schema files successfully updated');
            return Command::SUCCESS;
        } catch (Throwable $e) {
            // Handle any errors that occurred during the process
            $io->error('Failed to update schemas: ' . $e->getMessage());
            if ($output->isDebug()) {
                $io->text($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    /**
     * Delete existing schema files
     */
    private function deleteExistingSchemas(SymfonyStyle $io): void
    {
        if (!$this->filesystem->exists($this->schemaDir)) {
            $this->filesystem->mkdir($this->schemaDir);
            return;
        }

        // Get all JSON files in the schema directory
        $finder = new Finder();
        $finder->files()->in($this->schemaDir)->name('*.json');

        if (!$finder->hasResults()) {
            $io->text('No existing schema files found');
            return;
        }

        // Delete each file found
        $deletedCount = 0;
        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            $this->filesystem->remove($filePath);
            $io->text(sprintf('Deleted: %s', $file->getFilename()));
            $deletedCount += 1;
        }

        $io->newLine();
        $io->text(sprintf('Deleted %d files', $deletedCount));
    }

    /**
     * Validate that a resource type name contains only safe characters
     *
     * @throws InvalidArgumentException if the name contains disallowed characters
     */
    private function sanitizeResourceType(string $resourceType): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $resourceType)) {
            throw new InvalidArgumentException(
                "Invalid resource type name: '$resourceType'. Only alphanumeric characters, underscores, and hyphens are allowed."
            );
        }
        return $resourceType;
    }

    /**
     * Validate the structural shape of a downloaded schema
     *
     * @throws RuntimeException if the schema fails structural validation
     */
    private function validateSchemaShape(string $resourceType, array $schema): void
    {
        // Must contain at least one standard JSON Schema root keyword
        $jsonSchemaKeywords = ['type', 'properties', '$schema', 'allOf', 'anyOf', 'oneOf', 'required', 'definitions', '\$defs'];
        $hasKeyword = false;
        foreach ($jsonSchemaKeywords as $keyword) {
            if (array_key_exists($keyword, $schema)) {
                $hasKeyword = true;
                break;
            }
        }
        if (!$hasKeyword) {
            throw new RuntimeException(
                "Schema '$resourceType' does not contain any recognised JSON Schema keywords (type, properties, \$schema, …)."
            );
        }

        // Must have the FEGA resource envelope
        if (!isset($schema['x-resource']) || !is_array($schema['x-resource'])) {
            throw new RuntimeException(
                "Schema '$resourceType' is missing required 'x-resource' envelope."
            );
        }
        if (!isset($schema['x-resource']['schema']) || !is_array($schema['x-resource']['schema'])) {
            throw new RuntimeException(
                "Schema '$resourceType' is missing required 'x-resource.schema' definition."
            );
        }
    }

    /**
     * Create new schema files
     */
    private function createSchemaFiles(array $schemas, SymfonyStyle $io): void
    {
        $createdFilesCount = 0;

        foreach ($schemas as $resourceType => $schema) {
            // Only create files for schemas that contain table schemas
            if (!isset($schema['x-resource']['schema'])) {
                continue;
            }

            $resourceType = $this->sanitizeResourceType($resourceType);

            // Validate the structural shape of the schema
            $this->validateSchemaShape($resourceType, $schema);

            $filePath = $this->schemaDir . '/' . $resourceType . '.json';

            // Format the JSON content
            $jsonContent = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $this->filesystem->dumpFile($filePath, $jsonContent);
            $io->text(sprintf('Created: %s.json', $resourceType));
            $createdFilesCount++;
        }

        $io->newLine();
        $io->text(sprintf('Created %d files', $createdFilesCount));
    }
}
