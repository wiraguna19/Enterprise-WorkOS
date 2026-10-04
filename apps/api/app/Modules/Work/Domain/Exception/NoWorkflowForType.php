<?php

declare(strict_types=1);

namespace App\Modules\Work\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * Work of a type no workflow handles cannot be created (ADR 0047).
 *
 * It used to arrive as `firstOrFail()` inside `resolveWorkflow()` — a
 * ModelNotFoundException, rendered as "Resource not found." on a form whose
 * every field pointed at something that existed. The create form offered seven
 * types and the seed gives Acme a default workflow for two, so five choices on
 * that form were a 404 nobody could read. Named, so the sentence says which
 * type and why.
 */
final class NoWorkflowForType extends DomainException
{
    public static function for(string $type): self
    {
        $label = ucfirst(str_replace('_', ' ', $type));

        return new self(
            __(':type work cannot be created here yet: no active workflow handles it.', ['type' => $label]),
            ['type' => $type],
        );
    }

    public function errorCode(): string
    {
        return 'work_item.no_workflow_for_type';
    }
}
