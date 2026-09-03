# ADR 0011 — A report is a view of numbers that already exist, and an export is a record of a request

- **Status:** accepted
- **Date:** 2026-09-03
- **Phase:** 6 (Insights)
- **Relates to:** `docs/10-roadmap.md` Phase 6, `docs/05-api-architecture.md`
  (`GET /reports/{key}`, `POST /reports/{key}/export`, §6 rate limits),
  `docs/06-auth-and-authorization.md` §2, `docs/03-database-schema.md` §5,
  ADR 0007, ADR 0008, ADR 0010

## Context

`docs/10` closes Phase 6 with "project report, team report, personal work
report, organization report; CSV/XLSX export via queued job", and `docs/05`
reserves two endpoints and a rate limit of five exports per hour per
organization. Nothing anywhere says what a report contains, what an export
produces, or — the question that actually matters — **whose eyes a worker has**
when it builds a file nobody is watching.

## Decision

### A report is a named view of numbers this phase already defined

Four reports, and **not one of them computes anything new**. Each is a
composition of figures ADRs 0007, 0008 and 0010 already define, with the same
definitions, the same drill-throughs and the same nulls:

| Key | Answers | Built from |
|---|---|---|
| `project` | how is one project doing | health signals + that project's flow |
| `team` | what is one team carrying | per-member workload rows |
| `personal` | what did I do | one person's completions and time |
| `organization` | how is work flowing | flow, departments, bottlenecks |

A fifth number invented for a report is a fifth definition to keep in step with
four others, and the first one to drift silently. If a report needs a figure
that does not exist yet, that figure gets an ADR of its own first — the rule the
whole phase has run on.

### An export is a row, not a response

`POST /reports/{key}/export` returns **202 with an export record**, never a
file. A queued job cannot stream bytes to a browser, and pretending otherwise —
building the file synchronously "just for small reports" — produces an endpoint
whose timeout behaviour depends on how much data a customer happens to have.

So an export is a tracked thing with a status: `pending` → `ready` (or
`failed`), a filename, a byte count, and an expiry. It is fetched afterwards
through the same presigned-URL path uploads already use (`docs/03` §5): the
bucket is never public, and the download URL is issued only after an
authorization check on the row.

**The row is per requester, not per organization.** Two people exporting the
same report get two files, because the contents differ — which is the next
decision, and the important one.

### The worker acts as the REQUESTER, never as the system

Every other queued job in this product runs with `TenantContext::runFor()`,
which binds an organization and deliberately leaves the membership null: a rule
engine acts as the system. **An export must not.** Its whole content is a
visibility decision, and a worker with no membership either sees nothing or —
far worse, if someone "fixes" it by widening the query — sees everything.

The export job binds `runForMembership(organization, requester)`, which already
exists for the calendar feed and which `ActingMembership` resolves from, so
`WorkItemVisibility` and every policy downstream behave exactly as they did in
the request that asked. Two consequences follow, both wanted:

- Permissions are re-resolved **at run time**, not at request time. Someone
  whose access narrowed between asking and the job running gets the narrower
  file. An export is not a promise made at 09:00 and honoured at 09:05.
- The aggregate/list split (`docs/06` §2, and the house rule this phase has
  applied everywhere) holds inside the file too: totals are the organization's,
  and the rows behind them are the reader's, with the withheld count written
  into the file rather than left as an unexplained shortfall.

A test asserts two people exporting the same report get different row counts.
It is the only assertion here that catches a leak, and a leak is the failure
that would look completely fine in review.

### CSV now; XLSX when there is something that can write one

`docs/10` asks for both. CSV ships: it needs no dependency, it is what people
load into whatever they already use, and it is written with the streaming
primitives already in the runtime.

**XLSX does not ship in this slice, and is refused rather than faked.** No
spreadsheet writer is installed, and an `.xlsx` that is really a CSV is the
worst available outcome — it opens, it looks right, and it is a lie about the
file's type that some other program will eventually choke on. The column accepts
the value so the table does not need changing later, and the API answers 422
naming the reason until a writer (`openspout` or similar) is added.

That refusal is the point: this codebase's most expensive recurring defect is
the thing that was declared and never built, and half-building the format is how
one gets declared.

**Neither format will carry a formula.** A spreadsheet that recomputes a figure
is a second implementation of a definition this phase has spent four ADRs
keeping singular, and it recomputes it where no test can reach.

### The rate limit is per organization, as `docs/05` says

Five per hour, keyed on the organization rather than the person: the cost being
limited is the worker's, and ten people in one tenant each politely exporting
twice is the same load as one person exporting twenty times.

## Consequences

**Bought:** the last Phase 6 deliverable, built from definitions that already
exist, and the first background job in the product that acts as a person — with
the mechanism (`runForMembership`) already proven by the calendar feed rather
than invented here.

**Paid:** a new deptrac edge — `Insights → Files`. Insights holds no records of
its own and now writes one kind of file, and it reaches storage through Files'
contract rather than learning what S3 is (`docs/03` §5). Files does not import
Insights, so no cycle appears; the alternative, a second storage contract in
Platform, would put two names on one capability.

Also paid: a new table, and a file lifecycle to own. Exports expire and their
objects are deleted; that needs a scheduled command, which is the same
"declared but never built" hazard this phase keeps finding, so it ships with the
table rather than after it.

**Trigger to revisit:** a report someone wants emailed on a schedule. That is a
subscription, and it is a Phase 7 feature — but the export row is the thing it
would produce, so it should not become a shape only an interactive request can
create.

## Alternatives considered

**Synchronous export for "small" reports.** Half the code paths, and a cliff
where the customer with the most data gets the worst behaviour. Rejected: the
timeout is not a size problem, it is a promise problem.

**One export row per organization, deduplicated.** Cheaper, and wrong the first
time two people with different visibility ask for the same key — the second one
would silently receive the first one's rows.

**Running the job as the system and filtering afterwards.** This is the
attractive mistake: it makes the query simple and moves visibility into
application code, which is precisely where `docs/06` §2 says it must never live,
because the fifteenth call site is the one that forgets.
