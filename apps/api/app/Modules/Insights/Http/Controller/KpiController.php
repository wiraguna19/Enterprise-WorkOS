<?php

declare(strict_types=1);

namespace App\Modules\Insights\Http\Controller;

use App\Modules\Insights\Application\Kpi\KpiAuthority;
use App\Modules\Insights\Application\Kpi\KpiMetrics;
use App\Modules\Insights\Application\Kpi\KpiPeriod;
use App\Modules\Insights\Application\Kpi\Kpis;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Key performance indicators (ADR 0062).
 *
 * Reads are gated on `kpi.view` at the route. Writes are not gated there:
 * `kpi.manage` granted on one team is a way to be allowed, and route
 * middleware only knows organization-wide permissions. KpiAuthority decides.
 */
final class KpiController extends ApiController
{
    /** What the shared list can be filtered by: groups only, never people (ADR 0062). */
    private const SUBJECTS = ['team', 'department', 'project'];

    public function __construct(
        private readonly Kpis $kpis,
        private readonly KpiAuthority $authority,
    ) {}

    public function index(Request $request): ApiResponse
    {
        /** @var array{subject_type?: string, subject_id?: string} $validated */
        $validated = $request->validate([
            'subject_type' => ['sometimes', 'string', Rule::in(self::SUBJECTS)],
            'subject_id' => ['sometimes', 'uuid'],
        ]);

        return ApiResponse::collection($this->kpis->list(
            $validated['subject_type'] ?? null,
            $validated['subject_id'] ?? null,
        ));
    }

    /**
     * What a KPI can be: the sources with the unit and direction each fixes,
     * the periods, and the subjects this person may keep KPIs for. Served, so
     * the form never keeps its own copy (Phase 7's served vocabulary).
     */
    public function vocabulary(): ApiResponse
    {
        $sources = [['key' => 'manual', 'unit' => null, 'direction' => null]];

        foreach (KpiMetrics::SOURCES as $source) {
            $sources[] = [
                'key' => $source,
                'unit' => KpiMetrics::UNITS[$source],
                'direction' => KpiMetrics::DIRECTIONS[$source],
            ];
        }

        return ApiResponse::item([
            'sources' => $sources,
            'periods' => KpiPeriod::KINDS,
            'subjects' => $this->authority->subjects(),
        ]);
    }

    /**
     * One person's KPIs (ADR 0062, "Per person"). Their own, or someone's
     * below them in the reporting line; 404 to anybody else.
     */
    public function person(string $membership): ApiResponse
    {
        $result = $this->kpis->forPerson($membership);

        return ApiResponse::collection($result['kpis'], ['can_manage' => $result['can_manage']]);
    }

    public function show(string $id): ApiResponse
    {
        return ApiResponse::item($this->kpis->show($id));
    }

    public function store(Request $request): ApiResponse
    {
        /** @var array{name: string, description?: string|null, subject_type: string, subject_id: string, source: string, unit?: string|null, direction?: string|null, target: float|int|string, period: string} $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'subject_type' => ['required', 'string', Rule::in([...self::SUBJECTS, 'person'])],
            'subject_id' => ['required', 'uuid'],
            'source' => ['required', 'string', Rule::in(['manual', ...KpiMetrics::SOURCES])],
            'unit' => ['sometimes', 'nullable', 'string', 'max:20'],
            'direction' => ['sometimes', 'nullable', 'string', Rule::in(['higher', 'lower'])],
            'target' => ['required', 'numeric', 'between:-999999999,999999999'],
            'period' => ['required', 'string', Rule::in(KpiPeriod::KINDS)],
        ]);

        return ApiResponse::item($this->kpis->show($this->kpis->create($validated)), 201);
    }

    public function update(Request $request, string $id): ApiResponse
    {
        /** @var array{name?: string, description?: string|null, unit?: string|null, direction?: string, target?: float|int|string} $validated */
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:20'],
            'direction' => ['sometimes', 'string', Rule::in(['higher', 'lower'])],
            'target' => ['sometimes', 'numeric', 'between:-999999999,999999999'],
            // Changing any of these would make the history mean something else.
            'subject_type' => ['prohibited'],
            'subject_id' => ['prohibited'],
            'source' => ['prohibited'],
            'period' => ['prohibited'],
        ]);

        $this->kpis->update($id, $validated);

        return ApiResponse::item($this->kpis->show($id));
    }

    public function destroy(string $id): Response
    {
        $this->kpis->archive($id);

        return response()->noContent();
    }

    public function record(Request $request, string $id): ApiResponse
    {
        /** @var array{period_start: string, value: float|int|string, note?: string|null} $validated */
        $validated = $request->validate([
            'period_start' => ['required', 'date_format:Y-m-d'],
            'value' => ['required', 'numeric', 'between:-999999999,999999999'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $this->kpis->record($id, $validated['period_start'], (float) $validated['value'], $validated['note'] ?? null);

        return ApiResponse::item($this->kpis->show($id));
    }
}
