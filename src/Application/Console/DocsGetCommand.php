<?php

declare(strict_types=1);

namespace Semitexa\Docs\Application\Console;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Docs\Application\Document\DocumentId;
use Semitexa\Docs\Application\Service\DocumentHtmlRenderer;
use Semitexa\Docs\Application\Service\FileDocumentRepository;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'docs:get', description: 'Get a canonical Semitexa document as Markdown or HTML')]
final class DocsGetCommand extends BaseCommand
{
    public function __construct(
        private readonly FileDocumentRepository $repository,
        private readonly DocumentHtmlRenderer $renderer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('document-id', InputArgument::REQUIRED, 'Document id in "<section>/<slug>" format')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: markdown or html', 'markdown')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Requested locale', 'en');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $documentId = $input->getArgument('document-id');
        $localeOption = $input->getOption('locale');
        $formatOption = $input->getOption('format');

        try {
            $id = DocumentId::fromString(is_string($documentId) ? $documentId : '');
            $document = $this->repository->find($id, is_string($localeOption) ? $localeOption : '');
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return self::FAILURE;
        }

        if ($document === null) {
            $io->error(sprintf('Document "%s" was not found.', $id->toString()));
            return self::FAILURE;
        }

        $format = strtolower(is_string($formatOption) ? $formatOption : '');
        $rendered = match ($format) {
            'markdown' => $this->renderer->renderMarkdown($document),
            'html' => $this->renderer->renderHtml($document),
            default => null,
        };

        if ($rendered === null) {
            $io->error(sprintf('Unsupported format "%s". Use "markdown" or "html".', $format));
            return self::FAILURE;
        }

        $output->writeln($rendered->content);

        return self::SUCCESS;
    }
}
