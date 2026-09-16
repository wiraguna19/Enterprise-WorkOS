<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controller;

use App\Modules\Identity\Application\Service\AuthenticationService;
use App\Modules\Identity\Application\Service\PermissionResolver;
use App\Modules\Identity\Application\Service\SessionDirectory;
use App\Modules\Identity\Http\Request\LoginRequest;
use App\Modules\Identity\Http\Resource\UserResource;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Identity\Infrastructure\Eloquent\SessionModel;
use App\Modules\Identity\Infrastructure\Eloquent\UserModel;
use App\Modules\Platform\Domain\Contract\OrganizationDirectory;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use Illuminate\Http\Request;

/**
 * Validate, authorize, call one service, return one resource
 * (docs/01 §3). No business logic lives here.
 */
final class AuthController extends ApiController
{
    public function __construct(
        private readonly AuthenticationService $auth,
        private readonly PermissionResolver $permissions,
        private readonly OrganizationDirectory $organizations,
        private readonly SessionDirectory $sessions,
    ) {}

    public function login(LoginRequest $request): ApiResponse
    {
        $result = $this->auth->login(
            email: $request->string('email')->toString(),
            password: $request->string('password')->toString(),
            request: $request,
            organizationId: $request->has('organization_id')
                ? $request->string('organization_id')->toString()
                : null,
        );

        return $this->ok([
            'token' => $result['token'],
            'expires_at' => $result['session']->expires_at,
            'user' => new UserResource($result['user']),
            'organization' => $this->organizationPayload($result['membership']),
        ]);
    }

    public function logout(Request $request): ApiResponse
    {
        /** @var UserModel $user the route is behind auth:sanctum */
        $user = $request->user();

        /** @var SessionModel $session */
        $session = $user->currentAccessToken();
        $this->auth->logout($session, $request);

        return $this->noContent();
    }

    /**
     * What else is signed in as me (ADR 0023).
     *
     * No permission gates these three: they are not an administrative power
     * over somebody, they are an account looking at itself. Every one of them
     * is scoped to the user in the request, and a session that belongs to
     * anybody else is a 404 — a session id is a uuid, and "that exists but is
     * not yours" would confirm a guess about another account.
     */
    public function sessions(Request $request): ApiResponse
    {
        /** @var UserModel $user the route is behind auth:sanctum */
        $user = $request->user();

        /** @var SessionModel $session */
        $session = $user->currentAccessToken();

        return $this->ok($this->sessions->forUser($user, (string) $session->getKey()));
    }

    public function revokeSession(Request $request, string $id): ApiResponse
    {
        /** @var UserModel $user the route is behind auth:sanctum */
        $user = $request->user();

        /** @var SessionModel $session */
        $session = $user->currentAccessToken();

        $this->sessions->revoke($user, $id, (string) $session->getKey(), $request);

        return $this->noContent();
    }

    /** Everything except this device — the button for "I think I have been
     *  compromised". */
    public function revokeOtherSessions(Request $request): ApiResponse
    {
        /** @var UserModel $user the route is behind auth:sanctum */
        $user = $request->user();

        /** @var SessionModel $session */
        $session = $user->currentAccessToken();

        return $this->ok([
            'ended' => $this->sessions->revokeOthers($user, (string) $session->getKey(), $request),
        ]);
    }

    /**
     * The bootstrap call every client makes on load: who am I, where am I, and
     * what may I do. Returning permissions here is what lets the UI render
     * without inventing its own copy of the rules (docs/07 §4).
     */
    public function me(Request $request): ApiResponse
    {
        /** @var UserModel $user the route is behind auth:sanctum */
        $user = $request->user();

        /** @var SessionModel $session */
        $session = $user->currentAccessToken();

        $membership = MembershipModel::query()
            ->with('employeeProfile')
            ->where('user_id', $user->getKey())
            ->firstOrFail();

        return $this->ok([
            'user' => new UserResource($request->user()),
            'membership' => [
                'id' => $membership->id,
                'status' => $membership->status,
                'joined_at' => $membership->joined_at,
                'job_title' => $membership->employeeProfile?->job_title,
            ],
            'organization' => $this->organizationPayload($membership),
            'permissions' => $this->permissions->permissionsFor($membership),
            'session' => [
                'id' => $session->getKey(),
                'expires_at' => $session->expires_at,
            ],
        ]);
    }

    /**
     * Read through Organization's own contract, not its model.
     *
     * The session payload needs a name and a slug; wanting three fields is not
     * a reason for Identity to depend on Organization (docs/04 §3).
     *
     * @return array<string, mixed>
     */
    private function organizationPayload(MembershipModel $membership): array
    {
        return $this->organizations->summary((string) $membership->organization_id) ?? [
            'id' => (string) $membership->organization_id,
            'name' => '',
            'slug' => '',
        ];
    }
}
