# Changelog — waffle-commons/async

All notable changes to this component are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Released in lockstep with the Waffle Commons umbrella tag.

## [0.1.0-beta6] — 2026-09

**Theme: first full documentation pack.**

### Added
- README, LICENSE, CONTRIBUTING, SECURITY, CODE_OF_CONDUCT and CODEOWNERS. The component shipped in 0.1.0-beta5 without them; the README documents the real `DeferredTaskRunner` surface, the Fiber finish-request lifecycle and the worker-safety rules, verified against the source.

### Documentation
- The README now links into the central Diátaxis documentation tree (DOC-02).

## [0.1.0-beta5] — 2026-07-08

**Theme: Fiber-based finish-request deferred tasks (AXE 2 / RFC-015 · ASYNC-01).**

### Added
- `DeferredTaskRunner` — a cooperative, finish-request task runner implementing the contract
  `Async\TaskRunnerInterface`. Tasks deferred during a request via `defer()` accumulate in a
  request-scoped queue and run after the response is flushed (before the worker accepts its next
  request), lifting short post-response work — mail delivery, audit/API-log writes, webhook payloads —
  out of the user-perceived latency path.
- Each task runs inside its own native `Fiber` as an isolation boundary: a thrown failure (or a throwing
  destructor/`finally` on an abandoned, still-suspended task) is caught and logged without aborting the
  sibling tasks or crashing the finish-request flush.
- A bounded per-request deferral budget (`DEFAULT_BUDGET = 64`, configurable): exceeding it raises
  `Exception\DeferralBudgetExceededException`, surfacing the explicit recommendation to move heavy or
  long-running work to a real queue/broker. A budget below 1 is refused eagerly with
  `Exception\InvalidBudgetException`.

### Notes
- Depends only on `waffle-commons/contracts` (plus `psr/log`). The pending queue is the only
  request-scoped state, so the runner implements `Service\ResettableInterface` directly and the kernel
  empties it after every request (`wfl igor` 0 KO).
- Cooperative concurrency within a single thread — not background threads. This trades a little worker
  throughput for perceived latency; it is not a substitute for background processing.
