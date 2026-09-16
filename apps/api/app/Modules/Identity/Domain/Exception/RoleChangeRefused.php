<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * A change to a role that the organization's own state refuses.
 *
 * Separate from `RoleGrantRefused`, which is about giving a role to somebody:
 * this one is about the role itself — editing one the product ships with,
 * deleting one people hold, or writing authority the author does not have.
 * Two names because a client branching on `role.grant_refused` for "you cannot
 * delete this" would be reading the wrong sentence.
 *
 * 409: the request is well-formed and the actor holds `role.manage`.
 */
final class RoleChangeRefused extends DomainException
{
    public function errorCode(): string
    {
        return 'role.change_refused';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
