<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Language;

/**
 * The languages this product speaks (ADR 0060).
 *
 * In Platform because two modules need the list and only one direction is
 * legal: Identity stores a person's choice and may depend on Platform, while
 * the middleware that answers each request in a language lives here and may
 * depend on nothing. One list, read from both sides, so a third language is
 * added in one place.
 */
final class Locales
{
    public const SUPPORTED = ['en', 'id'];

    public const DEFAULT = 'en';

    /**
     * The first supported language in an Accept-Language header, by the
     * weights the client gave, or the default.
     *
     * Only the primary subtag is compared: "id-ID" is Indonesian and "en-GB"
     * is English, and nothing here has a regional variant to tell apart.
     */
    public static function fromAcceptLanguage(?string $header): string
    {
        if ($header === null || trim($header) === '') {
            return self::DEFAULT;
        }

        $ranked = [];

        foreach (explode(',', $header) as $position => $part) {
            $pieces = explode(';', trim($part));
            $tag = strtolower(trim($pieces[0]));
            $weight = 1.0;

            foreach (array_slice($pieces, 1) as $parameter) {
                $parameter = trim($parameter);

                if (str_starts_with($parameter, 'q=')) {
                    $weight = (float) substr($parameter, 2);
                }
            }

            if ($tag === '' || $weight <= 0.0) {
                continue;
            }

            // The position breaks ties, so "id, en" stays in the order given.
            $ranked[] = [$weight, -$position, explode('-', $tag)[0]];
        }

        rsort($ranked);

        foreach ($ranked as [, , $language]) {
            if (in_array($language, self::SUPPORTED, true)) {
                return $language;
            }
        }

        return self::DEFAULT;
    }
}
