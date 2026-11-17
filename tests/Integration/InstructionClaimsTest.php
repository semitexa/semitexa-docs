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

    /**
     * tk-docs-instructions-fail-in-consumers: the scaffold's AGENTS.md and
     * AI_ENTRY.md name the package itself, `packages/semitexa-docs/`. Its
     * trailing slash was trimmed before the workspace-to-vendor mapping, which
     * wanted a slash after the package name, so every consumer's
     * docs:instructions gate reported it missing and could never pass.
     */
    #[Test]
    public function a_workspace_package_named_as_a_directory_is_the_installed_package(): void
    {
        self::assertSame([], $this->lint('AGENTS.md', "`packages/semitexa-docs/` is the single official documentation source.\n"));
        self::assertSame([], $this->lint('README.md', "Hub: [docs](./packages/semitexa-docs/)\n"), 'a link written ./packages/... is the same path');
        self::assertSame(['packages/semitexa-gone/'], array_column($this->lint('AGENTS.md', "Open `packages/semitexa-gone/`.\n"), 'claim'), 'a package nobody installed is still missing');
    }

    #[Test]
    public function a_tilde_fence_is_an_example_too(): void
    {
        // Review of docs#87: only backtick fences were skipped.
        self::assertSame([], $this->lint('AGENTS.md', "~~~bash\n`ai:vanished` then open `packages/semitexa-dev/src/Gone.php`\n```\nstill inside: a backtick line does not close a tilde fence\n~~~\n"));
        self::assertSame(['ai:vanished'], array_column($this->lint('AGENTS.md', "~~~\nexample\n~~~\nRun `ai:vanished`.\n"), 'claim'));
    }

    #[Test]
    public function an_indented_fence_is_not_a_fence_and_a_titled_link_is_still_a_link(): void
    {
        // Review of docs#87: four spaces before ~~~ is an indented code line,
        // and a link title hid the destination from the matcher.
        self::assertSame(
            ['ai:vanished', 'docs/Gone.md'],
            array_column($this->lint('AGENTS.md', "    ~~~\nRun `ai:vanished`.\nSee [guide](docs/Gone.md \"Guide\").\n"), 'claim'),
        );
    }

    #[Test]
    public function a_link_is_not_rescued_by_a_file_of_that_name_at_the_root(): void
    {
        // Review of docs#87: the root was tried first, so a skill linking
        // docs/README.md passed on the project's own docs/README.md.
        mkdir($this->root . '/docs');
        touch($this->root . '/docs/README.md');

        self::assertSame(
            [['path', 'docs/README.md']],
            array_map(static fn (array $f): array => [$f['kind'], $f['claim']], $this->lint('.claude/skills/review/SKILL.md', "See [docs](docs/README.md).\n")),
        );
        self::assertSame([], $this->lint('AGENTS.md', "See [docs](docs/README.md) and `docs/README.md`.\n"));
    }

    /**
     * tk-instructions-lint-scaffold-paths: a consumer's AGENTS.md is the scaffold's, written for the
     * framework workspace. Outside it, its paths are not claims anyone there can keep true; inside
     * it (packages/semitexa-ultimate exists) they are checked; a command is checked either way.
     */
    #[Test]
    public function a_scaffold_doc_s_workspace_paths_are_checked_where_it_is_written(): void
    {
        $doc = "# AGENTS.md\n\n> Scaffold doc (`semitexa/ultimate`). Edit root.\n\nDocs live in `packages/semitexa-gone/docs/`. Run `ai:vanished`.\n";
        self::assertSame(['ai:vanished'], array_column($this->lint('AGENTS.md', $doc), 'claim'), 'a consumer: the path is the workspace\'s, the command is anyone\'s');

        mkdir($this->root . '/packages/semitexa-ultimate', 0777, true);
        $claims = array_column($this->lint('AGENTS.md', $doc), 'claim');
        sort($claims);
        self::assertSame(['ai:vanished', 'packages/semitexa-gone/docs/'], $claims, 'the workspace: both are checked');

        // A project's own instructions are checked as before.
        rmdir($this->root . '/packages/semitexa-ultimate');
        self::assertSame(['packages/semitexa-gone/docs/'], array_column($this->lint('AGENTS.md', "Docs live in `packages/semitexa-gone/docs/`.\n"), 'claim'));
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
