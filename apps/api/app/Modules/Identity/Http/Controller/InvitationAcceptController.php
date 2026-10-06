<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controller;

use App\Modules\Identity\Application\Service\Invitations;
use App\Modules\Identity\Http\Request\AcceptInvitationRequest;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use Illuminate\Http\Request;

/**
 * The public half of an invitation (ADR 0017).
 *
 * Unauthenticated by necessity — the person holding the link has no account
 * yet — which makes these the only two endpoints besides login that an
 * attacker can reach with nothing. They are throttled like login, and they
 * answer identically for a token that is wrong, expired, revoked or already
 * used: any difference between those four is an oracle for guessing tokens.
 */
final class InvitationAcceptController extends ApiController
{
    public function __construct(
        private readonly Invitations $invitations,
    ) {}

    /**
     * Enough to tell you whether you are in the right place, and no more.
     *
     * The organization's name and the address it was sent to. Not the inviter,
     * not the role, not anything about who else is here: this is readable by
     * anyone holding a string.
     */
    public function show(Request $request): ApiResponse
    {
        // POST with the token in the body rather than GET with it in the
        // path: the token is the whole of the credential, and a path ends up
        // in every proxy's and web server's access log.
        $validated = $request->validate(['token' => ['required', 'string', 'max:200']]);

        $invitation = $this->invitations->preview((string) $validated['token']);

        if ($invitation === null) {
            abort(404);
        }

        return $this->ok($invitation);
    }

    public function accept(AcceptInvitationRequest $request): ApiResponse
    {
        return $this->ok($this->invitations->accept(
            $request->string('token')->toString(),
            $request->string('name')->toString(),
            $request->string('password')->toString(),
            $request,
            $request->string('locale', 'en')->toString(),
        ));
    }
}
