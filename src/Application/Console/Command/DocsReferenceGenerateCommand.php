<?php

declare(strict_types=1);

namespace Semitexa\Docs\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Docs\Application\Service\ReferenceGenerator;
use Semitexa\Docs\Application\Service\TruthIndexBuilder;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes the reference section from the framework's own surface.
 *
 * Run it as part of a release. `--check` is the CI form: it regenerates into
 * memory and fails if what is on disk differs, which catches a signature that
 * changed without the page being rebuilt.
 */
#[AsCommand(
    name: 'docs:reference:generate',
    description: 'Generate the reference section (attributes, commands, events) from the truth index',
)]
final class DocsReferenceGenerateCommand extends BaseCommand
{
    public function __construct(
        private readonly TruthIndexBuilder $truthIndexBuilder,
        private readonly ReferenceGenerator $generator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Docs root to write into (defaults to this package\'s docs/en)')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Do not write; fail if the generated output differs from what is on disk');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $out = $input->getOption('out');
        $root = is_string($out) && $out !== '' ? rtrim($out, '/') : dirname(__DIR__, 4) . '/docs/en';

        $files = $this->generator->generate($this->truthIndexBuilder->build($this->getApplication()));

        if ((bool) $input->getOption('check')) {
            // A consumer project installs its own mix of semitexa/* packages, so
            // regenerating there legitimately produces different pages than the
            // ones the docs package shipped. Checking a read-only vendor copy
            // against a local installation would fail for everyone, every time,
            // which is how a gate teaches people to ignore it.
            if ($out === null && str_contains(realpath($root) ?: $root, '/vendor/')) {
                $io->success('Reference pages ship with semitexa/docs; nothing to check against a vendor copy.');

                return self::SUCCESS;
            }

            return $this->check($io, $root, $files);
        }

        foreach ($files as $relative => $contents) {
            $path = $root . '/' . $relative;
            $directory = dirname($path);

            if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
                $io->error(sprintf('Cannot create %s', $directory));

                return self::FAILURE;
            }

            if (file_put_contents($path, $contents) === false) {
                $io->error(sprintf('Cannot write %s', $path));

                return self::FAILURE;
            }
        }

        $io->success(sprintf('Wrote %d reference page(s) into %s', count($files), $root));

        return self::SUCCESS;
    }

    /**
     * @param array<string, string> $files
     */
    private function check(SymfonyStyle $io, string $root, array $files): int
    {
        $stale = [];
        foreach ($files as $relative => $contents) {
            $path = $root . '/' . $relative;
            if (!is_file($path) || file_get_contents($path) !== $contents) {
                $stale[] = $relative;
            }
        }

        if ($stale === []) {
            $io->success(sprintf('All %d reference page(s) match the code.', count($files)));

            return self::SUCCESS;
        }

        $io->error(sprintf('%d reference page(s) no longer match the code:', count($stale)));
        $io->listing(array_slice($stale, 0, 20));
        $io->text('Run `bin/semitexa docs:reference:generate` and commit the result.');

        return self::FAILURE;
    }
}
