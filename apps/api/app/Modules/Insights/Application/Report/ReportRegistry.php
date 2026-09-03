<?php

declare(strict_types=1);

namespace App\Modules\Insights\Application\Report;

use InvalidArgumentException;

/**
 * The four reports docs/10 asks for, and no fifth one by accident.
 *
 * Closed on purpose: the report key is in a CHECK constraint on
 * `report_exports` as well as here, so a key that reaches the queue is a key
 * that existed when the row was written. A registry that accepted anything
 * would let a typo become a `failed` export an hour later instead of a 422 in
 * the moment.
 */
final class ReportRegistry
{
    public function __construct(
        private readonly ProjectReport $project,
        private readonly TeamReport $team,
        private readonly PersonalReport $personal,
        private readonly OrganizationReport $organization,
    ) {}

    /** @return list<string> */
    public function keys(): array
    {
        return ['project', 'team', 'personal', 'organization'];
    }

    public function get(string $key): ReportBuilder
    {
        return match ($key) {
            'project' => $this->project,
            'team' => $this->team,
            'personal' => $this->personal,
            'organization' => $this->organization,
            default => throw new InvalidArgumentException("Unknown report [{$key}]."),
        };
    }
}
