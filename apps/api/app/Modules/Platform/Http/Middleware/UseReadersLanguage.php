<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\Domain\Language\Locales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answer each request in the language it asked for (ADR 0060).
 *
 * From Accept-Language, the header every HTTP client already knows how to
 * send. The web sends the reader's language with each call; a script that
 * sends nothing gets English, as before.
 *
 * Early in the group, so a refusal raised anywhere after it — authentication,
 * validation, a domain rule — is rendered in that language. Strings not yet
 * translated fall back to English by themselves: `lang/id.json` is keyed by
 * the English sentence, so a module moves over one sentence at a time.
 */
final class UseReadersLanguage
{
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale(Locales::fromAcceptLanguage($request->header('Accept-Language')));

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('Content-Language', App::getLocale());

        return $response;
    }
}
