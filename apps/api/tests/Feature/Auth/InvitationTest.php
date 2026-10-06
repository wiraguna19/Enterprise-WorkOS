<?php

declare(strict_types=1);

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Inviting somebody in, and letting them accept (ADR 0017).
 *
 * `person.invite` was in the permission catalogue from Phase 1, granted to
 * managers and org admins, with no endpoint behind it for seven phases — and
 * the `invitations` table was written in the same migration, with a token
 * digest, an expiry, a revocation column and a partial unique index over
 * pending rows. Everything was here except the feature: this codebase's oldest
 * defect class in its longest-running instance.
 */
beforeEach(function (): void {
    $this->admin = $this->loginAs('rina@acme.test');      // person.invite
    $this->employee = $this->loginAs('sarah@acme.test');  // not

    // Stamped with the clock: an accepted invitation creates a user, and this
    // suite has no delete. A fixed address passes once and collides forever
    // after — the same rule the E2E flows learned.
    $this->address = 'newcomer'.now()->getTimestampMs().'@acme.test';
});

it('issues an invitation and hands back the link exactly once', function (): void {
    $invitation = $this->withToken($this->admin)
        ->postJson('/api/v1/people/invite', ['email' => $this->address, 'role' => 'employee'])
        ->assertStatus(201)
        ->json('data');

    expect($invitation['token'])->not->toBeEmpty();

    // Only the digest is stored. An invitation token creates an account, which
    // makes it a credential, and credentials are digests in this codebase.
    $stored = DB::table('invitations')->where('id', $invitation['id'])->first();

    expect($stored->token_hash)->toBe(hash('sha256', $invitation['token']))
        ->and($stored->token_hash)->not->toBe($invitation['token']);

    // And it is never handed out again: the pending list knows the address and
    // the expiry, and nothing that would let somebody accept one.
    $row = collect($this->withToken($this->admin)->getJson('/api/v1/invitations')->assertOk()->json('data'))
        ->firstWhere('email', $this->address);

    expect($row)->not->toBeNull()
        ->and($row)->not->toHaveKey('token')
        ->and($row)->not->toHaveKey('token_hash');
});

it('lets a stranger accept, and puts them in the organization with their role', function (): void {
    $token = $this->withToken($this->admin)
        ->postJson('/api/v1/people/invite', ['email' => $this->address, 'role' => 'employee'])
        ->json('data.token');

    // Public: the person holding this link has no account yet, so there is
    // nothing to authenticate with.
    $this->postJson('/api/v1/invitations/preview', ['token' => $token])
        ->assertOk()
        ->assertJsonPath('data.email', $this->address)
        ->assertJsonPath('data.organization', 'Acme Corporation');

    $this->postJson('/api/v1/invitations/accept', [
        'token' => $token,
        'name' => 'Newcomer Person',
        'password' => 'a-long-enough-password',
    ])->assertOk();

    // Signing in as themselves is the only proof that matters.
    $this->postJson('/api/v1/auth/login', [
        'email' => $this->address,
        'password' => 'a-long-enough-password',
    ])->assertOk();

    $membership = DB::table('memberships as m')
        ->join('users as u', 'u.id', '=', 'm.user_id')
        ->whereRaw('lower(u.email) = ?', [$this->address])
        ->first(['m.id', 'm.status']);

    expect($membership->status)->toBe('active');

    $role = DB::table('membership_roles as mr')
        ->join('roles as r', 'r.id', '=', 'mr.role_id')
        ->where('mr.membership_id', $membership->id)
        ->value('r.key');

    expect($role)->toBe('employee');
});

it('gives a new account the language its invitation was accepted in', function (): void {
    // The page a newcomer reads is in the language their browser asked for;
    // without this, the account it creates opened in English regardless.
    $token = $this->withToken($this->admin)
        ->postJson('/api/v1/people/invite', ['email' => $this->address])
        ->json('data.token');

    $this->postJson('/api/v1/invitations/accept', [
        'token' => $token,
        'name' => 'Newcomer Person',
        'password' => 'a-long-enough-password',
        'locale' => 'id',
    ])->assertOk();

    $locale = DB::table('users')->whereRaw('lower(email) = ?', [$this->address])->value('locale');

    expect($locale)->toBe('id');
});

