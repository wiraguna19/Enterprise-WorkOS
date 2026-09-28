<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controller;

use App\Modules\Identity\Application\Service\ApiTokens;
use App\Modules\Identity\Infrastructure\Eloquent\SessionModel;
use App\Modules\Identity\Infrastructure\Eloquent\UserModel;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A person's own API tokens (ADR 0049).
 *
 * Every route here is reachable from a browser session only — `LimitApiTokens`
 * refuses `api_tokens.*` to a token, so a leaked token cannot mint another one
 * and outlive its own revocation.
 */
final class ApiTokenController extends ApiController
{
    public function __construct(
        private readonly ApiTokens $tokens,
    ) {}

    public function index(Request $request): ApiResponse
    {
        return ApiResponse::collection(
            $this->tokens->forUser($this->user($request))->map($this->present(...))->all(),
        );
    }

    /** The response carries the token's value; nothing else ever will. */
    public function store(Request $request): ApiResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'access' => ['required', Rule::in(array_keys(ApiTokens::ACCESS))],
            'expires_in_days' => ['required', 'integer', Rule::in(ApiTokens::LIFETIMES)],
        ]);

        [$token, $value] = $this->tokens->issue(
            $this->user($request),
            (string) $validated['name'],
            (string) $validated['access'],
            (int) $validated['expires_in_days'],
            $request,
        );

        return $this->created($this->present($token) + ['token' => $value]);
    }

    public function destroy(Request $request, string $id): ApiResponse
    {
        $this->tokens->revoke($this->user($request), $id, $request);

        return $this->noContent();
    }

    private function user(Request $request): UserModel
    {
        /** @var UserModel $user the routes are behind auth:sanctum */
        $user = $request->user();

        return $user;
    }

    /** @return array<string, mixed> */
    private function present(SessionModel $token): array
    {
        return [
            'id' => $token->getKey(),
            'name' => $token->name,
            'access' => $token->canWrite() ? 'read_write' : 'read',
            'created_at' => $token->created_at->toIso8601String(),
            // Sanctum writes this on every authenticated request, so "is this
            // integration still running" has an answer without a log.
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'expires_at' => $token->expires_at->toIso8601String(),
        ];
    }
}
