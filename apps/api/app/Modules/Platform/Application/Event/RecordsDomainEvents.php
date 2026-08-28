<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Event;

use App\Modules\Platform\Domain\Event\DomainEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Application services record events; the bus dispatches them after commit.
 *
 * This is what makes it impossible for an activity-log entry or a notification
 * to exist for a change that rolled back. Listeners run against committed data,
 * always.
 */
trait RecordsDomainEvents
{
    /** @var list<DomainEvent> */
    private array $recordedEvents = [];

    protected function record(DomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
    }

    /** @return list<DomainEvent> */
    private function takeRecordedEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }

    /**
     * Run the callback in a transaction and dispatch recorded events on commit.
     *
     * @template T
     *
     * @param  callable():T  $work
     * @return T
     */
    protected function transactional(callable $work): mixed
    {
        $this->recordedEvents = [];

        // Wrapped rather than passed straight through: DB::transaction() is
        // typed for a Closure, and handing it a bare callable loses the return
        // type on the way out — every caller of every service then sees mixed.
        $result = DB::transaction(static fn (): mixed => $work());

        // Read back through the property rather than a local: record() runs
        // inside the transaction closure above, which static analysis cannot
        // follow, so a local would be provably empty and the dispatch below
        // would look like dead code.
        $events = $this->takeRecordedEvents();

        foreach ($events as $event) {
            Event::dispatch($event);
        }

        return $result;
    }
}
