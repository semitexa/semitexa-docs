<?php

declare(strict_types=1);

namespace Semitexa\Docs\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Docs\Application\Service\ReferenceGenerator;

/**
 * Review on docs#166: the reference page for #[UiEntryPoint] showed a bare
 * `#[UiEntryPoint]` from a negative test, although `reason` is required, so
 * the example was a declaration PHP refuses to build. An attribute written
 * bare while it has a required parameter is never taken as the example.
 */
final class ReferenceExampleNeedsItsArgumentsTest extends TestCase
{
    private string $root;
    private ?string $rootBefore = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-ref-example-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/vendor/semitexa/sample/tests', 0777, true);
        // The bare one comes first: at equal rank the first one found used to win.
        file_put_contents($this->root . '/vendor/semitexa/sample/tests/ABareTest.php', "<?php\n#[EntryMark]\nfinal class Bare {}\n");
        file_put_contents($this->root . '/vendor/semitexa/sample/tests/BGoodTest.php', "<?php\n#[EntryMark(reason: 'Posted by a widget.')]\nfinal class Good {}\n");

        $property = new \ReflectionProperty(ProjectRoot::class, 'root');
        $before = $property->getValue();
        $this->rootBefore = is_string($before) ? $before : null;
        $property->setValue(null, $this->root);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(ProjectRoot::class, 'root'))->setValue(null, $this->rootBefore);
        foreach (['ABareTest.php', 'BGoodTest.php'] as $file) {
            @unlink($this->root . '/vendor/semitexa/sample/tests/' . $file);
        }
        foreach (['/vendor/semitexa/sample/tests', '/vendor/semitexa/sample', '/vendor/semitexa', '/vendor', ''] as $dir) {
            @rmdir($this->root . $dir);
        }
    }

    #[Test]
    public function a_bare_declaration_of_an_attribute_with_a_required_parameter_is_never_the_example(): void
    {
        $found = $this->harvest([['name' => 'reason', 'type' => 'string', 'optional' => false]]);

        self::assertArrayHasKey('EntryMark', $found);
        self::assertStringContainsString("#[EntryMark(reason: 'Posted by a widget.')]", $found['EntryMark']['snippet']);
    }

    #[Test]
    public function a_bare_declaration_stays_a_fine_example_when_every_parameter_is_optional(): void
    {
        $found = $this->harvest([['name' => 'reason', 'type' => 'string', 'optional' => true]]);

        self::assertArrayHasKey('EntryMark', $found);
        self::assertStringContainsString('#[EntryMark]', $found['EntryMark']['snippet']);
    }

    /**
     * @param list<array<string, mixed>> $parameters
     * @return array<string, array{file: string, snippet: string}>
     */
    private function harvest(array $parameters): array
    {
        $generator = (new \ReflectionClass(ReferenceGenerator::class))->newInstanceWithoutConstructor();
        $harvest = new \ReflectionMethod(ReferenceGenerator::class, 'harvestAttributeUsages');
        /** @var array<string, array{file: string, snippet: string}> $found */
        $found = $harvest->invoke($generator, ['attributes' => [['name' => 'EntryMark', 'parameters' => $parameters]]]);

        return $found;
    }
}
