<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Finder\Finder;

/**
 * A permission nothing asks about is a promise nobody keeps.
 *
 * `EveryEndpointIsReachableTest` asks "what calls this route?" and has found
 * twenty-six answers of "nothing". This asks the same question one layer
 * earlier — **what consults this permission?** — and it is the layer where the
 * defect is most convincing, because a permission is VISIBLE: it is granted to
 * roles in the seed, it is listed in the role builder with a description, and
 * an administrator can tick it. `person.invite` sat there for seven phases,
 * ticked and meaningless, and the only thing that ever noticed was somebody
 * trying to invite a person.
 *
 * A permission MEANS something if any of these is true:
 *
 *   - a route gates on it (`permission:` or `permission.any:`), or
 *   - some PHP in `app/` names it — a policy, a service, a resource's
 *     `permissions` block.
 *
 * The second is what keeps this honest about the four routes ADR 0016
 * deliberately left ungated: their policies ask the question, so the permission
 * is not a promise, it is just checked somewhere a grep for `permission:` will
 * not see.
 *
 * The web app is NOT evidence. An interface can hide a button on a permission
 * the server has never heard of, and that is the least visible defect of the
 * lot: the product looks like it enforces something and enforces nothing.
 * `organization.view` is exactly that today, which is why it is listed below in
 * its own terms rather than counted as met.
 */

/**
 * Permissions that mean nothing yet, and what each is owed.
 *
 * An entry is a bill, not an excuse. Written out on 2026-09-16, the first time
 * anything asked this question: ten of fifty-five, which is the honest size of
 * a gap this project had been finding one instance at a time.
 *
 * @var array<string, string>
 */
const MEANING_OWED = [
    // The one that is not merely unbuilt but actively misleading: the web app's
    // nav gates the Settings entry on this, and no route, policy or service on
    // the server has ever asked for it. An interface hiding a control on a
    // permission the server does not check is a product that LOOKS like it
    // enforces something.
    'organization.view' => 'The web nav gates Settings on it; no server code asks. Either the API must check it or the nav must stop pretending.',

    // `audit_log.view` was here — the log had been written since Phase 1 and
    // read by nothing but the partition command, a write path with no read
    // path. `GET /audit-logs` and `/settings/audit` pay it (ADR 0019). Worth
    // recording what its absence had been hiding: nothing, for once. The log
    // was correct, complete and unreadable — which is its own kind of defect,
    // because an audit trail nobody can open is indistinguishable from one
    // that was never written.

    'organization.update' => 'No organization endpoint of any kind exists; the org profile is read from /auth/me and changed by nobody.',
    'organization.manage_settings' => 'Same: organization-wide settings have a `settings` table and no endpoint.',

    // Features whose tables shipped in Phase 2 and whose endpoints never did.
    // Listed separately from the ones above because the table existing is what
    // makes the permission look built.
    'milestone.manage' => 'Milestones are read (project health, calendar) and cannot be created or changed through the API.',
    'tag.manage' => '`tags` exists since Phase 2 with no endpoint and no interface.',
    'saved_view.share' => '`saved_views` exists since Phase 2 with no endpoint and no interface.',

    // Half-built slices: the read exists, the destructive verb does not.
    'comment.delete_any' => 'Comments can be written and edited; nothing deletes one.',
    'file.delete_any' => 'Attachments can be uploaded and listed; nothing removes one.',

    'workflow.run_rule' => 'Phase 7 — the rule screens show what a rule DID and cannot make it run.',
];

/** Every permission the catalogue declares. */
function declaredPermissions(): array
{
    /** @var list<string> $keys */
    $keys = DB::table('permissions')->orderBy('key')->pluck('key')->all();

    return $keys;
}

/** Every permission a route gates on, including the `.any` form. */
function gatedPermissions(): array
{
    $gated = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        foreach ($route->middleware() as $middleware) {
            if (preg_match('/^permission(?:\.any)?:(.+)$/', (string) $middleware, $matches) !== 1) {
                continue;
            }

            // `preg_split` answers `false` on a bad pattern, and level 8 will
            // not unpack a `false` — assigned and checked rather than spread
            // inline, which is the same courtesy the rest of this codebase
            // extends to a possible null.
            $parts = preg_split('/[,|]/', $matches[1]);

            if ($parts !== false) {
                $gated = [...$gated, ...$parts];
            }
        }
    }

    return array_values(array_unique(array_filter($gated)));
}

/**
 * Every permission any PHP in `app/` names.
 *
 * A grep, like its sibling test, and for the same reason: a crude check that
 * runs on every commit beats a precise one nobody writes. It over-accepts by
 * design — a permission named in a comment counts — because the failure this
 * guards against is a permission named NOWHERE, and a check that cried wolf
 * about a docblock would stop being read.
 */
function askedPermissions(): array
{
    static $asked = null;

    if ($asked !== null) {
        return $asked;
    }

    $source = '';

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        $source .= (string) file_get_contents($file->getRealPath());
    }

    preg_match_all('/[\'"]([a-z_]+\.[a-z_]+)[\'"]/', $source, $matches);

    return $asked = array_values(array_unique($matches[1]));
}

it('has something in the application consulting every permission it grants', function (): void {
    $meaningless = [];
    $met = [...gatedPermissions(), ...askedPermissions()];

    foreach (declaredPermissions() as $permission) {
        if (in_array($permission, $met, strict: true)) {
            continue;
        }

        if (array_key_exists($permission, MEANING_OWED)) {
            continue;
        }

        $meaningless[] = $permission;
    }

    // Reported together, because this defect comes in bunches — ten of the
    // fifty-five, the first time anything asked.
    expect($meaningless === [])->toBeTrue(
        "Permissions this product grants and never consults:\n  "
        .implode("\n  ", $meaningless)
        ."\n\nEach of these is ticked in the role builder and means nothing. Either "
        .'check it somewhere, or add it to MEANING_OWED with what it is owed — '
        .'with the reason, not just the name.',
    );
});

/**
 * An entry on the owed list must still be owed.
 *
 * Without this the list only grows: somebody builds the audit log view, the
 * guard stays green because the exemption is still there, and the inventory
 * quietly becomes fiction — which is worse than no inventory, because it is
 * consulted and believed. Its sibling test learned this the same way.
 */
it('has no stale entries on the permission bill', function (): void {
    $met = [...gatedPermissions(), ...askedPermissions()];
    $stale = array_values(array_intersect(array_keys(MEANING_OWED), $met));

    expect($stale === [])->toBeTrue(
        "These permissions are listed as meaningless and something now consults them:\n  "
        .implode("\n  ", $stale)
        ."\n\nDelete the entries. A list of known gaps that includes closed ones is "
        .'consulted, believed, and wrong.',
    );
});
