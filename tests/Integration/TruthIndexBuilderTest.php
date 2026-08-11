<?php

declare(strict_types=1);

namespace Semitexa\Docs\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Docs\Application\Service\TruthIndexBuilder;
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
    public function console_commands_keep_their_arguments_and_options(): void
    {
        $index = $this->build($this->applicationWithSampleCommand());

        $command = $this->commandNamed($index, 'demo:thing');

        self::assertSame('console', $command['kind']);
        self::assertSame([['name' => 'target', 'required' => true]], $command['arguments']);
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
