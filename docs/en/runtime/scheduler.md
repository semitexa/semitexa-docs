---
id: runtime/scheduler
section: runtime
slug: scheduler
title: Scheduled and Delayed Jobs
summary: Run work on a clock with #[AsScheduledJob], queue one-off jobs through SchedulerInterface, and let scheduler:plan and leased scheduler:work processes run them with retries, overlap locks and per-tenant runs.
order: 90
locale: en
status: canonical
verified_against: 2026.10.20.0340
keywords:
  - "#[AsScheduledJob]"
  - ScheduledJobInterface
  - ScheduledJobContext
  - SchedulerInterface
  - LeaseLostException
  - scheduler:plan
  - scheduler:work
  - scheduler:run-now
  - scheduler:list
  - SCHEDULER_DEFAULT_POOL
---

# Scheduled and Delayed Jobs

`semitexa/scheduler` runs work that belongs to a clock rather than to a request. There are two kinds of run:

- **Recurring**: a class declares a cron expression with `#[AsScheduledJob]`, and `scheduler:plan` turns each due occurrence into a run.
- **Delayed**: code calls `SchedulerInterface::dispatchAt()` or `dispatchAfter()`, which writes one run due at a given time.

Both kinds end up as rows in `scheduler_runs`. A `scheduler:work` process claims rows for its pool, holds a lease on each one, and runs the job. Nothing here runs inside the web server.

## A job class

A job implements `ScheduledJobInterface` and **must be an `#[AsService]`**. The worker builds it from its class name through the container, and the container only builds registered services.

```php
namespace App\Modules\Reports\Application\Service\Job;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Scheduler\Attribute\AsScheduledJob;
use Semitexa\Scheduler\Domain\Contract\ScheduledJobInterface;
use Semitexa\Scheduler\Domain\Model\ScheduledJobContext;

#[AsService]
#[AsScheduledJob(
    key: 'reports.nightly',
    cronExpression: 'env::REPORTS_NIGHTLY_CRON::0 3 * * *',
    overlapPolicy: 'skip',
    misfirePolicy: 'run_once',
    maxAttempts: 3,
    retryBackoffSeconds: 60,
)]
final class NightlyReportJob implements ScheduledJobInterface
{
    #[InjectAsReadonly]
    protected ReportBuilder $reports;

    public function handle(ScheduledJobContext $context): void
    {
        foreach ($this->reports->pendingBatches() as $batch) {
            $this->reports->build($batch);
            $context->renewLease(); // a checkpoint: see "Leases" below
        }
    }
}
```

`handle()` returning means the run succeeded. Any `Throwable` it throws means the attempt failed, and the message is stored as `last_error`.

`ScheduledJobContext` (`Semitexa\Scheduler\Domain\Model`) is a readonly object:

| Property / method | What it holds |
|---|---|
| `runId` | the `scheduler_runs` id |
| `jobClass`, `pool` | what the run row says |
| `tenantId` | the run's tenant, or `null` |
| `scheduleKey` | the `#[AsScheduledJob]` key; `null` for a run from `dispatchAt()`/`dispatchAfter()` |
| `sourceType` | `'recurring'` or `'delayed'` |
| `attemptNumber` | 1 on the first attempt |
| `payload` | the array passed when the job was dispatched; always `[]` for recurring runs |
| `renewLease()` | extends this run's lease and its overlap lock. Throws `LeaseLostException` if this worker no longer owns them |

## Recurring schedules

`Semitexa\Scheduler\Attribute\AsScheduledJob` takes these parameters:

