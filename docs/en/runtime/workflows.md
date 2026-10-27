---
id: runtime/workflows
section: runtime
slug: workflows
title: Workflows and State Machines
summary: Declare a business process as a code-defined state machine with #[AsWorkflowDefinition], move instances through guarded transitions with WorkflowEngineInterface, and let the scheduler fire timeouts and retry failed side effects.
order: 80
locale: en
status: canonical
verified_against: 2026.10.20.0340
keywords:
  - "#[AsWorkflowDefinition]"
  - WorkflowDefinitionInterface
  - WorkflowEngineInterface
  - TransitionDefinition
  - WorkflowGuardInterface
  - WorkflowSideEffectInterface
  - TimeoutPolicy
  - RetryPolicy
  - WorkflowTimeoutJob
  - workflow_instances
---

# Workflows and State Machines

`semitexa/workflow` keeps the legal moves of a business process in one PHP class instead of a status column and `if` statements spread across handlers. A **definition** names the states and the transitions between them; an **instance** is one subject (an order, a quote) moving through that definition. The engine checks every move against the definition, runs its guards, saves the new state with an optimistic version check, writes a history row, runs side effects, dispatches events, and hands timeouts and side-effect retries to the scheduler.

The definition lives in code. The database holds only instances and their history.

## Declaring a workflow

A definition implements `WorkflowDefinitionInterface` and carries `#[AsWorkflowDefinition]`. `WorkflowDefinitionRegistry` finds every class with the attribute that implements the interface; a class with the attribute that does not implement it is skipped without a word.

```php
<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Service\Workflow;

use Semitexa\Workflow\Attribute\AsWorkflowDefinition;
use Semitexa\Workflow\Domain\Contract\WorkflowDefinitionInterface;
use Semitexa\Workflow\Domain\Model\RetryPolicy;
use Semitexa\Workflow\Domain\Model\TimeoutPolicy;
use Semitexa\Workflow\Domain\Model\TransitionDefinition;

#[AsWorkflowDefinition]
final class OrderWorkflow implements WorkflowDefinitionInterface
{
    public static function key(): string { return 'order'; }

    public function initialState(): string { return 'new'; }

    public function states(): array { return ['new', 'awaiting_payment', 'paid', 'shipped', 'expired']; }

    public function terminalStates(): array { return ['shipped', 'expired']; }

    public function transitions(): array
    {
        return [
            new TransitionDefinition(
                key: 'checkout',
                fromStates: ['new'],
                toState: 'awaiting_payment',
                timeout: new TimeoutPolicy(afterSeconds: 3600, transitionKey: 'expire'),
            ),
            new TransitionDefinition(
                key: 'pay',
                fromStates: ['awaiting_payment'],
                toState: 'paid',
                sideEffects: [SendReceipt::class],
                retryPolicy: new RetryPolicy(maxAttempts: 4, backoffSeconds: 30),
            ),
            new TransitionDefinition('ship', ['paid'], 'shipped', guards: [StockReserved::class]),
            new TransitionDefinition('expire', ['awaiting_payment'], 'expired'),
        ];
    }
}
```

`key()` is static and is stored on every instance. The registry asks the container for the class first, so a definition registered as a service gets its injected properties; otherwise it is built with `new`.

### `TransitionDefinition`

| Argument | Type | Default | Meaning |
|---|---|---|---|
| `key` | `string` | — | the name you apply; the same key may appear on several transitions with different `fromStates` |
| `fromStates` | `list<string>` | — | the states this transition may leave |
| `toState` | `string` | — | the state it lands in |
| `triggerType` | `TriggerType` | `Manual` | descriptive only; the engine never compares it with the caller's trigger |
| `guards` | `list<class-string>` | `[]` | `WorkflowGuardInterface` services, all evaluated |
| `sideEffects` | `list<class-string>` | `[]` | `WorkflowSideEffectInterface` services, run after the state is saved |
| `requiresManualApproval` | `bool` | `false` | marks the instance `awaiting_manual_action` after the transition is applied (see below) |
| `retryPolicy` | `?RetryPolicy` | `null` | retries failed side effects through the scheduler |
| `timeout` | `?TimeoutPolicy` | `null` | schedules another transition to be applied later |

