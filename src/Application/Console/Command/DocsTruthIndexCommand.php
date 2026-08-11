<?php

declare(strict_types=1);

namespace Semitexa\Docs\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Docs\Application\Service\TruthIndexBuilder;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Emits the framework's public surface as data — the ground truth every
 * documentation claim is checked against.
 *
 * Write it to a file with `--out` and diff two releases to see exactly which
 * part of the surface moved, which is the same question "did this rename break
 * a page?" reduces to.
 */
#[AsCommand(
    name: 'docs:truth-index',
    description: 'Emit the framework public surface (attributes, commands, contracts, routes, events, env keys, Twig) as JSON',
)]
final class DocsTruthIndexCommand extends BaseCommand
{
    public function __construct(
        private readonly TruthIndexBuilder $builder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the index as JSON (default when --out is absent)')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Write the index to this path instead of stdout');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $index = $this->builder->build($this->getApplication());

        $encoded = json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            $io->error('Failed to encode the truth index: ' . json_last_error_msg());

            return self::FAILURE;
        }

        $out = $input->getOption('out');
        if (is_string($out) && $out !== '') {
            $directory = dirname($out);
            if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
                $io->error(sprintf('Cannot create directory %s', $directory));

                return self::FAILURE;
            }

            if (file_put_contents($out, $encoded . "\n") === false) {
                $io->error(sprintf('Cannot write %s', $out));

                return self::FAILURE;
            }

            $io->success(sprintf('Truth index written to %s', $out));
            $this->summarize($io, $index);

            return self::SUCCESS;
        }

        if ((bool) $input->getOption('json') || !$output->isDecorated()) {
            $output->writeln($encoded);

            return self::SUCCESS;
        }

        $io->title('Semitexa public surface');
        $this->summarize($io, $index);

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $index
     */
    private function summarize(SymfonyStyle $io, array $index): void
    {
        $rows = [];
        foreach (['attributes', 'commands', 'contracts', 'routes', 'events', 'env_keys'] as $section) {
            $value = $index[$section] ?? [];
            $rows[] = [$section, is_array($value) ? (string) count($value) : '0'];
        }

        $twig = $index['twig'] ?? ['functions' => [], 'filters' => []];
        $rows[] = ['twig functions', (string) count((array) ($twig['functions'] ?? []))];
        $rows[] = ['twig filters', (string) count((array) ($twig['filters'] ?? []))];

        $io->table(['surface', 'count'], $rows);

        /** @var list<string> $incomplete */
        $incomplete = $index['incomplete'] ?? [];
        foreach ($incomplete as $note) {
            $io->warning($note);
        }
    }
}