| Parameter | Default | Meaning |
|---|---|---|
| `key` | required | unique schedule key (the row in `scheduler_schedule_definitions`) |
| `cronExpression` | required | a five-field cron expression, or `env::VAR::default` |
| `pool` | `'default'` | which `scheduler:work` runs it |
| `overlapPolicy` | `'skip'` | `allow`, `skip` or `delay` |
| `misfirePolicy` | `'run_once'` | `skip`, `run_once` or `catch_up` |
| `tenantMode` | `'global'` | `global` or `per_tenant` (`explicit_tenant` is refused, see [Tenants](#tenants)) |
| `maxAttempts` | `1` | total attempts, the first one included |
| `retryBackoffSeconds` | `0` | base retry delay (see [Retries](#retries)) |
| `maxCatchUpRuns` | `null` | limit for `catch_up`; `null` means no limit |

Use `env::VAR::default` to let each install change a schedule without changing the package's code. A value that does not resolve to a valid expression is refused: `scheduler:plan` prints a warning naming the job, keeps that job's previous schedule, and plans the other jobs as usual.

On every run, `scheduler:plan` first syncs the attribute into `scheduler_schedule_definitions`. That sync overwrites the key, class, cron, pool, policies, attempts, backoff and `maxCatchUpRuns` from the attribute, so editing those columns by hand does not last. The sync never writes `enabled`, `timezone` or `payload_template_json`, so a schedule you switched off by hand stays off.

**Timezone.** Occurrences are calculated in the row's `timezone` column and stored in UTC. The attribute has no timezone parameter, so new rows get `UTC`. The only other way to set a timezone is to edit the column by hand; the sync leaves that edit alone.

### How planning works

Each definition keeps a planning cursor. A run of `scheduler:plan` calculates every occurrence after the cursor, up to and including now. For a new definition with no cursor yet, it starts one minute ago, so nothing older is filled in. The misfire policy then chooses which occurrences become runs. The cursor moves to the last calculated occurrence, whether or not a run was created for it.

| `misfirePolicy` | Occurrences that become runs |
|---|---|
| `skip` | only those at most 120 seconds old (`MisfirePolicyResolver::ON_TIME_SECONDS`), so missed ones are dropped and the current one still runs |
| `run_once` | only the newest one, however late it is |
| `catch_up` | all of them, or the newest `maxCatchUpRuns` |

Dropped occurrences get no row. Instead, the planner prints and logs one warning per schedule: `Schedule '<key>' (misfirePolicy …) dropped N missed occurrences …`.

Every run has an occurrence key, `schedule:<key>:at:<ISO time>` (`schedule:<key>:tenant:<id>:at:<ISO time>` for a tenant run), and the column is unique. Planning the same occurrence twice, for example from two worker containers, creates one row.

### Overlap

Unless `overlapPolicy` is `allow`, a recurring run carries a lock key: `scheduler:<key>`, or `scheduler:<key>:tenant:<id>` for a tenant run. Before running the job, the worker inserts a row into `scheduler_locks`, which is unique on `lock_key` and expires after `SCHEDULER_LOCK_TTL` seconds. If another run holds a lock that has not expired:

- `skip`: the run becomes `skipped_overlap`. That status is final.
- `delay`: the run becomes `retry_scheduled`, due again after `max(30, retryBackoffSeconds)` seconds, and its attempt count does not change.

An expired lock is taken over, and every worker loop deletes expired locks. The lock is released when the run finishes, whether it succeeded or failed.

## One-off jobs: `SchedulerInterface`

Inject `Semitexa\Scheduler\Domain\Contract\SchedulerInterface`:

```php
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Scheduler\Domain\Contract\SchedulerInterface;

#[InjectAsReadonly]
protected SchedulerInterface $scheduler;

// ...

$runId = $this->scheduler->dispatchAfter(
    jobClass: SendReminderJob::class,
    delay: new \DateInterval('PT15M'),
    payload: ['orderId' => $order->getId()],
    pool: 'mail',
    tenantId: $tenantId,
    lockKey: 'reminder:' . $order->getId(),
    maxAttempts: 5,
    retryBackoffSeconds: 30,
);
```

```php
public function dispatchAt(string $jobClass, \DateTimeImmutable $runAt, array $payload = [], ?string $pool = null, ?string $tenantId = null, ?string $lockKey = null, int $maxAttempts = 1, int $retryBackoffSeconds = 0): string;
public function dispatchAfter(string $jobClass, \DateInterval $delay, array $payload = [], ?string $pool = null, ?string $tenantId = null, ?string $lockKey = null, int $maxAttempts = 1, int $retryBackoffSeconds = 0): string;
```

Both return the new run's id. `dispatchAfter()` is `dispatchAt(now + $delay)`.

- `payload` is stored as JSON and reaches the job as `$context->payload`. It must be JSON-encodable, or `dispatchAt()` throws.
- `pool: null` means `SCHEDULER_DEFAULT_POOL`, or `default` if that is unset.
- `tenantId` makes the job run as that tenant (see [Tenants](#tenants)).
- `lockKey` turns on the overlap lock for this run. A dispatched run has no schedule definition: when its lock is held it waits (`delay`: the larger of 30 seconds and its backoff, as many times as it takes), it is never dropped.
- The job class does not need `#[AsScheduledJob]`, but it must be an `#[AsService]` that implements `ScheduledJobInterface`. This is not checked when you dispatch: a wrong class fails each time a worker tries to run it.

## The worker model

**`scheduler:plan`** exits after one pass: it syncs definitions, plans due occurrences and prints `Planned N run(s).`

**`scheduler:work [pool]`** runs a loop until it is killed. Each pass:

1. Handles every `claimed` or `running` run whose lease has expired, in all pools, not only its own. A `running` run that has used up its attempts becomes `failed`, with `last_error` saying its lease ran out. Any other one goes back to `pending`.
2. Deletes expired locks.
3. Claims the oldest due run in its pool, meaning status `pending` or `retry_scheduled` with `available_at` already past. A guarded update sets it to `claimed` with `lease_owner = <host>:<pid>:<random>` and `lease_expires_at = now + SCHEDULER_LEASE_TTL`. If there is nothing to claim, it sleeps for `SCHEDULER_POLL_INTERVAL` seconds.
4. Applies the overlap lock, sets the run to `running`, adds one to `attempt_count`, and calls `handle()` in the same process. A worker runs one job at a time.
5. Writes the outcome only if the run's `lease_owner` is still this worker. If it is not, the worker logs `outcome discarded` and writes nothing.

### Leases

The worker does **not** renew the lease while a job runs. A job that can take longer than `SCHEDULER_LEASE_TTL` (300 s by default) must call `$context->renewLease()` at checkpoints. If it does not, another worker reclaims the "expired" run. With attempts left, it starts the run a second time, alongside the first. With none left (the default `maxAttempts: 1`), it marks the run `failed`, and the first copy's outcome is then discarded when it finishes. When `renewLease()` throws `LeaseLostException`, let the exception propagate: the worker then discards this attempt's outcome.

### Retries

`maxAttempts` counts every attempt. After a failed attempt `n`, if `n < maxAttempts`, the run becomes `retry_scheduled` with `available_at = now + retryBackoffSeconds × 2^(n−1)`. Otherwise it becomes `failed`. For example, `maxAttempts: 3, retryBackoffSeconds: 60` waits 60 s, then 120 s, then fails. With a backoff of `0`, the retry is due immediately.

### When a worker dies

Stopping the worker container, or killing the process any other way, ends `scheduler:work` mid-job. The job does not get to finish, and the worker does not release its lease or lock. Once the lease expires, the next pass of any worker sets the run back to `pending`, and it runs again from the start as a new attempt; a run whose attempts are used up (the dead attempt counts) becomes `failed` instead, so a job that crashes its worker does not run forever. Until the lock's own TTL runs out, a run of the same schedule with `skip` becomes `skipped_overlap`. Delivery is **at least once**, so write jobs that are safe to run twice.

### Run statuses

| Status | Meaning |
|---|---|
| `pending` | waiting for `available_at` |
| `claimed` | leased by a worker, not started yet |
| `running` | `handle()` is executing |
| `retry_scheduled` | failed or delayed by overlap, due again at `available_at` |
| `succeeded`, `failed`, `skipped_overlap` | final |

`RunStatus` also has `misfire_skipped` and `cancelled`, but the package never writes either.

## Tenants

| `tenantMode` | Runs per occurrence |
|---|---|
| `global` | one, with `tenant_id` null |
| `per_tenant` | one for each tenant the tenancy repository lists, each with its own occurrence key and lock. If no tenants are configured, one run with no tenant |
| `explicit_tenant` | refused: a recurring schedule has no way to name its tenant. `scheduler:plan` reports the schedule and does not plan it |

For a run with a `tenant_id`, whether it came from `per_tenant`, `dispatchAt(tenantId: …)` or `scheduler:run-now --tenant`, the worker makes that tenant the current tenant context for the length of `handle()`, then restores the previous one. Use `per_tenant` for any job that reads tenant-scoped data. A `global` run sees only the default scope.

## Commands

| Command | Arguments and options |
|---|---|
| `scheduler:list` | none. Reads `scheduler_schedule_definitions`, enabled rows only, and shows key, cron, next due time (in the row's timezone, with the UTC time beside it when that is not UTC), pool, overlap, misfire, tenant mode and `Last Planned`, which is the planning cursor |
| `scheduler:plan` | none. One pass: sync, then plan |
| `scheduler:work` | `[pool]`, which defaults to `SCHEDULER_DEFAULT_POOL` or `default`. Runs until killed |
| `scheduler:run-now` | `<schedule-key>`, `--tenant` / `-t <id>`, `--inline` |

`scheduler:run-now` creates a run for an existing definition that is due now, using the definition's pool, attempts, backoff and the same lock key a planned run would get. Without `--inline`, a worker for that pool picks it up. With `--inline`, the run is saved already claimed under the command's own lease, so no worker can claim it while the command holds it. The command runs it in its own process through the same overlap, executor and retry path, and exits non-zero unless a success was recorded. `renewLease()` works there as it does in a worker. A retry or an overlap delay it schedules goes to the workers, and so does a run whose lease ran out, because the job outlived it or the command was killed.

## Tables

All four come from ORM resource models; `bin/semitexa orm:sync` creates them.

| Table | Holds |
|---|---|
| `scheduler_schedule_definitions` | one row per `#[AsScheduledJob]` key, plus `enabled`, `timezone` and the planning cursor |
| `scheduler_runs` | every recurring and delayed run: status, `available_at`, attempts, lease, lock key, payload, `last_error` |
| `scheduler_locks` | overlap locks, unique on `lock_key`, each with an expiry |
| `scheduler_run_history` | an append-only event log per run (`running`, `succeeded`, `retry_scheduled`, `failed`, `skipped_overlap`, `overlap_delayed`). A reclaim after an expired lease writes no entry |

Locks are deleted when released and when they expire. Every `scheduler:work` process deletes runs in a final status (`succeeded`, `failed`, `skipped_overlap`, and the never-written `misfire_skipped` and `cancelled`) last changed more than `SCHEDULER_RETENTION_DAYS` ago, with their history: once when it starts, then at most once an hour, up to 10 000 runs each time, across all pools. Pending, claimed, running and retrying rows are never deleted, and definitions stay.

## How the worker container runs it

In a project started with `bin/semitexa server:start`, the `worker` service runs every line that `bin/semitexa worker:processes` prints, and starts each command again after a pause once it exits. The scheduler adds these lines through `#[AsWorkerProcessSource]` (`SchedulerWorkerProcesses`):

- `scheduler:plan`, started again 60 s after each run ends, so occurrences are planned up to about a minute late. `available_at` is the occurrence time, so a late run is claimed straight away.
- one `scheduler:work <pool>` (5 s pause) each for `default`, for `SCHEDULER_DEFAULT_POOL`, and for every `pool` named in an `#[AsScheduledJob]`.

A pool name must be a single plain word (`A–Z a–z 0–9 . _ : / = -`). Any other name gets no worker, and a warning is logged. `WORKER_REPLICAS` runs more containers. The unique occurrence key and the guarded claim keep the extra planners and workers from running anything twice. In development, each run also shows up in `ai:observe` as a `scheduler` process. See [Workers & Scheduling](../cli/workers-scheduling.md).

## Configuration

| Key | Default | Used for |
|---|---|---|
| `SCHEDULER_DEFAULT_POOL` | `default` | the pool of a dispatch with no `pool`, `scheduler:work` with no argument, and one more worker line. It does not change the attribute's `pool` default, which is the literal `'default'` |
| `SCHEDULER_POLL_INTERVAL` | 5 | seconds a worker sleeps when nothing is due |
| `SCHEDULER_LEASE_TTL` | 300 | seconds a claim lasts; `renewLease()` resets it to now plus this |
| `SCHEDULER_LOCK_TTL` | 300 | seconds an overlap lock lasts; `renewLease()` resets it to now plus this |
| `SCHEDULER_RETENTION_DAYS` | 30 | days a finished run and its history are kept; `0` keeps them forever |
| `WORKER_REPLICAS` | 1 | worker containers |

`SCHEDULER_MAX_ATTEMPTS` and `SCHEDULER_RETRY_BACKOFF` are read into `SchedulerConfig`, but nothing uses them. Attempts and backoff come from the attribute or the dispatch call, both defaulting to `1` and `0`.

## Watch out for

- **A deleted job class stops its schedule, without disabling it.** `scheduler:plan` does not plan a schedule whose job class no longer exists, and reports it on every pass, about once a minute. The row stays `enabled`, so the schedule plans again once the class is back. If it is gone for good, set `enabled = 0` on the row.
- **A key no attribute declares any more is not planned.** After renaming `key` or removing `#[AsScheduledJob]`, the old row stays `enabled` but `scheduler:plan` passes it over and reports it on every pass. Nothing is disabled, so rolling back to the old name plans it again. While a rolling deploy runs the old and new images side by side, each plans its own key, so the job can run twice until the old image stops. Its cursor stays put meanwhile, so it then catches up by its misfire policy. To silence the report for good, set `enabled = 0` or delete the row.
- **No `#[AsService]`, no job.** `scheduler:plan` warns `... is not a service ... mark it #[AsService]` and does not sync the schedule. A delayed dispatch of such a class fails on every attempt.
- **One instance serves every run.** A service is built once per worker process, so a job object is reused from run to run. Do not keep per-run state on its properties.
- **A long job without `renewLease()` runs twice, or is marked failed.** After 300 s, another worker reclaims it. With attempts left, it starts a second copy while the first is still running. On its last attempt, the run is marked `failed` and the first copy's result is thrown away.
- **A pool with no worker never runs.** Only `default`, `SCHEDULER_DEFAULT_POOL` and pools named in an attribute get a `scheduler:work`. If you dispatch to any other pool, add your own `#[AsWorkerProcessSource]`, as the workflow package does for its timeout pools.
- **A mistyped policy stops that schedule.** `overlapPolicy`, `misfirePolicy` and `tenantMode` are not checked when synced. A value outside the enum makes `scheduler:plan` report that schedule and pass over it on every pass; the other schedules are planned as usual. Its planning cursor does not move, so once the value is fixed, the occurrences missed meanwhile go through the misfire policy like an outage.
- **`scheduler:list` and `scheduler:run-now` read the table, not the code.** A new job appears only after the next `scheduler:plan`.
- **`explicit_tenant` is refused.** Use `per_tenant`, or dispatch with a `tenantId`.
- **New schedules do not backfill, and `run_once` runs late.** A daily 03:00 job with `run_once` whose planner was down until 09:00 runs once at 09:00. With `skip` it does not run that day. With `catch_up` and no `maxCatchUpRuns`, it runs every missed occurrence.
- **The tables grow without limit.** Delete old `scheduler_runs` and `scheduler_run_history` rows yourself, for example from a scheduled job.