The engine picks the first transition whose `key` matches and whose `fromStates` contains the current state.

### Guards

A guard decides whether a move is allowed. It returns a result rather than throwing, so the denial is stored and returned to the caller:

```php
use Semitexa\Core\Attribute\AsService;
use Semitexa\Workflow\Domain\Contract\WorkflowGuardInterface;
use Semitexa\Workflow\Domain\Contract\WorkflowSubjectReferenceInterface;
use Semitexa\Workflow\Domain\Model\GuardResult;
use Semitexa\Workflow\Domain\Model\TransitionDefinition;
use Semitexa\Workflow\Domain\Model\WorkflowInstance;

#[AsService]
final class StockReserved implements WorkflowGuardInterface
{
    public function evaluate(
        WorkflowSubjectReferenceInterface $subject,
        WorkflowInstance $instance,
        TransitionDefinition $transition,
        array $context,
    ): GuardResult {
        return ($context['reserved'] ?? false) === true
            ? GuardResult::pass()
            : GuardResult::deny('stock_not_reserved', 'Reserve stock before shipping.');
    }
}
```

Every guard on the transition runs, and every denial is collected. `$context` is the `context` array of the `ApplyTransitionCommand`; `$subject` is rebuilt from the instance's stored subject type, id and tenant.

### Side effects

A side effect runs once the new state is saved. It returns `SideEffectResult::success()` or `SideEffectResult::failure($code, $message)`; a thrown exception counts as a failure with code `exception`. A failure never undoes the transition: the engine logs a warning and, if the transition has a `RetryPolicy`, schedules a retry. A side effect that fails for good — no `RetryPolicy`, its last attempt failed, or its retry could not be scheduled — means the transition did not complete: the engine logs an error and dispatches `WorkflowFailed`. A retry that is dropped because the instance was deleted or the transition no longer declares that side effect is logged as a warning, with no event.

```php
use Semitexa\Core\Attribute\AsService;
use Semitexa\Workflow\Domain\Contract\WorkflowSideEffectInterface;
use Semitexa\Workflow\Domain\Contract\WorkflowSubjectReferenceInterface;
use Semitexa\Workflow\Domain\Model\SideEffectResult;
use Semitexa\Workflow\Domain\Model\TransitionDefinition;
use Semitexa\Workflow\Domain\Model\WorkflowInstance;

#[AsService]
final class SendReceipt implements WorkflowSideEffectInterface
{
    public function execute(
        WorkflowSubjectReferenceInterface $subject,
        WorkflowInstance $instance,
        TransitionDefinition $transition,
        array $context,
    ): SideEffectResult {
        // send, keyed on $subject->workflowSubjectId() so a retry does not send twice
        return SideEffectResult::success();
    }
}
```

Guards and side effects are fetched with `$container->get($class)`, which builds registered services only, hence `#[AsService]`.

## Starting an instance

The subject is anything implementing `WorkflowSubjectReferenceInterface`:

```php
use Semitexa\Workflow\Domain\Contract\WorkflowSubjectReferenceInterface;

final readonly class OrderRef implements WorkflowSubjectReferenceInterface
{
    public function __construct(private string $orderId, private ?string $tenantId) {}

    public function workflowSubjectType(): string { return 'order'; }
    public function workflowSubjectId(): string { return $this->orderId; }
    public function workflowTenantId(): ?string { return $this->tenantId; }
}
```

Inject the engine by its contract and start:

```php
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Workflow\Domain\Command\StartWorkflowCommand;
use Semitexa\Workflow\Domain\Contract\WorkflowEngineInterface;

#[InjectAsReadonly]
protected WorkflowEngineInterface $workflows;

$instance = $this->workflows->start(new StartWorkflowCommand(
    workflowKey: 'order',
    subject: new OrderRef($orderId, $tenantId),
    payload: ['total' => 4200],
));
$instanceId = $instance->getId(); // a UUID string
```

