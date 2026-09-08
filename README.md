[![Discord](https://img.shields.io/discord/755288001592033391?logo=discord)](https://discord.gg/eKgywnfXr2)
[![PHP Version Require](http://poser.pugx.org/waffle-commons/async/require/php)](https://packagist.org/packages/waffle-commons/async)
[![PHP CI](https://github.com/waffle-commons/async/actions/workflows/main.yml/badge.svg)](https://github.com/waffle-commons/async/actions/workflows/main.yml)
[![codecov](https://codecov.io/gh/waffle-commons/async/graph/badge.svg)](https://codecov.io/gh/waffle-commons/async)
[![Latest Stable Version](http://poser.pugx.org/waffle-commons/async/v)](https://packagist.org/packages/waffle-commons/async)
[![Latest Unstable Version](http://poser.pugx.org/waffle-commons/async/v/unstable)](https://packagist.org/packages/waffle-commons/async)
[![Total Downloads](https://img.shields.io/packagist/dt/waffle-commons/async.svg)](https://packagist.org/packages/waffle-commons/async)
[![Packagist License](https://img.shields.io/packagist/l/waffle-commons/async)](https://github.com/waffle-commons/async/blob/main/LICENSE.md)

Waffle Async Component
=======================

> **Release:** `0.1.0-beta6` &nbsp;|&nbsp; [`CHANGELOG.md`](./CHANGELOG.md)
> **RFC:** RFC-015 (`ASYNC-01`) — Fiber-based finish-request task deferral

A bounded, worker-safe runner that lifts short post-response work — mail delivery, webhook fan-out, audit
writes — out of the user-perceived latency path. Tasks deferred during a request run **after the response is
flushed to FrankenPHP but before the worker accepts its next request**, each inside its own native `Fiber` for
failure isolation. This is *finish-request deferral*, **not** background processing: the work runs on the same
single worker thread, sequentially, under a hard per-request budget.

> The concurrent half of RFC-015 — outbound HTTP fan-out via `ConcurrentClientInterface` — ships in
> [`waffle-commons/http-client`](https://github.com/waffle-commons/http-client), joined to this package only
> through `waffle-commons/contracts`.

## 📦 Installation

```bash
composer require waffle-commons/async
```

## 🧱 Surface

| Class | Role |
| :--- | :--- |
| `Waffle\Commons\Async\DeferredTaskRunner` | `final` runner implementing `TaskRunnerInterface` **and** `ResettableInterface` (declared directly, as `igor-php` requires). Holds the request-scoped pending queue. |
| `Waffle\Commons\Async\Exception\DeferralBudgetExceededException` | Thrown by `defer()` once the per-request budget is exhausted. Implements the contract `DeferralBudgetExceededExceptionInterface`; exposes `budget(): int`. |
| `Waffle\Commons\Async\Exception\InvalidBudgetException` | Thrown at construction when `$budget < 1`. Implements the contract `AsyncExceptionInterface`; exposes `budget(): int`. |

The contracts — `Waffle\Commons\Contracts\Async\TaskRunnerInterface`, `DeferredTaskInterface`,
`Exception\AsyncExceptionInterface`, `Exception\DeferralBudgetExceededExceptionInterface` — live in
`waffle-commons/contracts`. Consumers (controllers, the kernel) depend on those interfaces, never on this
concrete package.

## 🚀 Usage

```php
use Psr\Log\LoggerInterface;
use Waffle\Commons\Async\DeferredTaskRunner;
use Waffle\Commons\Contracts\Async\DeferredTaskInterface;
use Waffle\Commons\Contracts\Async\TaskRunnerInterface;

// 1. A self-contained, worker-safe task.
final readonly class SendReceiptTask implements DeferredTaskInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private string $orderId,
    ) {}

    #[\Override]
    public function run(): void
    {
        // short post-response work: mail / webhook / audit write
        $this->logger->info("receipt sent for {$this->orderId}");
    }

    #[\Override]
    public function name(): string
    {
        return 'order.receipt';
    }
}

// 2. Registered once per worker iteration — the kernel does this (see "Wiring" below).
$runner = new DeferredTaskRunner(budget: DeferredTaskRunner::DEFAULT_BUDGET, logger: $logger);

// 3. In a handler — defer, return immediately, the kernel drains on TerminateEvent.
function placeOrder(TaskRunnerInterface $runner): ResponseInterface
{
    $runner->defer(new SendReceiptTask($logger, $orderId)); // queued, not run yet

    return jsonResponse(['pending' => $runner->pending()]);
    // ── response flushed ── then run() executes SendReceiptTask in an isolated Fiber.
}
```

## ⏱️ The runner

```php
public const int DEFAULT_BUDGET = 64;

public function __construct(
    int $budget = self::DEFAULT_BUDGET,          // hard ceiling on tasks deferred per request (>= 1)
    LoggerInterface $logger = new NullLogger(),  // PSR-3; receives per-task failure/abandon logs
);

public function defer(DeferredTaskInterface $task): void; // throws DeferralBudgetExceededException at the ceiling
public function run(): void;                               // snapshot, clear, then drain each task in its own Fiber
public function pending(): int;                            // tasks currently queued this request
public function reset(): void;                             // worker reset: empty the pending queue
```

- **Budget guard.** A `$budget` below `1` throws `InvalidBudgetException` at construction — a budget that
  rejects every deferral is a programming error, refused eagerly. `DEFAULT_BUDGET` is `64`.
- **`defer()`** appends to a request-scoped `list<DeferredTaskInterface>`; once `pending() >= $budget` it
  throws `DeferralBudgetExceededException` instead of enqueuing — the explicit signal to move the workload to
  a real queue or broker.
- **`run()`** snapshots the pending queue and clears it *before* draining, so a task that itself defers
  another task cannot grow the queue being drained, and the queue is empty even if the drain throws.
- **Fiber lifecycle, per task.** The runner `start()`s the task's `Fiber`; if it is not terminated it is
  `resume()`d **exactly once** — the single cooperative resume, there is no scheduler loop. If it is *still*
  not terminated it is abandoned with a `warning` log. The Fiber is then force-destroyed (`unset`) inside the
  same `try`, so a throwing destructor/`finally` is caught and logged rather than detonating as a fatal that
  would abort the sibling tasks.
- **Failure isolation.** Any `\Throwable` from a task is caught and logged at `error` level using
  `DeferredTaskInterface::name()` as the label; siblings continue running.
- **`reset()`** empties the pending queue — the only request-scoped state — so deferred work never bleeds
  across the FrankenPHP worker boundary.

## 🧵 Worker safety

`DeferredTaskRunner`'s pending queue is the only request-scoped state. It implements `ResettableInterface`
**directly** (igor-php requires the direct declaration, not one inherited transitively via
`TaskRunnerInterface`), and the kernel calls `reset()` after every request so no deferred work ever crosses
the worker boundary (`wfl igor` 0 KO). Fibers here are a **failure-isolation boundary, not a thread pool** —
there is exactly one OS thread; two deferred tasks never run *simultaneously*, they run one after another
during finish-request.

Finish-request deferral is deliberately **not** background processing: it trades a slice of worker throughput
for perceived latency, bounded by the hard per-request budget rather than a wall-clock timeout. Routinely
wanting to defer dozens of tasks is the tripwire that the work belongs on a real queue/broker instead, not in
this budget.

## 🔌 Wiring (kernel package)

The finish-request drain itself is **not** in `waffle-commons/async` — the package stays contracts-only. The
kernel package wires:

- `Waffle\Event\Listener\DeferredTaskFlushListener` (`final readonly`) — subscribed to the kernel's
  `TerminateEvent` (fires after the response is flushed); its `__invoke()` calls `$runner->run()` inside a
  catch-all, so a throwable escaping the drain cannot reach the client.
- The template `AppKernelFactory` registers `DeferredTaskRunner` under `TaskRunnerInterface::class` with
  `DeferredTaskRunner::DEFAULT_BUDGET`, and adds the flush listener on `TerminateEvent`.

## 🐘 PHP 8.5 features used

- `final class DeferredTaskRunner` with promoted, `readonly` constructor properties (`$budget`, `$logger`).
- Native `Fiber` as a per-task isolation boundary (`start()` / `resume()` / `isTerminated()`).
- `public const int DEFAULT_BUDGET` — typed class constant.
- `#[\Override]` on every interface implementation.

## 🧭 Architectural boundary (`mago guard`)

An active dependency **perimeter** is enforced on every CI run by `vendor/bin/mago guard` (bundled into
`composer mago`; zero baselines). The rules live in [`mago.toml`](./mago.toml) under `[guard.perimeter]` — a
forbidden `use` statement fails the build, not a reviewer.

Production code under `Waffle\Commons\Async` may depend **only** on:

- `Waffle\Commons\Async\**` — itself
- `Waffle\Commons\Contracts\**` — the shared contracts package, the **only** Waffle dependency permitted
- `Psr\**` — PSR interfaces (`psr/log`)
- `@global` + `Psl\**` — PHP core (including native `Fiber`) and the PHP Standard Library

Test code under `WaffleTests\Commons\Async` is unrestricted (`@all`). Structural rules are guarded too:
interfaces must be named `*Interface`, `Exception\**` classes must end in `*Exception`, and any `Enum\**`
namespace may hold only `enum` declarations.

Contract-first, component-agnostic by construction: components compose through `waffle-commons/contracts`,
never directly through one another.

## 🧪 Testing

```bash
docker exec -w /waffle-commons/async waffle-dev composer tests
```

## 📚 Documentation

Central framework docs (Diátaxis) for this component:

- Reference: [`reference/async.md`](https://github.com/waffle-commons/documentation/blob/main/reference/async.md)
- Explanation: [`explanation/async-finish-request-deferral.md`](https://github.com/waffle-commons/documentation/blob/main/explanation/async-finish-request-deferral.md)
- Full documentation tree: [waffle-commons/documentation](https://github.com/waffle-commons/documentation)

## 📄 License

MIT — see [LICENSE.md](./LICENSE.md).