it('refuses a language the interface does not have', function (): void {
    $token = $this->withToken($this->admin)
        ->postJson('/api/v1/people/invite', ['email' => $this->address])
        ->json('data.token');

    $this->postJson('/api/v1/invitations/accept', [
        'token' => $token,
        'name' => 'Newcomer Person',
        'password' => 'a-long-enough-password',
        'locale' => 'fr',
    ])->assertStatus(422);
});

it('does not touch the password of somebody who already has an account', function (): void {
    // The branch that is an account takeover if it is wrong. Gil exists, in
    // Globex; inviting him into Acme must add a membership and leave his
    // credentials alone — this is an invitation to join an organization, not a
    // password reset, and anyone who can get an invitation sent to a
    // colleague's address must not be able to choose that colleague's password.
    $token = $this->withToken($this->admin)
        ->postJson('/api/v1/people/invite', ['email' => 'gil@globex.test'])
        ->assertStatus(201)
        ->json('data.token');

    $before = DB::table('users')->where('email', 'gil@globex.test')->value('password_hash');

    // Somebody else holding the link cannot join on his behalf: an existing
    // account joins only with its own password. The link goes to whoever SENT
    // the invitation, so without this an admin of any organization could pull
    // a stranger's account into theirs.
    $this->postJson('/api/v1/invitations/accept', [
        'token' => $token,
        'name' => 'Gil Barnes',
        'password' => 'a-brand-new-password-chosen-by-somebody-else',
    ])->assertStatus(409)
        ->assertJsonPath('error.details.refusal', 'existing_account_password');

    // Gil himself, with the password he already has (shorter than twelve
    // characters: the rule is for choosing one, not for proving one).
    $this->postJson('/api/v1/invitations/accept', [
        'token' => $token,
        'name' => 'Gil Barnes',
        'password' => 'password',
    ])->assertOk();

    $after = DB::table('users')->where('email', 'gil@globex.test')->value('password_hash');

    expect($after)->toBe($before);

    // And he is now in both organizations, with one user behind them.
    // Across both organizations on purpose, so it says so: under Row-Level
    // Security the bound tenant would see only its own row.
    $memberships = app(TenantContext::class)->runAsPlatform(
        'test: count a person\'s memberships everywhere',
        fn (): int => DB::table('memberships as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('u.email', 'gil@globex.test')
            ->count(),
    );

    expect($memberships)->toBe(2);
});

it('refuses inviting somebody who is already here', function (): void {
    $this->withToken($this->admin)
        ->postJson('/api/v1/people/invite', ['email' => 'tono@acme.test'])
        ->assertStatus(409)
        ->assertJsonPath('error.details.refusal', 'already_a_member');
});

it('refuses a second invitation to the same address, and takes the first one back', function (): void {
    $first = $this->withToken($this->admin)
        ->postJson('/api/v1/people/invite', ['email' => $this->address])
        ->assertStatus(201)
        ->json('data');

    // The partial unique index would refuse this too, with a message naming an
    // index. Refusing here names the situation, and the answer is a control on
    // the same screen.
    $this->withToken($this->admin)
        ->postJson('/api/v1/people/invite', ['email' => $this->address])
        ->assertStatus(409)
        ->assertJsonPath('error.details.refusal', 'already_invited');

    $this->withToken($this->admin)
        ->deleteJson("/api/v1/invitations/{$first['id']}")
        ->assertNoContent();

    $this->postJson('/api/v1/invitations/preview', ['token' => $first['token']])
        ->assertNotFound();

    $this->withToken($this->admin)
        ->postJson('/api/v1/people/invite', ['email' => $this->address])
        ->assertStatus(201);
});

it('refuses an expired link the same way it refuses a wrong one', function (): void {
    $token = $this->withToken($this->admin)
        ->postJson('/api/v1/people/invite', ['email' => $this->address])
        ->json('data.token');

    DB::table('invitations')
        ->where('token_hash', hash('sha256', $token))
        ->update(['expires_at' => now()->subMinute()]);

    // Wrong, expired, revoked, already used — one answer for all four. Any
    // difference between them is an oracle for guessing tokens.
    $this->postJson('/api/v1/invitations/preview', ['token' => $token])->assertNotFound();
    $this->postJson('/api/v1/invitations/preview', ['token' => 'not-a-real-token'])->assertNotFound();
});

it('cannot be accepted twice', function (): void {
    $token = $this->withToken($this->admin)
        ->postJson('/api/v1/people/invite', ['email' => $this->address])
        ->json('data.token');

    $this->postJson('/api/v1/invitations/accept', [
        'token' => $token,
        'name' => 'Newcomer Person',
        'password' => 'a-long-enough-password',
    ])->assertOk();

    // Otherwise one link is a membership factory.
    $this->postJson('/api/v1/invitations/accept', [
        'token' => $token,
        'name' => 'Newcomer Person',
        'password' => 'a-long-enough-password',
    ])->assertStatus(409);

    $memberships = DB::table('memberships as m')
        ->join('users as u', 'u.id', '=', 'm.user_id')
        ->whereRaw('lower(u.email) = ?', [$this->address])
        ->count();

    expect($memberships)->toBe(1);
});

it('refuses a password nobody should be allowed to choose', function (): void {
    $token = $this->withToken($this->admin)
        ->postJson('/api/v1/people/invite', ['email' => $this->address])
        ->json('data.token');

    // The one place in this product where a password is CHOSEN is the only
    // place that can refuse a bad one.
    $this->postJson('/api/v1/invitations/accept', [
        'token' => $token,
        'name' => 'Newcomer Person',
        'password' => 'short',
    ])->assertStatus(422);
});

it('keeps inviting away from somebody who may not invite', function (): void {
    $this->withToken($this->employee)
        ->postJson('/api/v1/people/invite', ['email' => $this->address])
        ->assertForbidden();

    $this->withToken($this->employee)
        ->getJson('/api/v1/invitations')
        ->assertForbidden();
});

it('records who opened the way in', function (): void {
    // An invitation is a way INTO the organization, so it is an audit event
    // rather than an activity one: "who let them in" is a security question.
    $this->withToken($this->admin)
        ->postJson('/api/v1/people/invite', ['email' => $this->address])
        ->assertStatus(201);

    $logged = DB::table('audit_logs')
        ->where('event', 'invitation.issued')
        ->where('occurred_at', '>', now()->subMinute())
        ->exists();

    expect($logged)->toBeTrue();
});

it('refuses to invite someone with more authority than the inviter holds', function (): void {
    $manager = $this->loginAs('ahmad@acme.test');   // person.invite, not org admin

    // The escalation this closes: a manager invited a second address of their
    // own as org_admin and accepted the link the API handed back.
    $this->withToken($manager)
        ->postJson('/api/v1/people/invite', ['email' => $this->address, 'role' => 'org_admin'])
        ->assertStatus(409)
        ->assertJsonPath('error.details.refusal', 'beyond_your_own_authority');

    expect(DB::table('invitations')->where('email', $this->address)->exists())->toBeFalse();

    // Within their own authority, the same manager still invites.
    foreach (['employee', 'manager'] as $i => $role) {
        $this->withToken($manager)
            ->postJson('/api/v1/people/invite', ['email' => "m{$i}-{$this->address}", 'role' => $role])
            ->assertCreated();
    }

    // And an administrator may invite an administrator.
    $this->withToken($this->admin)
        ->postJson('/api/v1/people/invite', ['email' => $this->address, 'role' => 'org_admin'])
        ->assertCreated();
});

it('still asks a new account for a password of twelve characters', function (): void {
    $token = $this->withToken($this->admin)
        ->postJson('/api/v1/people/invite', ['email' => $this->address])
        ->assertStatus(201)
        ->json('data.token');

    $this->postJson('/api/v1/invitations/accept', [
        'token' => $token,
        'name' => 'Newcomer Person',
        'password' => 'too-short',
    ])->assertStatus(422)
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonStructure(['error' => ['details' => ['password']]]);
});
