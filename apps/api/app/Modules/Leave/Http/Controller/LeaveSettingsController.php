<?php

declare(strict_types=1);

namespace App\Modules\Leave\Http\Controller;

use App\Modules\Leave\Application\Service\LeaveSettings;
use App\Modules\Leave\Domain\LeavePreset;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The organization's leave rules, types and holidays (ADR 0063).
 *
 * Everything here is behind `leave.manage` at the route, except reading the
 * holidays, which a person asking for time off needs to see.
 */
final class LeaveSettingsController extends ApiController
{
    public function __construct(private readonly LeaveSettings $settings) {}

    public function show(): ApiResponse
    {
        return $this->ok([
            'policy' => $this->settings->policy(),
            'types' => $this->settings->types(),
            'warnings' => $this->settings->warnings(),
            'presets' => LeavePreset::KEYS,
            'levels' => LeaveSettings::LEVELS,
        ]);
    }

    public function applyPreset(Request $request): ApiResponse
    {
        $validated = $request->validate([
            'preset' => ['required', 'string', Rule::in(LeavePreset::KEYS)],
        ]);

        $this->settings->applyPreset((string) $validated['preset'], $request);

        return $this->show();
    }

    public function updatePolicy(Request $request): ApiResponse
    {
        $validated = $request->validate([
            'period' => ['required', Rule::in(['calendar_year', 'hire_anniversary'])],
            'accrual' => ['required', Rule::in(['upfront', 'monthly', 'monthly_first_year'])],
            'base_days' => ['required', 'numeric', 'min:0', 'max:365'],
            'probation_months' => ['required', 'integer', 'min:0', 'max:24'],
            'carry_over_max_days' => ['required', 'numeric', 'min:0', 'max:365'],
            'carry_over_until_month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'approval' => ['required', Rule::in(['manager', 'hr', 'manager_then_hr'])],
            'working_days' => ['required', 'array', 'min:1', 'max:7'],
            'working_days.*' => ['integer', 'min:1', 'max:7'],
            'tenure_bonus' => ['present', 'array', 'max:20'],
            'tenure_bonus.*.years' => ['required', 'integer', 'min:1', 'max:60', 'distinct'],
            'tenure_bonus.*.days' => ['required', 'numeric', 'min:0', 'max:365'],
            'level_bonus' => ['present', 'array'],
            'level_bonus.*' => ['numeric', 'min:0', 'max:365'],
        ]);

        $this->settings->updatePolicy($validated, $request);

        return $this->show();
    }

    public function storeType(Request $request): ApiResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,39}$/'],
            ...$this->typeRules(required: true),
        ]);

        $this->settings->createType($validated, $request);

        return $this->show();
    }

    public function updateType(Request $request, string $id): ApiResponse
    {
        $this->settings->updateType($id, $request->validate($this->typeRules(required: false)), $request);

        return $this->show();
    }

    public function holidays(Request $request): ApiResponse
    {
        $validated = $request->validate(['year' => ['sometimes', 'integer', 'min:2000', 'max:2100']]);

        return $this->ok($this->settings->holidays((int) ($validated['year'] ?? now()->year)));
    }

    public function storeHoliday(Request $request): ApiResponse
    {
        $validated = $request->validate([
            'on_date' => ['required', 'date_format:Y-m-d'],
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(['public', 'collective'])],
        ]);

        $id = $this->settings->addHoliday((string) $validated['on_date'], (string) $validated['name'], (string) $validated['kind'], $request);

        return $this->created(['id' => $id]);
    }

    public function destroyHoliday(Request $request, string $id): ApiResponse
    {
        $this->settings->removeHoliday($id, $request);

        return $this->noContent();
    }

    /** @return array<string, list<mixed>> */
    private function typeRules(bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'string', 'max:80'],
            'paid' => [$presence, 'boolean'],
            'uses_quota' => [$presence, 'boolean'],
            'after_probation' => [$presence, 'boolean'],
            'day_basis' => [$presence, Rule::in(['working_days', 'calendar_days'])],
            'max_days_per_request' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:366'],
            'attachment_after_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:366'],
            'allow_half_day' => [$presence, 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
