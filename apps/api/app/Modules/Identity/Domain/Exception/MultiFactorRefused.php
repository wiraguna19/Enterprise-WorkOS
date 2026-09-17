<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * A second factor that will not do what the caller is asking of it.
 *
 * Covers enrolment refusals (already on, nothing to confirm, code does not
 * match the pending secret) rather than the login challenge, which answers with
 * `InvalidCredentials` and its 401 — a wrong code at a login prompt is a failed
 * sign-in, not a misuse of the feature, and the two must not be distinguishable
 * to somebody guessing.
 *
 * `details.refusal` names which rule refused, so an interface can be specific
 * without reading prose.
 */
final class MultiFactorRefused extends DomainException
{
    public function errorCode(): string
    {
        return 'auth.mfa_refused';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
