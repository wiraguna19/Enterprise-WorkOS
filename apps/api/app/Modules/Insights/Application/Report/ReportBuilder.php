<?php

declare(strict_types=1);

namespace App\Modules\Insights\Application\Report;

/**
 * One named report (ADR 0011).
 *
 * A report composes figures the Insights ADRs already define. **No
 * implementation here computes a new number.** A fifth definition is a fifth
 * thing to keep in step with four others, and the first one to drift quietly;
 * if a report needs a figure that does not exist, that figure gets an ADR of
 * its own before it gets a column.
 *
 * Every builder runs INSIDE the requester's tenant and membership context, in a
 * request or in a queued job bound with `runForMembership()`. That is why none
 * of them takes an actor argument: the visibility rules they call are the same
 * ones the interactive endpoints use, resolved from the context, and an actor
 * parameter would be an invitation to pass a different one.
 */
interface ReportBuilder
{
    /**
     * The column headings, in file order.
     *
     * @return list<string>
     */
    public function columns(): array;

    /**
     * The rows this reader may see, and how many were withheld.
     *
     * `hidden_count` is not optional bookkeeping: a total is a fact about the
     * organization and the rows are a fact about the reader (docs/06 §2), and
     * a file whose rows do not add up to its header is the defect every
     * drill-through in this phase was built to avoid.
     *
     * @param  array<string, mixed>  $parameters
     * @return array{rows: list<list<scalar|null>>, hidden_count: int}
     */
    public function build(array $parameters): array;
}
