<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "Why can't I do that" — one permission, named in the query string.
 *
 * A query parameter rather than a path segment because a permission key
 * contains a dot, and a dotted last segment is a filename to half the proxies
 * in the world. A FormRequest validates query input as readily as a body, so
 * asking about nothing is a 422 with a sentence in it rather than an
 * explanation of the empty string.
 */
final class ExplainPermissionRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'permission' => ['required', 'string', 'max:80'],
        ];
    }
}
