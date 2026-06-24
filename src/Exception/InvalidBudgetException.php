<?php

declare(strict_types=1);

namespace Waffle\Commons\Async\Exception;

use InvalidArgumentException;
use Waffle\Commons\Contracts\Async\Exception\AsyncExceptionInterface;

use function sprintf;

/**
 * Thrown when a {@see \Waffle\Commons\Async\DeferredTaskRunner} is constructed
 * with a non-positive deferral budget (ASYNC-01).
 *
 * A budget below one is meaningless — it would reject every deferral — so it is
 * a programming error rejected eagerly at construction, mirroring the connection
 * pools' "must allow at least one" guard rather than failing silently later.
 */
final class InvalidBudgetException extends InvalidArgumentException implements AsyncExceptionInterface
{
    public function __construct(
        private readonly int $budget,
    ) {
        parent::__construct(sprintf('A deferral budget must allow at least one task, got %d.', $budget));
    }

    /**
     * The rejected (non-positive) budget that was supplied.
     */
    public function budget(): int
    {
        return $this->budget;
    }
}
