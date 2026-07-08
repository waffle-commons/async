<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Async;

use Fiber;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Waffle\Commons\Async\DeferredTaskRunner;
use Waffle\Commons\Async\Exception\DeferralBudgetExceededException;
use Waffle\Commons\Async\Exception\InvalidBudgetException;
use WaffleTests\Commons\Async\Fixture\RecordingLogger;
use WaffleTests\Commons\Async\Fixture\RecordingTask;
use WaffleTests\Commons\Async\Fixture\ThrowingOnDestruct;

#[CoversClass(DeferredTaskRunner::class)]
#[CoversClass(DeferralBudgetExceededException::class)]
#[CoversClass(InvalidBudgetException::class)]
final class DeferredTaskRunnerTest extends AbstractTestCase
{
    public function testDeferAndRunExecutesEveryTaskInOrder(): void
    {
        $ran = [];
        $runner = new DeferredTaskRunner();
        foreach (['mail', 'webhook', 'audit'] as $name) {
            $runner->defer(new RecordingTask($name, static function () use (&$ran, $name): void {
                $ran[] = $name;
            }));
        }

        self::assertSame(3, $runner->pending());
        $runner->run();

        self::assertSame(['mail', 'webhook', 'audit'], $ran);
        self::assertSame(0, $runner->pending());
    }

    public function testBudgetOverflowThrows(): void
    {
        $runner = new DeferredTaskRunner(budget: 1);
        $runner->defer(new RecordingTask('first', static fn(): null => null));

        try {
            $runner->defer(new RecordingTask('second', static fn(): null => null));
            self::fail('Expected the deferral budget to be exceeded.');
        } catch (DeferralBudgetExceededException $exception) {
            self::assertSame(1, $exception->budget());
        }
    }

    public function testFailingTaskIsIsolatedAndLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $ran = [];
        $runner = new DeferredTaskRunner(logger: $logger);
        $runner->defer(new RecordingTask('boom', static function (): void {
            throw new \RuntimeException('task blew up');
        }));
        $runner->defer(new RecordingTask('survivor', static function () use (&$ran): void {
            $ran[] = 'survivor';
        }));

        $runner->run();

        // The failure was contained; the sibling task still ran.
        self::assertSame(['survivor'], $ran);
        self::assertSame(0, $runner->pending());
    }

    public function testResetClearsPendingTasks(): void
    {
        $ran = [];
        $runner = new DeferredTaskRunner();
        $runner->defer(new RecordingTask('mail', static function () use (&$ran): void {
            $ran[] = 'mail';
        }));

        $runner->reset();

        self::assertSame(0, $runner->pending());
        $runner->run();
        self::assertSame([], $ran);
    }

    public function testRunningWithNoTasksIsANoOp(): void
    {
        $runner = new DeferredTaskRunner();

        $runner->run();

        self::assertSame(0, $runner->pending());
    }

    public function testSuspendingTaskIsResumedToCompletion(): void
    {
        $ran = [];
        $runner = new DeferredTaskRunner();
        $runner->defer(new RecordingTask('suspends-once', static function () use (&$ran): void {
            Fiber::suspend();
            $ran[] = 'done';
        }));

        $runner->run();

        // The single resume let the task finish past its one suspension point.
        self::assertSame(['done'], $ran);
    }

    public function testTaskThatNeverCompletesIsAbandonedWithWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $ran = [];
        $runner = new DeferredTaskRunner(logger: $logger);
        $runner->defer(new RecordingTask('suspends-twice', static function () use (&$ran): void {
            Fiber::suspend();
            Fiber::suspend();
            $ran[] = 'unreached';
        }));

        $runner->run();

        // Start + one resume leaves it still suspended ⇒ abandoned, body unreached.
        self::assertSame([], $ran);
    }

    public function testAbandonedTaskThatThrowsOnDestructionDoesNotAbortSiblings(): void
    {
        $logger = new RecordingLogger();

        $ran = [];
        $runner = new DeferredTaskRunner(logger: $logger);

        // A task that NEVER terminates (suspends twice) AND holds a local whose
        // destructor throws when the abandoned, still-suspended Fiber is destroyed.
        // Before the fix that destruction happened AFTER runOne()'s try/catch
        // (when the local Fiber fell out of scope) and surfaced as an uncaught
        // fatal, aborting every sibling and crashing the finish-request flush.
        $runner->defer(new RecordingTask('hostile', static function (): void {
            $resource = new ThrowingOnDestruct();
            Fiber::suspend();
            Fiber::suspend();
            // Unreached: keeps $resource bound to the suspended frame so its
            // destructor only runs when the abandoned Fiber is destroyed.
            unset($resource);
        }));
        // A sibling deferred AFTER the hostile task: it must still execute.
        $runner->defer(new RecordingTask('survivor', static function () use (&$ran): void {
            $ran[] = 'survivor';
        }));

        // No fatal: if the throwing destructor escaped, this call would die and the
        // assertions below would never run (the test process would abort).
        $runner->run();

        // The sibling ran in full — isolation held across the destruction throw.
        self::assertSame(['survivor'], $ran);
        self::assertSame(0, $runner->pending());

        // The abandonment was reported as a warning...
        $warnings = $logger->recordsAtLevel(LogLevel::WARNING);
        self::assertCount(1, $warnings);
        $warning = $warnings[0] ?? null;
        self::assertNotNull($warning);
        self::assertStringContainsString('abandoned', $warning['message']);

        // ...and the throwing destructor was caught and logged as an error,
        // not allowed to escape runOne().
        $errors = $logger->recordsAtLevel(LogLevel::ERROR);
        self::assertCount(1, $errors);
        $error = $errors[0] ?? null;
        self::assertNotNull($error);
        self::assertSame('hostile', $error['context']['task'] ?? null);
        self::assertStringContainsString(ThrowingOnDestruct::MESSAGE, (string) ($error['context']['message'] ?? ''));
    }

    public function testNonPositiveBudgetIsRejectedAtConstruction(): void
    {
        try {
            new DeferredTaskRunner(budget: 0);
            self::fail('Expected a budget of 0 to be rejected.');
        } catch (InvalidBudgetException $exception) {
            self::assertSame(0, $exception->budget());
            self::assertStringContainsString('at least one', $exception->getMessage());
        }

        try {
            new DeferredTaskRunner(budget: -5);
            self::fail('Expected a negative budget to be rejected.');
        } catch (InvalidBudgetException $exception) {
            self::assertSame(-5, $exception->budget());
        }
    }

    public function testBudgetOfOneIsAccepted(): void
    {
        // The boundary value (the documented minimum) must be allowed.
        $runner = new DeferredTaskRunner(budget: 1);

        self::assertSame(0, $runner->pending());
        $runner->defer(new RecordingTask('only', static fn(): null => null));
        self::assertSame(1, $runner->pending());
    }
}
