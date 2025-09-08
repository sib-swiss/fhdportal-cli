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

            $response = $this->httpClient->request('GET', $schemasUrl);
            $content = $response->getContent();
            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('Failed to parse API response: ' . json_last_error_msg());
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
            if ($output->isVerbose()) {
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
