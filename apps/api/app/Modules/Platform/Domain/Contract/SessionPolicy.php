<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contract;

/**
 * What an organization has decided about the sessions inside it (ADR 0028).
 *
 * The same seam as `OrganizationDirectory`, for the same reason: Identity
 * issues the session and must know how long it may live, and Organization owns
 * the setting — but the module graph runs Organization → Identity and never
 * back (docs/04 §3). The interface lives in Platform, which everyone may
 * depend on, and Organization binds the implementation.
 *
 * Separate from `OrganizationDirectory` rather than a fifth method on it. That
 * one is a read of a name for a payload; this one decides whether somebody is
 * still signed in tomorrow. Two questions with nothing in common but a subject
 * do not belong behind one interface, and the narrow-on-purpose docblock over
 * there says as much.
 */
interface SessionPolicy
{
    /**
     * The fallback when the organization cannot be read.
     *
     * Deliberately the value that has been hard-coded in
     * `AuthenticationService` since Phase 1: an unreadable organization must
     * not quietly become a stricter — or laxer — policy than the product's
     * documented default.
     */
    public const DEFAULT_LIFETIME_DAYS = 30;

    /** How many days a session issued now may live. */
    public function sessionLifetimeDays(string $organizationId): int;
}
