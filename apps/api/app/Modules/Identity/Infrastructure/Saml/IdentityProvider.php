<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Saml;

/**
 * The three facts about an identity provider that verifying it needs.
 *
 * Its own type rather than the connection model, so the toolkit never sees
 * a tenant-scoped row and cannot be handed the wrong organization's one by a
 * caller that forgot which tenant it was in.
 */
final readonly class IdentityProvider
{
    public function __construct(
        public string $entityId,
        public string $ssoUrl,
        public string $certificate,
    ) {}
}
