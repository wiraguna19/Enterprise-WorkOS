<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Symfony\Component\Finder\Finder;

/**
 * An endpoint nothing calls is a feature nobody can use.
 *
 * This is the single most repeated defect in this codebase, and every instance
 * was found by accident:
 *
 *   - the work-item transition action did not exist in the web app at all
 *     (Phase 3 → 6), while every API test for transitions passed;
 *   - the board's cards were `<Link>`s under a comment calling them draggable;
 *   - `activity.view` was granted to every role with no endpoint behind it, and
 *     the endpoint, once written, had no reader;
 *   - the ENTIRE export slice — four reports, a queued job, presigned URLs,
 *     expiry, a pruning command, an ADR — shipped with no button;
 *   - three of the four reports had no screen.
 *
 * None was a broken feature. Each worked perfectly and could not be reached,
 * which is why they survived phases: **nobody reports the absence of something
 * they never knew was there.** Tests pass, review passes, and the only thing
 * that finds it is somebody trying to use the product.
 *
 * So this test asks the question nothing else asks — "what calls this?" — by
 * searching the web app for each route's shape. It is deliberately crude: a
 * grep, not a call graph. A crude check that runs on every commit beats a
 * precise one nobody writes.
 *
 * **A route listed in UNREACHED_ON_PURPOSE needs a reason, not just an entry.**
 * The list is where this test stops being useful if it is treated as a chore.
 */

/**
 * Routes with no caller in `apps/web`, because they are not for this app.
 *
 * An entry here is a claim that the absence is CORRECT. It needs a reason, and
 * the reason has to survive being read out loud.
 *
 * @var array<string, string>
 */
const NO_INTERFACE_BY_DESIGN = [
    'api/v1/calendar/{token}.ics' => 'Fetched by calendar clients over the subscription URL, not by this app.',
];

/**
 * Routes this product cannot reach YET, and what each is owed.
 *
 * A separate list from the one above, and the separation is the point: that one
 * says "correct", this one says "owed". Both keep the gate green; only this one
 * is a bill.
 *
 * Written out on 2026-09-06, the first time anything asked "what calls this?".
 * Twenty endpoints answered nothing — which is the honest size of a defect this
 * project had been finding one instance at a time, by accident, for six phases.
 *
 * Then the question got sharper the same day — the path must END where the
 * route ends, and a write must be called with its own verb — and seven more
 * appeared, including creating a work item. **When a check finds nine things,
 * the next question to ask is what the check cannot see.**
 *
 * @var array<string, string>
 */
const INTERFACE_OWED = [
    // The three department writes were here, and are paid: `/departments`
    // creates, renames and re-parents one. Worth keeping the note about HOW
    // they were listed — verb-scoped, because the LIST had a reader (the New
    // project form) while the writes had none, and exempting the whole path
    // would have stopped watching a read the product depends on.

    // Recurrence was here — the rule, the materializer, the scheduled command
    // and the `recurrence_id` on every item it produced, all shipped in Phase 5
    // with nothing able to create one. `/recurring` does now, and it was the
    // last entry on this list that belonged to the PRODUCT rather than to a
    // phase that has not started.

    // ── Found 2026-09-06, when this test learned to ask about the VERB ──────
    //
    // Everything below was counted as reached until the day the path stopped
    // being enough evidence: a write hiding behind the read on its own path, or
    // a route hiding behind a longer one that starts the same way. Together
    // they say something the phase reports did not: **this product can read
    // almost everything and create almost nothing.**
    //
    // They are listed, not fixed, deliberately. An inventory written down is a
    // bill; six features built in a hurry is how the last one got here.

    // `POST /work-items` was here for one commit — it had existed since Phase 3
    // with nothing calling it — and is now the New work item form. What is left
    // is the other half: an item can be created and never corrected.
    // `POST /teams` was here — a team could gain and lose members and could not
    // be created, so every team in the product came from the seed.

    // `GET /approvals/{id}` was here, and paid: it is `/approvals/[id]` now.
    // Worth recording what the absence had been hiding — the route was gated
    // on a reviewer's permission while its policy named the requester a
    // participant, so the submitter could withdraw a submission the API would
    // not let her read. Three phases old, and unreachable by anyone, which is
    // the only reason it was never reported.

    // `people/{membership}/workload/items` was here, and paid. It was the last
    // entry on this list that broke a house rule rather than merely lacking a
    // screen — Phase 6 rule 1 says a number must be able to show its work, and
    // the one figure a staffing decision is made from was the one figure nobody
    // could check. **A rule with a standing counter-example in the product is
    // not a rule.**

    // Phase 7 owns these: the visual workflow and rule builders.
    'api/v1/workflows' => 'Phase 7 — the workflow builder reads this.',
    'api/v1/workflow-rules' => 'Phase 7 — the rule builder reads this.',
    'api/v1/workflow-rules/{id}/runs' => 'Phase 7 — the rule builder shows run history.',
];

