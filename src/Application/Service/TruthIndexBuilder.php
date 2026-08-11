<?php

declare(strict_types=1);

namespace Semitexa\Docs\Application\Service;

use Semitexa\Core\Attribute\AsEventListener;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Container\ServiceContractRegistry;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Support\ProjectRoot;
use Symfony\Component\Console\Application;

/**
 * The framework's public surface, as data.
 *
 * Documentation makes checkable claims — this attribute exists, that command
 * takes this option, this env key is read. Checking them by hand does not
 * survive contact with 114 pages, so this service produces the other half of
 * the comparison: everything a page is allowed to claim, read from the code
 * rather than from another document.
 *
 * Every section is derived from the framework's own registries — the same ones
 * the runtime uses to boot — so the index cannot describe a surface the runtime
 * does not have.
 */
#[AsService]
final class TruthIndexBuilder
{
    public const ARTIFACT = 'semitexa.docs.truth-index/v1';

    /**
     * Attribute-target bit names, for turning the `#[Attribute(flags)]` bitmask
     * back into something a reader (or a linter) can compare against.
     *
     * @var array<int, string>
     */
    private const TARGET_NAMES = [
        \Attribute::TARGET_CLASS => 'class',
        \Attribute::TARGET_FUNCTION => 'function',
        \Attribute::TARGET_METHOD => 'method',
        \Attribute::TARGET_PROPERTY => 'property',
        \Attribute::TARGET_CLASS_CONSTANT => 'class_constant',
        \Attribute::TARGET_PARAMETER => 'parameter',
    ];

    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    #[InjectAsReadonly]
    protected AttributeDiscovery $attributeDiscovery;

    #[InjectAsReadonly]
    protected ModuleRegistry $moduleRegistry;

    /**
     * @return array<string, mixed>
     */
    public function build(?Application $console = null): array
    {
        $notes = [];

        return [
            'artifact' => self::ARTIFACT,
            'attributes' => $this->attributes(),
            'foreign_attributes' => $this->foreignAttributes(),
            'commands' => $this->commands($console, $notes),
            'contracts' => $this->contracts(),
            'routes' => $this->routes(),
            'events' => $this->events(),
            'env_keys' => $this->envKeys(),
            'env_key_patterns' => $this->envKeyPatterns(),
            'twig' => $this->twig($notes),
            // Anything the index could not see says so here, rather than
            // leaving a caller to read an empty list as "none exist".
            'incomplete' => $notes,
        ];
    }

