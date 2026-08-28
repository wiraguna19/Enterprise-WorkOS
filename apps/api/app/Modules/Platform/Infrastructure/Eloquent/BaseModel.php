<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Eloquent;

use App\Modules\Platform\Infrastructure\Eloquent\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

/**
 * Every persistence model extends this.
 *
 * `$guarded = ['*']` is not a style preference: it makes mass assignment
 * impossible by construction. Attributes are set explicitly from validated
 * DTOs, never from request input. An arch test asserts no model overrides it.
 */
abstract class BaseModel extends Model
{
    use HasUuidV7;

    /** @var list<string> */
    protected $guarded = ['*'];

    public $timestamps = true;

    protected $keyType = 'string';

    public $incrementing = false;
}
