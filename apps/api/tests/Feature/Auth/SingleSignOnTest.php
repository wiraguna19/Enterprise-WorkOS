<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Eloquent\SessionModel;
use App\Modules\Identity\Infrastructure\Saml\SamlToolkit;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\SamlFixture;

/**
 * Signing in through an organization's identity provider (ADR 0052).
 *
 * Every response here is SIGNED — by SamlFixture, with a real key whose
 * certificate the organization configured — and verified by the real toolkit.
 * A mocked verifier would make every one of these pass and prove nothing about
 * the part an attacker aims at.
 *
 * Rina administers Acme (and holds `sso.manage`) and is an employee in Globex;
 * Ahmad is an Acme manager without it. Acme's addresses are @acme.test.
 */
const SSO_ACME = '01900000-0000-7000-8000-0000000000ac';
const SSO_GLOBEX = '01900000-0000-7000-8000-0000000000b0';
const SSO_BINDING = 'the-browser-that-started-this-sign-in-0001';

beforeEach(function (): void {
    $this->rina = $this->loginAs('rina@acme.test');
});

/**
 * Acme's connection, through the API, as its administrator.
 *
 * @return TestResponse<Response>
 */
function connectAcme(string $token, array $domains = ['acme.test']): TestResponse
{
    return test()->withToken($token)->putJson('/api/v1/sso-connection', [
        'idp_entity_id' => SamlFixture::ENTITY_ID,
        'idp_sso_url' => SamlFixture::SSO_URL,
        'idp_certificate' => SamlFixture::pair()['certificate'],
        'domains' => $domains,
    ]);
}

/**
 * What the start step sent the browser to: the relay state, and the id of the
 * request inside SAMLRequest that a response must quote.
 *
 * @return array{relay_state: string, request_id: string}
 */
function readAuthnRequest(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    $encoded = $query['SAMLRequest'] ?? null;
    $relayState = $query['RelayState'] ?? null;
    $xml = is_string($encoded) ? (string) gzinflate((string) base64_decode($encoded, true)) : '';

    return [
        'relay_state' => is_string($relayState) ? $relayState : '',
        'request_id' => preg_match('/ID="([^"]+)"/', $xml, $id) === 1 ? $id[1] : '',
    ];
}

/**
 * Ask for a sign-in, and return where the browser was sent.
 *
 * Headers are flushed first: `withToken()` persists on the test case, and the
 * web server calls these endpoints anonymously — a leftover bearer token would
 * make the request somebody's, and their organization's rules would apply to a
 * sign-in that has no organization yet.
 */
function startSignIn(string $email, string $binding = SSO_BINDING): string
{
    $url = (string) test()->flushHeaders()->postJson('/api/v1/auth/sso/start', ['email' => $email, 'binding' => $binding])
        ->assertOk()
        ->json('data.redirect_url');

    expect($url)->toStartWith(SamlFixture::SSO_URL.'?');

    return $url;
}

/**
 * Start a sign-in and answer it as the IdP would.
 *
 * @param  array<string, mixed>  $fixture  overrides for SamlFixture::response()
 * @return array{consume: TestResponse<Response>, relay_state: string, request_id: string}
 */
function answerAsIdp(string $email, array $fixture = [], string $binding = SSO_BINDING): array
{
    $request = readAuthnRequest(startSignIn($email, $binding));
    $sp = app(SamlToolkit::class)->serviceProvider();

    $response = SamlFixture::response($fixture + [
        'request_id' => $request['request_id'],
        'acs_url' => $sp['acs_url'],
        'audience' => $sp['entity_id'],
        'email' => $email,
    ]);

    return [
        'consume' => test()->postJson('/api/v1/auth/sso/acs', [
            'saml_response' => $response,
            'relay_state' => $request['relay_state'],
        ]),
    ] + $request;
}

/** The whole round trip, to a bearer token. */
function signInThroughIdp(string $email): string
{
    $completion = (string) answerAsIdp($email)['consume']->assertOk()->json('data.completion');

    return (string) test()->postJson('/api/v1/auth/sso/complete', [
        'completion' => $completion,
        'binding' => SSO_BINDING,
    ])->assertOk()->json('data.token');
}

function ssoAudit(string $event, string $organizationId): int
{
    return DB::table('audit_logs')->where('event', $event)->where('organization_id', $organizationId)->count();
}

// ── Configuring it ──────────────────────────────────────────────────────────

