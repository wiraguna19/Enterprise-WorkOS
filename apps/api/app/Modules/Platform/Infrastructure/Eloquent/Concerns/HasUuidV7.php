<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Eloquent\Concerns;

use Symfony\Component\Uid\UuidV7;

/**
 * Time-ordered UUIDs.
 *
 * v7 rather than v4 because these IDs are primary keys on tables that will hold
 * millions of rows: v4's randomness destroys B-tree insert locality and inflates
 * index bloat. v7 keeps the locality of an auto-increment while remaining
 * non-guessable and safe to expose in URLs.
 *
 * See docs/03-database-schema.md §0.
 */
trait HasUuidV7
{
    public static function bootHasUuidV7(): void
    {
        static::creating(function ($model): void {
            if ($model->getKey() === null) {
                $model->setAttribute($model->getKeyName(), (string) new UuidV7);
            }
        });
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }

    public static function newId(): string
    {
        return (string) new UuidV7;
    }
}
