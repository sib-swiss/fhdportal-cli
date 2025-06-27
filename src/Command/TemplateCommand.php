<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\String\Inflector\EnglishInflector;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use App\Service\SchemaService;
use App\Service\FileService;

#[AsCommand(
    name: 'template',
    description: 'Generate TSV file templates',
)]
class TemplateCommand extends Command
{
    public const DEFAULT_OUTPUT_FILENAME = 'templates.zip';

    private SchemaService $schemaService;
    private FileService $fileService;
    private EnglishInflector $inflector;
    private CamelCaseToSnakeCaseNameConverter $nameConverter;

    public function __construct(SchemaService $schemaService, FileService $fileService)
    {
        parent::__construct();
        $this->schemaService = $schemaService;
        $this->fileService = $fileService;
        $this->inflector = new EnglishInflector();
        $this->nameConverter = new CamelCaseToSnakeCaseNameConverter();
    }

    protected function configure(): void
    {
        $this->addOption('output-file', 'o', InputOption::VALUE_OPTIONAL, 'Output file path for the archive');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Generating file templates');

        $outputFile = $input->getOption('output-file');
        if (!$outputFile) {
            $outputFile = getcwd() . '/' . static::DEFAULT_OUTPUT_FILENAME;
        }

        // Get all resource types
        $resourceTypes = $this->schemaService->getResourceTypes();
        sort($resourceTypes, SORT_STRING);
        $io->section('Fetching resource types');
        $io->text(sprintf('Found %d resource types', count($resourceTypes)));

        // Create a temporary directory for TSV files
        $tempDir = $this->fileService->createTempDirectory();
        $io->section('Creating templates');
        $io->text(sprintf('Created temporary directory: %s', $tempDir));

        $createdFiles = [];

        // Process each resource type
        foreach ($resourceTypes as $resourceType) {

            // Get a table schema
            $tableSchema = $this->schemaService->getTableSchema($resourceType);

            if (!$tableSchema || !isset($tableSchema['fields']) || empty($tableSchema['fields'])) {
                continue;
            }

            // Get the field names
            $fields = [];
            foreach ($tableSchema['fields'] as $field) {
                if (isset($field['name'])) {
                    $fields[] = $field['name'];
                }
            }

            if (empty($fields)) {
                continue;
            }

            // Generate a file name
            $fileName = $this->inflector->pluralize($this->nameConverter->normalize($resourceType))[0] . '.tsv';
            $filePath = $tempDir . '/' . $fileName;

            // Create the file content
            $fileContent = implode("\t", $fields) . "\n";

            // Write the TSV file
            file_put_contents($filePath, $fileContent);
            $createdFiles[] = $fileName;

            $io->text(sprintf('Created template: %s with %d columns', $fileName, count($fields)));
        }

        if (empty($createdFiles)) {
            $io->warning('No templates were created');
            $this->fileService->removeTempDirectory($tempDir);
            return Command::SUCCESS;
        }

        // Create a ZIP archive
        $io->section('Creating archive');
        $archivePath = $this->fileService->createArchive($tempDir, $outputFile, true);

        // Clean up
        $this->fileService->removeTempDirectory($tempDir);

        $io->success([
            sprintf('Successfully created %d template files', count($createdFiles)),
            sprintf('Templates are available in: %s', $archivePath)
        ]);

        return Command::SUCCESS;
    }
}
