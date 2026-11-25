<?php

declare(strict_types=1);

namespace Semitexa\Docs\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Docs\Application\Service\TruthIndexBuilder;
use Semitexa\Docs\Tests\Support\UnreadableFileStream;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * The index is the yardstick every documentation claim is measured against, so
 * the failures worth guarding are the ones that would quietly change what
 * counts as "exists": a command surface that loses its options, or a name
 * counted twice because two sources describe it.
 */
final class TruthIndexBuilderTest extends TestCase
{
    #[Test]
    public function an_unreadable_ultimate_manifest_fails_instead_of_reporting_no_release(): void
    {
        // Returning null here would be indistinguishable from "not installed":
        // every generated page would be stamped `unverified` and the release
        // half of docs:lint would switch itself off, both silently.
        self::assertTrue(stream_wrapper_register('unreadable', UnreadableFileStream::class));
        // Snapshot rather than reset(): reset() nulls the root, which is not
        // the same as putting back whatever the suite had already resolved.
        $root = new \ReflectionProperty(ProjectRoot::class, 'root');
        $previous = $root->getValue();
        $root->setValue(null, 'unreadable://workspace');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/Cannot read .*composer\.json.* to resolve the release version/');
            $this->build($this->applicationWithSampleCommand());
        } finally {
            $root->setValue(null, $previous);
            stream_wrapper_unregister('unreadable');
        }
    }

    /**
     * Every way the framework reads an environment key counts. Only getEnvValue()
     * and EnvReader::get*() were read, so docs:lint called real keys unknown:
     * SWOOLE_LOG_ROTATION (read through Environment's $get closure) on core#253,
     * BOOT_STRICT_MODE (a bare getenv()) on core#271.
     *
     * The project itself sits under a directory named tools: only a tests or
     * tools directory inside the scanned root is left out, not a checkout that
     * happens to live under one (review of the follow-up).
     */
    #[Test]
    public function every_way_the_code_reads_an_environment_key_counts(): void
    {
        $root = new \ReflectionProperty(ProjectRoot::class, 'root');
        $previous = $root->getValue();
        $base = sys_get_temp_dir() . '/semitexa-env-keys-' . bin2hex(random_bytes(4));
        $workspace = $base . '/tools/checkout';
        mkdir($workspace . '/packages/semitexa-probe/src', 0755, true);
        file_put_contents($workspace . '/packages/semitexa-probe/src/Reader.php', <<<'PHP'
            <?php
            final class Reader
            {
                #[Config(env: 'WEBHOOK_BACKOFF_MULTIPLIER', default: 2.0)]
                protected float $multiplier = 2.0;

                #[Config(default: 30, env: 'WEBHOOK_TIMEOUT_SECONDS')]
                protected int $timeout = 30;

                public function read(\Closure $get): void
                {
                    $get('SWOOLE_LOG_ROTATION', 'daily');
                    getenv('BOOT_STRICT_MODE');
                    $_ENV['MAIL_FROM_NAME'] ?? null;
                    Environment::getEnvValue('APP_ENV');
                    $lowercase = getenv('not_a_key');
                }
            }
            PHP);
        // One more way a key reaches the code, and one reader that is no setting.
        file_put_contents($workspace . '/packages/semitexa-probe/src/Scheduled.php', <<<'PHP'
            <?php
            final class Scheduled
            {
                #[AsScheduledJob(cron: 'env::PROBE_SEO_CRON::0 3 * * *')]
                public function run(): void
                {
                }
            }
            PHP);
        // An editor's lock file: a link to nothing with a .php name, skipped rather than failed.
        symlink('someone@host.4242', $workspace . '/packages/semitexa-probe/src/.#Reader.php');
        mkdir($workspace . '/packages/semitexa-probe/tests', 0755, true);
        file_put_contents($workspace . '/packages/semitexa-probe/tests/ReaderTest.php', "<?php\ngetenv('PROBE_TEST_ONLY');\n");
        $root->setValue(null, $workspace);

        try {
            $keys = $this->build($this->applicationWithSampleCommand())['env_keys'];
        } finally {
            $root->setValue(null, $previous);
            exec('rm -rf ' . escapeshellarg($base));
        }

        self::assertSame(['APP_ENV', 'BOOT_STRICT_MODE', 'MAIL_FROM_NAME', 'PROBE_SEO_CRON', 'SWOOLE_LOG_ROTATION', 'WEBHOOK_BACKOFF_MULTIPLIER', 'WEBHOOK_TIMEOUT_SECONDS'], $keys, 'and not PROBE_TEST_ONLY, which only a test reads');
    }

    /**
     * A source file the scan cannot read fails the index (review, docs#195).
     * Read as empty, its keys would silently go missing, and docs:lint would
     * then call real settings unknown. Every scan reads through readSource().
     * The path stats as a regular file and refuses to open: the container
     * runs as root, which a mode-000 file would not stop.
     */
    #[Test]
    public function a_source_file_that_cannot_be_read_fails_the_index(): void
    {
        $read = new \ReflectionMethod(TruthIndexBuilder::class, 'readSource');
        self::assertTrue(stream_wrapper_register('unreadable', UnreadableFileStream::class));

        try {
            self::assertTrue(is_file('unreadable://src/Locked.php'), 'the path passes the scans\' regular-file check');
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Cannot read "unreadable://src/Locked.php" for the truth index.');
            $read->invoke(null, 'unreadable://src/Locked.php');
        } finally {
            stream_wrapper_unregister('unreadable');
        }
    }

    /** An empty source file is read, as empty, and not taken for one that cannot be read. */
    #[Test]
    public function an_empty_source_file_reads_as_empty(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'empty-source');
        try {
            self::assertSame('', (new \ReflectionMethod(TruthIndexBuilder::class, 'readSource'))->invoke(null, $file));
        } finally {
            @unlink($file);
        }
    }

    /** A checkout counts only with an exact version to be newer than (review, docs#139). */
    #[Test]
    public function a_checkout_is_named_only_when_its_manifest_pins_an_exact_core(): void
    {
        $root = new \ReflectionProperty(ProjectRoot::class, 'root');
        $previous = $root->getValue();
        $previousRelease = getenv('SEMITEXA_RELEASE_VERSION');
        putenv('SEMITEXA_RELEASE_VERSION');
        $workspace = sys_get_temp_dir() . '/semitexa-checkout-' . bin2hex(random_bytes(4));
        mkdir($workspace . '/packages/semitexa-ultimate', 0755, true);
        $root->setValue(null, $workspace);
        $seen = [];
        try {
            foreach (['2026.11.10.0849', '^2026.11.10.0849'] as $core) {
                file_put_contents($workspace . '/packages/semitexa-ultimate/composer.json', json_encode(['require' => ['semitexa/core' => $core]]));
                $index = $this->build($this->applicationWithSampleCommand());
                $seen[$core] = [$index['release_version'], $index['release_version_from_checkout']];
            }
        } finally {
            $root->setValue(null, $previous);
            putenv($previousRelease === false ? 'SEMITEXA_RELEASE_VERSION' : 'SEMITEXA_RELEASE_VERSION=' . $previousRelease);
            exec('rm -rf ' . escapeshellarg($workspace));
        }

        self::assertSame(['2026.11.10.0849', true], $seen['2026.11.10.0849']);
        self::assertSame([null, false], $seen['^2026.11.10.0849'], 'a constraint is no release: the pages stay unverified, never next');
    }

    #[Test]
    public function console_commands_keep_their_arguments_and_options(): void
    {
        // Pinned: an app built from a branch checkout has no release, and this
        // test is about the command surface, not about where it runs.
        $previousRelease = getenv('SEMITEXA_RELEASE_VERSION');
        putenv('SEMITEXA_RELEASE_VERSION=2026.09.19.1020');
        try {
            $index = $this->build($this->applicationWithSampleCommand());
        } finally {
            putenv($previousRelease === false ? 'SEMITEXA_RELEASE_VERSION' : 'SEMITEXA_RELEASE_VERSION=' . $previousRelease);
        }

        self::assertSame('2026.09.19.1020', $index['release_version']);

        $command = $this->commandNamed($index, 'demo:thing');

        self::assertSame('console', $command['kind']);
        self::assertSame([
            ['name' => 'target', 'required' => true, 'array' => false],
            ['name' => 'more', 'required' => false, 'array' => true],
        ], $command['arguments']);
        self::assertContains(
            ['name' => '--force', 'accepts_value' => false],
            $command['options'],
            'An option documented as a flag must be readable as one from the index.',
        );
        self::assertNotContains(
            '--help',
            array_column($command['options'], 'name'),
            "The runner's own options belong to bin/semitexa, not to the command.",
        );
    }

    #[Test]
    public function a_wrapper_name_that_forwards_to_php_is_not_counted_twice(): void
    {
        $index = $this->build($this->applicationWithSampleCommand());

        $names = array_column($index['commands'], 'name');

        self::assertSame(
            array_values(array_unique($names)),
            $names,
            'The shell wrapper lists commands it merely forwards; those must not appear a second time.',
        );
    }

    #[Test]
    public function an_unreadable_surface_is_reported_rather_than_returned_empty(): void
    {
        $index = $this->build(null);

        self::assertNotEmpty(
            $index['incomplete'],
            'Without a console Application the command surface is unknown, not empty — silence there would read as "no commands exist".',
        );
    }

    #[Test]
    public function the_command_surface_does_not_depend_on_what_already_ran(): void
    {
        // Symfony merges the application definition into a command the first
        // time it runs. Read raw, an identical command reports seven more
        // options once it has been used, and every page built from it changes
        // for no reason a reader could see.
        $application = $this->applicationWithSampleCommand();
        $cold = $this->build($application);

        $application->find('demo:thing')->mergeApplicationDefinition();
        $warm = $this->build($application);

        self::assertSame(
            $this->commandNamed($cold, 'demo:thing'),
            $this->commandNamed($warm, 'demo:thing'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function build(?Application $console): array
    {
        $builder = new TruthIndexBuilder();

        $classDiscovery = $this->createMock(ClassDiscovery::class);
        $classDiscovery->method('findClassesWithAttribute')->willReturn([]);

        $attributeDiscovery = $this->createMock(AttributeDiscovery::class);
        $attributeDiscovery->method('getEnrichedRoutes')->willReturn([]);

        // The service takes its dependencies as injected properties, which is
        // the framework's one DI channel; a test stands in for the container.
        foreach ([
            'classDiscovery' => $classDiscovery,
            'attributeDiscovery' => $attributeDiscovery,
            'moduleRegistry' => $this->createMock(ModuleRegistry::class),
        ] as $property => $double) {
            (new \ReflectionProperty(TruthIndexBuilder::class, $property))->setValue($builder, $double);
        }

        return $builder->build($console);
    }

    private function applicationWithSampleCommand(): Application
    {
        $command = new Command('demo:thing');
        $command->addArgument('target', InputArgument::REQUIRED);
        $command->addArgument('more', InputArgument::IS_ARRAY);
        $command->addOption('force', null, InputOption::VALUE_NONE);

        $application = new Application();
        $application->add($command);

        return $application;
    }

    /**
     * @param array<string, mixed> $index
     *
     * @return array<string, mixed>
     */
    private function commandNamed(array $index, string $name): array
    {
        foreach ($index['commands'] as $command) {
            if ($command['name'] === $name) {
                return $command;
            }
        }

        self::fail(sprintf('Command %s missing from the index.', $name));
    }
}
