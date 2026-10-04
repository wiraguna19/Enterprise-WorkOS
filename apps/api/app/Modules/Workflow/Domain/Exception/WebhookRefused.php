<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * Every refusal the webhook machinery can express, each with a name (ADR 0048).
 *
 * One class with named constructors, like its siblings: these are one decision
 * — where this organization's data may go — seen from several angles.
 */
final class WebhookRefused extends DomainException
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
            __('An endpoint called ":name" already exists.', ['name' => $name]),
            'webhook.name_taken',
            409,
            ['name' => $name],
        );
    }

    /**
     * The address is somewhere this product will not send to.
     *
     * Refused when it is registered AND checked again before every send,
     * because a hostname that resolved to a public address on Monday can
     * resolve to 10.0.0.5 on Tuesday.
     */
    public static function destinationNotAllowed(string $reason): self
    {
        return new self(
            __('That address cannot receive webhooks: :reason', ['reason' => $reason]),
            'webhook.destination_not_allowed',
            422,
            ['reason' => $reason],
        );
    }

    /**
     * The name does not resolve — which, unlike a private address, can change
     * by itself. A delivery that meets this retries; a form that meets it
     * refuses, because an address nobody can reach today is almost always a
     * typo.
     */
    public static function unresolvable(string $host): self
    {
        return new self(
            __('That address cannot receive webhooks: :host does not resolve to an IPv4 address.', ['host' => $host]),
            'webhook.destination_unresolvable',
            422,
            ['host' => $host],
        );
    }

    /**
     * Rules still send to it.
     *
     * Deleting it would turn each of those rules into one that fails on every
     * match until it disables itself — the endpoint's removal reported, days
     * later, as a broken rule. Switching it off is the reversible answer.
     *
     * @param  list<string>  $rules  the names of the rules that name it
     */
    public static function inUse(array $rules): self
    {
        return new self(
            __('Rules still send to this endpoint: :rules. Switch it off, or change those rules first.', ['rules' => implode(', ', $rules)]),
            'webhook.endpoint_in_use',
            409,
            ['rules' => $rules],
        );
    }

    public static function unknown(string $id): self
    {
        return new self(
            'That webhook endpoint does not exist for this organization.',
            'webhook.unknown',
            404,
            ['id' => $id],
        );
    }
}
