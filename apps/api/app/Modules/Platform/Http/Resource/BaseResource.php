<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Resource;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * No model is ever serialized directly — that is how password_hash ends up in a
 * payload. Every response passes through a Resource that names its fields
 * explicitly (docs/05-api-architecture.md §1).
 */
abstract class BaseResource extends JsonResource
{
    public static $wrap = null;

    /**
     * The server's own authorization decision, echoed so the client knows which
     * controls to render. It describes the decision; it does not delegate it.
     *
     * @param  array<string, string>  $abilities  action => policy method
     * @return array<string, bool>
     */
    protected function permissions(array $abilities): array
    {
        // Typed by capability, not by class: Platform may not know Identity's
        // user model, and `can()` is all this needs (docs/04 §3).
        /** @var Authorizable|null $user */
        $user = request()?->user();
        $resolved = [];

        foreach ($abilities as $action => $ability) {
            $resolved[$action] = $user !== null && $user->can($ability, $this->resource);
        }

        return $resolved;
    }
}
