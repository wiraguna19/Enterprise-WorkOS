<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Infrastructure\Console;

use App\Modules\Workflow\Application\Service\RecurrenceMaterializer;
use Illuminate\Console\Command;

final class MaterializeRecurrences extends Command
{
    protected $signature = 'workflow:materialize-recurrences';

    protected $description = 'Create the work that recurrence rules are due to produce';

    public function handle(RecurrenceMaterializer $materializer): int
    {
        $tally = $materializer->run();

        $this->info(sprintf(
            'Recurrences: %d created, %d skipped, %d failed.',
            $tally['created'],
            $tally['skipped'],
            $tally['failed'],
        ));

        // A failure here has already deactivated the offending rule and logged
        // why; the command still reports success, because the tick did its job.
        // Exiting non-zero would page someone for a customer's typo.
        return self::SUCCESS;
    }
}
