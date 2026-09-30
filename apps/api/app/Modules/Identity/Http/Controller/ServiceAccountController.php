<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controller;

use App\Modules\Identity\Application\Service\ApiTokens;
use App\Modules\Identity\Application\Service\ServiceAccounts;
use App\Modules\Identity\Infrastructure\Eloquent\SessionModel;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * `service-accounts` (ADR 0059). Every route is `service_account.manage`, and
 * every route is refused to API tokens by name (`service_accounts.*`,
 * LimitApiTokens): a token that could reach these could mint its successor.
 */
final class ServiceAccountController extends ApiController
{
    public function __construct(
        private readonly ServiceAccounts $accounts,
    ) {}

    public function index(): ApiResponse
    {
        return ApiResponse::collection(
            $this->accounts->all(),
            // Served, so the form does not keep its own copy of the rule.
            ['refused_roles' => ServiceAccounts::REFUSED_ROLES],
        );
    }

    public function store(Request $request): ApiResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'role' => ['required', 'string', 'max:60'],
        ]);

        $account = $this->accounts->create((string) $validated['name'], (string) $validated['role'], $request);

        return $this->created(['id' => $account->getKey()]);
    }

    public function destroy(Request $request, string $id): ApiResponse
    {
        $this->accounts->deactivate($this->accounts->find($id), $request);

        return $this->noContent();
    }

    public function tokens(string $id): ApiResponse
    {
        return $this->ok(array_map(
            $this->presentToken(...),
            $this->accounts->tokensOf($this->accounts->find($id)),
        ));
    }

    public function issueToken(Request $request, string $id): ApiResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'access' => ['required', Rule::in(array_keys(ApiTokens::ACCESS))],
            'expires_in_days' => ['required', 'integer', Rule::in(ApiTokens::LIFETIMES)],
        ]);

        [$token, $value] = $this->accounts->issueToken(
            $this->accounts->find($id),
            (string) $validated['name'],
            (string) $validated['access'],
            (int) $validated['expires_in_days'],
            $request,
        );

        // The value, once — as for a person's token.
        return $this->created($this->presentToken($token) + ['token' => $value]);
    }

    public function revokeToken(Request $request, string $id, string $token): ApiResponse
    {
        $this->accounts->revokeToken($this->accounts->find($id), $token, $request);

        return $this->noContent();
    }

    /** @return array<string, mixed> */
    private function presentToken(SessionModel $token): array
    {
        return [
            'id' => $token->getKey(),
            'name' => $token->name,
            'access' => $token->canWrite() ? 'read_write' : 'read',
            'created_at' => $token->created_at->toIso8601String(),
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'expires_at' => $token->expires_at->toIso8601String(),
        ];
    }
}