function webSourceDirectory(): ?string
{
    $path = dirname(__DIR__, 3).'/web/src';

    return is_dir($path) ? $path : null;
}

/**
 * Every line of the web app, once.
 *
 * Read as one string rather than file by file: the question is whether ANY of
 * it calls the endpoint, and searching a concatenation answers that in one pass
 * instead of once per route per file.
 */
function webSource(): string
{
    static $source = null;

    if ($source !== null) {
        return $source;
    }

    $directory = webSourceDirectory();

    if ($directory === null) {
        return $source = '';
    }

    $parts = [];

    foreach (Finder::create()->files()->in($directory)->name(['*.ts', '*.tsx']) as $file) {
        $parts[] = (string) file_get_contents($file->getRealPath());
    }

    return $source = implode("\n", $parts);
}

/**
 * The route's path, as the web app would write it.
 *
 * `api/v1/work-items/{reference}/activity` becomes a pattern matching
 * `/work-items/${reference}/activity`: a parameter is "anything that is not a
 * quote or a slash", which matches a template expression without running past
 * the end of the string it lives in.
 *
 * No leading slash is added — `substr` already leaves one. Prepending another
 * made every pattern `//…`, which matches nothing, and the test then reported
 * the ENTIRE API as unreachable. A result too large to be true is the
 * instrument failing, not the subject.
 *
 * **The path must END where the route ends.** Without the lookahead,
 * `work-items/{reference}/assign` was satisfied by the
 * `/work-items/${reference}/assignments` a page already fetched, and
 * `approvals/{id}` by `/approvals/${id}/decide`. A prefix is not a caller, and
 * this test reporting a false green is worse than not existing: it is consulted
 * and believed.
 */
function patternFor(string $uri): string
{
    // Cast: preg_replace returns string|null, and level 8 will not hand a
    // possible null to a concatenation.
    return '#'.(string) preg_replace(
        '/\\\\\{[^}]+\\\\\}/',
        '[^"\'`/]+',
        preg_quote(substr($uri, strlen('api/v1')), '#'),
    ).'(?=["\'`?])#';
}

/**
 * The same path, called with the verb the route actually answers.
 *
 * A write that shares its path with a read hides behind it. `POST
 * /work-items/{reference}/comments` was counted as reached for three phases by
 * the GET the page makes on the same path — while the comment box was a bare
 * `<form>` with no action, so posting a comment reloaded the page and threw the
 * text away. The endpoint was perfect, tested, and unreachable.
 *
 * So a write must be proved by a call that NAMES it: `api(path, { method:
 * "POST" … })`, the one shape this client has. 300 characters is the window
 * between the path and its options object — generous enough for a formatted
 * body, short enough that the next call in the file cannot vouch for this one.
 *
 * Crude, like the rest of this test, and for the same reason: it runs on every
 * commit.
 */
function patternForCall(string $uri, string $method): string
{
    return '#'.substr(patternFor($uri), 1, -1)
        .'[\s\S]{0,300}?method:\s*"'.$method.'"#';
}

/**
 * Is this endpoint exempt?
 *
 * An entry keys either a whole path (`api/v1/recurrences` — nothing about
 * recurrence is reachable) or one verb of it (`POST api/v1/departments` — the
 * list is read by the New project form, and nothing administers one). The
 * second form exists because the first would exempt the read as well, and then
 * a screen that quietly stopped loading would be covered by an entry about a
 * missing admin button.
 */
