<?php

declare(strict_types=1);

namespace App\Modules\Files\Infrastructure\Eloquent;

use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;

/**
 * A stored blob. Distinct from an attachment, which is a LINK from a subject to
 * this blob — so the same file attached twice is one blob (docs/03 §5).
 *
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it. Add a column here when you add one there, or
 * PHPStan loses the ability to tell a typo from a real field.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string|null $uploaded_by_membership_id
 * @property string $scan_status
 * @property string $upload_state
 * @property CarbonImmutable $created_at
 */
final class FileModel extends TenantModel
{
    protected $table = 'files';

    public $timestamps = false;

    /** The storage path is internal; exposing it invites direct-URL guessing. */
    protected $hidden = ['path', 'disk'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    public function isAvailable(): bool
    {
        return $this->upload_state === 'complete'
            && in_array($this->scan_status, ['clean', 'skipped'], strict: true);
    }
}