it('lets the administrator connect an identity provider, and shows what the IdP needs from this side', function (): void {
    connectAcme($this->rina)
        ->assertOk()
        ->assertJsonPath('data.idp_entity_id', SamlFixture::ENTITY_ID)
        ->assertJsonPath('data.domains', ['acme.test'])
        ->assertJsonPath('data.enforced', false);

    $shown = $this->withToken($this->rina)->getJson('/api/v1/sso-connection')->assertOk();

    expect($shown->json('data.service_provider.acs_url'))->toEndWith('/api/auth/sso/acs')
        ->and($shown->json('data.connection.domains'))->toBe(['acme.test'])
        ->and(ssoAudit('sso.connection_created', SSO_ACME))->toBe(1);
});

it('is administered by sso.manage alone', function (): void {
    $ahmad = $this->loginAs('ahmad@acme.test');

    $this->withToken($ahmad)->getJson('/api/v1/sso-connection')->assertForbidden();
    connectAcme($ahmad)->assertForbidden();
});

it('asks for the password again before changing who may vouch for everybody', function (): void {
    $this->travel(16)->minutes();

    connectAcme($this->rina)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'auth.reauthentication_required');
});

it('refuses a certificate that is not one', function (): void {
    $this->withToken($this->rina)->putJson('/api/v1/sso-connection', [
        'idp_entity_id' => SamlFixture::ENTITY_ID,
        'idp_sso_url' => SamlFixture::SSO_URL,
        'idp_certificate' => "-----BEGIN CERTIFICATE-----\nnot really\n-----END CERTIFICATE-----",
        'domains' => ['acme.test'],
    ])->assertStatus(422)->assertJsonPath('error.code', 'sso.invalid_certificate');
});

it('refuses a domain another organization signs in already', function (): void {
    actingWithinTenant(SSO_GLOBEX, fn () => DB::table('sso_domains')->insert([
        'id' => '01900000-0000-7000-8000-00000000d001',
        'organization_id' => SSO_GLOBEX,
        'connection_id' => probeSsoConnectionIn(SSO_GLOBEX),
        'domain' => 'shared.test',
    ]));

    connectAcme($this->rina, ['acme.test', 'shared.test'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'sso.domain_taken')
        ->assertJsonPath('error.details.domain', 'shared.test');

    // Nothing half-saved: the whole connection rolled back with the domain.
    expect(DB::table('sso_connections')->where('organization_id', SSO_ACME)->exists())->toBeFalse();
});

it('serves this product\'s metadata for an IdP to import', function (): void {
    $response = $this->get('/api/v1/auth/sso/metadata')->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('samlmetadata+xml')
        ->and($response->getContent())->toContain(app(SamlToolkit::class)->serviceProvider()['acs_url']);
});

// ── Signing in ──────────────────────────────────────────────────────────────

it('signs somebody in through the IdP, and the session knows how', function (): void {
    connectAcme($this->rina)->assertOk();

    $token = signInThroughIdp('ahmad@acme.test');

    $this->withToken($token)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', 'ahmad@acme.test')
        ->assertJsonPath('data.organization.id', SSO_ACME);

    $session = SessionModel::findToken($token);

    expect($session?->authenticated_by)->toBe('sso')
        ->and(DB::table('sso_connections')->where('organization_id', SSO_ACME)->value('last_succeeded_at'))->not->toBeNull()
        ->and(DB::table('audit_logs')
            ->where('event', 'auth.login')
            ->where('organization_id', SSO_ACME)
            ->whereRaw("metadata->>'authenticated_by' = 'sso'")
            ->exists())->toBeTrue();
});

it('says plainly when an address has no single sign-on', function (): void {
    $this->postJson('/api/v1/auth/sso/start', ['email' => 'someone@gmail.example', 'binding' => SSO_BINDING])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'auth.sso_not_available');
});

it('refuses an answer signed by anybody but the configured IdP', function (): void {
    connectAcme($this->rina)->assertOk();

    answerAsIdp('ahmad@acme.test', ['signed_by' => 'impostor'])['consume']
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'auth.sso_failed');

    // The reason is for the administrator, in their own audit log — never in
    // the answer to the browser.
    expect(ssoAudit('auth.sso_failed', SSO_ACME))->toBe(1);
});

it('refuses an answer whose address was changed after it was signed', function (): void {
    connectAcme($this->rina)->assertOk();

    answerAsIdp('ahmad@acme.test', ['tamper_email' => 'rina@acme.test'])['consume']
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'auth.sso_failed');
});

