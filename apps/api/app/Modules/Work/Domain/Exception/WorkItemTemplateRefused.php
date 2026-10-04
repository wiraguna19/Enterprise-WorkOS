<?php

declare(strict_types=1);

namespace App\Modules\Work\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * Every refusal a work item template can express, each with a name (ADR 0047).
 *
 * One class with named constructors, like its siblings: these are one decision
 * — what a starting point may hold — seen from four angles. The `code` is what
 * a client branches on (docs/05 §3).
 */
final class WorkItemTemplateRefused extends DomainException
{
    /** @param array<string, mixed> $details */
    private function __construct(
        string $message,
        private readonly string $refusalCode,
        private readonly int $status = 422,
        array $details = [],
    ) {
        parent::__construct($message, $details);
    }

    public function errorCode(): string
    {
        return $this->refusalCode;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    public static function nameTaken(string $name): self
    {
        return new self(
            __('A template called ":name" already exists.', ['name' => $name]),
            'work_item_template.name_taken',
            409,
            ['name' => $name],
        );
    }

    /**
     * A template that fills in nothing.
     *
     * It would appear in the picker, be chosen, and change nothing on the form —
     * a control that looks like it worked and did not, which is the shape this
     * product keeps paying for.
     */
    public static function empty(): self
    {
        return new self(
            'A template has to fill in at least one field.',
            'work_item_template.empty',
        );
    }

    /**
     * Keys a template may not hold, every one of them at once, each with why.
     *
     * Reported together because the person fixing them is looking at one form,
     * and one-offender-per-round-trip teaches them the rules by trial. Refused
     * rather than dropped: a key silently discarded is a setting that appears
     * to save and does nothing.
     *
     * @param  array<string, string>  $reasons  key => why it is refused
     */
    public static function notTemplatable(array $reasons): self
    {
        $keys = implode(', ', array_keys($reasons));

        return new self(
            __('A template cannot hold: :keys.', ['keys' => $keys]),
            'work_item_template.not_templatable',
            422,
            ['fields' => $reasons],
        );
    }

    public static function unknown(string $id): self
    {
        return new self(
            'That template does not exist for this organization.',
            'work_item_template.unknown',
            404,
            ['id' => $id],
        );
    }
}
