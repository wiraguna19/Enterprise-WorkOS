<?php

declare(strict_types=1);
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Infrastructure\Eloquent\BaseModel;

/**
 * Architecture rules are enforced by tests, not by review discipline.
 *
 * The difference between a modular monolith and a big ball of mud is whether
 * these rules are checked automatically (docs/01 §1).
 */

/**
 * Controllers validate, authorize, call one service, return one resource.
 *
 * This rule used to be written as `controllers never touch the database
 * directly` and it enforced neither half of its own sentence. It named two of
 * the thirteen controller namespaces, and what it watched was the `DB` facade —
 * so an Eloquent write in a controller was invisible to it in the two modules
 * it covered and in the eleven it did not. That is precisely how the department
 * rename went unrecorded for two phases: the write was `$department->forceFill(
 * $request->validated())->save()`, in the Organization namespace the rule
 * already named, and the rule looked straight past it (ADR 0045, ADR 0046).
 *
 * What is enforced now is narrower than the old sentence and actually true: a
 * controller does not WRITE. Reads are deliberately still allowed — a scoped
 * query a controller hands to a resource is not what goes wrong here, and
 * banning them would be an arch rule nobody can satisfy without a query object
 * per endpoint. A WRITE is different in kind: the service is where the activity
 * entry, the domain event, the `lock_version` bump and the transaction live, so
 * a write placed beside them instead of inside them silently ships without any
 * of the four. The rename is the whole argument — it worked, and it recorded
 * nothing.
 *
 * Written as a source test rather than an arch expectation because Pest's arch
 * API expresses "uses this class" and not "calls this method": `->save()` on a
 * model is a method call on an instance, which no `toBeUsedIn` can see.
 */
test('controllers never write to the database', function (): void {
    /**
     * Method calls that put something in a table. `forceFill` is here even
     * though it writes nothing by itself: it is how this codebase names the
     * columns of a write (docs/06 §3), so it never appears except in front of
     * one, and naming it makes the failure point at the readable line.
     */
    $writes = [
        'save', 'saveQuietly', 'saveOrFail', 'delete', 'deleteQuietly',
        'forceDelete', 'restore', 'forceFill', 'update', 'updateQuietly',
        'updateOrInsert', 'updateOrCreate', 'insert', 'insertGetId',
        'insertOrIgnore', 'firstOrCreate', 'create', 'createQuietly',
        'increment', 'decrement', 'truncate', 'upsert',
    ];

    $offenders = [];

    foreach (glob('app/Modules/*/Http/Controller/*.php') ?: [] as $file) {
        $source = file_get_contents($file);

        if ($source === false) {
            continue;
        }

        // Comments are stripped first. This file's own prose names half the
        // write methods above, and a grep that reads comments would convict
        // the explanation of the rule along with its violations.
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], strict: true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        foreach (explode("\n", $code) as $number => $line) {
            foreach ($writes as $method) {
                // `$this->something->update(...)` is a controller delegating to
                // an injected service, which is the shape this rule wants. Only
                // a call on anything else — a model, a query, the DB facade —
                // is a controller doing the write itself.
                if (preg_match('/(->|::)'.$method.'\s*\(/', $line) !== 1) {
                    continue;
                }

                if (preg_match('/\$this->(\w+->)?'.$method.'\s*\(/', $line) === 1) {
                    continue;
                }

                $offenders[] = basename($file).':'.($number + 1).' '.$method.'()';
            }
        }
    }

    expect($offenders)->toBe([]);
});

arch('controllers extend the shared base')
    ->expect('App\Modules\Organization\Http\Controller')
    ->toExtend(ApiController::class);

arch('no debugging statements ship')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die'])
    ->not->toBeUsed();

/**
 * Mass assignment is forbidden: every write in this codebase names its columns
 * through forceFill() in a service, so a request body can never reach a column
 * nobody listed (docs/06 §3).
 *
 * Written as a reflection test rather than an arch expectation because Pest's
 * `toHaveProperty` does not operate on class properties in the arch API — it
 * silently degrades into a value expectation on the class name.
 */
test('models never mass assign', function (): void {
    $offenders = [];

    foreach (glob('app/Modules/*/Infrastructure/Eloquent/*.php') ?: [] as $file) {
        $class = str_replace(['app/', '/', '.php'], ['App\\', '\\', ''], $file);

        if (! class_exists($class) || ! is_subclass_of($class, BaseModel::class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract()) {
            continue;
        }

        if ($reflection->newInstanceWithoutConstructor()->getFillable() !== []) {
            $offenders[] = $class;
        }
    }

    expect($offenders)->toBe([]);
});

arch('domain layer is framework free')
    ->expect('App\Modules\Identity\Domain')
    ->not->toUse([
        'Illuminate\Database\Eloquent\Model',
        'Illuminate\Support\Facades\DB',
        'Illuminate\Http\Request',
    ]);

arch('http layer never touches another module\'s infrastructure')
    ->expect('App\Modules\Organization\Http')
    ->not->toUse('App\Modules\Identity\Infrastructure\Eloquent\SessionModel');

arch('everything is strictly typed')
    ->expect('App')
    ->toUseStrictTypes();

arch('value objects and services are final')
    ->expect('App\Modules\Identity\Application\Service')
    ->classes()
    ->toBeFinal();

// ── Phase 4 ─────────────────────────────────────────────────────────────────

arch('the workflow domain is framework free')
    ->expect('App\Modules\Workflow\Domain')
    ->not->toUse([
        'Illuminate\Database\Eloquent\Model',
        'Illuminate\Support\Facades\DB',
        'Illuminate\Http\Request',
    ]);

arch('the approval domain is framework free')
    ->expect('App\Modules\Approval\Domain')
    ->not->toUse([
        'Illuminate\Database\Eloquent\Model',
        'Illuminate\Support\Facades\DB',
        'Illuminate\Http\Request',
    ]);

/**
 * The dependency direction that keeps the module graph a tree.
 *
 * Work emits domain events; Workflow, Approval, and Notification subscribe.
 * Work never calls any of them, which is what stops a status change from
 * accumulating every downstream concern (docs/01 §5, docs/04 §3).
 *
 * Deptrac enforces the layering too. These are repeated here because a Deptrac
 * failure names a layer violation, while this names the decision.
 */
arch('work never depends on the modules that react to it')
    ->expect('App\Modules\Work')
    ->not->toUse([
        'App\Modules\Approval',
        'App\Modules\Notification',
    ]);

arch('workflow never depends on notification')
    ->expect('App\Modules\Workflow\Domain')
    ->not->toUse('App\Modules\Notification\Infrastructure');

arch('approval services are final')
    ->expect('App\Modules\Approval\Application\Service')
    ->classes()
    ->toBeFinal();

arch('workflow services are final')
    ->expect('App\Modules\Workflow\Application\Service')
    ->classes()
    ->toBeFinal();

arch('notification services are final')
    ->expect('App\Modules\Notification\Application\Service')
    ->classes()
    ->toBeFinal();
