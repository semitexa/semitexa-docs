<?php

declare(strict_types=1);

namespace Semitexa\Docs\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Docs\Application\Service\DocumentationClaimLinter;

/**
 * The linter's value is entirely in being believed, so the tests that matter are
 * the ones about *not* reporting: every false positive here was found in the
 * real corpus first, and each would have made the report easy to dismiss.
 */
final class DocumentationClaimLinterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/docs-lint-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->dir);
    }

    #[Test]
    public function reports_an_attribute_and_a_command_that_do_not_exist(): void
    {
        $findings = $this->lint("Use `#[AsInvented]` then run `bin/semitexa nope:missing`.\n");

        self::assertSame(
            [['attribute', 'AsInvented'], ['command', 'nope:missing']],
            array_map(static fn (array $f): array => [$f['kind'], $f['claim']], $findings),
        );
    }

    #[Test]
    public function checks_inside_fenced_blocks_because_that_is_what_readers_copy(): void
    {
        $findings = $this->lint("Example:\n\n```php\n#[AsInvented]\nfinal class Thing {}\n```\n");

        self::assertCount(1, $findings);
        self::assertSame(4, $findings[0]['line'], 'The line reported must be the one inside the block.');
    }

    #[Test]
    public function an_attribute_from_another_library_is_not_an_invention(): void
    {
        // PHPUnit's own attributes appear in testing pages. Flagging them made
        // 3 of the first 35 findings noise.
        self::assertSame([], $this->lint("Mark it `#[RunTestsInSeparateProcesses]`.\n"));
    }

    #[Test]
    public function a_documentation_filename_is_not_an_environment_variable(): void
    {
        // A bare-token env regex counted MODULE_STRUCTURE and ADDING_ROUTES as
        // unknown keys and put the false-positive rate at 82%.
        self::assertSame([], $this->lint("See MODULE_STRUCTURE and ADDING_ROUTES for detail.\n"));
    }

    #[Test]
    public function a_key_the_code_composes_at_runtime_is_accepted(): void
    {
        // TENANT_ACME_DOMAIN is never written down anywhere: the code builds
        // "TENANT_{$id}_DOMAIN", so only the shape can be known.
        self::assertSame([], $this->lint("TENANT_ACME_DOMAIN=acme.test\n"));
    }

    #[Test]
    public function a_rename_is_reported_with_the_name_that_replaced_it(): void
    {
        $findings = $this->lint("Declare `#[SatisfiesServiceContrac]` on the class.\n");

        self::assertCount(1, $findings);
        self::assertSame('SatisfiesServiceContract', $findings[0]['suggestion']);
    }

    #[Test]
    public function a_before_section_may_name_the_api_being_migrated_from(): void
    {
        // A migration guide's whole job is to show what you are moving away
        // from. Reporting it would mean one can never be written.
        self::assertSame([], $this->lint(
            "## Access\n\n### Before\n\n```php\n#[AsInvented]\n```\n",
        ));
    }

    #[Test]
    public function the_after_section_of_the_same_page_is_still_checked(): void
    {
        $findings = $this->lint(
            "### Before\n\n`#[AsInvented]`\n\n### After\n\n`#[AlsoInvented]`\n",
        );

        self::assertSame(['AlsoInvented'], array_column($findings, 'claim'));
    }

    #[Test]
    public function a_wildcard_stands_for_the_attributes_it_covers(): void
    {
        // `#[InjectAs*]` is one claim about three attributes, not a claim about
        // an attribute called "InjectAs".
        self::assertSame([], $this->lint("Annotate with `#[AsPublic*]` properties.\n"));
    }

    #[Test]
    public function a_wildcard_matching_nothing_is_still_reported(): void
    {
        self::assertSame(['AsNothing*'], array_column($this->lint("Use `#[AsNothing*]`.\n"), 'claim'));
    }

    #[Test]
    public function the_ignore_marker_covers_the_line_below_it(): void
    {
        self::assertSame([], $this->lint(
            "<!-- docs-lint-ignore -->\nDon't create `#[AsInvented]` — identity comes from composer.json.\n",
        ));
    }

    #[Test]
    public function the_ignore_marker_above_a_block_covers_the_whole_block(): void
    {
        // A migration step that greps for retired names is one instruction, not
        // one finding per line.
        self::assertSame([], $this->lint(
            "<!-- docs-lint-ignore -->\n```bash\ngrep -r '#[AsInvented]' src\ngrep -r '#[AlsoInvented]' src\n```\n",
        ));
    }

    #[Test]
    public function a_whole_document_can_be_exempted(): void
    {
        // A proposal names unbuilt API in every other paragraph; marking each
        // line would bury the document in scaffolding.
        self::assertSame([], $this->lint(
            "<!-- docs-lint-ignore-file -->\n# Proposal\n\n`#[AsInvented]` and `#[AlsoInvented]` and `bin/semitexa nope:missing`.\n",
        ));
    }

    #[Test]
    public function the_file_exemption_does_not_leak_to_other_documents(): void
    {
        file_put_contents($this->dir . '/exempt.md', "<!-- docs-lint-ignore-file -->\n`#[AsInvented]`\n");

        self::assertSame(['AlsoInvented'], array_column($this->lint("`#[AlsoInvented]`\n"), 'claim'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lint(string $markdown): array
    {
        file_put_contents($this->dir . '/page.md', $markdown);

        $report = (new DocumentationClaimLinter())->lint($this->index(), [$this->dir]);

        /** @var list<array<string, mixed>> $findings */
        $findings = $report['findings'];

        return $findings;
    }

    /**
     * @return array<string, mixed>
     */
    private function index(): array
    {
        return [
            'attributes' => [
                ['name' => 'AsPublicPayload'],
                ['name' => 'SatisfiesServiceContract'],
            ],
            'foreign_attributes' => ['RunTestsInSeparateProcesses'],
            'commands' => [['name' => 'ai:verify'], ['name' => 'docs:lint']],
            'env_keys' => ['SWOOLE_PORT'],
            'env_key_patterns' => ['TENANT_*_DOMAIN'],
        ];
    }
}
