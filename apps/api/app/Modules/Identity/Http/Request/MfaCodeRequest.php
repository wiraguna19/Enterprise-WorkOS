<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Six digits from an authenticator app, or a recovery code (ADR 0030).
 *
 * Loose on purpose: `max:40` rather than `digits:6`. The same field takes a
 * recovery code at the login prompt, and a rule that rejected anything but six
 * digits would tell somebody holding one that they are in the wrong place. The
 * shape is checked where the meaning is — `Totp::verify` strips non-digits and
 * refuses anything that is not six of them.
 */
final class MfaCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40'],
            // Present only at the login prompt, where the session does not
            // exist yet and the challenge is what says who is asking.
            'challenge' => ['sometimes', 'string', 'max:2000'],
        ];
    }
}