    /**
     * Every `#[Attribute]` class with its targets and constructor signature —
     * the surface a page means when it writes `#[AsPublicPayload(path: '/x')]`.
     *
     * @return list<array<string, mixed>>
     */
    private function attributes(): array
    {
        $out = [];

        foreach ($this->classDiscovery->findClassesWithAttribute(\Attribute::class) as $fqcn) {
            try {
                $reflection = new \ReflectionClass($fqcn);
            } catch (\ReflectionException) {
                continue;
            }

            $flags = \Attribute::TARGET_ALL;
            foreach ($reflection->getAttributes(\Attribute::class) as $marker) {
                $arguments = $marker->getArguments();
                $flags = (int) ($arguments['flags'] ?? $arguments[0] ?? \Attribute::TARGET_ALL);
            }

            $out[] = [
                'name' => $reflection->getShortName(),
                'fqcn' => $reflection->getName(),
                'targets' => $this->targetNames($flags),
                'repeatable' => ($flags & \Attribute::IS_REPEATABLE) !== 0,
                'parameters' => $this->parameters($reflection),
            ];
        }

        usort($out, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return $out;
    }

    /**
     * Attribute names from the rest of the vendor tree.
     *
     * A page that shows a PHPUnit attribute is not claiming Semitexa has one,
     * and a checker that cannot tell the difference reports
     * `#[RunTestsInSeparateProcesses]` as an invention. These are listed
     * separately so a consumer can recognise them without confusing them for
     * framework surface.
     *
     * @return list<string>
     */
    private function foreignAttributes(): array
    {
        $vendor = ProjectRoot::get() . '/vendor';
        if (!is_dir($vendor)) {
            return [];
        }

        $names = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($vendor, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            if (str_contains($file->getPathname(), '/vendor/semitexa/')) {
                continue;
            }

            $body = (string) file_get_contents($file->getPathname());
            if (!str_contains($body, '#[Attribute')) {
                continue;
            }

            if (preg_match('/^\s*(?:final\s+|readonly\s+)*class\s+([A-Za-z0-9_]+)/m', $body, $match) === 1) {
                $names[$match[1]] = true;
            }
        }

        $out = array_keys($names);
        sort($out);

        return $out;
    }

    /**
     * @return list<string>
     */
    private function targetNames(int $flags): array
    {
        $names = [];
        foreach (self::TARGET_NAMES as $bit => $name) {
            if (($flags & $bit) !== 0) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parameters(\ReflectionClass $reflection): array
    {
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return [];
        }

        $out = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $out[] = [
                'name' => $parameter->getName(),
                'type' => $type instanceof \ReflectionNamedType ? $type->getName() : (string) $type,
                'optional' => $parameter->isOptional(),
            ];
        }

        return $out;
    }

    /**
     * Console commands come from the running Application, so arguments and
     * options are the real definitions rather than a transcription of them.
     *
     * The shell wrapper is a second, separate surface: `bin/semitexa` handles
     * server lifecycle and local routing itself and never reaches PHP, so those
     * names are absent from the Application and would otherwise read as
     * undocumented inventions.
     *
     * @param list<string> $notes
     *
     * @return list<array<string, mixed>>
     */
    private function commands(?Application $console, array &$notes): array
    {
        $out = [];

        if ($console === null) {
            $notes[] = 'commands: no console Application supplied; PHP command surface omitted.';
        } else {
            foreach ($console->all() as $name => $command) {
                if ($command->isHidden()) {
                    continue;
                }

                $definition = $command->getDefinition();
                $out[] = [
                    'name' => $name,
                    'kind' => 'console',
                    'description' => $command->getDescription(),
                    'arguments' => array_values(array_map(
                        static fn ($argument): array => [
                            'name' => $argument->getName(),
                            'required' => $argument->isRequired(),
                        ],
                        $definition->getArguments(),
                    )),
                    'options' => array_values(array_map(
                        static fn ($option): array => [
                            'name' => '--' . $option->getName(),
                            'accepts_value' => $option->acceptValue(),
                        ],
                        $definition->getOptions(),
                    )),
                ];
            }
        }

        // The wrapper's help block also lists commands it merely forwards to
        // PHP. Those are already above with their real definitions; adding them
        // again as bare shell entries would double-count the surface.
        $known = array_column($out, 'name');
        foreach ($this->shellCommands() as $name) {
            if (in_array($name, $known, true)) {
                continue;
            }

            $out[] = ['name' => $name, 'kind' => 'shell', 'arguments' => [], 'options' => []];
        }

        usort($out, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return $out;
    }

    /**
     * Names the wrapper prints in its own help block, which is where its
     * self-handled commands are declared.
     *
     * @return list<string>
     */
    private function shellCommands(): array
    {
        $wrapper = ProjectRoot::get() . '/bin/semitexa';
        if (!is_file($wrapper)) {
            return [];
        }

        $contents = (string) file_get_contents($wrapper);
        preg_match_all('/printf\s+"\s+%-\d+s\s+%s\\\\n"\s+"([a-z][a-z0-9:._-]*)/', $contents, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contracts(): array
    {
        // Not container-managed: core builds it on demand from the two
        // registries it reads, and so do we.
        $registry = new ServiceContractRegistry($this->classDiscovery, $this->moduleRegistry);

        $out = [];
        foreach ($registry->getContractDetails() as $interface => $details) {
            $out[] = [
                'interface' => is_string($interface) ? $interface : (string) ($details['interface'] ?? ''),
                'implementation' => is_array($details) ? ($details['implementation'] ?? null) : null,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function routes(): array
    {
        $out = [];
        foreach ($this->attributeDiscovery->getEnrichedRoutes() as $route) {
            if (!is_array($route)) {
                continue;
            }

            $methods = $route['methods'] ?? $route['method'] ?? [];
            $out[] = [
                'path' => (string) ($route['path'] ?? ''),
                'methods' => is_array($methods) ? array_values($methods) : [(string) $methods],
                'payload' => $route['payload_class'] ?? $route['payload'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Events are named by the listeners that subscribe to them: a listener
     * carries the event class it handles, which makes the set of events the
     * runtime actually routes observable without guessing at dispatch sites.
     *
     * @return list<array<string, mixed>>
     */
    private function events(): array
    {
        $events = [];

        foreach ($this->classDiscovery->findClassesWithAttribute(AsEventListener::class) as $fqcn) {
            try {
                $reflection = new \ReflectionClass($fqcn);
            } catch (\ReflectionException) {
                continue;
            }

            foreach ($reflection->getAttributes(AsEventListener::class) as $attribute) {
                $arguments = $attribute->getArguments();
                $event = $arguments['event'] ?? $arguments[0] ?? null;
                if (!is_string($event) || $event === '') {
                    continue;
                }

                $events[$event] ??= ['event' => $event, 'listeners' => []];
                $events[$event]['listeners'][] = $reflection->getName();
            }
        }

        ksort($events);

        return array_values($events);
    }

    /**
     * Environment keys as the code reads them, so a documented key that no
     * longer exists is visible as a claim with nothing behind it.
     *
     * @return list<string>
     */
    private function envKeys(): array
    {
        $keys = [];

        foreach ($this->sourceRoots() as $root) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                // Two readers, not one: core reads through Environment and
                // tenancy through its own EnvReader. Scanning only the first
                // makes real keys look undocumented.
                preg_match_all(
                    '/(?:getEnvValue|EnvReader::get[A-Za-z]*)\(\s*[\'"]([A-Z][A-Z0-9_]*)[\'"]/',
                    (string) file_get_contents($file->getPathname()),
                    $matches,
                );

                foreach ($matches[1] ?? [] as $key) {
                    $keys[$key] = true;
                }
            }
        }

        $names = array_keys($keys);
        sort($names);

        return $names;
    }

    /**
     * Keys the code composes rather than spells out — `TENANT_{$id}_DOMAIN` and
     * friends. There is no finite list to compare against, so the shape is
     * recorded instead, with `*` where the code interpolates. Without this a
     * page documenting `TENANT_ACME_DOMAIN` looks like it invented a key.
     *
     * @return list<string>
     */
    private function envKeyPatterns(): array
    {
        $patterns = [];

        foreach ($this->sourceRoots() as $root) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                preg_match_all(
                    '/"([A-Z][A-Z0-9_]*)\{\$[A-Za-z_][A-Za-z0-9_]*\}([A-Z0-9_]*)"/',
                    (string) file_get_contents($file->getPathname()),
                    $matches,
                    PREG_SET_ORDER,
                );

                foreach ($matches as $match) {
                    $patterns[$match[1] . '*' . ($match[2] ?? '')] = true;
                }
            }
        }

        $out = array_keys($patterns);
        sort($out);

        return $out;
    }

    /**
     * @return list<string>
     */
    private function sourceRoots(): array
    {
        $candidates = [
            ProjectRoot::get() . '/src',
            ProjectRoot::get() . '/vendor/semitexa',
            ProjectRoot::get() . '/packages',
        ];

        return array_values(array_filter($candidates, static fn (string $path): bool => is_dir($path)));
    }

    /**
     * Twig helpers live in semitexa/ssr, which this package does not require —
     * the registry is read when the rendering layer happens to be installed and
     * the gap is reported when it is not, rather than pulling in a dependency
     * the docs tooling does not otherwise need.
     *
     * @param list<string> $notes
     *
     * @return array<string, list<string>>
     */
    private function twig(array &$notes): array
    {
        $registry = 'Semitexa\\Ssr\\Application\\Service\\Extension\\TwigExtensionRegistry';

        if (!class_exists($registry)) {
            $notes[] = 'twig: semitexa/ssr is not installed; Twig functions and filters omitted.';

            return ['functions' => [], 'filters' => []];
        }

        try {
            // The catalog is wired during an HTTP boot; on the CLI it has to be
            // handed the same discovery instance before it will answer.
            $registry::setClassDiscovery($this->classDiscovery);

            /** @var array<string, mixed> $functions */
            $functions = $registry::getFunctions();
            /** @var array<string, mixed> $filters */
            $filters = $registry::getFilters();
        } catch (\Throwable $e) {
            $notes[] = 'twig: registry unavailable in this context (' . $e->getMessage() . ').';

            return ['functions' => [], 'filters' => []];
        }

        $functionNames = array_keys($functions);
        $filterNames = array_keys($filters);
        sort($functionNames);
        sort($filterNames);

        return ['functions' => $functionNames, 'filters' => $filterNames];
    }
}
