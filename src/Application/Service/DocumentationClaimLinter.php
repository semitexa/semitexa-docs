<?php

declare(strict_types=1);

namespace Semitexa\Docs\Application\Service;

use Semitexa\Core\Attribute\AsService;

/**
 * Checks what the documentation asserts against what the framework has.
 *
 * A page makes claims a machine can settle: `#[AsPublicPayload]` is an
 * attribute, `bin/semitexa ai:verify` is a command, `SWOOLE_PORT` is a key the
 * code reads. This finds those claims and looks each one up in the truth index.
 *
 * The hard part is not finding claims — it is not inventing them. An earlier
 * pass counted `MODULE_STRUCTURE` and `ADDING_ROUTES` as unknown environment
 * variables and reported an 82% failure rate; they are documentation filenames.
 * A linter that cries wolf gets muted, so every extractor here is deliberately
 * narrower than it could be, and anything that cannot be settled confidently is
 * left alone rather than guessed at.
 */
#[AsService]
final class DocumentationClaimLinter
{
    public const ARTIFACT = 'semitexa.docs.lint/v1';

    /** Placed on, or directly above, a line whose claim is deliberate. */
    public const IGNORE_MARKER = '<!-- docs-lint-ignore -->';

    /**
     * Suggest a rename only when the names are close enough to be the same idea.
     * Edit distance is the wrong ruler here: `AsPayload` became
     * `AsPublicPayload`, six edits apart but obviously the same thing, while six
     * edits also separates plenty of unrelated names. Character overlap tracks
     * what a rename actually does — keep the word, qualify it.
     */
    private const SUGGESTION_MIN_SIMILARITY = 68.0;

    /**
     * @param array<string, mixed> $index
     * @param list<string>         $roots
     *
     * @return array<string, mixed>
     */
    public function lint(array $index, array $roots): array
    {
        $known = $this->knownSurface($index);
        $findings = [];
        $filesScanned = 0;

        foreach ($this->markdownFiles($roots) as $path) {
            $filesScanned++;
            $body = (string) file_get_contents($path);
            $lines = preg_split('/\R/', $body) ?: [];

            foreach ($this->claims($lines) as $claim) {
                $names = $known[$claim['kind']] ?? [];
                if (in_array($claim['value'], $names, true)) {
                    continue;
                }

                // `#[InjectAs*]` is one claim about three attributes, not a
                // claim about an attribute called "InjectAs".
                if (str_ends_with($claim['value'], '*')) {
                    $prefix = rtrim($claim['value'], '*');
                    foreach ($names as $name) {
                        if ($prefix !== '' && str_starts_with($name, $prefix)) {
                            continue 2;
                        }
                    }
                }

                if ($claim['kind'] === 'env' && $this->matchesPattern($claim['value'], $known['env_pattern'] ?? [])) {
                    continue;
                }

                $findings[] = [
                    'file' => $path,
                    'line' => $claim['line'],
                    'kind' => $claim['kind'],
                    'claim' => $claim['value'],
                    'suggestion' => $this->closest(
                        $claim['value'],
                        $known[$claim['kind'] . '_own'] ?? $names,
                    ),
                ];
            }
        }

        usort($findings, static function (array $a, array $b): int {
            return [$a['file'], $a['line']] <=> [$b['file'], $b['line']];
        });

        return [
            'artifact' => self::ARTIFACT,
            'files_scanned' => $filesScanned,
            'findings' => $findings,
            'by_kind' => $this->countByKind($findings),
        ];
    }

    /**
     * @param array<string, mixed> $index
     *
     * @return array<string, list<string>>
     */
    private function knownSurface(array $index): array
    {
        /** @var list<array{name?: string}> $attributes */
        $attributes = $index['attributes'] ?? [];
        /** @var list<array{name?: string}> $commands */
        $commands = $index['commands'] ?? [];

        // A page may legitimately show an attribute from another library. Those
        // are known names too — just not Semitexa's — and flagging them would
        // make the report untrustworthy exactly where it should be sharpest.
        /** @var list<string> $foreign */
        $foreign = (array) ($index['foreign_attributes'] ?? []);

        return [
            'attribute' => array_values(array_unique(array_merge(
                array_values(array_filter(array_column($attributes, 'name'))),
                $foreign,
            ))),
            // Foreign names are valid to mention but never worth recommending:
            // suggesting PHPUnit's RequiresPhp for RequiresAuth is noise.
            'attribute_own' => array_values(array_filter(array_column($attributes, 'name'))),
            'command' => array_values(array_filter(array_column($commands, 'name'))),
            'env' => array_values((array) ($index['env_keys'] ?? [])),
            'env_pattern' => array_values((array) ($index['env_key_patterns'] ?? [])),
        ];
    }

