<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resource;

use App\Modules\Identity\Infrastructure\Eloquent\UserModel;
use App\Modules\Platform\Http\Resource\BaseResource;

/**
 * @property UserModel $resource
 */
final class UserResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        return [
            'id' => $this->resource->id,
            'type' => 'user',
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'avatar_url' => $this->resource->avatar_path,
            'timezone' => $this->resource->timezone,
            'locale' => $this->resource->locale,
            'mfa_enabled' => $this->resource->hasMfaEnabled(),
        ];
    }
}
