<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Request;

use Closure;

/**
 * The filter whitelist docs/05 §4 promises, as one mechanism (ADR 0039).
 *
 * Every collection endpoint in this product carried the promise and none but
 * one carried the code. The reason it survived is worth keeping: a FormRequest
 * rule is `sometimes`, so an unlisted key is not REFUSED — it is never
 * mentioned. `rules()` looks exactly like a whitelist and is not one, and three
 * endpoints did not even reach that far, reading `filter.*` straight off the
 * request with nothing validating anything.
 *
 * What that cost, concretely:
 *
 * - `filter[assignee]` — one letter short of `assignee_id` — returned
 *   everybody's work to a client that believed it had asked for one person's.
 * - `filter[department_id]=banana` reached Postgres as a uuid comparison and
 *   came back a **500**. A malformed query parameter is a 422; a 500 says the
 *   server broke, and sends somebody reading logs instead of their own request.
 *
 * One class rather than a copy per endpoint, because the endpoints do not
 * disagree about what this means — they only disagree about which keys they
 * answer. **Fix the class, not the instance.**
 *
 * The message names the key and lists what is allowed. The commonest cause of
 * this refusal is a typo one letter long, and the second is a client written
 * against another version; neither is helped by "invalid filter".
 */
final class OnlyKnownFilters
{
    /**
     * @param  list<string>  $allowed  every key this endpoint answers, in the
     *                                 order a person would want to read them
     * @param  string  $hint  a shape the list cannot spell out, appended to the
     *                        message — `cf_<key> for a custom field`, where the
     *                        legal keys depend on the organization
     */
    public function __construct(
        private readonly array $allowed,
        private readonly string $hint = '',
    ) {}

    public function __invoke(string $attribute, mixed $value, Closure $fail): void
    {
        // Not an array: some other rule's problem. A rule that also policed
        // the type would report two failures for one mistake.
        if (! is_array($value)) {
            return;
        }

        foreach (array_keys($value) as $key) {
            if (in_array((string) $key, $this->allowed, strict: true)) {
                continue;
            }

            $fail(sprintf(
                'Unknown filter "%s". Allowed: %s%s.',
                (string) $key,
                implode(', ', $this->allowed),
                $this->hint === '' ? '' : ', or '.$this->hint,
            ));
        }
    }
}