it('refuses an answer to a different request', function (): void {
    connectAcme($this->rina)->assertOk();

    answerAsIdp('ahmad@acme.test', ['request_id' => 'ONELOGIN_somebody_elses_request'])['consume']
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'auth.sso_failed');
});

it('refuses an answer for an address outside the domains the organization claimed', function (): void {
    connectAcme($this->rina)->assertOk();

    // The IdP is Acme's, correctly signed — vouching for a Globex address.
    // Without the domain check, Acme's IdP could sign in anybody who also
    // happened to belong to Acme, by any address.
    $sp = app(SamlToolkit::class)->serviceProvider();
    $request = readAuthnRequest(startSignIn('ahmad@acme.test'));

    $this->postJson('/api/v1/auth/sso/acs', [
        'saml_response' => SamlFixture::response([
            'request_id' => $request['request_id'],
            'acs_url' => $sp['acs_url'],
            'audience' => $sp['entity_id'],
            'email' => 'gil@globex.test',
        ]),
        'relay_state' => $request['relay_state'],
    ])->assertUnauthorized()->assertJsonPath('error.code', 'auth.sso_failed');
});

it('uses each round trip once', function (): void {
    connectAcme($this->rina)->assertOk();

    $first = answerAsIdp('ahmad@acme.test');
    $first['consume']->assertOk();

    // The same relay state again — a replayed post.
    $this->postJson('/api/v1/auth/sso/acs', [
        'saml_response' => 'irrelevant',
        'relay_state' => $first['relay_state'],
    ])->assertUnauthorized()->assertJsonPath('error.code', 'auth.sso_expired');
});

it('finishes only in the browser that started it', function (): void {
    connectAcme($this->rina)->assertOk();

    $completion = (string) answerAsIdp('ahmad@acme.test')['consume']->assertOk()->json('data.completion');

    $this->postJson('/api/v1/auth/sso/complete', [
        'completion' => $completion,
        'binding' => 'a-different-browser-entirely-00000000000',
    ])->assertUnauthorized()->assertJsonPath('error.code', 'auth.sso_failed');

    // And the code is spent: the right browser cannot use it afterwards either.
    $this->postJson('/api/v1/auth/sso/complete', ['completion' => $completion, 'binding' => SSO_BINDING])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'auth.sso_expired');
});

it('does not create an account for an address nobody invited', function (): void {
    connectAcme($this->rina)->assertOk();

    answerAsIdp('stranger@acme.test')['consume']
        ->assertForbidden()
        ->assertJsonPath('error.code', 'auth.sso_no_account');

    expect(DB::table('users')->where('email', 'stranger@acme.test')->exists())->toBeFalse();
});

// ── Requiring it ────────────────────────────────────────────────────────────

it('names the people requiring it would lock out, before anybody presses the button', function (): void {
    // Found by hand: enforcement with example.com as the only domain locked
    // Ahmad (@acme.test) out of both doors, and the panel had only counted
    // sessions.
    connectAcme($this->rina, ['example.com'])->assertOk();

    $emails = collect($this->withToken($this->rina)->getJson('/api/v1/sso-connection')
        ->assertOk()
        ->json('data.members_outside_domains'))
        ->pluck('email');

    // Rina keeps the break-glass, so a password still gets her in.
    expect($emails)->toContain('ahmad@acme.test')
        ->and($emails)->not->toContain('rina@acme.test');

    connectAcme($this->rina, ['acme.test'])->assertOk();

    expect($this->withToken($this->rina)->getJson('/api/v1/sso-connection')
        ->json('data.members_outside_domains'))->toBe([]);
});

it('will not require a connection nobody has signed in through', function (): void {
    connectAcme($this->rina)->assertOk();

    $this->withToken($this->rina)->patchJson('/api/v1/sso-connection/enforcement', ['enforced' => true])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'sso.untested');
});

