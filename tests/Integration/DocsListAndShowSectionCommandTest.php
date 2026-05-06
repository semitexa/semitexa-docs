<?php

declare(strict_types=1);

namespace Semitexa\Docs\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Docs\Application\Console\Command\DocsListCommand;
use Semitexa\Docs\Application\Console\Command\DocsShowSectionCommand;
use Semitexa\Docs\Application\Service\DocumentFrontMatterParser;
use Semitexa\Docs\Application\Service\DocumentManifestBuilder;
use Semitexa\Docs\Application\Service\FileDocumentRepository;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class DocsListAndShowSectionCommandTest extends TestCase
{
    #[Test]
    public function lists_sections_as_json(): void
    {
        $tester = $this->createListTester();
        $tester->execute(['--json' => true]);

        $decoded = json_decode($tester->getDisplay(), true);

        self::assertSame(0, $tester->getStatusCode());
        self::assertIsArray($decoded);
        self::assertArrayHasKey('get-started', $decoded);
        self::assertSame('get-started/installation', $decoded['get-started'][0]['id']);
    }

    #[Test]
    public function shows_one_section_as_json(): void
    {
        $tester = $this->createShowSectionTester();
        $tester->execute([
            'section' => 'get-started',
            '--json' => true,
        ]);

        $decoded = json_decode($tester->getDisplay(), true);

        self::assertSame(0, $tester->getStatusCode());
        self::assertIsArray($decoded);
        self::assertSame('semitexa.docs-section/v1', $decoded['artifact']);
        self::assertSame('get-started', $decoded['section']);
        self::assertIsArray($decoded['documents']);
        self::assertSame('get-started/installation', $decoded['documents'][0]['id']);
    }

    #[Test]
    public function shows_one_section_as_human_readable_output(): void
    {
        $tester = $this->createShowSectionTester();
        $tester->execute([
            'section' => 'get-started',
        ]);

        $output = $tester->getDisplay();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Docs Section: get-started', $output);
        self::assertStringContainsString('get-started/installation', $output);
    }

    private function createListTester(): CommandTester
    {
        $command = new DocsListCommand($this->createManifestBuilder());
        $command->setName('docs:list');

        $application = new Application('Test', '1.0');
        $application->setAutoExit(false);
        $application->add($command);

        return new CommandTester($application->find('docs:list'));
    }

    private function createShowSectionTester(): CommandTester
    {
        $command = new DocsShowSectionCommand($this->createManifestBuilder());
        $command->setName('docs:show-section');

        $application = new Application('Test', '1.0');
        $application->setAutoExit(false);
        $application->add($command);

        return new CommandTester($application->find('docs:show-section'));
    }

    private function createManifestBuilder(): DocumentManifestBuilder
    {
        $builder = new DocumentManifestBuilder();
        $property = new \ReflectionProperty($builder, 'repository');
        $property->setValue($builder, $this->createRepository());

        return $builder;
    }

    private function createRepository(): FileDocumentRepository
    {
        $repository = new FileDocumentRepository();
        $property = new \ReflectionProperty($repository, 'frontMatterParser');
        $property->setValue($repository, new DocumentFrontMatterParser());

        return $repository;
    }
}
