<?php

declare(strict_types=1);

namespace App\Modules\Insights\Http\Controller;

use App\Modules\Insights\Application\Kpi\PersonDelivery;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * One person's delivery: items finished and the share on time (ADR 0062,
 * "Delivery without a KPI"). Their own, or someone's below them in the
 * reporting line; 404 to anybody else.
 */
final class DeliveryController extends ApiController
{
    public function __construct(
        private readonly PersonDelivery $delivery,
    ) {}

    public function show(string $membership): ApiResponse
    {
        return ApiResponse::item($this->delivery->overview($membership));
    }

    public function items(Request $request, string $membership): ApiResponse
    {
        /** @var array{from: string, to: string} $validated */
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.CarbonImmutable::parse((string) $request->input('from', 'today'))->addYear()->toDateString()],
        ]);

        return ApiResponse::collection($this->delivery->items(
            $membership,
            CarbonImmutable::parse($validated['from'], 'UTC'),
            CarbonImmutable::parse($validated['to'], 'UTC'),
        ));
    }
}
