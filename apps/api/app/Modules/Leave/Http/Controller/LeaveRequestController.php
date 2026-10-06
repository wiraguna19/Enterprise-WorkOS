<?php

declare(strict_types=1);

namespace App\Modules\Leave\Http\Controller;

use App\Modules\Leave\Application\Service\LeaveBalance;
use App\Modules\Leave\Application\Service\LeaveRequests;
use App\Modules\Leave\Application\Service\LeaveSettings;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Asking for time off, and deciding (ADR 0063, slice 2). Who may decide is
 * the service's answer, not a route permission: a manager's authority is the
 * reporting line, which no permission expresses.
 */
final class LeaveRequestController extends ApiController
{
    public function __construct(
        private readonly LeaveRequests $requests,
        private readonly LeaveBalance $balance,
        private readonly LeaveSettings $settings,
        private readonly TenantContext $tenant,
    ) {}

    /** The reader's balance, their requests, and the types they may ask for. */
    public function mine(): ApiResponse
    {
        return $this->ok([
            'balance' => $this->balance->for($this->tenant->membershipId(), CarbonImmutable::today()),
            'requests' => $this->requests->mine(),
            'types' => $this->settings->types(activeOnly: true),
        ]);
    }

    public function quote(Request $request): ApiResponse
    {
        $validated = $request->validate($this->rangeRules());

        return $this->ok($this->requests->quote(
            (string) $validated['leave_type_id'],
            (string) $validated['starts_on'],
            (string) $validated['ends_on'],
            isset($validated['half_day']) ? (string) $validated['half_day'] : null,
        ));
    }

    public function store(Request $request): ApiResponse
    {
        $validated = $request->validate([
            ...$this->rangeRules(),
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        /** @var array{leave_type_id: string, starts_on: string, ends_on: string, half_day?: string|null, reason?: string|null} $validated */
        return $this->created(['id' => $this->requests->submit($validated, $request)]);
    }

    public function awaiting(): ApiResponse
    {
        return $this->ok($this->requests->awaitingMe());
    }

    public function approve(Request $request, string $id): ApiResponse
    {
        $this->requests->approve($id, $this->note($request), $request);

        return $this->noContent();
    }

    public function reject(Request $request, string $id): ApiResponse
    {
        $this->requests->reject($id, $this->note($request), $request);

        return $this->noContent();
    }

    public function cancel(Request $request, string $id): ApiResponse
    {
        $this->requests->cancel($id, $request);

        return $this->noContent();
    }

    /** Every request in a window, for HR. */
    public function index(Request $request): ApiResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['pending', 'approved', 'rejected', 'cancelled'])],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $from = isset($validated['from']) ? CarbonImmutable::parse((string) $validated['from']) : CarbonImmutable::today()->subMonths(3);
        $to = isset($validated['to']) ? CarbonImmutable::parse((string) $validated['to']) : CarbonImmutable::today()->addMonths(6);

        return $this->ok($this->requests->all(
            isset($validated['status']) ? (string) $validated['status'] : null,
            $from,
            $to,
        ));
    }

    /** @return array<string, list<mixed>> */
    private function rangeRules(): array
    {
        return [
            'leave_type_id' => ['required', 'uuid'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d'],
            'half_day' => ['sometimes', 'nullable', Rule::in(['am', 'pm'])],
        ];
    }

    private function note(Request $request): ?string
    {
        $validated = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:1000']]);

        return isset($validated['note']) ? (string) $validated['note'] : null;
    }
}
