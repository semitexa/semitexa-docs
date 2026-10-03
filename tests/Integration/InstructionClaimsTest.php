<?php

declare(strict_types=1);

namespace Semitexa\Docs\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Docs\Application\Service\DocumentationClaimLinter;

/**
 * Instructions to an agent are held to two claims a docs page is not: a bare
 * command in backticks, and a file path. Every "not reported" case below was a
 * false positive in the real root instructions and skills before it was a test.
 */
final class InstructionClaimsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/instruction-claims-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/packages/semitexa-dev/src', 0777, true);
        mkdir($this->root . '/vendor/semitexa/docs/docs', 0777, true);
        mkdir($this->root . '/.claude/skills/review/references', 0777, true);
        touch($this->root . '/packages/semitexa-dev/src/Present.php');
        touch($this->root . '/vendor/semitexa/docs/docs/README.md');
        touch($this->root . '/.claude/skills/review/references/GUIDE.md');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function a_bare_command_or_a_path_that_is_gone_is_reported(): void
    {
        $findings = $this->lint('AGENTS.md', <<<'MD'
            | `ai:orient` | first command |
            | `ai:vanished` | gone since the rename |
            Open `packages/semitexa-dev/src/Present.php`, not `packages/semitexa-dev/src/Gone.php`.
            See [the old guide](docs/OLD.md).
            MD);

        self::assertSame([
            ['command', 'ai:vanished', 2],
            ['path', 'packages/semitexa-dev/src/Gone.php', 3],
            ['path', 'docs/OLD.md', 4],
        ], array_map(static fn (array $f): array => [$f['kind'], $f['claim'], $f['line']], $findings));
    }

    #[Test]
    public function shapes_families_targets_and_examples_are_not_claims(): void
    {
        self::assertSame([], $this->lint('AGENTS.md', <<<'MD'
            Use `ai:review-graph:*` for "who uses this", and read the `skill_copies:project` target.
            A module lives at `src/modules/{Name}/Application/`, a skill under `.claude/skills/...`.
            Pages are `packages/<package>/docs/*.md`; a target id such as `module_structure:x` is not a command.
            ```bash
            bin/semitexa ai:verify > packages/semitexa-dev/src/Written.php
            ```
            MD));
    }

    #[Test]
    public function a_link_is_read_from_its_file_and_a_workspace_path_from_the_installed_package(): void
    {
        // A skill links its own references; a scaffold doc names a framework
        // file by its workspace path, which a consumer has under vendor/.
        self::assertSame([], $this->lint('.claude/skills/review/SKILL.md', "Follow [the guide](references/GUIDE.md).\n"));
        self::assertSame([], $this->lint('README.md', "Hub: [docs](packages/semitexa-docs/docs/README.md)\n"));
    }

    #[Test]
    public function a_skill_s_shell_environment_is_not_the_framework_s(): void
    {
        // RELEASE_CHANNEL is read by the release scripts a skill drives, not by PHP.
        self::assertSame([], $this->lint('.claude/skills/review/SKILL.md', "- `RELEASE_CHANNEL` = stable by default\n"));
        self::assertSame(['env'], array_column($this->lint('AGENTS.md', "- `RELEASE_CHANNEL` = stable by default\n"), 'kind'));
    }

    #[Test]
    public function without_instructions_mode_none_of_this_is_checked(): void
    {
        file_put_contents($this->root . '/AGENTS.md', "| `ai:vanished` | open `packages/semitexa-dev/src/Gone.php` |\n");

        self::assertSame([], (new DocumentationClaimLinter())->lint($this->index(), [$this->root . '/AGENTS.md'])['findings']);
    }

    /** @return list<array<string, mixed>> */
    private function lint(string $relative, string $markdown): array
    {
        file_put_contents($this->root . '/' . $relative, $markdown);
        $report = (new DocumentationClaimLinter())->lint($this->index(), [$this->root . '/' . $relative], $this->root);

        /** @var list<array<string, mixed>> $findings */
        $findings = $report['findings'];

        return $findings;
    }

    /** @return array<string, mixed> */
    private function index(): array
    {
        return [
            'attributes' => [],
            'commands' => [['name' => 'ai:orient'], ['name' => 'ai:verify'], ['name' => 'ai:review-graph:query'], ['name' => 'docs:lint']],
            'env_keys' => ['SWOOLE_PORT'],
        ];
    }
}
