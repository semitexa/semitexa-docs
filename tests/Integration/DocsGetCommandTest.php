<?php

declare(strict_types=1);

namespace Semitexa\Docs\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Docs\Application\Console\Command\DocsGetCommand;
use Semitexa\Docs\Application\Service\DocumentFrontMatterParser;
use Semitexa\Docs\Application\Service\DocumentHtmlRenderer;
use Semitexa\Docs\Application\Service\FileDocumentRepository;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class DocsGetCommandTest extends TestCase
{
    #[Test]
    public function outputs_canonical_markdown(): void
    {
        $tester = $this->createTester();
        $tester->execute([
            'document-id' => 'get-started/installation',
            '--format' => 'markdown',
        ]);

        $output = $tester->getDisplay();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('# Installation', $output);
        self::assertStringContainsString('## Canonical flow', $output);
        self::assertStringNotContainsString('<article class="sx-docs-fragment"', $output);
    }

    #[Test]
    public function outputs_rendered_html_fragment(): void
    {
        $tester = $this->createTester();
        $tester->execute([
            'document-id' => 'get-started/installation',
            '--format' => 'html',
        ]);

        $output = $tester->getDisplay();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('<article class="sx-docs-fragment" data-doc-id="get-started/installation"', $output);
        self::assertStringContainsString('<h1>Installation</h1>', $output);
        self::assertStringContainsString('<h2>Canonical flow</h2>', $output);
    }

    #[Test]
    public function rejects_unknown_output_format(): void
    {
        $tester = $this->createTester();
        $tester->execute([
            'document-id' => 'get-started/installation',
            '--format' => 'xml',
        ]);

        $output = $tester->getDisplay();

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Unsupported format "xml"', $output);
    }

    private function createTester(): CommandTester
    {
        $command = new DocsGetCommand(
            repository: $this->createRepository(),
            renderer: new DocumentHtmlRenderer(),
        );
        $command->setName('docs:get');

        $application = new Application('Test', '1.0');
        $application->setAutoExit(false);
        $application->add($command);

        return new CommandTester($application->find('docs:get'));
    }

    private function createRepository(): FileDocumentRepository
    {
        $repository = new FileDocumentRepository();
        $property = new \ReflectionProperty($repository, 'frontMatterParser');
        $property->setValue($repository, new DocumentFrontMatterParser());

        return $repository;
    }
}
