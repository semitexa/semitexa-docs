<?php

declare(strict_types=1);

namespace Semitexa\Docs\Application\Console;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Docs\Application\Service\DocumentManifestBuilder;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'docs:show-section', description: 'Show canonical documents for one Docs section')]
final class DocsShowSectionCommand extends BaseCommand
{
    public function __construct(
        private readonly DocumentManifestBuilder $manifestBuilder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('section', InputArgument::REQUIRED, 'Section key, for example "get-started"')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Requested locale', 'en');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sectionArgument = $input->getArgument('section');
        $localeOption = $input->getOption('locale');
        $section = is_string($sectionArgument) ? $sectionArgument : '';
        $locale = is_string($localeOption) ? $localeOption : '';

        try {
            $manifest = $this->manifestBuilder->buildBySection($locale);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return self::FAILURE;
        }

        if (!isset($manifest[$section])) {
            $io->error(sprintf('Section "%s" was not found.', $section));
            return self::FAILURE;
        }

        if ((bool) $input->getOption('json')) {
            $payload = [
                'artifact' => 'semitexa.docs-section/v1',
                'section' => $section,
                'locale' => $locale,
                'documents' => array_map(
                    static fn ($item): array => [
                        'id' => $item->id->toString(),
                        'title' => $item->metadata->title,
                        'summary' => $item->metadata->summary,
                        'order' => $item->metadata->order,
                        'locale' => $item->metadata->locale,
                    ],
                    $manifest[$section],
                ),
            ];

            $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                $io->error('Failed to encode docs section JSON: ' . json_last_error_msg());
                return self::FAILURE;
            }

            $output->writeln($encoded);
            return self::SUCCESS;
        }

        $io->title(sprintf('Docs Section: %s', $section));
        foreach ($manifest[$section] as $item) {
            $io->text(sprintf('- %s: %s', $item->id->toString(), $item->metadata->summary));
        }

        return self::SUCCESS;
    }
}
