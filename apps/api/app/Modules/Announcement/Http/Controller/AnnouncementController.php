<?php

declare(strict_types=1);

namespace App\Modules\Announcement\Http\Controller;

use App\Modules\Announcement\Application\Service\AnnouncementAuthority;
use App\Modules\Announcement\Application\Service\Announcements;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Announcements (ADR 0061).
 *
 * Reading needs no permission — what was said to your group was said to you.
 * Writing is decided by {@see AnnouncementAuthority}, group by group.
 */
final class AnnouncementController extends ApiController
{
    public function __construct(
        private readonly Announcements $announcements,
        private readonly AnnouncementAuthority $authority,
    ) {}

    /**
     * The feed, or with `?manage=1` the ones this person may change.
     */
    public function index(Request $request): ApiResponse
    {
        if ($request->boolean('manage')) {
            return ApiResponse::collection($this->announcements->managed());
        }

        return ApiResponse::collection(
            $this->announcements->feed(),
            ['unread' => $this->announcements->unreadCount()],
        );
    }

    public function show(string $id): ApiResponse
    {
        return ApiResponse::item($this->announcements->show($id));
    }

    /** The groups this person may address, for the compose form. Empty is an answer. */
    public function audiences(): ApiResponse
    {
        return ApiResponse::collection($this->authority->audiences());
    }

    public function store(Request $request): ApiResponse
    {
        /** @var array{title: string, body: string, audience_type: string, audience_id?: string|null, pinned?: bool, requires_acknowledgement?: bool, expires_at?: string|null} $validated */
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:5000'],
            'audience_type' => ['required', 'string', Rule::in(['organization', 'department', 'team'])],
            'audience_id' => ['nullable', 'required_unless:audience_type,organization', 'uuid'],
            'pinned' => ['sometimes', 'boolean'],
            'requires_acknowledgement' => ['sometimes', 'boolean'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $id = $this->announcements->publish($validated);

        return ApiResponse::item($this->announcements->show($id), 201);
    }

    public function update(Request $request, string $id): ApiResponse
    {
        /** @var array{title?: string, body?: string, pinned?: bool, requires_acknowledgement?: bool, expires_at?: string|null} $validated */
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:160'],
            'body' => ['sometimes', 'string', 'max:5000'],
            'pinned' => ['sometimes', 'boolean'],
            'requires_acknowledgement' => ['sometimes', 'boolean'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
            // The people who were told are the people who were told.
            'audience_type' => ['prohibited'],
            'audience_id' => ['prohibited'],
        ]);

        $this->announcements->update($id, $validated);

        return ApiResponse::item($this->announcements->show($id));
    }

    public function destroy(string $id): Response
    {
        $this->announcements->remove($id);

        return response()->noContent();
    }

    public function markRead(Request $request): ApiResponse
    {
        /** @var array{ids: list<string>} $validated */
        $validated = $request->validate([
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['uuid'],
        ]);

        return ApiResponse::item(['marked' => $this->announcements->markRead($validated['ids'])]);
    }

    public function acknowledge(string $id): ApiResponse
    {
        $this->announcements->acknowledge($id);

        return ApiResponse::item($this->announcements->show($id));
    }
}
