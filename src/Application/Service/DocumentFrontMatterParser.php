<?php

declare(strict_types=1);

namespace Semitexa\Docs\Application\Service;

use Semitexa\Core\Attribute\AsService;

#[AsService]
final class DocumentFrontMatterParser
{
    /**
     * @return array{meta: array<string, mixed>, body: string}
     */
    public function parse(string $contents, string $path): array
    {
        if (!str_starts_with($contents, "---\n")) {
            throw new \RuntimeException(sprintf('Document "%s" is missing YAML-like front matter.', $path));
        }

        $endMarker = "\n---\n";
        $endPos = strpos($contents, $endMarker, 4);
        if ($endPos === false) {
            throw new \RuntimeException(sprintf('Document "%s" has unterminated front matter.', $path));
        }

        $rawMeta = substr($contents, 4, $endPos - 4);
        $body = substr($contents, $endPos + strlen($endMarker));

        return [
            'meta' => $this->parseMetaBlock($rawMeta, $path),
            'body' => ltrim($body),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseMetaBlock(string $block, string $path): array
    {
        $meta = [];
        $currentKey = null;

        foreach (preg_split("/\r\n|\n|\r/", $block) ?: [] as $lineNumber => $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if (str_starts_with($trimmed, '- ')) {
                if ($currentKey === null || !isset($meta[$currentKey]) || !is_array($meta[$currentKey])) {
                    throw new \RuntimeException(sprintf(
                        'Invalid list entry in front matter for "%s" on line %d.',
                        $path,
                        $lineNumber + 1,
                    ));
                }

                $meta[$currentKey][] = $this->normalizeScalar(substr($trimmed, 2));
                continue;
            }

            $colonPos = strpos($line, ':');
            if ($colonPos === false) {
                throw new \RuntimeException(sprintf(
                    'Invalid front matter line in "%s" on line %d: %s',
                    $path,
                    $lineNumber + 1,
                    $line,
                ));
            }

            $key = trim(substr($line, 0, $colonPos));
            $value = trim(substr($line, $colonPos + 1));

            if ($key === '') {
                throw new \RuntimeException(sprintf('Empty front matter key in "%s" on line %d.', $path, $lineNumber + 1));
            }

            if ($value === '') {
                $meta[$key] = [];
                $currentKey = $key;
                continue;
            }

            $meta[$key] = $this->normalizeScalar($value);
            $currentKey = $key;
        }

        return $meta;
    }

    private function normalizeScalar(string $value): mixed
    {
        $trimmed = trim($value);

        if (
            (str_starts_with($trimmed, '"') && str_ends_with($trimmed, '"'))
            || (str_starts_with($trimmed, "'") && str_ends_with($trimmed, "'"))
        ) {
            return substr($trimmed, 1, -1);
        }

        if ($trimmed === 'true') {
            return true;
        }

        if ($trimmed === 'false') {
            return false;
        }

        if (is_numeric($trimmed) && preg_match('/^-?\d+$/', $trimmed) === 1) {
            return (int) $trimmed;
        }

        return $trimmed;
    }
}