    /**
     * Claims worth checking, with the line they were made on.
     *
     * Deliberately absent: Twig calls and application class names. Pages show
     * plenty of both as illustrations of code a reader is about to write, and a
     * checker cannot tell an example apart from a reference to something that
     * should exist. Reporting those would be guessing with a straight face.
     *
     * @param list<string> $lines
     *
     * @return list<array{kind: string, value: string, line: int}>
     */
    private function claims(array $lines): array
    {
        $claims = [];
        $inFence = false;
        $inHistory = false;
        $fenceIgnored = false;
        $pendingIgnore = false;

        foreach ($lines as $offset => $line) {
            $marked = str_contains($line, self::IGNORE_MARKER);

            if (preg_match('/^\s*```/', $line) === 1) {
                if (!$inFence) {
                    // A marker on the line above covers the whole block, not
                    // just its first line: a migration step that greps for the
                    // old attribute names is one instruction, not five claims.
                    $fenceIgnored = $pendingIgnore || $marked;
                }

                $inFence = !$inFence;
                $pendingIgnore = false;

                continue;
            }

            if (!$inFence && preg_match('/^#{1,6}\s+(.*)$/', $line, $heading) === 1) {
                // A "Before" section exists to name the API you are migrating
                // from. Reporting it would mean a migration guide can never be
                // written without failing the build.
                $inHistory = preg_match(
                    '/^(before|old|legacy|deprecated|removed|previously)\b/i',
                    trim($heading[1]),
                ) === 1;
                $pendingIgnore = false;

                continue;
            }

            $ignored = $inHistory
                || ($inFence && $fenceIgnored)
                || $marked
                || $pendingIgnore;

            // The marker carries to the next line so it can sit above the thing
            // it excuses rather than trailing it.
            $pendingIgnore = $marked;

            if ($ignored) {
                continue;
            }

            $number = $offset + 1;

            // Fenced blocks are checked for attributes and commands — they hold
            // most such mentions, and a fenced example is precisely what a
            // reader copies. They are *not* checked for env keys: a shell or PHP
            // block is full of assignments that are not environment at all.
            foreach ($this->matches('/#\[([A-Z][A-Za-z0-9]*\*?)/', $line) as $value) {
                $claims[] = ['kind' => 'attribute', 'value' => $value, 'line' => $number];
            }

            foreach ($this->matches('/(?:bin\/)?semitexa\s+([a-z][a-z0-9]*:[a-z0-9:_-]+)/', $line) as $value) {
                $claims[] = ['kind' => 'command', 'value' => rtrim($value, ':'), 'line' => $number];
            }

            // Only an assignment is unambiguous. `FOO=bar` is someone writing an
            // environment variable down; a bare capitalised token in prose is
            // just as likely to be a filename, a constant or an acronym.
            if (!$inFence) {
                foreach ($this->matches('/^\s*(?:[-*]\s*)?`?([A-Z][A-Z0-9_]{2,})`?\s*=/', $line) as $value) {
                    $claims[] = ['kind' => 'env', 'value' => $value, 'line' => $number];
                }
            }
        }

        return $claims;
    }

    /**
     * Some keys are never written down: the code composes
     * `TENANT_{$id}_DOMAIN`, so only the shape can be known.
     *
     * @param list<string> $patterns
     */
    private function matchesPattern(string $value, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $regex = '/^' . str_replace('\*', '[A-Z0-9_]+', preg_quote($pattern, '/')) . '$/';
            if (preg_match($regex, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function matches(string $pattern, string $subject): array
    {
        preg_match_all($pattern, $subject, $found);

        return array_values(array_unique($found[1] ?? []));
    }

    /**
     * The nearest real name, when one is near enough to be the same idea under a
     * different spelling — which is what a rename leaves behind.
     *
     * @param list<string> $candidates
     */
    private function closest(string $value, array $candidates): ?string
    {
        $best = null;
        $bestSimilarity = 0.0;

        foreach ($candidates as $candidate) {
            similar_text($value, $candidate, $percent);
            if ($percent > $bestSimilarity) {
                $bestSimilarity = $percent;
                $best = $candidate;
            }
        }

        return $bestSimilarity >= self::SUGGESTION_MIN_SIMILARITY ? $best : null;
    }

    /**
     * @param list<array{kind: string}> $findings
     *
     * @return array<string, int>
     */
    private function countByKind(array $findings): array
    {
        $counts = [];
        foreach ($findings as $finding) {
            $counts[$finding['kind']] = ($counts[$finding['kind']] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @param list<string> $roots
     *
     * @return list<string>
     */
    private function markdownFiles(array $roots): array
    {
        $files = [];

        foreach ($roots as $root) {
            if (is_file($root)) {
                $files[] = $root;
                continue;
            }

            if (!is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && strtolower($file->getExtension()) === 'md') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return array_values(array_unique($files));
    }

    /**
     * A finding's identity for baseline purposes: the claim and where it lives,
     * but not the line it sits on, so that editing the prose above a known
     * problem does not resurrect it as new.
     *
     * @param array{file: string, kind: string, claim: string} $finding
     */
    public function fingerprint(array $finding, string $relativeTo = ''): string
    {
        $file = $finding['file'];
        if ($relativeTo !== '' && str_starts_with($file, $relativeTo)) {
            $file = ltrim(substr($file, strlen($relativeTo)), '/');
        }

        return $finding['kind'] . ':' . $finding['claim'] . '@' . $file;
    }
}
