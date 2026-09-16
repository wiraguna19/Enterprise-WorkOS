<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * An invitation that cannot be issued, or cannot be accepted.
 *
 * 409 for the issuing side — the request is well-formed and the actor holds
 * `person.invite`; it is the state of the organization that refuses. The
 * accepting side uses 404 through `notFound()` instead, because a token that
 * is wrong, expired, revoked or already used must be indistinguishable: any
 * difference is an oracle for guessing tokens.
 *
 * `details.refusal` names which rule refused.
 */
final class InvitationRefused extends DomainException
{
    public function errorCode(): string
    {
        return 'invitation.refused';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
