<?php

declare(strict_types=1);

namespace App\Modules\Work\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * Every refusal project membership can express, each with a name (ADR 0041).
 *
 * One class with named constructors rather than five files: these are one
 * decision seen from five angles, and a reader needs the whole set in front of
 * them. The `code` is what a client branches on (docs/05 §3).
 */
final class ProjectMembershipRefused extends DomainException
{
    /** @param array<string, mixed> $details */
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

    public static function alreadyAMember(): self
    {
        return new self(
            'They are already on this project.',
            'project.already_a_member',
            409,
        );
    }

    public static function teamAlreadyAdded(): self
    {
        return new self(
            'That team already has access to this project.',
            'project.team_already_added',
            409,
        );
    }

    /**
     * A row grants access to a person OR a team, never both and never neither.
     *
     * The database says the same thing with a CHECK. This says it in a sentence
     * somebody can act on, which the constraint violation would not.
     */
    public static function needsExactlyOneSubject(): self
    {
        return new self(
            'Add either a person or a team, not both.',
            'project.member_subject',
        );
    }

    /**
     * A project with nobody who can administer it is a project nobody can fix.
     *
     * The owner row is what `ProjectPolicy` reads to decide who may change a
     * project without the organization-wide permission, so removing the last
     * one would leave the project editable only by an administrator — and a
     * private one visible to nobody at all.
     */
    public static function lastOwner(): self
    {
        return new self(
            'A project keeps at least one owner. Make somebody else the owner first.',
            'project.last_owner',
        );
    }

    public static function notAMember(): self
    {
        return new self(
            'That is not a current member of this project.',
            'project.not_a_member',
            404,
        );
    }
}
