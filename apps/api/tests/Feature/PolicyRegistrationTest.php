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
