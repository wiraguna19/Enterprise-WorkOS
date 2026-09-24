# ADR 0046 — A rule that names two of thirteen enforces nothing

- **Status:** accepted
- **Date:** 2026-09-24
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/01` §1, `docs/01` §3, ADR 0045

## Context

ADR 0045 ended on an admission. The department rename had been written straight
from the controller — `$department->forceFill($request->validated())->save()` —
and so recorded nothing, and the architecture rule that exists to prevent
exactly that watched it happen:

> It also slipped the architecture rule. `controllers never touch the database
> directly` covers the Organization namespace, but it watches the `DB` facade —
> and this was Eloquent.

That sentence names one hole. Reading the rule afterwards found a second, and
the second is larger:

```php
arch('controllers never touch the database directly')
    ->expect('Illuminate\Support\Facades\DB')
    ->not->toBeUsedIn([
        'App\Modules\Identity\Http\Controller',
        'App\Modules\Organization\Http\Controller',
    ]);
```

Two namespaces. There are **thirteen**. The rule was written in Phase 2, when
two were all there were, and every module added since arrived outside it — the
list was never a decision about scope, it was a snapshot of the codebase on the
day it was written, frozen.

So the rule enforced neither half of its own sentence: not "controllers", which
meant two of thirteen, and not "the database", which meant one facade. Its
comment, meanwhile, spoke generally and confidently:

> Controllers validate, authorize, call one service, return one resource.
> Raw database access there is the first step toward a fat controller.

**This is variant 11 again** — a docblock asserting a mechanism directly above
the code that contradicts it — but in its most expensive form, because here the
prose was not merely wrong, it was *reassuring*. A reviewer who reads that
comment stops looking. The rule was load-bearing in everybody's head and
load-bearing nowhere else.

Running the missing halves of it over the whole tree produced the bill:
**30 direct writes across 9 controllers in 8 modules.**

| Module | Controller | What it wrote |
|---|---|---|
| Calendar | `CalendarFeedController` | issued, revoked and touched subscription tokens |
| Files | `FileController` | inserted attachment rows |
| Insights | `ReportController` | inserted export requests |
| Notification | `NotificationController` | marked read; upserted preferences |
| Organization | `OrganizationSettingsController` | the MFA policy; the session policy |
| Work | `WorkItemController` | deleted work items |
| Work | `ProjectController` | created projects; pinned them |
| Workflow | `WorkflowController` | created and edited automation rules |
| Workflow | `RecurrenceController` | created and stopped recurrences |

One of those already knew. `ProjectController::setPinned` carried this comment,
shipped in ADR 0044:

> An arch test says so; this found it at runtime first **because the write is in
> a controller.**

The code had written down its own diagnosis and nobody, including the author,
followed it one step further.

## Decision

**Widen the rule to every module, and make it about writes.**

The old sentence is retired rather than repaired, because it promised something
this codebase cannot honour: a controller that may not READ the database needs a
query object per endpoint, and thirteen modules of them would be an arch rule
paid for in indirection nobody asked for. *Over-asking is not a safe direction
for a guard* — a rule people cannot satisfy is a rule people turn off.

What is enforced now is narrower and actually true:

> **A controller does not write.** Reads are allowed. A write is not.

The asymmetry is not a compromise, it is the finding. A read in a controller
produces a wrong page. A write in a controller produces a **right page and a
wrong database**, because the service is where the activity entry, the domain
event, the `lock_version` bump and the transaction live — so a write placed
beside them instead of inside them ships without any of the four and looks
correct from the outside. The rename is the entire argument: it worked, and it
recorded nothing.

It is written as a source test, not an arch expectation. Pest's arch API
expresses *"uses this class"*; `->save()` on a model is a method call on an
instance, which no `toBeUsedIn` can see. The test strips comments with
`token_get_all` before matching — this codebase's prose names half the write
methods it looks for, and a grep that reads comments would convict the
explanation along with the violation — then flags any write call whose receiver
is not `$this->something`, since a controller delegating to an injected service
is precisely the shape being asked for.

**And pay all thirty.** Six new services, and methods on three that already
existed:

- `Calendar\CalendarFeedService` — `issue()` revokes and mints in one call, so a
  leaked URL cannot be left alive by a caller that forgot the first half.
- `Insights\ExportRequests`
- `Organization\OrganizationSettingsService`
- `Workflow\RecurrenceService`
- `Workflow\WorkflowRuleService` — the failure-count reset moves here, where two
  callers cannot each forget it.
- `Files\UploadService::attach()`
- `Notification\NotificationDispatcher::markRead()`, `savePreference()`
- `Work\WorkItemService::delete()`
- `Work\ProjectService::create()`, `setPinned()`

Three of those writes gained a history entry they never had, which is the point
of the rule rather than a side effect of obeying it:

- **a project's own creation** — `ProjectService` recorded `updated`,
  `member_added`, `member_removed` and `member_role_changed`, and a project's
  timeline therefore began in the middle of its own story
- **a work item's deletion** — recorded before the soft delete, not after, since
  `deleted_at` puts the row outside the default scope and a logger that reads it
  back would be writing about something it can no longer see
- **the two organization policies** — requiring a second factor confines every
  person without one, and shortening the session window signs devices out; both
  were answerable only by reading the column afterwards, which says what the
  policy *is* and never who changed it or from what

A pin deliberately records nothing. It is one person's arrangement of their own
sidebar, not something that happened to the project, and putting it in the
project's history would bury the acts that did.

## Consequences

The guard now fails on any write added to any controller in any module,
including modules that do not exist yet — which is the property the old rule
lacked and the reason it aged into decoration.

Three endpoints write one more row than they did yesterday (`activity_logs`).
That is a real cost on create-project and delete-work-item, and it is the cost
`QueryPerformanceTest` exists to price rather than to forbid.

`OrganizationSettingsController` gained a dependency on
`OrganizationSettingsService`, which depends on `Governance\ActivityLogger` —
the same edge `DepartmentService` already has, so the module graph is unchanged.

**The rule that catches this class of defect was itself an instance of it.**
`controllers never touch the database directly` was a specification with an
implementation that covered a sixth of it — variant 10 and variant 11 in one
object. The general lesson is not about controllers:

> A guard written against a list of namespaces is a guard that stops guarding
> the day somebody adds a namespace. Enumerate what is EXCLUDED, never what is
> covered — an exclusion list is read when it is wrong, and an inclusion list is
> silent when it is incomplete.

Nothing else in `ArchitectureTest.php` was audited in this slice. Five other
rules there name a single module by hand — `domain layer is framework free`
names Identity, `value objects and services are final` names Identity, the HTTP
isolation rule names Organization. Each is the same shape as the rule this ADR
replaces, and each is now a known open item rather than an assumption.
