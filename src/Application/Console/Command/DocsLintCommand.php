<?php

declare(strict_types=1);

namespace Semitexa\Docs\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Docs\Application\Service\DocumentationClaimLinter;
use Semitexa\Docs\Application\Service\TruthIndexBuilder;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fails when the documentation claims something the framework does not have.
 *
 * A rename is invisible to prose: the code moves on, the page keeps teaching the
 * old name, and nobody finds out until a reader copies it. This turns that into
 * a build failure.
 *
 * The corpus starts with known debt, so a baseline can be recorded and the
 * command then fails only on claims that were not already broken — the point is
 * to stop the bleeding first and drain the backlog on purpose.
 */
#[AsCommand(
    name: 'docs:lint',
    description: 'Check documented attributes, commands and env keys against the framework surface',
)]
final class DocsLintCommand extends BaseCommand
{
    public function __construct(
        private readonly TruthIndexBuilder $truthIndexBuilder,
        private readonly DocumentationClaimLinter $linter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('path', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Documentation root to scan (repeatable)')
            ->addOption('index', null, InputOption::VALUE_REQUIRED, 'Compare against a stored truth index instead of the live one')
            ->addOption('baseline', null, InputOption::VALUE_REQUIRED, 'Ignore findings listed in this baseline file')
            ->addOption('write-baseline', null, InputOption::VALUE_REQUIRED, 'Record current findings as the baseline and succeed')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output findings as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $index = $this->resolveIndex($input, $io);
        if ($index === null) {
            return self::FAILURE;
        }

        $roots = $this->resolveRoots($input);
        if ($roots === []) {
            $io->error('No documentation roots to scan.');

            return self::FAILURE;
        }

        $report = $this->linter->lint($index, $roots);
        $projectRoot = ProjectRoot::get();

        /** @var list<array<string, mixed>> $findings */
        $findings = $report['findings'];

        $writeBaseline = $input->getOption('write-baseline');
        if (is_string($writeBaseline) && $writeBaseline !== '') {
            return $this->writeBaseline($io, $findings, $projectRoot, $writeBaseline);
        }

        $baseline = $this->loadBaseline($input, $io);
        if ($baseline === null) {
            return self::FAILURE;
        }

        if ($baseline !== []) {
            $findings = array_values(array_filter(
                $findings,
                fn (array $finding): bool => !in_array(
                    $this->linter->fingerprint($finding, $projectRoot),
                    $baseline,
                    true,
                ),
            ));
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode(
                ['artifact' => DocumentationClaimLinter::ARTIFACT] + $report + ['findings' => $findings],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return $findings === [] ? self::SUCCESS : self::FAILURE;
        }

        return $this->report($io, $report, $findings, $projectRoot, $baseline !== []);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveIndex(InputInterface $input, SymfonyStyle $io): ?array
    {
        $path = $input->getOption('index');
        if (!is_string($path) || $path === '') {
            return $this->truthIndexBuilder->build($this->getApplication());
        }

        if (!is_file($path)) {
            $io->error(sprintf('Truth index not found: %s', $path));

            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            $io->error(sprintf('Truth index is not valid JSON: %s', $path));

            return null;
        }

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function resolveRoots(InputInterface $input): array
    {
        /** @var list<string> $paths */
        $paths = (array) $input->getOption('path');
        $paths = array_values(array_filter($paths, static fn (string $p): bool => $p !== ''));

        if ($paths !== []) {
            return $paths;
        }

        // The package's own docs tree is the canonical corpus; it is where the
        // ownership policy says every page now lives.
        $default = dirname(__DIR__, 4) . '/docs';

        return is_dir($default) ? [$default] : [];
    }

    /**
     * @return list<string>|null
     */
    private function loadBaseline(InputInterface $input, SymfonyStyle $io): ?array
    {
        $path = $input->getOption('baseline');
        if (!is_string($path) || $path === '') {
            return [];
        }

        if (!is_file($path)) {
            $io->error(sprintf('Baseline not found: %s', $path));

            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || !isset($decoded['ignored']) || !is_array($decoded['ignored'])) {
            $io->error(sprintf('Baseline is not a docs:lint baseline: %s', $path));

            return null;
        }

        /** @var list<string> $ignored */
        $ignored = array_values(array_filter($decoded['ignored'], 'is_string'));

        return $ignored;
    }

    /**
     * @param list<array<string, mixed>> $findings
     */
    private function writeBaseline(SymfonyStyle $io, array $findings, string $projectRoot, string $path): int
    {
        $ignored = [];
        foreach ($findings as $finding) {
            /** @var array{file: string, kind: string, claim: string} $finding */
            $ignored[] = $this->linter->fingerprint($finding, $projectRoot);
        }

        $ignored = array_values(array_unique($ignored));
        sort($ignored);

        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            $io->error(sprintf('Cannot create directory %s', $directory));

            return self::FAILURE;
        }

        $encoded = json_encode(
            ['artifact' => DocumentationClaimLinter::ARTIFACT . '.baseline', 'ignored' => $ignored],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );

        if ($encoded === false || file_put_contents($path, $encoded . "\n") === false) {
            $io->error(sprintf('Cannot write %s', $path));

            return self::FAILURE;
        }

        $io->success(sprintf('Recorded %d known finding(s) as the baseline in %s', count($ignored), $path));
        $io->text('Anything new fails from here. Drain this list on purpose, not by editing it.');

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed>       $report
     * @param list<array<string, mixed>> $findings
     */
    private function report(SymfonyStyle $io, array $report, array $findings, string $projectRoot, bool $baselined): int
    {
        $io->title('Documentation claims');
        $io->text(sprintf('Scanned %d file(s).', (int) $report['files_scanned']));

        if ($findings === []) {
            $io->success($baselined
                ? 'No new claims beyond the baseline.'
                : 'Every documented attribute, command and env key exists.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($findings as $finding) {
            /** @var array{file: string, line: int, kind: string, claim: string, suggestion: ?string} $finding */
            $file = str_starts_with($finding['file'], $projectRoot)
                ? ltrim(substr($finding['file'], strlen($projectRoot)), '/')
                : $finding['file'];

            $rows[] = [
                $file . ':' . $finding['line'],
                $finding['kind'],
                $finding['claim'],
                $finding['suggestion'] ?? '—',
            ];
        }

        $io->table(['where', 'kind', 'claimed', 'did you mean'], $rows);
        $io->error(sprintf('%d claim(s) with nothing behind them.', count($findings)));

        return self::FAILURE;
    }
}
