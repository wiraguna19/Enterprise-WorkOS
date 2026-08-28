<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controller;

use App\Modules\Platform\Http\Response\ApiResponse;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Routing\Controller;

/**
 * Controllers validate, authorize, call ONE application service, and return one
 * resource. Anything longer than ~25 lines is a smell
 * (docs/01-system-architecture.md §3). An arch test forbids DB access here.
 */
abstract class ApiController extends Controller
{
    use AuthorizesRequests;

    protected function ok(mixed $data): ApiResponse
    {
        return ApiResponse::item($data);
    }

    protected function created(mixed $data): ApiResponse
    {
        return ApiResponse::item($data, 201);
    }

    protected function noContent(): ApiResponse
    {
        return ApiResponse::noContent();
    }
}
