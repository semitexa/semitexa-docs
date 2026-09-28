<?php

declare(strict_types=1);

namespace Semitexa\Docs\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Docs\Application\Service\ReferenceGenerator;

/**
 * Which usage the attribute reference quotes as "the example" when an
 * attribute is used in several places.
 */
final class ReferenceExampleRankTest extends TestCase
{
    /** @return iterable<string, array{string, string}> better, worse */
    public static function pairs(): iterable
    {
        yield 'valid code in a lower-ranked package beats a negative fixture in the preferred one' => [
            'vendor/semitexa/platform-site/src/Foo.php',
            'vendor/semitexa/demo/tests/InvalidFoo.php',
        ];
        yield 'a valid test fixture beats a negative one in the same package' => [
            'vendor/semitexa/orm/tests/Fixture/ReplicatedNote.php',
            'vendor/semitexa/orm/tests/Fixture/InvalidReplicatedKey.php',
        ];
        yield 'production code beats tests in the same package' => [
            'vendor/semitexa/orm/src/Foo.php',
            'vendor/semitexa/orm/tests/Fixture/Foo.php',
        ];
        yield 'the preferred package still wins among valid examples' => [
            'vendor/semitexa/demo/src/Foo.php',
            'vendor/semitexa/orm/src/Foo.php',
        ];
    }

    #[Test]
    #[DataProvider('pairs')]
    public function the_better_example_wins_in_either_order(string $better, string $worse): void
    {
        $generator = (new \ReflectionClass(ReferenceGenerator::class))->newInstanceWithoutConstructor();
        $isBetter  = new \ReflectionMethod(ReferenceGenerator::class, 'isBetterExample');

        self::assertTrue($isBetter->invoke($generator, $better, $worse));
        self::assertFalse($isBetter->invoke($generator, $worse, $better));
    }
}