it('requires it: password sessions end, passwords stop working, administrators keep the break-glass', function (): void {
    connectAcme($this->rina)->assertOk();
    $sso = signInThroughIdp('ahmad@acme.test');
    $password = $this->loginAs('ahmad@acme.test');
    $rina = $this->loginAs('rina@acme.test');

    $this->withToken($rina)->patchJson('/api/v1/sso-connection/enforcement', ['enforced' => true])
        ->assertOk()
        ->assertJsonPath('data.enforced', true)
        ->assertJsonPath('data.sessions_ended', 2); // Ahmad's password session, and the first Rina one

    // The password session is over; the one the IdP vouched for is not; the
    // administrator who pressed the button keeps hers.
    $this->withToken($password)->getJson('/api/v1/auth/me')->assertUnauthorized();
    $this->withToken($sso)->getJson('/api/v1/auth/me')->assertOk();
    $this->withToken($rina)->getJson('/api/v1/auth/me')->assertOk();

    $this->postJson('/api/v1/auth/login', ['email' => 'ahmad@acme.test', 'password' => 'password'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'auth.sso_required');

    // Rina administers the connection: a broken IdP must not lock out the
    // people who can fix it — and every use is written down.
    $this->postJson('/api/v1/auth/login', ['email' => 'rina@acme.test', 'password' => 'password'])->assertOk();

    expect(ssoAudit('auth.sso_bypassed', SSO_ACME))->toBe(1);
});

it('lands a password in the organization that still accepts one', function (): void {
    // Tono belongs to Acme and, from here, to Globex. Acme requiring SSO
    // should move his password sign-in to Globex, not refuse it.
    actingWithinTenant(SSO_GLOBEX, fn () => DB::table('memberships')->insert(minimalRowFor('memberships', SSO_GLOBEX)));

    DB::table('sso_connections')->insert([
        'id' => '01900000-0000-7000-8000-00000000c001',
        'organization_id' => SSO_ACME,
        'idp_entity_id' => SamlFixture::ENTITY_ID,
        'idp_sso_url' => SamlFixture::SSO_URL,
        'idp_certificate' => SamlFixture::pair()['certificate'],
        'enforced' => true,
        'last_succeeded_at' => now(),
    ]);

    $this->postJson('/api/v1/auth/login', ['email' => 'tono@acme.test', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('data.organization.id', SSO_GLOBEX);
});

it('refuses to remove a connection while it is required', function (): void {
    connectAcme($this->rina)->assertOk();
    signInThroughIdp('ahmad@acme.test');
    $rina = $this->loginAs('rina@acme.test');

    $this->withToken($rina)->patchJson('/api/v1/sso-connection/enforcement', ['enforced' => true])->assertOk();

    $this->withToken($rina)->deleteJson('/api/v1/sso-connection')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'sso.enforced');

    $this->withToken($rina)->patchJson('/api/v1/sso-connection/enforcement', ['enforced' => false])->assertOk();
    $this->withToken($rina)->deleteJson('/api/v1/sso-connection')->assertNoContent();
});

// ── What an IdP's session may not do ────────────────────────────────────────

it('keeps an IdP\'s session in the organization that trusts that IdP', function (): void {
    connectAcme($this->rina)->assertOk();

    // Rina belongs to Globex too — which never agreed to trust Acme's IdP.
    $sso = signInThroughIdp('rina@acme.test');

    expect(collect($this->withToken($sso)->getJson('/api/v1/auth/organizations')->assertOk()->json('data'))
        ->pluck('id')->all())->toBe([SSO_ACME]);

    $this->withToken($sso)->postJson('/api/v1/auth/organization', ['organization_id' => SSO_GLOBEX])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'auth.sso_session_bound');
});

it('will not switch a password session into an organization that requires SSO', function (): void {
    // Written as Globex: the test is bound to Rina's Acme session, and with
    // Row-Level Security on, Acme may not write a Globex row — rightly.
    actingWithinTenant(SSO_GLOBEX, fn () => DB::table('sso_connections')->insert([
        'id' => '01900000-0000-7000-8000-00000000c002',
        'organization_id' => SSO_GLOBEX,
        'idp_entity_id' => 'https://idp.globex.test',
        'idp_sso_url' => 'https://idp.globex.test/sso',
        'idp_certificate' => SamlFixture::pair()['certificate'],
        'enforced' => true,
        'last_succeeded_at' => now(),
    ]));

    // Rina is an employee in Globex: no break-glass there.
    $this->withToken($this->rina)->postJson('/api/v1/auth/organization', ['organization_id' => SSO_GLOBEX])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'auth.sso_required');
});

it('leaves the second factor to the IdP, and the account\'s own factor to the account', function (): void {
    connectAcme($this->rina)->assertOk();
    DB::table('organizations')->where('id', SSO_ACME)->update(['require_mfa' => true]);

    $sso = signInThroughIdp('ahmad@acme.test');

    // Not confined to enrolment: the IdP is where this organization's factor
    // lives for a person who signed in through it.
    $this->withToken($sso)->getJson('/api/v1/work-items?limit=1')->assertOk();

    // And not able to put an authenticator on the account either.
    $this->withToken($sso)->postJson('/api/v1/auth/mfa')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'auth.sso_session_bound');
});
