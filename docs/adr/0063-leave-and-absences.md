# ADR 0063: Leave and absences

- **Status:** accepted (slice 1 of 3: the rules)
- **Date:** 2026-10-06
- **Relates to:** `docs/02` §11 (capacity minus approved time off) and §12, `docs/06` §2, ADR 0018, ADR 0028
- **Amends:** `docs/02` §12, which deferred time off

## Context

Capacity has been defined since Phase 1 as weekly hours *minus approved time
off*. There was nowhere to record time off, so `WorkloadQuery` returned
`time_off_hours: null` and the screens said capacity was unadjusted. A person
on leave looked under-committed, which is the staffing mistake the workload
number exists to prevent.

The product is meant for many companies, each with its own leave rules. So
the rules cannot be code.

## Decision

**Every leave rule is the organization's own setting. A preset fills them in
and warns when they go below it. It never refuses.**

- **`leave_policies`**, one row per organization:
  - quota period: calendar year, or each person's hire anniversary;
  - accrual: all at once, monthly, or monthly in the first year and then all
    at once;
  - base days;
  - probation months;
  - carry-over: maximum days, and the month they expire;
  - approval: the direct manager, HR, or the manager then HR;
  - working days (ISO weekdays);
  - extra days by tenure (bands by years since `hired_at`);
  - extra days by job level.
- **`leave_types`**, what people may be absent for. Each type has:
  - paid or unpaid;
  - whether it uses the annual quota;
  - whether it is available from day one or only after probation;
  - whether it is counted in working days or calendar days;
  - the most days per request;
  - when a document is needed;
  - whether half days are allowed.

  A type's key cannot be changed. A type is switched off, never deleted,
  because past requests must keep saying what they were.
- **`leave_holidays`**: public holidays and collective leave. These are not
  leave types; nobody applies for them, and a request that spans one does not
  count it.
- **`employee_profiles.job_level`**: staff, supervisor, manager or director.
  `hired_at` already existed and is what tenure is counted from.

**Presets.** `indonesia` is the first. As understood in October 2026, it sets:

- 12 working days a year;
- monthly accrual in the first year;
- 3 months' probation;
- 5 days carried over until June;
- approval by the manager;
- Monday to Friday as working days;
- these types: annual, sick (no quota), personal, maternity (90 calendar days)
  and paternity (2 days).

Its minimums produce warnings on the settings screen. Applying a preset again
never overwrites a type that already exists.

**Permissions.**

- `leave.request`: everyone who works here.
- `leave.manage`: the rules, the holidays, every request.
- Approving a report's request needs no permission. Being their manager is the
  authority (ReportingLine).
- A new system role, `hr`, holds people, structure, announcements and leave.
  It holds no projects or work. As always, the role is a set of permissions,
  never a name the code branches on.

**Employment facts** (`PATCH people/{id}/employment`) need `person.update` and
are never your own. These facts decide how much leave a person is owed and how
much work they are measured against.

## Consequences

- Slice 2 adds requests, balances and approval, computed from these rules.
- Slice 3 subtracts approved leave from capacity and shows absences on the
  calendar. Colleagues see "unavailable". The type of leave and the reason are
  for the person, their manager and HR.
- One policy per organization. Different rules for, say, contract staff or a
  second country are a later step: a policy per group.
- The preset's legal figures are a starting point, not legal advice. The
  screen says so.
