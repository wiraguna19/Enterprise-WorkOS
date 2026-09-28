<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Saml;

/**
 * What a verified response vouches for — and only that.
 *
 * `email` is null when the response names somebody by an identifier that is
 * not an address; the caller refuses it with a sentence the administrator can
 * act on ("send an email attribute"), rather than guessing.
 */
final readonly class SamlAssertion
{
    public function __construct(
        public string $nameId,
        public ?string $email,
    ) {}
}
