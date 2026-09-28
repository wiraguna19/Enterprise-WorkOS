<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Saml;

use RuntimeException;

/**
 * The toolkit's refusal, with the toolkit's reason.
 *
 * Never shown to the person signing in — "the signature does not match" is
 * for the administrator, and the audit log is where they read it. The service
 * turns it into one public answer (ADR 0052).
 */
final class SamlRejected extends RuntimeException {}
