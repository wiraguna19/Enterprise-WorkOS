<?php

declare(strict_types=1);

namespace App\Modules\Files\Http\Controller;

use App\Modules\Files\Application\Service\UploadService;
use App\Modules\Files\Infrastructure\Eloquent\AttachmentModel;
use App\Modules\Files\Infrastructure\Eloquent\FileModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use App\Modules\Work\Application\Query\WorkItemVisibility;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

final class FileController extends ApiController
{
    public function __construct(
        private readonly UploadService $uploads,
        private readonly WorkItemVisibility $visibility,
        private readonly TenantContext $tenant,
    ) {}

    public function reserve(Request $request): ApiResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'mime_type' => ['required', 'string', 'max:160'],
            'size_bytes' => ['required', 'integer', 'min:1'],
        ]);

        return $this->ok($this->uploads->reserve(
            $validated['name'],
            $validated['mime_type'],
            $validated['size_bytes'],
        ));
    }

    public function complete(string $file): ApiResponse
    {
        // Only the person who reserved the upload finishes it.
        $model = $this->ownUpload($file);

        return $this->ok([
            'id' => $model->id,
            'upload_state' => $this->uploads->complete($model)->upload_state,
        ]);
    }

    /**
     * Redirects to a short-lived signed URL after the authorization check.
     *
     * The bucket is never public, and the URL expires in minutes — so a link
     * copied out of the browser's network tab stops working quickly.
     */
    public function download(string $file): ApiResponse
    {
        $model = $this->readable($file);

        return $this->ok(['url' => $this->uploads->downloadUrl($model)]);
    }

    /**
     * What is attached to this work item.
     *
     * Ordered oldest first, like the comments: an attachment list is a record
     * of what was added as the work went on, and reversing it puts the
     * specification under the screenshot of the bug it caused.
     *
     * `available` is the file's own answer — uploaded AND scanned — and it is
     * rendered rather than filtered on. A file that is still being scanned
     * exists and the person who just uploaded it knows it exists; hiding it
     * reads as a failed upload, which is the one thing it is not.
     */
    public function index(string $reference): ApiResponse
    {
        $item = $this->findVisibleWorkItem($reference);

        $attachments = AttachmentModel::query()
            ->with(['file', 'attachedBy.user:id,name'])
            ->where('attachable_type', 'work_item')
            ->where('attachable_id', $item->getKey())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return $this->ok($attachments->map(fn (AttachmentModel $attachment) => [
            'id' => $attachment->id,
            'attached_at' => $attachment->created_at->toIso8601String(),
            'attached_by' => $attachment->attachedBy?->user?->name,
            'file' => [
                'id' => $attachment->file?->id,
                'name' => $attachment->file?->original_name,
                'size_bytes' => $attachment->file?->size_bytes,
                'mime_type' => $attachment->file?->mime_type,
                'available' => $attachment->file?->isAvailable() ?? false,
                'scan_status' => $attachment->file?->scan_status,
            ],
        ])->values()->all());
    }

    public function attach(Request $request, string $reference): ApiResponse
    {
        $item = $this->findVisibleWorkItem($reference);

        $validated = $request->validate(['file_id' => ['required', 'uuid']]);

        // Your own upload only. Attaching somebody else's file to an item you
        // can see would be a second way to READ it — attach, then list.
        $file = $this->ownUpload((string) $validated['file_id']);

        $attachment = $this->uploads->attach($file, 'work_item', (string) $item->getKey());

        return $this->created([
            'id' => $attachment->id,
            'file' => [
                'id' => $file->id,
                'name' => $file->original_name,
                'size_bytes' => $file->size_bytes,
                'mime_type' => $file->mime_type,
                'available' => $file->isAvailable(),
            ],
        ]);
    }

    /**
     * A file this person may download: one they uploaded, or one attached to a
     * work item they can see.
     *
     * Downloading used to need only `work_item.view` and the file's id — no
     * look at what it was attached to. Somebody removed from a private
     * project, or anyone the id reached, kept fetching that project's
     * attachments. Anything else is reported as not found, like an item one
     * cannot see.
     */
    private function readable(string $id): FileModel
    {
        $file = FileModel::query()->findOrFail($id);

        if ($file->uploaded_by_membership_id === $this->tenant->membershipId()) {
            return $file;
        }

        $visible = WorkItemModel::query()->select('id');
        $this->visibility->apply($visible);

        $attachedToVisibleWork = AttachmentModel::query()
            ->where('file_id', $file->getKey())
            ->where('attachable_type', 'work_item')
            ->whereNull('deleted_at')
            ->whereIn('attachable_id', $visible)
            ->exists();

        if (! $attachedToVisibleWork) {
            throw new ModelNotFoundException;
        }

        return $file;
    }

    /** An upload this person made; anyone else's does not exist to them. */
    private function ownUpload(string $id): FileModel
    {
        $file = FileModel::query()->findOrFail($id);

        if ($file->uploaded_by_membership_id !== $this->tenant->membershipId()) {
            throw new ModelNotFoundException;
        }

        return $file;
    }

    private function findVisibleWorkItem(string $reference): WorkItemModel
    {
        $query = WorkItemModel::query()->where('reference', mb_strtoupper($reference));

        $this->visibility->apply($query);

        return $query->firstOrFail();
    }
}
