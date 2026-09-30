<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Application\Service\Webhook;

use App\Modules\Governance\Application\Service\AuditLogger;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Workflow\Domain\Exception\WebhookRefused;
use App\Modules\Workflow\Infrastructure\Eloquent\WebhookEndpointModel;
use App\Modules\Workflow\Infrastructure\Eloquent\WorkflowRuleModel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

/**
 * Registering and administering the addresses this organization sends webhooks
 * to (ADR 0048).
 *
 * Every write here goes to the AUDIT log, not the activity log. Where an
 * organization's data may be sent is a security fact — "who pointed our
 * events at that address, and when" is asked by the person reviewing a leak,
 * not by somebody reading a work item's history.
 */
final class WebhookEndpoints
{
    /**
     * What an endpoint may subscribe to without a rule: every event the
     * product emits to the rule engine. `approval.decided` and `schedule.*`
     * joined when something began to emit them (ADR 0057) — a subscription
     * that can never be delivered is a promise, not a feature. The latest
     * migration's CHECK holds the same six.
     */
    public const SUBSCRIBABLE = [
        'work_item.created',
        'work_item.assigned',
        'work_item.status_changed',
        'approval.decided',
        'schedule.due_soon',
        'schedule.overdue',
    ];

    public function __construct(
        private readonly DestinationGuard $guard,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * The active endpoints subscribed to an event.
     *
     * @return Collection<int, WebhookEndpointModel>
     */
    public function subscribedTo(string $event): Collection
    {
        return WebhookEndpointModel::query()
            ->where('is_active', true)
            ->whereJsonContains('events', $event)
            ->get();
    }

    /** @return Collection<int, WebhookEndpointModel> */
    public function all(): Collection
    {
        return WebhookEndpointModel::query()
            ->orderByRaw('lower(name)')
            ->get();
    }

    public function find(string $id): WebhookEndpointModel
    {
        // Checked before the query: a rule's stored config is data, and a
        // string that is not a uuid reaches Postgres as a cast error — a 500
        // where "that endpoint does not exist" is the true answer.
        if (! Str::isUuid($id)) {
            throw WebhookRefused::unknown($id);
        }

        $endpoint = WebhookEndpointModel::query()->find($id);

        if (! $endpoint instanceof WebhookEndpointModel) {
            throw WebhookRefused::unknown($id);
        }

        return $endpoint;
    }

    /**
     * Register an address, and hand back its secret — the only time anybody
     * will see it.
     *
     * The address is checked by the same guard every delivery passes through,
     * so a private address is refused while the person who typed it is still
     * looking at the form, not discovered as a string of refused deliveries.
     *
     * @param  list<string>  $events  subscriptions, from SUBSCRIBABLE
     * @return array{0: WebhookEndpointModel, 1: string} the endpoint and its secret
     */
    public function register(string $name, string $url, array $events = []): array
    {
        $this->guard->resolve($url);

        $secret = $this->newSecret();

        $endpoint = new WebhookEndpointModel;
        $endpoint->id = (string) new UuidV7;
        $endpoint->name = trim($name);
        $endpoint->url = $url;
        $endpoint->secret_encrypted = Crypt::encryptString($secret);
        $endpoint->created_by_membership_id = $this->tenant->membershipId();
        $endpoint->events = array_values(array_intersect(self::SUBSCRIBABLE, $events));

        $this->save($endpoint);

        $this->audit->record('webhook.endpoint_registered', [
            'name' => $endpoint->name,
            'host' => $this->hostOf($endpoint->url),
            'events' => $endpoint->events,
        ], targetType: 'webhook_endpoint', targetId: $endpoint->id);

        return [$endpoint, $secret];
    }

    /**
     * Rename it, or point it somewhere else.
     *
     * A new address passes the guard again. The secret does NOT change with
     * the address: the receiver behind the new URL is usually the same service
     * moved, and rotating is its own deliberate act with its own control.
     *
     * @param  array{name?: string, url?: string, events?: list<string>}  $changes
     */
    public function update(WebhookEndpointModel $endpoint, array $changes): WebhookEndpointModel
    {
        $before = ['name' => $endpoint->name, 'host' => $this->hostOf($endpoint->url), 'events' => $endpoint->events];

        if (array_key_exists('url', $changes) && $changes['url'] !== $endpoint->url) {
            $this->guard->resolve($changes['url']);
            $endpoint->url = $changes['url'];
        }

        if (array_key_exists('name', $changes)) {
            $endpoint->name = trim($changes['name']);
        }

        if (array_key_exists('events', $changes)) {
            // In the catalogue's order, once each: the list is a set, and a
            // stored order that follows the form's would make two equal
            // subscriptions look different in the audit entry.
            $endpoint->events = array_values(array_intersect(self::SUBSCRIBABLE, $changes['events']));
        }

        $this->save($endpoint);

        $this->audit->record('webhook.endpoint_updated', [
            'before' => $before,
            'after' => ['name' => $endpoint->name, 'host' => $this->hostOf($endpoint->url), 'events' => $endpoint->events],
        ], targetType: 'webhook_endpoint', targetId: $endpoint->id);

        return $endpoint;
    }

    /**
     * Switch it on or off.
     *
     * Switching it back on clears the failure count and the reason, for the
     * reason a rule's does: somebody who just fixed the receiver should not be
     * one failure away from it switching itself off again.
     */
    public function setActive(WebhookEndpointModel $endpoint, bool $active): WebhookEndpointModel
    {
        if ($endpoint->is_active === $active) {
            return $endpoint;
        }

        $endpoint->is_active = $active;

        if ($active) {
            $endpoint->failure_count = 0;
            $endpoint->disabled_reason = null;
        }

        $endpoint->save();

        $this->audit->record($active ? 'webhook.endpoint_enabled' : 'webhook.endpoint_disabled', [
            'name' => $endpoint->name,
        ], targetType: 'webhook_endpoint', targetId: $endpoint->id);

        return $endpoint;
    }

    /**
     * A new secret, effective immediately, shown once.
     *
     * No overlap window in which both secrets sign: that is a real feature for
     * zero-downtime rotation, and it needs a second column and a rule for when
     * the old one dies. Named as owed in ADR 0048 rather than half-built.
     */
    public function rotateSecret(WebhookEndpointModel $endpoint): string
    {
        $secret = $this->newSecret();

        $endpoint->secret_encrypted = Crypt::encryptString($secret);
        $endpoint->save();

        $this->audit->record('webhook.secret_rotated', [
            'name' => $endpoint->name,
        ], targetType: 'webhook_endpoint', targetId: $endpoint->id);

        return $secret;
    }

    /**
     * Delete an endpoint no rule sends to, and its delivery history with it.
     *
     * Refused, with the rules named, while any rule still names it: see
     * {@see WebhookRefused::inUse()}.
     */
    public function delete(WebhookEndpointModel $endpoint): void
    {
        $rules = $this->rulesSendingTo($endpoint);

        if ($rules !== []) {
            throw WebhookRefused::inUse($rules);
        }

        $snapshot = ['name' => $endpoint->name, 'host' => $this->hostOf($endpoint->url)];
        $id = $endpoint->id;

        $endpoint->delete();

        $this->audit->record('webhook.endpoint_deleted', $snapshot, targetType: 'webhook_endpoint', targetId: $id);
    }

    /** The secret in the clear, for signing. Never for display after creation. */
    public function secretOf(WebhookEndpointModel $endpoint): string
    {
        return Crypt::decryptString($endpoint->secret_encrypted);
    }

    /**
     * The names of the rules whose actions name this endpoint.
     *
     * Asked of the jsonb with containment rather than by loading every rule:
     * `@>` matches an action object anywhere in the array, whatever else it
     * carries.
     *
     * @return list<string>
     */
    public function rulesSendingTo(WebhookEndpointModel $endpoint): array
    {
        $needle = json_encode(
            [['type' => 'webhook', 'with' => ['endpoint_id' => $endpoint->id]]],
            JSON_THROW_ON_ERROR,
        );

        return array_values(array_map(
            strval(...),
            WorkflowRuleModel::query()
                ->whereRaw('actions @> ?::jsonb', [$needle])
                ->orderBy('name')
                ->pluck('name')
                ->all(),
        ));
    }

    /**
     * The host, never the whole address, for the audit log.
     *
     * A webhook URL is often itself a credential — a chat tool's incoming
     * webhook is a secret token in a path — and the audit log is read by more
     * people, for longer, than the endpoint screen. "Pointed at hooks.slack.com
     * on Tuesday" answers the security question without handing out the key.
     */
    private function hostOf(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }

    /**
     * 32 random bytes, prefixed so a leaked one is recognisable in a paste —
     * the reason secret scanners can find other vendors' keys.
     */
    private function newSecret(): string
    {
        return 'whsec_'.bin2hex(random_bytes(32));
    }

    /**
     * The name is decided by the unique index, inside a savepoint so a refused
     * insert does not poison a caller's transaction (see WorkItemTemplates).
     */
    private function save(WebhookEndpointModel $endpoint): void
    {
        try {
            DB::transaction(fn (): bool => $endpoint->save());
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw WebhookRefused::nameTaken($endpoint->name);
            }

            throw $e;
        }
    }
}
