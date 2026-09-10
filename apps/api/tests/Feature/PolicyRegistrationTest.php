<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Symfony\Component\Finder\Finder;

/**
 * A policy nobody registered is a policy that denies everything.
 *
 * Laravel finds policies by convention — App\Models\X → App\Policies\XPolicy —
 * and the modular layout puts both somewhere the convention does not look
 * (docs/04 §2). So every policy here must be registered by hand in its module's
 * provider, and forgetting one fails SILENTLY in the most misleading way
 * available: Gate falls through to deny, the endpoint answers 403, and it reads
 * exactly like a permission the actor is missing.
 *
 * That is not hypothetical. MembershipPolicy went unregistered from Phase 1
 * until Phase 5: /people/{id} answered 403 to everyone including org admins,
 * and every `permissions` block on a person reported false. Nothing caught it
 * because nothing had tested the endpoint.
 */
it('registers every policy with the gate', function (): void {
    $registered = array_map(
        static fn (string $policy): string => ltrim($policy, '\\'),
        array_values(Gate::policies()),
    );

    $declared = [];

    foreach (Finder::create()->files()->in(appDirectory().'/Modules')->name('*Policy.php') as $file) {
        $class = 'App\\'.str_replace(
            ['/', '.php'],
            ['\\', ''],
            substr($file->getRealPath(), strlen(appDirectory()) + 1),
        );

        if (class_exists($class)) {
            $declared[] = $class;
        }
    }

    sort($declared);

    $unregistered = array_values(array_diff($declared, $registered));

    expect($unregistered)->toBe([], sprintf(
        "These policies are never passed to Gate::policy(), so they deny everything:\n  - %s",
        implode("\n  - ", $unregistered),
    ));
});

/**
 * A policy nobody WROTE is invisible to the test above.
 *
 * That test compares the policies on disk against the ones registered, so it
 * cannot see the other half of the same defect: a controller that authorizes a
 * model no policy exists for. `TeamPolicy` was that (Phase 1 → 5), and
 * `DepartmentPolicy` was it again — `PATCH /departments/{id}` and
 * `POST /departments/{id}/move` answered 403 to everyone, org admins included,
 * from Phase 2 until a screen finally called them.
 *
 * So this asks the question from the other end: **if a controller calls
 * `authorize()` and binds a model, Gate must have a policy for that model.**
 *
 * Crude on purpose, like the reachability guard. It is a per-FILE check — any
 * model bound anywhere in a controller that authorizes anything — so it can
 * over-ask, and the answer to a false positive is a policy or a route that does
 * not bind the model, both of which are cheap. A precise check nobody writes
 * catches nothing.
 */
it('has a policy for every model a controller authorizes', function (): void {
    $missing = [];

    foreach (Finder::create()->files()->in(appDirectory().'/Modules')->name('*Controller.php') as $file) {
        $source = (string) file_get_contents($file->getRealPath());

        // Route-model-bound parameters, WITH the variable each one binds to.
        //
        // The variable is the whole point. The first version of this asked
        // only whether a controller bound a model and authorized SOMETHING,
        // and reported five models that are bound and never authorized —
        // `CommentController` binds a CommentModel and authorizes the work
        // item the comment is on, which is correct and needs no CommentPolicy.
        // **Over-asking is not a safe direction for a guard: five false names
        // is how a list stops being read.**
        preg_match_all('/\b([A-Z][A-Za-z0-9_]*Model)\s+\$([A-Za-z0-9_]+)\b/', $source, $bound, PREG_SET_ORDER);

        foreach ($bound as [, $short, $variable]) {
            // Authorized BY NAME. `$this->authorize('update', $department)` is
            // the shape that makes Gate consult a policy for this model.
            if (preg_match('/\$this->authorize\([^)]*\$'.preg_quote($variable, '/').'\b/', $source) !== 1) {
                continue;
            }

            // `\\\\` in a single-quoted string is ONE backslash to the regex
            // engine, which is what separates a namespace from a class name.
            // An earlier version wrote `\\]*\\` and produced a character class
            // ending in an escaped bracket — matching nothing, so this test
            // would have passed while inspecting no imports at all.
            $pattern = '/^use\s+([^\s;]+\\\\'.preg_quote($short, '/').');$/m';

            if (preg_match($pattern, $source, $import) !== 1) {
                continue;
            }

            $class = $import[1];

            if (! class_exists($class) || Gate::getPolicyFor($class) !== null) {
                continue;
            }

            $missing[] = $class.' (authorized in '.$file->getFilename().')';
        }
    }

    $missing = array_values(array_unique($missing));

    expect($missing)->toBe([], sprintf(
        "A controller authorizes these models and Gate has no policy for them, so every\nsuch request is refused — which reads exactly like a missing permission:\n  - %s",
        implode("\n  - ", $missing),
    ));
});
