<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Infrastructure\Console;

use App\Modules\Workflow\Application\Service\DeadlineScanner;
use Illuminate\Console\Command;

/**
 * `schedule.due_soon` and `schedule.overdue`, every fifteen minutes (ADR 0057).
 */
final class ScanDeadlines extends Command
{
    protected $signature = 'workflow:scan-deadlines';

    protected $description = 'Announce deadlines that were just crossed to the automation rules';

    public function handle(DeadlineScanner $scanner): int
    {
        $tally = $scanner->run();

        $this->info(sprintf('Deadlines: %d due soon, %d overdue.', $tally['due_soon'], $tally['overdue']));

        return self::SUCCESS;
    }
}
