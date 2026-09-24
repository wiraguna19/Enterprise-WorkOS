# ADR 0042 — "0%" and "nothing to measure" are different facts

- **Status:** accepted
- **Date:** 2026-09-24
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/12` §8, ADR 0040

## Context

`projects.progress_cache` is `NOT NULL` with a `CHECK (0..100)`. A project with
no countable work therefore stores **0**, exactly like a project whose work
exists and is entirely untouched.

`RollUpProjectProgress` knows this. Its own docblock says the column cannot
express "no work yet", and claims the directory tells the two cases apart *"by
the open-work count it already carries"*.

It could not. A project whose work is all finished also has none open, so the
open count does not separate them — and even where it does, the progress cell
itself still rendered `0%` with an empty bar. The reader had to infer the
meaning of one column from another, two columns away.

The result was a confident **"0% complete"** about a project nobody had put
work in yet. That is the same defect this column was already caught in once:
the bar rendered 0% for every project from Phase 2 until the roll-up was
written, and the comment recording that is still directly above the code that
kept doing a quieter version of it. **A wrong number that looks computed is
never reported as a bug.**

## Decision

**The API answers `null` for progress when nothing was counted.** The database
cannot express it and the client should not have to infer it, so the one place
left is the payload.

The directory query gains `countable_work_count` — the **denominator** the
percentage is computed over, filtered exactly as the roll-up command filters
(cancelled work excluded by both). A percentage and the count that explains it
must be computed over the same set, or the explanation contradicts the number.

**On an endpoint that does not select that count, the cached figure is served
unchanged.** Absent evidence is not evidence of absence: a detail payload
claiming "no work" because it never asked would be worse than the 0 it
replaced.

The directory renders **"no work yet"**, and still renders a bar at 0% for a
project whose work exists and is untouched — which is a real answer, not a
placeholder.

## Consequences

- `Project.progress` is `number | null` in the web contract. Every reader is a
  branch now, which is the cost of the distinction and is the point.
- The roll-up command's docblock is now wrong about how the directory
  distinguishes the two cases; it is corrected in the same commit rather than
  left as a second claim nobody checks.
- The overview screen is unaffected: it reads `progress_percent` from the
  health query, which computes live and has been nullable all along. The two
  paths now agree, which they did not before — one said `null`, the other said
  `0`, about the same project.
