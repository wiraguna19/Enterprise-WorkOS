<?php

declare(strict_types=1);

namespace App\Modules\Leave\Application\Service;

use App\Modules\Governance\Application\Service\AuditLogger;
use App\Modules\Identity\Application\Service\ActingMembership;
use App\Modules\Identity\Application\Service\PermissionResolver;
use App\Modules\Leave\Domain\Exception\LeaveRefused;
use App\Modules\Notification\Application\Service\NotificationDispatcher;
use App\Modules\Organization\Application\Query\ReportingLine;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use stdClass;
use Symfony\Component\Uid\UuidV7;

/**
 * Asking for time off, and deciding (ADR 0063, slice 2).
 *
 * Who decides is the policy's `approval`: the person's manager (anyone above
 * them in the reporting line), HR (`leave.manage`), or the manager and then
 * HR. Somebody with no manager goes straight to HR. HR may decide any request
 * at any step — they are who corrects mistakes — and nobody decides their own.
 *
 * The reason somebody gives is for them, whoever decides, and HR. Everyone
 * else learns, at most, that they are unavailable (slice 3).
 */
final class LeaveRequests
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ActingMembership $acting,
        private readonly PermissionResolver $permissions,
        private readonly LeaveSettings $settings,
        private readonly LeaveCalendar $calendar,
        private readonly LeaveBalance $balance,
        private readonly ReportingLine $reportingLine,
        private readonly NotificationDispatcher $notifications,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * What a request would be — its days, and the balance it would draw on —
     * without making it. The form asks this as dates are picked.
     *
     * @return array{days: float, uses_quota: bool, available: float|null, document_required: bool}
     */
    public function quote(string $typeId, string $startsOn, string $endsOn, ?string $halfDay): array
    {
        $policy = $this->policyOrRefuse();
        $type = $this->type($typeId);
        [$from, $to] = $this->range($startsOn, $endsOn, $halfDay, $type);

        $days = $this->calendar->count($from, $to, (string) $type->day_basis, $policy['working_days'], $halfDay !== null);
        $balance = (bool) $type->uses_quota ? $this->balance->for($this->tenant->membershipId(), $from) : null;

        return [
            'days' => $days,
            'uses_quota' => (bool) $type->uses_quota,
            'available' => $balance === null ? null : (float) $balance['available'],
            'document_required' => self::documentRequired($type, $days),
        ];
    }

    /** @param array{leave_type_id: string, starts_on: string, ends_on: string, half_day?: string|null, reason?: string|null} $input */
    public function submit(array $input, ?Request $request = null): string
    {
        $me = $this->tenant->membershipId();

        return DB::transaction(function () use ($input, $me, $request): string {
            // One request at a time per person: two submitted together must
            // not both pass the balance check with the same days.
            DB::table('memberships')->where('id', $me)->lockForUpdate()->first(['id']);

            $policy = $this->policyOrRefuse();
            $type = $this->type($input['leave_type_id']);

            if (! (bool) $type->is_active) {
                throw new LeaveRefused(__('That leave type is switched off.'), ['refusal' => 'type_inactive']);
            }

            $halfDay = $input['half_day'] ?? null;
            [$from, $to] = $this->range($input['starts_on'], $input['ends_on'], $halfDay, $type);

            $days = $this->calendar->count($from, $to, (string) $type->day_basis, $policy['working_days'], $halfDay !== null);

            if ($days <= 0) {
                throw new LeaveRefused(__('Those dates have no working days in them.'), ['refusal' => 'no_days']);
            }

            if ($type->max_days_per_request !== null && $days > (int) $type->max_days_per_request) {
                throw new LeaveRefused(
                    __('A single request for :type can be at most :days days.', ['type' => (string) $type->name, 'days' => (int) $type->max_days_per_request]),
                    ['refusal' => 'too_long', 'max' => (int) $type->max_days_per_request],
                );
            }

            $person = $this->balance->person($me);

            if ((bool) $type->after_probation && $person['hired'] !== null) {
                $probationEnds = $person['hired']->addMonths((int) $policy['probation_months']);

                if ($from->lessThan($probationEnds)) {
                    throw new LeaveRefused(
                        __(':type can be used from :date, after probation.', ['type' => (string) $type->name, 'date' => $probationEnds->toDateString()]),
                        ['refusal' => 'in_probation', 'from' => $probationEnds->toDateString()],
                    );
                }
            }

            $overlap = DB::table('leave_requests')
                ->where('organization_id', $this->tenant->organizationId())
                ->where('membership_id', $me)
                ->whereIn('status', ['pending', 'approved'])
                ->where('starts_on', '<=', $to->toDateString())
                ->where('ends_on', '>=', $from->toDateString())
                ->exists();

            if ($overlap) {
                throw new LeaveRefused(__('You already have time off on some of those days.'), ['refusal' => 'overlap']);
            }

            if ((bool) $type->uses_quota) {
                $balance = $this->balance->for($me, $from);
                $available = (float) ($balance['available'] ?? 0);

                if ($days > $available) {
                    throw new LeaveRefused(
                        __('That is :days days and :available are available.', ['days' => $days, 'available' => $available]),
                        ['refusal' => 'not_enough', 'days' => $days, 'available' => $available],
                    );
                }
            }

            $step = $this->firstStep($policy, $me);
            $id = (string) new UuidV7;

            DB::table('leave_requests')->insert([
                'id' => $id,
                'organization_id' => $this->tenant->organizationId(),
                'membership_id' => $me,
                'leave_type_id' => $type->id,
                'starts_on' => $from->toDateString(),
                'ends_on' => $to->toDateString(),
                'half_day' => $halfDay,
                'days' => $days,
                'reason' => isset($input['reason']) && trim((string) $input['reason']) !== '' ? trim((string) $input['reason']) : null,
                'document_required' => self::documentRequired($type, $days),
                'status' => 'pending',
                'step' => $step,
            ]);

            $this->audit->record('leave.requested', [
                'leave_type' => (string) $type->key, 'days' => $days,
            ], $request, targetType: 'leave_request', targetId: $id);

            $this->tell('leave.requested', $id, $this->deciders($step, $me), $type, $from, $to);

            return $id;
        });
    }

    public function approve(string $id, ?string $note, ?Request $request = null): void
    {
        $this->decide($id, 'approve', $note, $request);
    }

    public function reject(string $id, ?string $note, ?Request $request = null): void
    {
        $this->decide($id, 'reject', $note, $request);
    }

    /**
     * Withdrawn by its person while it is pending or has not started; by HR
     * at any time, which is how a mistake is corrected.
     */
    public function cancel(string $id, ?Request $request = null): void
    {
        $row = $this->find($id);
        $me = $this->tenant->membershipId();
        $hr = $this->isHr();

        $mine = (string) $row->membership_id === $me;
        $notStarted = CarbonImmutable::parse((string) $row->starts_on)->greaterThan(CarbonImmutable::today());
        $open = in_array($row->status, ['pending', 'approved'], strict: true);

        if (! $open || (! $hr && (! $mine || ($row->status === 'approved' && ! $notStarted)))) {
            throw new LeaveRefused(__('This request can no longer be withdrawn.'), ['refusal' => 'not_cancellable']);
        }

        DB::table('leave_requests')->where('id', $id)->update([
            'status' => 'cancelled', 'step' => null, 'updated_at' => now(),
            'decided_by_membership_id' => $me, 'decided_at' => now(),
        ]);

        $this->audit->record('leave.cancelled', ['by_hr' => $hr && ! $mine], $request, targetType: 'leave_request', targetId: $id);
    }

    /** @return list<array<string, mixed>> */
    public function mine(): array
    {
        return $this->present($this->query()
            ->where('r.membership_id', $this->tenant->membershipId())
            ->orderByDesc('r.starts_on')
            ->limit(100)
            ->get()
            ->all(), withReason: true);
    }

    /**
     * What is waiting for the reader: their reports' requests at the manager
     * step, and — for HR — everything at the HR step.
     *
     * @return list<array<string, mixed>>
     */
    public function awaitingMe(): array
    {
        $me = $this->tenant->membershipId();
        $below = $this->reportingLine->below($me);
        $hr = $this->isHr();

        $rows = $this->query()
            ->where('r.status', 'pending')
            ->where('r.membership_id', '!=', $me)
            ->where(function ($where) use ($below, $hr): void {
                $where->where(fn ($q) => $q->where('r.step', 'manager')->whereIn('r.membership_id', $below === [] ? [''] : $below));

                if ($hr) {
                    $where->orWhere('r.step', 'hr');
                }
            })
            ->orderBy('r.starts_on')
            ->get()
            ->all();

        return array_map(function (array $request): array {
            $request['balance'] = $request['uses_quota']
                ? $this->balance->for((string) $request['person']['id'], CarbonImmutable::parse((string) $request['starts_on']))
                : null;

            return $request;
        }, $this->present($rows, withReason: true));
    }

    /**
     * Every request in a window, for HR (`leave.manage` at the route).
     *
     * @return list<array<string, mixed>>
     */
    public function all(?string $status, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $query = $this->query()
            ->where('r.starts_on', '<=', $to->toDateString())
            ->where('r.ends_on', '>=', $from->toDateString())
            ->orderByDesc('r.starts_on');

        if ($status !== null) {
            $query->where('r.status', $status);
        }

        return $this->present($query->limit(1000)->get()->all(), withReason: true);
    }

    private function decide(string $id, string $verdict, ?string $note, ?Request $request): void
    {
        DB::transaction(function () use ($id, $verdict, $note, $request): void {
            $row = DB::table('leave_requests')
                ->where('organization_id', $this->tenant->organizationId())
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new LeaveRefused(__('Resource not found.'), ['refusal' => 'not_found'], 404);
            }

            if ($row->status !== 'pending') {
                throw new LeaveRefused(__('This request has already been decided.'), ['refusal' => 'already_decided']);
            }

            $me = $this->tenant->membershipId();

            if ((string) $row->membership_id === $me) {
                throw new LeaveRefused(__('Nobody decides their own request.'), ['refusal' => 'own_request'], 403);
            }

            $hr = $this->isHr();
            $manager = in_array((string) $row->membership_id, $this->reportingLine->below($me), strict: true);

            if (! $hr && ! ($row->step === 'manager' && $manager)) {
                throw new LeaveRefused(__('This request is not yours to decide.'), ['refusal' => 'not_your_step'], 403);
            }

            $policy = $this->policyOrRefuse();
            $type = $this->type((string) $row->leave_type_id);

            // Approved at the manager step under "manager then HR" moves on to
            // HR rather than finishing — unless HR is who approved it.
            $next = $verdict === 'approve' && $row->step === 'manager' && $policy['approval'] === 'manager_then_hr' && ! $hr
                ? 'hr'
                : null;

            DB::table('leave_requests')->where('id', $id)->update([
                'status' => $next !== null ? 'pending' : ($verdict === 'approve' ? 'approved' : 'rejected'),
                'step' => $next,
                'decided_by_membership_id' => $me,
                'decided_at' => now(),
                'decision_note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                'updated_at' => now(),
            ]);

            $this->audit->record($verdict === 'approve' ? 'leave.approved' : 'leave.rejected', [
                'step' => (string) $row->step, 'moved_to' => $next,
            ], $request, targetType: 'leave_request', targetId: $id);

            $from = CarbonImmutable::parse((string) $row->starts_on);
            $to = CarbonImmutable::parse((string) $row->ends_on);

            if ($next !== null) {
                $this->tell('leave.requested', $id, $this->deciders('hr', (string) $row->membership_id), $type, $from, $to);

                return;
            }

            $this->tell($verdict === 'approve' ? 'leave.approved' : 'leave.rejected', $id, [(string) $row->membership_id], $type, $from, $to);
        });
    }

    /** @param array<string, mixed> $policy */
    private function firstStep(array $policy, string $membershipId): string
    {
        if ($policy['approval'] === 'hr') {
            return 'hr';
        }

        // Nobody above them: the manager step has nobody to wait for.
        return $this->managerOf($membershipId) === null ? 'hr' : 'manager';
    }

    private function managerOf(string $membershipId): ?string
    {
        $manager = DB::table('employee_profiles as p')
            ->join('employee_profiles as m', 'm.id', '=', 'p.manager_profile_id')
            ->join('memberships as mm', 'mm.id', '=', 'm.membership_id')
            ->where('p.organization_id', $this->tenant->organizationId())
            ->where('p.membership_id', $membershipId)
            ->where('mm.status', 'active')
            ->value('m.membership_id');

        return $manager === null ? null : (string) $manager;
    }

    /** @return list<string> */
    private function deciders(string $step, string $requester): array
    {
        if ($step === 'manager') {
            $manager = $this->managerOf($requester);

            return $manager === null ? [] : [$manager];
        }

        /** @var list<string> $hr */
        $hr = DB::table('memberships as m')
            ->join('membership_roles as mr', 'mr.membership_id', '=', 'm.id')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'mr.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('m.organization_id', $this->tenant->organizationId())
            ->where('m.status', 'active')
            ->where('p.key', 'leave.manage')
            ->distinct()
            ->pluck('m.id')
            ->map(strval(...))
            ->all();

        return $hr;
    }

    /** @param list<string> $recipients */
    private function tell(string $kind, string $id, array $recipients, stdClass $type, CarbonImmutable $from, CarbonImmutable $to): void
    {
        if ($recipients === []) {
            return;
        }

        $dates = $from->equalTo($to) ? $from->toDateString() : $from->toDateString().' – '.$to->toDateString();

        $this->notifications->dispatch(
            type: $kind,
            subjectType: 'leave_request',
            subjectId: $id,
            recipients: $recipients,
            payload: ['title' => (string) $type->name.', '.$dates],
            dedupeSeed: $kind.':'.now()->timestamp,
        );
    }

    private function isHr(): bool
    {
        return $this->permissions->has($this->acting->getOrFail(), 'leave.manage');
    }

    /** @return array<string, mixed> */
    private function policyOrRefuse(): array
    {
        return $this->settings->policy()
            ?? throw new LeaveRefused(__('Leave rules have not been set up here yet.'), ['refusal' => 'no_policy']);
    }

    private function type(string $id): stdClass
    {
        /** @var stdClass|null $type */
        $type = DB::table('leave_types')
            ->where('organization_id', $this->tenant->organizationId())
            ->where('id', $id)
            ->first();

        return $type ?? throw new LeaveRefused(__('Resource not found.'), ['refusal' => 'type_not_found'], 404);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(string $startsOn, string $endsOn, ?string $halfDay, stdClass $type): array
    {
        $from = CarbonImmutable::parse($startsOn)->startOfDay();
        $to = CarbonImmutable::parse($endsOn)->startOfDay();

        if ($to->lessThan($from)) {
            throw new LeaveRefused(__('The last day is before the first.'), ['refusal' => 'backwards'], 422);
        }

        if ($from->diffInDays($to) > 366) {
            throw new LeaveRefused(__('A request can cover at most a year.'), ['refusal' => 'too_long'], 422);
        }

        if ($halfDay !== null && (! (bool) $type->allow_half_day || ! $from->equalTo($to))) {
            throw new LeaveRefused(__('A half day is one day, of a type that allows it.'), ['refusal' => 'half_day'], 422);
        }

        return [$from, $to];
    }

    private static function documentRequired(stdClass $type, float $days): bool
    {
        // Null: never. Zero: always. N: past N days.
        if ($type->attachment_after_days === null) {
            return false;
        }

        $after = (int) $type->attachment_after_days;

        return $after === 0 || $days > $after;
    }

    private function find(string $id): stdClass
    {
        /** @var stdClass|null $row */
        $row = DB::table('leave_requests')
            ->where('organization_id', $this->tenant->organizationId())
            ->where('id', $id)
            ->first();

        return $row ?? throw new LeaveRefused(__('Resource not found.'), ['refusal' => 'not_found'], 404);
    }

    private function query(): Builder
    {
        return DB::table('leave_requests as r')
            ->join('leave_types as t', 't.id', '=', 'r.leave_type_id')
            ->join('memberships as m', 'm.id', '=', 'r.membership_id')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->leftJoin('memberships as dm', 'dm.id', '=', 'r.decided_by_membership_id')
            ->leftJoin('users as du', 'du.id', '=', 'dm.user_id')
            ->where('r.organization_id', $this->tenant->organizationId())
            ->select([
                'r.*', 't.key as type_key', 't.name as type_name', 't.uses_quota', 't.paid',
                'u.name as person_name', 'du.name as decided_by_name',
            ]);
    }

    /**
     * @param  array<int, stdClass>  $rows
     * @return list<array<string, mixed>>
     */
    private function present(array $rows, bool $withReason): array
    {
        return array_map(static fn (stdClass $row): array => [
            'id' => (string) $row->id,
            'person' => ['id' => (string) $row->membership_id, 'name' => (string) $row->person_name],
            'type' => ['id' => (string) $row->leave_type_id, 'key' => (string) $row->type_key, 'name' => (string) $row->type_name],
            'uses_quota' => (bool) $row->uses_quota,
            'paid' => (bool) $row->paid,
            'starts_on' => substr((string) $row->starts_on, 0, 10),
            'ends_on' => substr((string) $row->ends_on, 0, 10),
            'half_day' => $row->half_day,
            'days' => (float) $row->days,
            'reason' => $withReason ? $row->reason : null,
            'document_required' => (bool) $row->document_required,
            'status' => (string) $row->status,
            'step' => $row->step,
            'decided_by' => $row->decided_by_name === null ? null : (string) $row->decided_by_name,
            'decided_at' => $row->decided_at === null ? null : (string) $row->decided_at,
            'decision_note' => $row->decision_note,
            'created_at' => (string) $row->created_at,
        ], array_values($rows));
    }
}
