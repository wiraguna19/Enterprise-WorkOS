<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * What a service account may not be, by name (ADR 0059).
 */
final class ServiceAccountRefused extends DomainException
{
    private function __construct(
        string $message,
        private readonly string $refusalCode,
        private readonly int $status = 422,
        array $details = [],
    ) {
        parent::__construct($message, $details);
    }

    public function errorCode(): string
    {
        return $this->refusalCode;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    /**
     * Not an administrator. An integration holding org_admin could manage
     * roles and people with a credential nobody signs in behind; the roles it
     * needs are narrower than that, and a custom role can say exactly which.
     */
    public static function tooPowerful(string $roleKey): self
    {
        return new self(
            __('A service account cannot hold the :role role. Give it a role with only what the integration needs.', ['role' => $roleKey]),
            'service_account.role_too_powerful',
            422,
            ['role' => $roleKey],
        );
    }

    /** @param  list<string>  $permissions */
    public static function beyondYourAuthority(string $roleKey, array $permissions): self
    {
        return new self(
            __('You cannot give a service account a role that holds permissions you do not hold yourself: :permissions', [
                'permissions' => implode(', ', $permissions),
            ]),
            'service_account.beyond_your_own_authority',
            409,
            ['role' => $roleKey, 'permissions' => $permissions],
        );
    }

    public static function unknownRole(string $roleKey): self
    {
        return new self(__('There is no role called :role here.', ['role' => $roleKey]), 'service_account.unknown_role', 422, ['role' => $roleKey]);
    }

    public static function notFound(): self
    {
        return new self('That service account does not exist.', 'service_account.not_found', 404);
    }

    public static function deactivated(): self
    {
        return new self('That service account has been deactivated. Make a new one.', 'service_account.deactivated', 409);
    }

    /** Work is held by people (ADR 0059). */
    public static function cannotHoldWork(): self
    {
        return new self(
            'A service account cannot be given work to do. Assign it to the person who answers for the integration.',
            'service_account.cannot_hold_work',
        );
    }
}
