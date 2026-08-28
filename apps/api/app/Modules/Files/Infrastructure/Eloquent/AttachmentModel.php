<?php

declare(strict_types=1);

namespace App\Modules\Files\Infrastructure\Eloquent;

use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it. Add a column here when you add one there, or
 * PHPStan loses the ability to tell a typo from a real field.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $file_id
 * @property string $attachable_type
 * @property string $attachable_id
 * @property int $version
 * @property string|null $attached_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $deleted_at
 */
final class AttachmentModel extends TenantModel
{
    use SoftDeletes;

    protected $table = 'attachments';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['version' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<FileModel, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(FileModel::class, 'file_id');
    }
}
