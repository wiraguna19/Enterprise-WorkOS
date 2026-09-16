<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Controller;

use App\Modules\Identity\Application\Service\Invitations;
use App\Modules\Organization\Http\Request\InvitePersonRequest;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use Illuminate\Http\Request;

/**
 * Inviting somebody into the organization (ADR 0017).
 *
 * `person.invite` was granted to managers and org admins in Phase 1 and had
 * nothing behind it until now — seven phases, the longest-standing entry in
 * this codebase's oldest defect class, and the half of docs/11 §4 flow 2 that
 * the flow asserted the ABSENCE of.
 *
 * The link comes back once, in the response to the person who created it. There
 * is no mail layer in this product and this slice does not invent one; the
 * administrator passes the link on however they already talk to the person, and
 * the screen says so.
 */
final class InvitationController extends ApiController
{
    public function __construct(
        private readonly Invitations $invitations,
    ) {}

    public function index(): ApiResponse
    {
        return $this->ok($this->invitations->pending());
    }

    public function store(InvitePersonRequest $request): ApiResponse
    {
        return $this->created($this->invitations->invite(
            $request->string('email')->toString(),
            $request->filled('role') ? $request->string('role')->toString() : null,
            $request,
        ));
    }

    public function destroy(Request $request, string $id): ApiResponse
    {
        $this->invitations->revoke($id, $request);

        return $this->noContent();
    }
}
