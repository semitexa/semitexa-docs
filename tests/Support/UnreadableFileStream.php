<?php

declare(strict_types=1);

namespace Semitexa\Docs\Tests\Support;

/**
 * A path that stats as an ordinary readable file and then refuses to open.
 *
 * The container runs as root, so chmod cannot make a file unreadable there —
 * a permission-based test would skip and report green without ever running.
 * This reproduces the condition the code actually guards against.
 */
final class UnreadableFileStream
{
    /** @var resource|null Set by PHP when opening a stream wrapper. */
    public $context;

    public static string $scheme = 'unreadable';

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return false;
    }

    /** @return array<int|string, int> */
    public function url_stat(string $path, int $flags): array
    {
        // 0100644: a regular file, so is_file() lets the reader through.
        return ['mode' => 0100644, 'size' => 1] + array_fill(0, 13, 0);
    }
}
