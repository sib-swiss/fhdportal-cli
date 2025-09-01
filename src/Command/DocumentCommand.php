<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use App\Service\SchemaService;
use Parsedown;

#[AsCommand(
    name: 'document',
    description: 'Generate documentation for resource schemas',
)]
class DocumentCommand extends Command
{
    public const DEFAULT_OUTPUT_FILENAME = 'schemas'; // without a file extension

    private SchemaService $schemaService;

    public function __construct(SchemaService $schemaService)
    {
        parent::__construct();
        $this->schemaService = $schemaService;
    }

    protected function configure(): void
    {
        $this->addOption('output-file', 'o', InputOption::VALUE_OPTIONAL, 'Output file path for the documentation');
        $this->addOption('output-format', 'f', InputOption::VALUE_OPTIONAL, 'Output format: md or html', 'md');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Generating schema documentation');

        $outputFormat = $input->getOption('output-format');

        // Validate output format
        if (!in_array($outputFormat, ['md', 'html'])) {
            $io->error('Invalid output format. Allowed values are: md, html');
            return Command::FAILURE;
        }

        $outputFile = $input->getOption('output-file');
        if (!$outputFile) {
            $defaultFilename = static::DEFAULT_OUTPUT_FILENAME;
            $defaultFilename .= $outputFormat === 'html' ? '.html' : '.md';
            $outputFile = getcwd() . '/' . $defaultFilename;
        }

        // Get all resource types
        $resourceTypes = $this->schemaService->getResourceTypes();
        sort($resourceTypes, SORT_STRING);
        $io->section('Fetching resource types');
        $io->text(sprintf('Found %d resource types', count($resourceTypes)));

        if (empty($resourceTypes)) {
            $io->warning('No resource types found');
            return Command::SUCCESS;
        }

        // Generate documentation content
        $io->section('Generating documentation');
        $documentationContent = $this->schemaService->generateDocumentation($resourceTypes);

        // Convert to HTML if requested
        if ($outputFormat === 'html') {
            $io->text('Converting Markdown to HTML');
            $parsedown = new Parsedown();
            $documentationContent = $this->wrapHtmlContent($parsedown->text($documentationContent));
        }

        // Write the documentation file
        $io->section('Writing documentation file');
        file_put_contents($outputFile, $documentationContent);

        $io->success([
            sprintf('Successfully generated %s documentation for %d resource types', strtoupper($outputFormat), count($resourceTypes)),
            sprintf('Documentation is available at: %s', $outputFile)
        ]);

        return Command::SUCCESS;
    }

    private function wrapHtmlContent(string $htmlContent): string
    {
        $document_title = SchemaService::DOCUMENTATION_TITLE;

        // Read CSS rules from a stylesheet file
        $cssPath = __DIR__ . '/../../public/style.css';
        $cssContent = file_exists($cssPath) ? file_get_contents($cssPath) : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$document_title}</title>
    <style>
{$cssContent}
    </style>
</head>
<body>
{$htmlContent}
</body>
</html>
HTML;
    }
}
