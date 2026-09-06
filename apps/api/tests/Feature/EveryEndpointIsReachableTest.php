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
    'api/v1/files/{file}/download' => 'Reached by the presigned URL the browser is handed, never by path.',
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
 * @var array<string, string>
 */
const INTERFACE_OWED = [
    // Phase 2 built department management; nothing in the product administers
    // one. The org chart is rendered from departments the seed created.
    'api/v1/departments' => 'No department admin screen exists.',
    'api/v1/departments/{department}' => 'No department admin screen exists.',
    'api/v1/departments/{department}/move' => 'No department admin screen exists.',

    // The whole attachment feature. docs/11 §4 flow 6 is "comment with a
    // @mention, attach a file" — the second half has nothing to drive it.
    'api/v1/files/upload-url' => 'No file picker anywhere in the product.',
    'api/v1/files/{file}/complete' => 'No file picker anywhere in the product.',
    'api/v1/work-items/{reference}/attachments' => 'No file picker anywhere in the product.',

    // Phase 5 shipped RRULE recurrence end to end and no way to create one.
    'api/v1/recurrences' => 'Recurring work can be created by API only.',
    'api/v1/recurrences/{id}' => 'Recurring work can be created by API only.',

    // Removing an assignee and withdrawing your own submission: two undo paths,
    // each the second half of an action the product already offers. (Editing a
    // comment was the third, and is now built.)
    'api/v1/work-items/{reference}/assignees/{assignment}' => 'No way to remove an assignee.',
    'api/v1/approvals/{id}/withdraw' => 'A requester cannot withdraw their own submission.',

    // A number without its drill-through, which is exactly what Phase 6 house
    // rule 1 forbids — the workload bar shows the hours and cannot show the work.
    'api/v1/people/{membership}/workload/items' => 'The workload bar has no drill-through.',

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
 */
function patternFor(string $uri): string
{
    // Cast: preg_replace returns string|null, and level 8 will not hand a
    // possible null to a concatenation.
    return '#'.(string) preg_replace(
        '/\\\\\{[^}]+\\\\\}/',
        '[^"\'`/]+',
        preg_quote(substr($uri, strlen('api/v1')), '#'),
    ).'#';
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

        if (! str_starts_with($uri, 'api/v1/')
            || array_key_exists($uri, NO_INTERFACE_BY_DESIGN)
            || array_key_exists($uri, INTERFACE_OWED)
        ) {
            continue;
        }

        if (preg_match(patternFor($uri), $source) !== 1) {
            $unreached[] = strtoupper(implode('|', $route->methods())).' '.$uri;
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

    foreach ([...array_keys(NO_INTERFACE_BY_DESIGN), ...array_keys(INTERFACE_OWED)] as $uri) {
        if (preg_match(patternFor($uri), $source) === 1) {
            $stale[] = $uri;
        }
    }

    expect($stale === [])->toBeTrue(
        "These endpoints are listed as unreachable and the web app now calls them:\n  "
        .implode("\n  ", $stale)
        ."\n\nDelete the entries. A list of known gaps that includes closed ones is "
        .'consulted, believed, and wrong.',
    );
});
