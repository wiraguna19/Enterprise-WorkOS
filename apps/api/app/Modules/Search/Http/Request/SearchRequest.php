<?php

declare(strict_types=1);

namespace App\Modules\Search\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /search?q=&types=&limit=` (docs/05 §2).
 */
final class SearchRequest extends FormRequest
{
    public const TYPES = ['work_item', 'project', 'person'];

    private const MAX_LIMIT = 25;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Two characters is the shortest query that means anything; one
            // matches most of the tenant and costs a scan to say so.
            'q' => ['required', 'string', 'min:2', 'max:200'],
            'types' => ['sometimes', 'string'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ];
    }

    /** @return array<string, mixed> */
    public function messages(): array
    {
        return [
            'q.min' => 'Search for at least two characters.',
        ];
    }

    public function terms(): string
    {
        return trim($this->string('q')->toString());
    }

    /**
     * Absent means all of them; an unknown type is a 422 rather than a silent
     * ignore, for the reason docs/05 §4 gives about filters.
     *
     * @return list<string>
     */
    public function types(): array
    {
        if (! $this->filled('types')) {
            return self::TYPES;
        }

        $requested = array_values(array_filter(array_map(
            trim(...),
            explode(',', $this->string('types')->toString()),
        )));

        $unknown = array_diff($requested, self::TYPES);

        if ($unknown !== []) {
            abort(422, 'Unknown search type: '.implode(', ', $unknown).'.');
        }

        return $requested;
    }

    public function limit(): int
    {
        $limit = $this->integer('limit');

        return $limit > 0 ? min($limit, self::MAX_LIMIT) : 10;
    }
}
