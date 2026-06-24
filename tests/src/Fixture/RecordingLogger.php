<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Async\Fixture;

use Psr\Log\AbstractLogger;
use Stringable;

use function array_filter;
use function array_values;

/**
 * A concrete PSR-3 logger that records every entry, so a test can assert on the
 * exact levels and rendered messages emitted by
 * {@see \Waffle\Commons\Async\DeferredTaskRunner} without an expectation-less
 * mock (which would trip PHPUnit's "OK, but there were issues!" notice).
 *
 * @phpstan-type LogEntry array{level: mixed, message: string, context: array<array-key, mixed>}
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<array-key, mixed>}> */
    public private(set) array $records = [];

    /**
     * @param array<array-key, mixed> $context
     */
    #[\Override]
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * Every record emitted at the given PSR-3 level, in order.
     *
     * @return list<array{level: mixed, message: string, context: array<array-key, mixed>}>
     */
    public function recordsAtLevel(string $level): array
    {
        return array_values(array_filter(
            $this->records,
            static fn(array $record): bool => $record['level'] === $level,
        ));
    }
}
