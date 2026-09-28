<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controller;

use App\Modules\Identity\Application\Service\SingleSignOn;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The three steps of signing in through an identity provider, and the
 * metadata an IdP imports (ADR 0052).
 *
 * All four are reachable with no session, like login: they are how one is
 * made. None of them is called by a browser directly — the web server calls
 * them, because the session cookie is the web server's to set (docs/06 §1).
 */
final class SingleSignOnController extends ApiController
{
    public function __construct(
        private readonly SingleSignOn $sso,
    ) {}

    /** An address in, an IdP URL out. */
    public function start(Request $request): ApiResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'max:254'],
            // Random, made by the web server and kept in its cookie. Only its
            // digest is stored here (see SingleSignOn).
            'binding' => ['required', 'string', 'min:32', 'max:128'],
        ]);

        return $this->ok($this->sso->start((string) $validated['email'], (string) $validated['binding']));
    }

    /** The IdP's answer, verified — and exchanged for a one-minute code, not a session. */
    public function consume(Request $request): ApiResponse
    {
        $validated = $request->validate([
            // Base64 of a signed XML document: a few kilobytes normally, and
            // bounded well above that so a megabyte of nonsense is refused by
            // the validator rather than parsed.
            'saml_response' => ['required', 'string', 'max:200000'],
            'relay_state' => ['required', 'string', 'max:200'],
        ]);

        return $this->ok([
            'completion' => $this->sso->consume(
                (string) $validated['saml_response'],
                (string) $validated['relay_state'],
                $request,
            ),
        ]);
    }

    /** The same browser, back with its binding: a session. */
    public function complete(Request $request): ApiResponse
    {
        $validated = $request->validate([
            'completion' => ['required', 'string', 'max:200'],
            'binding' => ['required', 'string', 'min:32', 'max:128'],
        ]);

        $result = $this->sso->complete(
            (string) $validated['completion'],
            (string) $validated['binding'],
            $request,
        );

        return $this->ok([
            'token' => $result['token'],
            'expires_at' => $result['session']->expires_at,
        ]);
    }

    /** XML, not the JSON envelope: it is read by IdP consoles, not by this product. */
    public function metadata(): Response
    {
        return response($this->sso->metadata(), 200, ['Content-Type' => 'application/samlmetadata+xml']);
    }
}
