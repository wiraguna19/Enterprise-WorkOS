<?php

declare(strict_types=1);

namespace App\Modules\Insights\Application\Report;

/**
 * Turning a report's rows into a file (ADR 0011).
 *
 * Introduced when the second format arrived, and shaped by what the first one
 * already knew: a writer owns not just the bytes but the **MIME type and the
 * extension that must agree with them**. The refusal this replaces existed
 * because an `.xlsx` that is really a CSV opens, looks right, and lies — so the
 * three facts travel together rather than being assembled by the caller from
 * memory.
 */
interface ReportWriter
{
    /**
     * @param  list<string>  $columns
     * @param  list<list<scalar|null>>  $rows
     */
    public function write(array $columns, array $rows): string;

    public function mimeType(): string;

    /** Without the dot. */
    public function extension(): string;
}
