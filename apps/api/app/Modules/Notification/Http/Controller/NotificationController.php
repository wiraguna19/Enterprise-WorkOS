<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Controller;

use App\Modules\Notification\Application\Service\NotificationDispatcher;
use App\Modules\Notification\Http\Resource\NotificationResource;
use App\Modules\Notification\Infrastructure\Eloquent\NotificationModel;
use App\Modules\Notification\Infrastructure\Eloquent\NotificationPreferenceModel;
use App\Modules\Platform\Application\Query\CursorPage;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use Illuminate\Http\Request;

final class NotificationController extends ApiController
{
    public function __construct(
        private readonly NotificationDispatcher $notifications,
        private readonly TenantContext $tenant,
    ) {}

    public function index(Request $request): ApiResponse
    {
        $query = NotificationModel::query()
            ->with('actor.user:id,name')
            ->where('membership_id', $this->tenant->membershipId())
            ->when($request->boolean('unread_only'), fn ($q) => $q->unread(), fn ($q) => $q->inbox())
            ->orderByDesc('created_at')
            ->orderBy('id');

        $page = new CursorPage(
            $query->cursorPaginate(CursorPage::perPage($request->integer('limit')))
        );

        return ApiResponse::collection(
            NotificationResource::collection($page->paginator->items()),
            $page->meta() + $this->notifications->counts($this->tenant->membershipId()),
        );
    }

    /** The badge. Runs on every page load, so it is one query and nothing else. */
    public function unreadCount(): ApiResponse
    {
        return $this->ok($this->notifications->counts($this->tenant->membershipId()));
    }

    public function markRead(Request $request): ApiResponse
    {
        $validated = $request->validate([
            'ids' => ['sometimes', 'array', 'max:200'],
            'ids.*' => ['uuid'],
        ]);

        /** @var list<string>|null $ids */
        $ids = $validated['ids'] ?? null;

        return $this->ok([
            'marked_read' => $this->notifications->markRead(
                $this->tenant->membershipId(),
                $ids,
            ),
        ]);
    }

    public function preferences(): ApiResponse
    {
        $rows = NotificationPreferenceModel::query()
            ->where('membership_id', $this->tenant->membershipId())
            ->get(['type', 'in_app', 'email', 'digest']);

        return $this->ok([
            'preferences' => $rows,
            // The client needs the defaults to render a meaningful form: a
            // missing row means "default", not "off".
            'defaults' => ['in_app' => true, 'email' => false, 'digest' => 'off'],
        ]);
    }

    public function updatePreferences(Request $request): ApiResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'max:60'],
            'in_app' => ['sometimes', 'boolean'],
            'email' => ['sometimes', 'boolean'],
            'digest' => ['sometimes', 'in:off,daily,weekly'],
        ]);

        $this->notifications->savePreference(
            $this->tenant->membershipId(),
            (string) $validated['type'],
            // A missing key is the default for that channel, not "off": this is
            // a PATCH of one type's row, and treating absence as false would
            // switch e-mail off every time somebody changed the digest beside it.
            (bool) ($validated['in_app'] ?? true),
            (bool) ($validated['email'] ?? false),
            (string) ($validated['digest'] ?? 'off'),
        );

        return $this->noContent();
    }
}
