<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\UpdateCommand;
use App\Service\AppDataService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Integration tests for the `update` console command.
 *
 * The HTTP client is replaced with a MockHttpClient so no real network requests
 * are made and the tests remain deterministic and fast.
 */
class UpdateCommandTest extends TestCase
{
    private string $tmpSchemaDir;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->tmpSchemaDir = sys_get_temp_dir() . '/fega-update-test-' . bin2hex(random_bytes(6));
        $this->filesystem->mkdir($this->tmpSchemaDir);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->tmpSchemaDir);
    }

    /**
     * Builds a minimal schema payload that passes structural checks.
     */
    private function buildValidApiPayload(array $extraSchemas = []): string
    {
        $study = [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required' => ['name'],
            'x-resource' => [
                'schema' => [
                    'fields' => [['name' => 'name', 'constraints' => ['required' => true]]],
                    'primaryKey' => ['name'],
                ],
            ],
        ];

        $schemas = array_merge(['Study' => ['data_schema' => $study]], $extraSchemas);
        return json_encode($schemas);
    }

    private function buildCommand(MockHttpClient $httpClient): UpdateCommand
    {
        $appDataService = $this->createStub(AppDataService::class);
        $appDataService->method('getSchemaDirectory')->willReturn($this->tmpSchemaDir);

        $params = new ParameterBag([
            'api.base_url' => 'https://api.example.test',
            'app.schema_dir' => $this->tmpSchemaDir,
        ]);

        return new UpdateCommand($params, $httpClient, $this->filesystem, $appDataService);
    }

    public function testUpdateCreatesSchemaFilesOnSuccess(): void
    {
        $mockResponse = new MockResponse($this->buildValidApiPayload(), ['http_code' => 200]);
        $command = $this->buildCommand(new MockHttpClient($mockResponse));

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFileExists($this->tmpSchemaDir . '/Study.json');

        // Verify the created schema is valid JSON
        $written = json_decode(file_get_contents($this->tmpSchemaDir . '/Study.json'), true);
        self::assertIsArray($written);
        self::assertArrayHasKey('type', $written);
    }

    public function testUpdateDeletesExistingSchemaFilesBeforeWritingNew(): void
    {
        // Pre-populate with an old schema
        file_put_contents($this->tmpSchemaDir . '/OldType.json', '{}');

        $mockResponse = new MockResponse($this->buildValidApiPayload(), ['http_code' => 200]);
        $command = $this->buildCommand(new MockHttpClient($mockResponse));

        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertFileDoesNotExist($this->tmpSchemaDir . '/OldType.json');
        self::assertFileExists($this->tmpSchemaDir . '/Study.json');
    }

    public function testUpdateFailsWhenApiReturnsNonJsonBody(): void
    {
        $mockResponse = new MockResponse('this is not json', ['http_code' => 200]);
        $command = $this->buildCommand(new MockHttpClient($mockResponse));

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
    }

    public function testUpdateFailsWhenApiReturnsEmptySchemaList(): void
    {
        $mockResponse = new MockResponse('{}', ['http_code' => 200]);
        $command = $this->buildCommand(new MockHttpClient($mockResponse));

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
    }

    public function testUpdateFailsWhenApiResponseIsMissingDataSchema(): void
    {
        // data_schema key is absent → no schemas with table schemas → empty
        $payload = json_encode(['Study' => ['other_key' => ['type' => 'object']]]);
        $mockResponse = new MockResponse($payload, ['http_code' => 200]);
        $command = $this->buildCommand(new MockHttpClient($mockResponse));

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
    }

    public function testUpdateSkipsSchemasWithoutXResourceEnvelope(): void
    {
        // Schemas missing x-resource.schema are silently skipped by UpdateCommand.
        // The command returns SUCCESS but creates 0 schema files.
        $payload = json_encode([
            'Study' => [
                'data_schema' => [
                    'type' => 'object',
                    'properties' => ['name' => ['type' => 'string']],
                ],
            ],
        ]);
        $mockResponse = new MockResponse($payload, ['http_code' => 200]);
        $command = $this->buildCommand(new MockHttpClient($mockResponse));

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Created 0 files', $tester->getDisplay());
    }

    public function testUpdateFailsOnNetworkError(): void
    {
        $mockResponse = new MockResponse('', [
            'http_code' => 500,
            'error' => 'Simulated network failure',
        ]);
        $command = $this->buildCommand(new MockHttpClient($mockResponse));

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
    }

    public function testStackTraceIsNotExposedInVerboseMode(): void
    {
        $mockResponse = new MockResponse('not-json', ['http_code' => 200]);
        $command = $this->buildCommand(new MockHttpClient($mockResponse));

        $tester = new CommandTester($command);
        $tester->execute(
            [],
            ['verbosity' => \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE]
        );

        $output = $tester->getDisplay();
        self::assertStringNotContainsString('#0 ', $output, 'Stack trace must not appear in verbose mode');
    }

    public function testStackTraceIsExposedInDebugMode(): void
    {
        $mockResponse = new MockResponse('not-json', ['http_code' => 200]);
        $command = $this->buildCommand(new MockHttpClient($mockResponse));

        $tester = new CommandTester($command);
        $tester->execute(
            [],
            ['verbosity' => \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_DEBUG]
        );

        $output = $tester->getDisplay();
        // In debug (-vvv) mode the stack trace SHOULD appear
        self::assertStringContainsString('#0 ', $output, 'Stack trace should appear in debug (-vvv) mode');
    }
}
