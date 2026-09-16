<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * "Delete my data" (ADR 0022).
 *
 * Anonymisation, not deletion: the person goes out of the rows and the rows
 * stay. Most of these tests are about the places a name SURVIVES an erasure
 * that only touched `users` — the snapshot columns, every one of which was
 * added on purpose so that history would outlive somebody leaving.
 *
 * The person erased here is a newcomer invented per test rather than a seeded
 * one: erasure is irreversible and destroys fixtures that other tests in the
 * same file would need.
 */
beforeEach(function (): void {
    $this->admin = $this->loginAs('rina@acme.test');      // person.deactivate
    $this->manager = $this->loginAs('ahmad@acme.test');   // person.deactivate too
    $this->sarah = $this->loginAs('sarah@acme.test');     // neither

    $this->newcomer = function (): array {
        $address = 'erasure'.now()->getTimestampMs().random_int(100, 999).'@acme.test';

        $token = $this->withToken($this->admin)
            ->postJson('/api/v1/people/invite', ['email' => $address, 'role' => 'employee'])
            ->assertStatus(201)
            ->json('data.token');

        $this->postJson("/api/v1/invitations/{$token}/accept", [
            'name' => 'Pat Newcomer',
            'password' => 'a-long-enough-password',
        ])->assertOk();

        $row = DB::table('memberships as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('u.email', $address)
            ->first(['m.id', 'u.id as user_id']);

        return [
            'address' => $address,
            'membership' => (string) $row?->id,
            'user' => (string) $row?->user_id,
        ];
    };
});

it('takes the person out of the rows and leaves the rows', function (): void {
    ['address' => $address, 'membership' => $membership, 'user' => $user] = ($this->newcomer)();

    $this->withToken($this->admin)
        ->postJson("/api/v1/people/{$membership}/erase")
        ->assertOk()
        ->assertJsonPath('data.erased_at', fn (?string $at): bool => $at !== null);

    $account = DB::table('users')->where('id', $user)->first(['name', 'email', 'password_hash', 'erased_at']);

    expect($account?->name)->toBe('Deleted person')
        ->and($account?->email)->not->toBe($address)
        ->and($account?->email)->toEndWith('@deleted.invalid')
        ->and($account?->password_hash)->toBeNull()
        ->and($account?->erased_at)->not->toBeNull();

    // The membership row survives. Everything that points at it — work,
    // comments, transitions — still resolves, which is the entire reason this
    // is an anonymisation and not a delete.
    $left = DB::table('memberships')->where('id', $membership)->first(['status', 'erased_at', 'erased_by']);

    expect($left)->not->toBeNull()
        ->and($left?->status)->toBe('revoked')
        ->and($left?->erased_at)->not->toBeNull()
        ->and($left?->erased_by)->not->toBeNull();
});

it('reaches the snapshots it can, and leaves the evidence alone', function (): void {
    ['address' => $address, 'membership' => $membership] = ($this->newcomer)();

    $this->postJson('/api/v1/auth/login', [
        'email' => $address,
        'password' => 'a-long-enough-password',
    ])->assertOk();

    $this->withToken($this->admin)
        ->postJson("/api/v1/people/{$membership}/erase")
        ->assertOk();

    // The invitation, which is the one copy of the address outside `users`.
    expect(DB::table('invitations')->where('email', $address)->exists())->toBeFalse()
        ->and(DB::table('users')->where('email', $address)->exists())->toBeFalse();

    // And the two that are NOT reached, on purpose. `activity_logs` and
    // `audit_logs` are append-only at the database level — a trigger from Phase
    // 1, because evidence the application can rewrite is not evidence. The
    // first version of the erasure tried to redact them and Postgres refused
    // with insufficient_privilege, which is the schema defending a guarantee
    // against the application. Their copies expire by retention instead
    // (ADR 0021), and the interface says so.
    expect(DB::table('audit_logs')->where('actor_email_snapshot', $address)->exists())->toBeTrue();
});

it('cannot rewrite the audit trail even to honour an erasure', function (): void {
    // Stated as a test rather than a comment, because it is the constraint the
    // whole design bends around: if this ever starts passing silently, an
    // erasure has been given the power to edit the evidence.
    expect(fn () => DB::table('audit_logs')->limit(1)->update(['actor_email_snapshot' => 'x']))
        ->toThrow(QueryException::class);
});

it('records that it happened, without quoting what it erased', function (): void {
    ['address' => $address, 'membership' => $membership] = ($this->newcomer)();

    $this->withToken($this->admin)
        ->postJson("/api/v1/people/{$membership}/erase")
        ->assertOk();

    $entry = DB::table('audit_logs')
        ->where('event', 'person.erased')
        ->orderByDesc('occurred_at')
        ->first(['actor_email_snapshot', 'target_id', 'metadata']);

    // After an erasure there is nothing left in the row to say it was done: a
    // user called "Deleted person" with no address is indistinguishable from a
    // broken import. This event is the record — and it names the administrator,
    // never the address, or it would put back what it just removed.
    expect($entry)->not->toBeNull()
        ->and($entry?->actor_email_snapshot)->toBe('rina@acme.test')
        ->and($entry?->target_id)->toBe($membership)
        ->and((string) $entry?->metadata)->not->toContain($address);
});

it('takes their authority and their sessions with them', function (): void {
    ['membership' => $membership, 'user' => $user] = ($this->newcomer)();

    $this->withToken($this->admin)
        ->postJson("/api/v1/people/{$membership}/roles", [
            'role' => 'manager',
            'scope_type' => 'team',
            'scope_id' => '01900000-0000-7000-8000-000000000801',
        ])->assertStatus(201);

    $this->withToken($this->admin)
        ->postJson("/api/v1/people/{$membership}/erase")
        ->assertOk();

    expect(DB::table('membership_roles')->where('membership_id', $membership)->exists())->toBeFalse()
        ->and(DB::table('scoped_role_assignments')->where('membership_id', $membership)->exists())->toBeFalse()
        // Deleted rather than revoked, unlike every other session in this
        // product: a revoked row is kept so somebody can be told why they were
        // logged out, there is nobody left to tell, and the row holds an IP
        // address.
        ->and(DB::table('sessions')->where('user_id', $user)->exists())->toBeFalse();
});

it('refuses a second erasure', function (): void {
    ['membership' => $membership] = ($this->newcomer)();

    $this->withToken($this->admin)->postJson("/api/v1/people/{$membership}/erase")->assertOk();

    // Every statement in the erasure is idempotent, so this is not about the
    // database: a second run would write a second audit event claiming a second
    // erasure, and a button offered again is a button that lies about what is
    // left.
    $this->withToken($this->admin)
        ->postJson("/api/v1/people/{$membership}/erase")
        ->assertStatus(409)
        ->assertJsonPath('error.details.refusal', 'already_erased');
});

it('refuses an account that belongs to another organization too', function (): void {
    ['membership' => $membership, 'user' => $user] = ($this->newcomer)();

    // The same human, a member of Globex as well. Anonymising `users` would
    // reach into a tenant whose administrator never asked and may be obliged to
    // keep the record; revoking access here and leaving the name would report
    // "erased" over somebody still named on every comment.
    DB::table('memberships')->insert([
        'id' => (string) new UuidV7,
        'organization_id' => '01900000-0000-7000-8000-0000000000b0',
        'user_id' => $user,
        'status' => 'active',
        'joined_at' => now(),
    ]);

    $this->withToken($this->admin)
        ->postJson("/api/v1/people/{$membership}/erase")
        ->assertStatus(409)
        ->assertJsonPath('error.details.refusal', 'shared_account');
});

it('refuses to erase yourself', function (): void {
    $rina = DB::table('memberships as m')
        ->join('users as u', 'u.id', '=', 'm.user_id')
        ->where('u.email', 'rina@acme.test')
        ->value('m.id');

    // Refused for the reason `deactivate` refuses self, and harder: the account
    // running the erasure would be the account erased.
    $this->withToken($this->admin)
        ->postJson("/api/v1/people/{$rina}/erase")
        ->assertForbidden();
});

it('refuses somebody who may not revoke access', function (): void {
    ['membership' => $membership] = ($this->newcomer)();

    $this->withToken($this->sarah)
        ->postJson("/api/v1/people/{$membership}/erase")
        ->assertForbidden();

    expect(DB::table('memberships')->where('id', $membership)->value('erased_at'))->toBeNull();
});

it('stops answering the door', function (): void {
    ['address' => $address, 'membership' => $membership] = ($this->newcomer)();

    $this->withToken($this->admin)->postJson("/api/v1/people/{$membership}/erase")->assertOk();

    // The address belongs to nobody now, and the password hash is gone, so
    // this is the same 401 an unknown address gets — which is the answer login
    // gives on purpose, since any difference between "wrong password" and "no
    // such person" is an enumeration oracle.
    $this->postJson('/api/v1/auth/login', [
        'email' => $address,
        'password' => 'a-long-enough-password',
    ])->assertStatus(401);
});

it('will not hand authority to somebody who no longer exists', function (): void {
    ['membership' => $membership] = ($this->newcomer)();

    $this->withToken($this->admin)->postJson("/api/v1/people/{$membership}/erase")->assertOk();

    // Found by opening the screen: the product announced the person as erased
    // and went on offering a form to make them Organization Admin. The form is
    // gone, and the refusal lives here rather than only there — an erased
    // membership holds no roles by definition, and a grant against one is
    // authority handed to an identity that does not exist.
    $this->withToken($this->admin)
        ->postJson("/api/v1/people/{$membership}/roles", [
            'role' => 'manager',
            'scope_type' => 'team',
            'scope_id' => '01900000-0000-7000-8000-000000000801',
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.details.refusal', 'person_erased');

    $this->withToken($this->admin)
        ->postJson("/api/v1/people/{$membership}/denials", [
            'permission' => 'person.invite',
            'reason' => 'Pointless.',
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.details.refusal', 'person_erased');
});

it('shows no address for an erased person', function (): void {
    ['membership' => $membership] = ($this->newcomer)();

    $this->withToken($this->admin)->postJson("/api/v1/people/{$membership}/erase")->assertOk();

    // `users.email` holds a random placeholder at a reserved domain. Sending it
    // made the profile render a mailto: link to somebody who has been erased.
    $this->withToken($this->admin)
        ->getJson("/api/v1/people/{$membership}")
        ->assertOk()
        ->assertJsonPath('data.email', null)
        ->assertJsonPath('data.name', 'Deleted person')
        ->assertJsonPath('data.erased_at', fn (?string $at): bool => $at !== null);
});
