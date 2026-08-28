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
use Illuminate\Http\Request;

final class FileController extends ApiController
{
    public function __construct(
        private readonly UploadService $uploads,
        private readonly WorkItemVisibility $visibility,
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
        $model = FileModel::query()->findOrFail($file);

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
        $model = FileModel::query()->findOrFail($file);

        return $this->ok(['url' => $this->uploads->downloadUrl($model)]);
    }

    public function attach(Request $request, string $reference): ApiResponse
    {
        $item = $this->findVisibleWorkItem($reference);

        $validated = $request->validate(['file_id' => ['required', 'uuid']]);

        /** @var FileModel $file */
        $file = FileModel::query()->findOrFail($validated['file_id']);

        $attachment = new AttachmentModel;
        $attachment->forceFill([
            'id' => AttachmentModel::newId(),
            'file_id' => $file->getKey(),
            'attachable_type' => 'work_item',
            'attachable_id' => $item->getKey(),
            'attached_by' => app(TenantContext::class)->membershipId(),
        ])->save();

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

    private function findVisibleWorkItem(string $reference): WorkItemModel
    {
        $query = WorkItemModel::query()->where('reference', mb_strtoupper($reference));

        $this->visibility->apply($query);

        return $query->firstOrFail();
    }
}
