<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http;

use App\Modules\Platform\Domain\Exception\DomainException;
use App\Modules\Platform\Domain\Exception\TenantContextMissing;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Turns every exception into the one error envelope
 * (docs/05-api-architecture.md §3).
 *
 * Two rules worth stating explicitly:
 *   - `code` is stable and machine-readable; `message` is for humans and may change.
 *   - 5xx never leaks a stack trace or an internal message to the client.
 */
final class ApiExceptionRenderer
{
    public function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->expectsJson() && ! $request->is('api/*')) {
            return null;
        }

        $requestId = (string) $request->attributes->get('request_id');

        return match (true) {
            $e instanceof ValidationException => $this->envelope(
                'validation.failed',
                'The given data was invalid.',
                422,
                $requestId,
                $e->errors(),
            ),

            $e instanceof AuthenticationException => $this->envelope(
                'auth.unauthenticated',
                'Authentication is required.',
                401,
                $requestId,
            ),

            $e instanceof AuthorizationException => $this->envelope(
                'auth.forbidden',
                $e->getMessage() ?: 'You are not permitted to perform this action.',
                403,
                $requestId,
            ),

            // A model that is invisible because of the tenant scope arrives here
            // as "not found" — which is correct and deliberate. Returning 403
            // across tenants would confirm the record exists (docs/05 §3).
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => $this->envelope(
                'resource.not_found',
                'Resource not found.',
                404,
                $requestId,
            ),

            $e instanceof TooManyRequestsHttpException => $this->envelope(
                'rate_limit.exceeded',
                'Too many requests. Try again shortly.',
                429,
                $requestId,
            ),

            $e instanceof DomainException => $this->envelope(
                $e->errorCode(),
                $e->getMessage(),
                $e->httpStatus(),
                $requestId,
                $e->details(),
            ),

            // Always a bug, never a user error: log loudly, tell the client nothing.
            $e instanceof TenantContextMissing => $this->serverError($e, $requestId),

            default => null, // let the framework handle it in local/testing
        };
    }

    /** @param array<string, mixed> $details */
    private function envelope(
        string $code,
        string $message,
        int $status,
        string $requestId,
        array $details = [],
    ): JsonResponse {
        $error = [
            'code' => $code,
            'message' => $message,
            'request_id' => $requestId,
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return response()->json(['error' => $error], $status);
    }

    private function serverError(Throwable $e, string $requestId): JsonResponse
    {
        Log::critical('tenancy.context_missing', [
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'request_id' => $requestId,
        ]);

        return $this->envelope(
            'server.error',
            'Something went wrong. Quote the request ID when contacting support.',
            500,
            $requestId,
        );
    }
}
