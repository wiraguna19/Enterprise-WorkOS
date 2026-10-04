# ADR 0062: Key performance indicators

- **Status:** accepted
- **Date:** 2026-10-04
- **Phase:** after Phase 7 (the Home queue, part 2 of 3: announcements, then KPIs, then Home)
- **Relates to:** `docs/02` §11 and §12, ADR 0007, ADR 0010, `docs/06` §2
- **Amends:** the "never per person" rule in `docs/02` §11 and ADR 0007, for KPIs only (see "Per person")

## Context

The product owner asked for a place to manage and show key performance data.
The product already measures flow, as ADR 0007 defines it. What it lacks is a
*target*, so there is nothing to say whether a number is good. It also lacks a
way to track things the work data does not hold, such as customer satisfaction
or releases shipped. `docs/02` §12 lists "OKR linkage" as deliberately deferred.
A KPI is the smaller half of that: one number, one target, one period. It does
not depend on objectives.

## Decision

**A KPI is a named number with a target and a period, about one subject.** The
subject is a team, a department, a project or a person. The number is either
computed from the work data or entered by hand.

- **Sources.**
  - `manual`: a value entered once per period, with an optional note. The
    unit is free text ("%", "tickets", "NPS").
  - Computed, using the definitions that already exist, and never new ones:
    - `throughput`: entries into `done` in the period (ADR 0007).
    - `cycle_time_p85`: p85 cycle time in hours, nearest-rank (ADR 0007).
    - `on_time_rate`: the share of *dated* completions that were not late.
      Undated work is outside the denominator (ADR 0010).

    A computed KPI has no stored values. It is recomputed for each period it
    shows. Storing it would create a second copy of a number that already has
    one.
- **Scope of a computed KPI.**
  - Project: its work.
  - Department: work in projects belonging to it *or to any department below
    it*.
  - Team: work whose assignee is a current member.
  - Person: work they are the assignee of.
- **Period.** Week (from Monday), month or quarter, in UTC like the flow report.
  The current period is shown with "so far".
- **Status, from the target and the direction ("higher is better" or "lower is
  better").**
  - *On track*: the target is met.
  - *At risk*: within 10% of the target.
  - *Off track*: further from the target than that.
  - *No data*: nothing to compare yet.

  The tolerance is a constant in one place. Making it configurable is a
  later choice.
- **Permissions.**
  - `kpi.view` lets a person see group KPIs. It is granted to employees,
    managers and admins.
  - `kpi.manage` lets a person create, change and archive group KPIs and
    record their values. It is granted to managers and admins, and it can be
    granted on a single team, department or project as well.

### Per person

The product owner chose to allow KPIs about one person, with both entered and
computed values. This reverses part of `docs/02` §11 and ADR 0007's "never per
person", so the reasons and the limits are written here:

- **The limit is visibility, not the ability to compute.** A person KPI is
  visible to the person and to the people above them in the reporting line
  (`employee_profiles.manager_profile_id`, followed upward). That is the same
  relationship `docs/06` §2 already uses for work-item visibility. An org
  admin outside that line does not see it. Neither does `kpi.view` or any
  report.
- **The manager sets it, and the person reports it.** Only someone above the
  person in the reporting line may create, change or archive a person KPI. The
  person, and only the person, records the values of a manual one, with a
  note if they want. What they report is theirs to say.
- **Never a comparison.**
  - No endpoint lists person KPIs across people.
  - No screen ranks or sorts people by one.
  - Nothing combines several KPIs into one score.

  A manager sees one person's KPIs on that person's page, and never sees a
  table of people.
- **The definitions are the same as for groups.** A computed number about a
  person can be traced item by item, like every other number in the product
  (Phase 6, house rule 1).

- **Where a person KPI is read.** It appears on the person's own page and at
  `GET /people/{membership}/kpis`. Anyone who is neither the person nor above
  them gets a 404, the same as for a KPI that does not exist. `GET /kpis/{id}`
  is therefore not gated on `kpi.view`. The check happens per KPI, so a person
  with no KPI permission can still read their own.

This shipped in a second slice, after the group KPIs, so the visibility rule
has its own tests.

## Consequences

- KPIs live in the `Insights` module. Insights already owns the flow
  definitions, and this adds no new dependency between modules.
- The FlowQuery docblock's "never per person" is amended to point here: per
  person is allowed *only* for a KPI, and only within the reporting line.
- Archived KPIs keep their entries. The history of a target that was dropped is
  still the history.