function isExempt(string $uri, string $method): bool
{
    foreach ([$uri, $method.' '.$uri] as $key) {
        if (array_key_exists($key, NO_INTERFACE_BY_DESIGN)
            || array_key_exists($key, INTERFACE_OWED)
        ) {
            return true;
        }
    }

    return false;
}

/** Does anything in the web app call this endpoint, with this verb? */
function isReached(string $source, string $uri, string $method): bool
{
    if (preg_match(patternFor($uri), $source) !== 1) {
        return false;
    }

    // A GET is the client's default and is written without a `method` option,
    // so the path is all the evidence there is. Everything else must say so.
    return $method === 'GET' || preg_match(patternForCall($uri, $method), $source) === 1;
}

it('has something in the web app calling every API route', function (): void {
    if (webSourceDirectory() === null) {
        $this->markTestSkipped('apps/web is not present in this checkout.');
    }

    $source = webSource();
    $unreached = [];

    // `->getRoutes()` twice on purpose: the collection is iterable at runtime
    // but its interface does not say so, and level 8 reads the interface. The
    // inner call returns a plain array of routes.
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = $route->uri();

        if (! str_starts_with($uri, 'api/v1/')) {
            continue;
        }

        // Per METHOD, not per route. A path can be a read the app makes daily
        // and a write nothing has ever called, and the two answer different
        // questions about whether the product can do the thing.
        foreach ($route->methods() as $method) {
            if ($method === 'HEAD' || $method === 'OPTIONS') {
                continue;
            }

            if (isExempt($uri, $method) || isReached($source, $uri, $method)) {
                continue;
            }

            $unreached[] = $method.' '.$uri;
        }
    }

    // Reported together, not one at a time. A test that fails on the first
    // offender turns a survey into a queue of round trips, and the shape of
    // this defect is that there are usually several.
    // `expect($list)->toBe([], $message)` would work, but the whole value of
    // this test is the LIST in the failure — so the assertion is a boolean and
    // the list is the message, where nothing can truncate or reformat it.
    expect($unreached === [])->toBeTrue(
        "Endpoints nothing in the product calls:\n  "
        .implode("\n  ", array_unique($unreached))
        ."\n\nEach of these exists and is tested. Either give it an interface, or add "
        .'it to NO_INTERFACE_BY_DESIGN (the absence is correct) or INTERFACE_OWED '
        .'(it is not, and here is what it needs) — with the reason, not just the name.',
    );
});

/**
 * An entry on the owed list must still be owed.
 *
 * Without this the list only ever grows. Somebody builds the file picker, the
 * gate stays green because the exemption is still there, and the inventory
 * quietly becomes fiction — which is worse than no inventory, because it is
 * consulted and believed.
 *
 * The same applies to the by-design list: an endpoint that grew an interface is
 * no longer an endpoint that has none by design.
 */
it('has no stale exemptions', function (): void {
    if (webSourceDirectory() === null) {
        $this->markTestSkipped('apps/web is not present in this checkout.');
    }

    $source = webSource();
    $stale = [];

    foreach ([...array_keys(NO_INTERFACE_BY_DESIGN), ...array_keys(INTERFACE_OWED)] as $key) {
        // A key is either "api/v1/thing" or "POST api/v1/thing", and the
        // question has to be asked in the same terms the entry was written in —
        // otherwise a verb-scoped entry is judged by whether the path is
        // fetched at all, which it always is.
        // Written out rather than destructured from `explode`: level 8 cannot
        // see that a two-part explode of a string containing a space has an
        // index 1, and patching the caller for a case that cannot happen is
        // the habit this codebase avoids.
        $parts = explode(' ', $key, 2);
        $method = count($parts) === 2 ? $parts[0] : 'GET';
        $uri = $parts[count($parts) - 1];

        if (isReached($source, $uri, $method)) {
            $stale[] = $key;
        }
    }

    expect($stale === [])->toBeTrue(
        "These endpoints are listed as unreachable and the web app now calls them:\n  "
        .implode("\n  ", $stale)
        ."\n\nDelete the entries. A list of known gaps that includes closed ones is "
        .'consulted, believed, and wrong.',
    );
});
