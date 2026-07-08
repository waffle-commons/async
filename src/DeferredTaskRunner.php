<?php

declare(strict_types=1);

namespace Waffle\Commons\Async;

use Fiber;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Waffle\Commons\Async\Exception\DeferralBudgetExceededException;
use Waffle\Commons\Async\Exception\InvalidBudgetException;
use Waffle\Commons\Contracts\Async\DeferredTaskInterface;
use Waffle\Commons\Contracts\Async\TaskRunnerInterface;
use Waffle\Commons\Contracts\Service\ResettableInterface;

use function count;

/**
 * Fiber-based finish-request deferred task runner (ASYNC-01).
 *
 * Tasks deferred during a request via {@see self::defer()} accumulate in a
 * request-scoped queue under a bounded budget; the finish-request listener calls
 * {@see self::run()} after the response is flushed. Each task runs inside its own
 * {@see Fiber} so it executes in isolation and a thrown failure is caught and
 * logged without aborting the remaining tasks.
 *
 * **Cooperative model.** Fibers here are an isolation boundary, not a scheduler:
 * tasks are expected to run to completion synchronously. A task that suspends is
 * resumed once to let it finish a single suspension point; if it still has not
 * terminated it is abandoned with a warning (heavy/long work belongs in a real
 * queue, not the per-request deferral budget).
 *
 * Worker-safety: the pending queue is the only request-scoped state, so the
 * runner implements {@see ResettableInterface} DIRECTLY and the kernel empties it
 * after every request (`wfl igor` 0 KO).
 */
final class DeferredTaskRunner implements TaskRunnerInterface, ResettableInterface
{
    /** Default per-request deferral budget. */
    public const int DEFAULT_BUDGET = 64;

    /** @var list<DeferredTaskInterface> */
    private array $pending = [];

    /**
     * @param int $budget Hard ceiling on tasks deferred per request (>= 1).
     *
     * @throws InvalidBudgetException When $budget is below 1 — a budget that
     *         rejects every deferral is a programming error, refused eagerly
     *         (mirrors the connection pools' "at least one" guard).
     */
    public function __construct(
        private readonly int $budget = self::DEFAULT_BUDGET,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        if ($budget < 1) {
            throw new InvalidBudgetException($budget);
        }
    }

    #[\Override]
    public function defer(DeferredTaskInterface $task): void
    {
        if (count($this->pending) >= $this->budget) {
            throw new DeferralBudgetExceededException($this->budget);
        }

        $this->pending[] = $task;
    }

    #[\Override]
    public function run(): void
    {
        // Snapshot then clear so a task that defers another task does not grow
        // the queue we are draining (and the queue is empty if run() throws).
        $tasks = $this->pending;
        $this->pending = [];

        foreach ($tasks as $task) {
            $this->runOne($task);
        }
    }

    #[\Override]
    public function pending(): int
    {
        return count($this->pending);
    }

    #[\Override]
    public function reset(): void
    {
        $this->pending = [];
    }

    /**
     * Run one task inside an isolated Fiber, containing any failure.
     *
     * The Fiber's ENTIRE lifecycle — start, the single cooperative resume, and
     * its destruction — happens inside the try/catch. An abandoned Fiber that is
     * still suspended runs its `finally` blocks / object destructors when it is
     * garbage-collected; forcing that destruction here via `unset()` (rather than
     * letting the local fall out of scope after the catch) keeps a throwing
     * destructor/finally inside the catch, so it is logged and contained instead
     * of surfacing as an uncaught fatal that would abort the sibling tasks and
     * crash the finish-request flush.
     */
    private function runOne(DeferredTaskInterface $task): void
    {
        try {
            $fiber = new Fiber(
                /** @throws Throwable */
                static function () use ($task): void {
                    $task->run();
                },
            );

            $fiber->start();

            // Finish-request model: no scheduler loop. Resume a single suspension
            // point so a task that yields once can still complete.
            if (!$fiber->isTerminated()) {
                $fiber->resume();
            }

            if (!$fiber->isTerminated()) {
                $this->logger->warning(
                    'Deferred task "{task}" was still suspended after its single cooperative resume and was abandoned; move long-running work to a real queue.',
                    [
                        'task' => $task->name(),
                    ],
                );
            }

            // Force the (possibly still-suspended) Fiber's destruction NOW, inside
            // the try, so any throwing destructor/finally is caught below instead
            // of detonating later when the local would otherwise fall out of scope.
            unset($fiber);
        } catch (Throwable $error) {
            // Isolate per-task failure so one task cannot abort its siblings.
            $this->logger->error('Deferred task "{task}" failed: {message}', [
                'task' => $task->name(),
                'message' => $error->getMessage(),
                'exception' => $error,
            ]);
        }
    }
}
