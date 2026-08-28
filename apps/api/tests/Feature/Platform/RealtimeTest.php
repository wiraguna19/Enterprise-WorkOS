<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Eloquent\UserModel;
use App\Modules\Platform\Domain\Contract\RealtimePublisher;
use App\Modules\Platform\Infrastructure\Realtime\Channel;
use App\Modules\Platform\Infrastructure\Realtime\NullPublisher;
use App\Modules\Realtime\Application\ChannelGuard;

/**
 * Real-time (docs/07 §8).
 *
 * Two claims are defended here. The first is the one the architecture rests on:
 * everything works without the socket, so the default binding is the null
 * publisher and a write succeeds with no broadcaster in the path. The second is
 * that a subscription is as hard to obtain as the HTTP request for the same
 * data.
 *
 * The guard is exercised directly rather than through /broadcasting/auth,
 * because under the null broadcaster that endpoint authorizes nothing and
 * answers 200 to everything — a test suite built on it would look thorough and
 * prove nothing.
 */
const ACME = '01900000-0000-7000-8000-0000000000ac';
const GLOBEX = '01900000-0000-7000-8000-0000000000b0';
const RINA_USER = '01900000-0000-7000-8000-000000000001';
const AHMAD_USER = '01900000-0000-7000-8000-000000000002';
const TONO_USER = '01900000-0000-7000-8000-000000000008';
const FORMER_USER = '01900000-0000-7000-8000-000000000009';
const GIL_USER = '01900000-0000-7000-8000-000000000101';
const ENG_142 = '01900014-0000-7000-8000-000000000001';

function guard(): ChannelGuard
{
    return app(ChannelGuard::class);
}

function userNamed(string $id): UserModel
{
    /** @var UserModel $user */
    $user = UserModel::query()->findOrFail($id);

    return $user;
}

it('is off unless a broadcaster is configured', function (): void {
    // Not a test-environment convenience: `null` is the default in
    // config/broadcasting.php, so this is what a fresh deployment does.
    expect(app(RealtimePublisher::class))->toBeInstanceOf(NullPublisher::class);
});

it('names channels with the organization first', function (): void {
    // The tenant is in the channel name so that every authorization check
    // matches on it rather than looking it up — the check that gets forgotten
    // is the one that is not in front of you.
    expect(Channel::user(ACME, RINA_USER))->toStartWith('org.'.ACME.'.')
        ->and(Channel::workItem(ACME, 'x'))->toStartWith('org.'.ACME.'.')
        ->and(Channel::board(ACME, 'x'))->toStartWith('org.'.ACME.'.')
        // Pusher-protocol presence channels must carry the prefix or they are
        // treated as ordinary private ones, silently.
        ->and(Channel::workItemPresence(ACME, 'x'))->toStartWith('presence-');
});

it('writes without a broadcaster and does not fail', function (): void {
    // The null publisher is bound, so this comment is written down a path with
    // no socket anywhere in it. That is the supported deployment.
    $token = $this->loginAs('ahmad@acme.test');

    $this->withToken($token)
        ->postJson('/api/v1/work-items/ENG-142/comments', ['body' => 'No socket here.'])
        ->assertCreated();
});

it('lets a person hear their own notification channel', function (): void {
    expect(guard()->mayHearUser(userNamed(RINA_USER), ACME, RINA_USER))->toBeTrue();
});

it('refuses someone else\'s notification channel', function (): void {
    // Rina is an org admin. Being able to read everything in her organization
    // is not being able to listen as another person.
    expect(guard()->mayHearUser(userNamed(RINA_USER), ACME, AHMAD_USER))->toBeFalse();
});

it('refuses an organization the listener does not belong to', function (): void {
    // The channel name is attacker-supplied, so naming Globex in it must not be
    // enough to hear Globex.
    expect(guard()->mayHearUser(userNamed(GIL_USER), ACME, GIL_USER))->toBeFalse()
        ->and(guard()->mayHearWorkItem(userNamed(GIL_USER), ACME, ENG_142))->toBeFalse();
});

it('refuses a revoked member their old organization', function (): void {
    // Eko's membership was revoked a month ago. Identity survives revocation;
    // the subscription must not.
    expect(guard()->mayHearUser(userNamed(FORMER_USER), ACME, FORMER_USER))->toBeFalse();
});

it('refuses a work item the listener cannot see', function (): void {
    // Tono is a viewer on no project, so the REST endpoint answers 404 for this
    // item. The socket must not be the way around that.
    expect(guard()->mayHearWorkItem(userNamed(TONO_USER), ACME, ENG_142))->toBeFalse()
        ->and(guard()->mayHearWorkItem(userNamed(AHMAD_USER), ACME, ENG_142))->toBeTrue();
});

it('gives presence a name and nothing else', function (): void {
    $identity = guard()->presenceIdentity(userNamed(AHMAD_USER), ACME, ENG_142);

    // Handed to every other subscriber on the channel, so what is absent
    // matters more than what is present.
    expect($identity)->toBeArray()
        ->and(array_keys((array) $identity))->toBe(['id', 'name'])
        ->and(guard()->presenceIdentity(userNamed(TONO_USER), ACME, ENG_142))->toBeFalse();
});
