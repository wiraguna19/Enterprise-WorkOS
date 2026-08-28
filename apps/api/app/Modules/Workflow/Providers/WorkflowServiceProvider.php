<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Providers;

use App\Modules\Approval\Domain\Event\ApprovalDecided;
use App\Modules\Work\Domain\Event\WorkItemAssigned;
use App\Modules\Work\Domain\Event\WorkItemCreated;
use App\Modules\Work\Domain\Event\WorkItemStatusChanged;
use App\Modules\Workflow\Infrastructure\Console\MaterializeRecurrences;
use App\Modules\Workflow\Infrastructure\Listener\DispatchRuleEvaluation;
use App\Modules\Workflow\Infrastructure\Listener\TransitionOnApprovalDecision;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class WorkflowServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([MaterializeRecurrences::class]);
        }

        // Work emits; Workflow reacts. Work never calls the rule engine, which
        // is what keeps a status change from becoming a 900-line service
        // (docs/01 §5).
        Event::listen(WorkItemStatusChanged::class, [DispatchRuleEvaluation::class, 'onStatusChanged']);
        Event::listen(WorkItemAssigned::class, [DispatchRuleEvaluation::class, 'onAssigned']);
        Event::listen(WorkItemCreated::class, [DispatchRuleEvaluation::class, 'onCreated']);

        // A decided approval moves the work it was about, in the deciding
        // reviewer's own request — see the listener for why it is not queued.
        Event::listen(ApprovalDecided::class, [TransitionOnApprovalDecision::class, 'handle']);

        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(__DIR__.'/../Routes/api.php');
    }
}
