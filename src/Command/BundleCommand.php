<?php

namespace App\Command;

use App\Service\FileService;
use App\Service\ManifestService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

use Exception;

#[AsCommand(
    name: 'bundle',
    description: 'Generate a manifest file for a FEGA submission bundle',
)]
class BundleCommand extends Command
{
    public const RESOURCE_TYPE_MAP = [
        'datasets' => 'Dataset',
        'files' => 'File',
        'molecularanalyses' => 'MolecularAnalysis',
        'molecularexperiments' => 'MolecularExperiment',
        'molecularruns' => 'MolecularRun',
        'samples' => 'Sample',
        'publications' => 'Publication',
        'sdafiles' => 'SdaFile',
        'studies' => 'Study',
        'submissions' => 'Submission',
    ];
    public const INDENT_SIZE = 4;

    private ManifestService $manifestService;
    private FileService $fileService;

    public function __construct(ManifestService $manifestService, FileService $fileService)
    {
        $this->manifestService = $manifestService;
        $this->fileService = $fileService;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('directory-path', InputArgument::REQUIRED, 'Directory containing files to bundle')
            ->addOption('create-archive', 'a', InputOption::VALUE_NONE, 'Create a ZIP archive of the bundle')
            ->addOption('output-file', 'o', InputOption::VALUE_OPTIONAL, 'Output file path for the archive')
            ->addOption('overwrite-manifest', 'w', InputOption::VALUE_NONE, 'Overwrite manifest file if it already exists')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dirPath = $input->getArgument('directory-path');
        $createArchive = $input->getOption('create-archive');
        $outputFile = $input->getOption('output-file');
        $overwriteManifest = $input->getOption('overwrite-manifest');

        // Validate the directory path
        if (!is_dir($dirPath)) {
            $io->error("Directory not found: $dirPath");
            return Command::FAILURE;
        }

        // Get an absolute path to the directory
        $dirPath = realpath($dirPath);

        if ($io->isVerbose()) {
            $io->section('FEGA Submission Bundle Creation');
            $io->text("Processing directory: $dirPath");
        }

        // Generate a manifest file
        $manifestPath = $dirPath . '/' . ManifestService::MANIFEST_FILE_NAME;
        if (!file_exists($manifestPath) || $overwriteManifest) {
            $this->generateManifest($dirPath, $manifestPath, $io);
            $action = $overwriteManifest ? 'Overwriting' : 'Creating';
            $io->info("$action manifest file: $manifestPath");
        } else {
            $io->info("Using existing manifest file: $manifestPath");
        }

        // Validate the manifest file
        try {
            $this->manifestService->validate($manifestPath);
        } catch (Exception $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        // Create a ZIP archive if requested
        if ($createArchive) {
            try {
                $archivePath = $this->fileService->createArchive($dirPath, $outputFile);
                $io->success("Archive created successfully: $archivePath");
            } catch (Exception $e) {
                $io->error($e->getMessage());
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Generate a manifest file for the bundle
     */
    private function generateManifest(string $dirPath, string $manifestPath, SymfonyStyle $io): void
    {
        // Scan the input directory for files
        $files = scandir($dirPath);
        $manifestData = [
            'version' => ManifestService::MANIFEST_VERSION,
            'files' => []
        ];

        // Iterate through each file in the directory
        foreach ($files as $file) {
            // Skip subdirectories, hidden files, and the manifest file itself
            if (is_dir($dirPath . '/' . $file) || $file[0] === '.' || $file === ManifestService::MANIFEST_FILE_NAME) {
                continue;
            }

            // Get a file name without an extension
            $fileInfo = pathinfo($file);
            $fileName = $fileInfo['filename'] ?? '';

            // Get a resource type based on the file name
            $normalizedName = preg_replace('/[^a-z]/', '', strtolower($fileName));
            $resourceType = static::RESOURCE_TYPE_MAP[$normalizedName] ?? '';

            if (empty($resourceType)) {
                $io->text("File <fg=yellow>$file</> cannot be automatically mapped to a resource type.");
            }

            // Add the file entry to the manifest
            $manifestData['files'][] = [
                'file_name' => $file,
                'resource_type' => $resourceType
            ];
        }

        // Generate a YAML file
        $yamlContent = Yaml::dump($manifestData, static::INDENT_SIZE);
        file_put_contents($manifestPath, $yamlContent);
    }
}