`start()` creates the instance in `initialState()` with status `active` and version 0, stores `payload` and `context` as JSON (`payload_json`, `context_json`; an empty array is stored as `NULL`), and dispatches `WorkflowStarted`. There is one instance per (workflow key, subject type, subject id, tenant): another tenant may run the same workflow for the same subject id (on a database created before this, once the old index is dropped — see [Upgrading](#upgrading-to-per-tenant-subjects)), and a second start in one tenant throws `WorkflowAlreadyExistsException`, including when two starts race and the unique index rejects the loser. An unknown key throws `WorkflowDefinitionNotFoundException`.

`findBySubject('order', new OrderRef($orderId, $tenantId))` and `get($instanceId)` return the instance or `null`.

## Applying a transition

```php
use Semitexa\Workflow\Domain\Command\ApplyTransitionCommand;
use Semitexa\Workflow\Domain\Enum\TriggerType;

$result = $this->workflows->apply(new ApplyTransitionCommand(
    workflowKey: 'order',
    instanceId: $instanceId,
    transitionKey: 'ship',
    triggerType: TriggerType::Manual,
    triggeredByType: 'user',
    triggeredById: $userId,
    context: ['reserved' => true],
));

if (!$result->isApplied()) {
    // $result->result, $result->failureCode, $result->failureMessage, $result->guardFailures
}
```

`apply()` throws only when the definition or the instance is missing (`WorkflowDefinitionNotFoundException`, `WorkflowInstanceNotFoundException`). A command whose `workflowKey` is not the instance's is rejected with failure code `workflow_mismatch`. The new state and its history row are written in one transaction. Everything else comes back as a `WorkflowTransitionResult`:

| `result` | `failureCode` | When | History row | Event |
|---|---|---|---|---|
| `Applied` | — | the move was saved | yes | `WorkflowTransitionApplied`, then possibly one more (below) |
| `RejectedInvalid` | `terminal_state` | the instance's status is `completed`, `failed` or `cancelled` | no | none |
| `RejectedInvalid` | `invalid_transition` | no transition with this key leaves the current state | yes | `WorkflowTransitionRejected` |
| `RejectedGuard` | `guard_denied` | at least one guard denied; details in `guardFailures` (`guard`, `code`, `message`) | yes | `WorkflowTransitionRejected` |
| `RejectedConflict` | `version_conflict` | another apply saved first | no | none |

`scheduledFollowUp` is true when the call scheduled a timeout or a side-effect retry. Inside your own transaction (below) nothing is scheduled yet: it then says whether the transition has a `TimeoutPolicy` or a `RetryPolicy`. `triggerType`, `triggeredByType` and `triggeredById` are written to the history row; `idempotencyKey` is accepted and ignored. `expectedVersion` applies the transition only to the instance at that version (the instance's `getVersion()` when you read it): any other gets `RejectedConflict` (`version_conflict`) and changes nothing. A timeout's job passes the version of the visit that scheduled it, so a timer whose instance left the state and came back between the job's check and the transition no longer ends the new visit.

An applied transition, in order:

1. sets the new state and adds one to `version`;
2. saves it with `UPDATE … WHERE id = :id AND version = :expected_version` (a miss is `RejectedConflict`);
3. inserts the history row;
4. runs the side effects and, if a `RetryPolicy` is set, schedules retries of the failed ones;
5. schedules the timeout job if the transition has a `TimeoutPolicy`;
6. dispatches the events.

Steps 1–3 join a database transaction you have open around `apply()` (or `start()`). Steps 4–6 wait for it: they run once your outermost transaction commits, and not at all if it rolls back, so no mail goes out and no event is heard for a transition that was undone. Without a transaction of yours they run inside `apply()`, as listed. A listener that throws is logged and the events after it still go out; `apply()` does not throw for it. A listener therefore never runs inside your transaction: one that writes (a projection) commits on its own, after yours. Inside a test transaction (`RollsBackDatabase`) nothing commits, so side effects, timers and workflow events never run: assert on the stored state, or use `RefreshesTables`. A timeout that cannot be scheduled (the scheduler unreachable) is logged as an error: the transition stands, and the instance waits until a transition is applied by hand.

### Instance status

The status is decided by the transition just applied, first match wins:

| Condition | `status` | Also |
|---|---|---|
| `toState` is in `terminalStates()` | `completed` | `completed_at` set; any terminal state, whatever its name |
| `requiresManualApproval` | `awaiting_manual_action` | `awaiting_manual_action = 1`, `active_transition_key` = the key just applied |
| has a `timeout` | `waiting` | `waiting_until` = now + `afterSeconds` |
| otherwise | `active` | |

`requiresManualApproval` does not hold the transition back for approval. The transition is applied at once; the flag marks the state it lands in as waiting for a person, and the next transition you apply out of that state clears it. Enforce who may apply that next transition with a guard.

## Timeouts

A `TimeoutPolicy` on a transition schedules a `WorkflowTimeoutJob` when that transition is applied:

```php
new TimeoutPolicy(afterSeconds: 3600, transitionKey: 'expire', pool: 'default');
```

The engine calls `SchedulerInterface::dispatchAt()` with `runAt` = now + `afterSeconds`, the policy's `pool`, the instance's tenant id, the lock key `workflow_timeout_{instanceId}_{transitionKey}` and three attempts, 30 s apart (the job itself failing on a lost connection, not the transition being rejected). Side-effect retry jobs get the same three attempts. When the scheduler runs it, the job loads the instance, does nothing if it is gone or its status is terminal, and otherwise applies `transitionKey` with `TriggerType::Scheduled`, `triggeredByType: 'scheduler'`, `triggeredById` and `context['schedulerRunId']` set to the scheduler run id. A timeout transition that is no longer valid from the current state is rejected like any other (`invalid_transition`, with a history row) and the job ends normally.

The job runs only where a `scheduler:work` reads its pool. The scheduler package always runs one for `default` and one for `SCHEDULER_DEFAULT_POOL`; `WorkflowWorkerProcesses` (an `#[AsWorkerProcessSource]`) adds one for every other pool a `TimeoutPolicy` names. In a project started with `bin/semitexa server:start` these run in the `worker` container; `bin/semitexa worker:processes` lists them. See [Workers & Scheduling](../cli/workers-scheduling.md).

## Retrying side effects

```php
new RetryPolicy(maxAttempts: 3, backoffSeconds: 60, backoffMultiplier: 2.0, maxBackoffSeconds: 3600);
```

Those are the defaults. `maxAttempts` counts every run, the first one inside `apply()` included, so `maxAttempts: 1` (or less) means no retry. Each failed side effect is retried on its own through `WorkflowSideEffectRetryJob`:

- attempt `n + 1` runs `backoffForAttempt(n)` seconds after attempt `n` failed, where `backoffForAttempt(n) = backoffSeconds × backoffMultiplier^(n − 1)`, clamped to 0…`maxBackoffSeconds`. With the defaults: 60 s, then 120 s;
- the job is dispatched with no pool (so `SCHEDULER_DEFAULT_POOL`) and the instance's tenant id. The retries of one failure share a chain id in their payload, and with the shipped scheduler (a `DeduplicatingSchedulerInterface`) each attempt of a chain is scheduled once: a retry run the scheduler took over from a worker that seemed dead, and that worker finishing after all, no longer both schedule the next attempt. A scheduler of your own that only implements `SchedulerInterface` gets a unique lock key instead, as before;
- the retry runs only a side effect the transition still declares, against the instance as it is now, not as it was when the transition was applied;
- the `context` is stored as JSON: a date comes back as an array, an enum as its value (a warning is logged), and a context that cannot be encoded is replaced by `[]`;
- after the last attempt fails, the engine logs an error ("failed N times; giving up") and dispatches `WorkflowFailed`. No history row is written and the status does not change.

Without a scheduler, or when dispatching fails, the engine logs, does not retry, and dispatches `WorkflowFailed` at once.

## Events

Every event is a plain class in `Semitexa\Workflow\Application\Payload\Event` with getters, dispatched through `EventDispatcherInterface` after the engine's database writes for that call. Listen with `#[AsEventListener]`:

```php
use Semitexa\Core\Attribute\AsEventListener;
use Semitexa\Core\Event\EventExecution;
use Semitexa\Workflow\Application\Payload\Event\WorkflowCompleted;

#[AsEventListener(event: WorkflowCompleted::class, execution: EventExecution::Async)]
final class NotifyOrderClosed
{
    public function handle(WorkflowCompleted $event): void
    {
        // $event->getSubjectId(), $event->getFinalState(), $event->getTenantId()
    }
}
```

| Event | Dispatched | Fields |
|---|---|---|
| `WorkflowStarted` | by `start()` | instanceId, workflowKey, subjectType, subjectId, tenantId, initialState |
| `WorkflowTransitionApplied` | every applied transition | instanceId, workflowKey, transitionKey, fromState, toState, triggerType, tenantId |
| `WorkflowCompleted` | after the above, when `toState` is terminal | instanceId, workflowKey, subjectType, subjectId, finalState, tenantId |
| `WorkflowEnteredWaitingState` | after the above, when the status became `waiting` | instanceId, workflowKey, currentState, waitingUntil (ATOM string), tenantId |
| `WorkflowManualActionRequired` | after the above, when the status became `awaiting_manual_action` | instanceId, workflowKey, subjectType, subjectId, currentState, pendingTransitionKey, tenantId |
| `WorkflowTransitionRejected` | `invalid_transition` and `guard_denied` rejections | instanceId, workflowKey, transitionKey, fromState, rejectionReason, failureCode, tenantId |
| `WorkflowFailed` | a side effect failed for good (see [Side effects](#side-effects)), once per such side effect: inside `apply()` after the transition's own events, or later from the retry job | instanceId, workflowKey, subjectType, subjectId, failedState (the instance's state when it was given up: after a retry, possibly a later state than the transition moved to), failureCode, failureMessage (`<transitionKey>: <side effect class>: <message>`), tenantId |

At most one of `WorkflowCompleted`, `WorkflowEnteredWaitingState` and `WorkflowManualActionRequired` follows `WorkflowTransitionApplied`. `pendingTransitionKey` is the key of the transition that was just applied, not of one still to come.

`WorkflowFailed` does not change the instance: it keeps its state and status (a terminal state stays `completed`, so `WorkflowCompleted` can be followed by `WorkflowFailed`) and can take further transitions. What to do — compensate, alert, move it on by hand — is the listener's; the transition key in `failureMessage` says which transition did not complete. A listener that throws is logged and does not fail `apply()`, whose transition is already committed. No code path sets the `failed` or `cancelled` status.

## Persistence

Both tables come from ORM resource models; `bin/semitexa orm:sync` creates them.

**`workflow_instances`** (`WorkflowInstanceResourceModel`): `id` (UUID, binary 16), `workflow_key`, `subject_type`, `subject_id`, `tenant_id`, `current_state`, `status`, `version`, `active_transition_key`, `last_error_code`, `last_error_message`, `waiting_until`, `awaiting_manual_action`, `payload_json`, `context_json`, `created_at`, `updated_at`, `completed_at`, `tenant_key` (`tenant_id`, or `''` for a global instance: a unique index lets any number of `NULL`s through). Indexes: unique `(workflow_key, subject_type, subject_id, tenant_key)`, `(tenant_id, status, waiting_until)`, `(workflow_key, current_state)`, `(awaiting_manual_action, updated_at)`.

**`workflow_transition_history`** (`WorkflowTransitionHistoryResourceModel`): one row per applied, `invalid_transition` or `guard_denied` attempt, with `workflow_instance_id`, `transition_key`, `from_state`, `to_state` (`NULL` when rejected), `trigger_type`, `triggered_by_type`, `triggered_by_id`, `attempt` (rows already stored for this instance and transition key, plus one, rejections included), `result` (`applied`, `rejected_guard`, `rejected_invalid`), `guard_failures_json`, `created_at`. The columns `side_effect_failures_json` and `metadata_json` exist but nothing writes them.

Read the history through `WorkflowTransitionHistoryRepositoryInterface::findByInstanceId($instanceId, $limit = 100)`, oldest first. The engine has no method for it.

## Tenant scoping

The tenant of an instance is whatever `workflowTenantId()` returned at `start()`, not the current tenant context. `WorkflowInstanceResourceModel` is `#[TenantScoped]` on `tenant_id`, but the engine's repository reads and writes it with an explicit system scope, so `get()` and `apply()` see every tenant's instances; `findBySubject()` looks only in the subject's own tenant (`workflowTenantId()`). Timeout and retry jobs are dispatched with the instance's tenant id, and the scheduler runs them inside that tenant's context. The history table has no tenant column.

## Commands

`workflow:backfill-tenant-key` fills `tenant_key` for instances started before the column existed (see [Upgrading](#upgrading-to-per-tenant-subjects)); safe to run again. Timeout and retry jobs are scheduler runs, executed by `scheduler:work`.

### Upgrading to per-tenant subjects

Instances used to be unique per (workflow key, subject type, subject id) for all tenants together. On an existing database:

1. `bin/semitexa orm:sync` adds `tenant_key` (empty for existing rows) and the new unique index. Run it with the deploy: the new code writes `tenant_key`, and until the column exists every `start()` fails.
2. `bin/semitexa workflow:backfill-tenant-key` copies each instance's `tenant_id` into it.
3. `bin/semitexa orm:sync --allow-destructive` drops the old index `uniq_workflow_instance_subject`. It runs every other pending destructive change too: read `orm:sync --dry-run` first.

Until step 3, the old index still refuses a second tenant's instance for a subject id another tenant holds (`WorkflowAlreadyExistsException`); a project that never runs step 3 keeps that behaviour. Before step 3, have every worker on the new code and run step 2 again: a row an old worker still wrote has an empty `tenant_key`, and after step 3 it lets a second instance of its subject into its tenant; a tenant row with an empty `tenant_key` also refuses a global instance of the same subject (`WorkflowAlreadyExistsException` for an instance that does not exist).

## Configuration

The package reads no environment keys of its own.

| Key | Default | Effect here |
|---|---|---|
| `SCHEDULER_DEFAULT_POOL` | `default` | the pool side-effect retries are dispatched to; timeouts use `TimeoutPolicy::$pool` instead, whose default is the literal `default` |

## Watch out for

- **One definition per `key()`.** Two classes declaring the same key make the registry throw when it is first used, naming both.
- **Guards and side effects must be services.** Without `#[AsService]` the container cannot build them. A guard that cannot be built, or that throws, makes `apply()` throw: no history row, no event. A side effect that cannot be built is a failure with code `exception`, logged after the state is already saved.
- **Side-effect failures are logged, not stored.** The result is still `Applied`; the warning in the log is the only record. Make side effects idempotent: a retry runs again what may have half-succeeded.
- **Any transition ends a wait.** A timer carries the instance's version when it was scheduled and does nothing once any transition has been applied since, as the engine itself already treats the instance as no longer waiting. A transition that should keep waiting (a reminder that loops back to the same state) needs its own `TimeoutPolicy`, which schedules a fresh timer. A timer is not removed from the scheduler, it just finds nothing to do.
- **A denied or failing timeout is not tried again.** A guard that denies the timeout transition leaves the instance where it is, and the scheduler dispatches the job with one attempt, so a job that throws fails for good.
- **`get($instanceId)` is not filtered by tenant.** Lookups by subject are filtered by the subject's tenant, so B never sees A's instance; `get($instanceId)` is not, so compare `$instance->getTenantId()` with the current tenant before applying a transition by an id that came from a request.
- **Terminal means completed.** Every terminal state gives status `completed` and `WorkflowCompleted`, including a state you named `rejected` or `cancelled`. After that every `apply()` is `terminal_state`, so a transition listed out of a terminal state can never run.
- **Declared but not enforced:** `states()` is never read (a `toState` missing from it is accepted), `TransitionDefinition::$triggerType` is never checked (a transition meant for the scheduler can be applied by any caller), and `ApplyTransitionCommand::$idempotencyKey` is ignored. `ScheduleWorkflowCheckCommand` and `WorkflowInstanceRepositoryInterface::findOverdueWaiting()` exist, but nothing uses them: there is no sweep of overdue `waiting` instances.
- **A synchronous listener can fail `apply()` after the fact.** An exception from a `Sync` listener of `WorkflowTransitionApplied` propagates out of `apply()` after the state, history, side effects and timeout are all done, and the follow-up event is not dispatched. Use `Async` or `Queued` for anything that can fail.
- **The start payload does not reach guards.** Guards and side effects get the `context` of the `ApplyTransitionCommand`. What you passed to `start()` is on the instance: `getPayloadJson()`, `getContextJson()`.
