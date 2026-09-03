<?php

declare(strict_types=1);

namespace App\Modules\Insights\Application\Report;

use App\Modules\Insights\Application\Query\FlowQuery;
use App\Modules\Work\Application\Query\WorkItemVisibility;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;

/**
 * "How is work flowing" as rows: every completion in the window (ADR 0010).
 *
 * The records rather than the summary, because a summary is what the page is
 * for — somebody exporting this wants the rows the figures came from, in
 * something they can pivot.
 *
 * Late is null, not false, for an item that had no due date: it was not on time
 * either, and a spreadsheet full of FALSE would be read as "delivered on
 * schedule" by everyone who opens it.
 */
final class OrganizationReport implements ReportBuilder
{
    public function __construct(
        private readonly FlowQuery $flow,
        private readonly WorkItemVisibility $visibility,
    ) {}

    public function columns(): array
    {
        return ['reference', 'title', 'project', 'department', 'completed_at', 'cycle_time_hours', 'late'];
    }

    public function build(array $parameters): array
    {
        [$from, $to] = ReportWindow::from($parameters);

        $completions = $this->flow->completions($from, $to);

        /** @var array<string, array{completed_at: string, hours: float|null, late: bool|null, department_name: string|null}> $byId */
        $byId = [];

        foreach ($completions as $row) {
            $byId[$row['work_item_id']] = $row;
        }

        $query = WorkItemModel::query()
            ->with(['project:id,key,name'])
            ->whereIn('id', array_keys($byId));

        $visible = $this->visibility->apply($query)->get();

        // array_values around the whole thing, not Collection::values(): a
        // sorted collection's keys survive `all()`, and `list<>` is a promise
        // about the keys as much as the values.
        $rows = array_values($visible
            ->sortByDesc(fn (WorkItemModel $item): string => $byId[(string) $item->getKey()]['completed_at'])
            ->map(fn (WorkItemModel $item): array => [
                (string) $item->reference,
                (string) $item->title,
                $item->project?->key,
                $byId[(string) $item->getKey()]['department_name'],
                $byId[(string) $item->getKey()]['completed_at'],
                $byId[(string) $item->getKey()]['hours'],
                $byId[(string) $item->getKey()]['late'],
            ])
            ->all());

        return ['rows' => $rows, 'hidden_count' => count($byId) - count($rows)];
    }
}
