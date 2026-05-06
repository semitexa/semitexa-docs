<?php

declare(strict_types=1);

namespace Semitexa\Docs\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Docs\Application\Service\DocumentManifestBuilder;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'docs:list', description: 'List canonical Semitexa documents by section')]
final class DocsListCommand extends BaseCommand
{
    public function __construct(
        private readonly DocumentManifestBuilder $manifestBuilder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Requested locale', 'en');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $localeOption = $input->getOption('locale');

        try {
            $manifest = $this->manifestBuilder->buildBySection(is_string($localeOption) ? $localeOption : '');
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return self::FAILURE;
        }

        if ((bool) $input->getOption('json')) {
            $payload = [];
            foreach ($manifest as $section => $items) {
                $payload[$section] = array_map(
                    static fn ($item): array => [
                        'id' => $item->id->toString(),
                        'title' => $item->metadata->title,
                        'summary' => $item->metadata->summary,
                        'order' => $item->metadata->order,
                        'locale' => $item->metadata->locale,
                    ],
                    $items,
                );
            }

            $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                $io->error('Failed to encode docs manifest JSON: ' . json_last_error_msg());
                return self::FAILURE;
            }

            $output->writeln($encoded);
            return self::SUCCESS;
        }

        $io->title('Semitexa Docs');

        foreach ($manifest as $section => $items) {
            $io->section($section);
            foreach ($items as $item) {
                $io->text(sprintf(
                    '- %s: %s',
                    $item->id->toString(),
                    $item->metadata->title,
                ));
            }
        }

        return self::SUCCESS;
    }
}
