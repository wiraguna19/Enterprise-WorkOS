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

// Controllers validate, authorize, call one service, return one resource.
// Raw database access there is the first step toward a fat controller.
arch('controllers never touch the database directly')
    ->expect('Illuminate\Support\Facades\DB')
    ->not->toBeUsedIn([
        'App\Modules\Identity\Http\Controller',
        'App\Modules\Organization\Http\Controller',
    ]);

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
