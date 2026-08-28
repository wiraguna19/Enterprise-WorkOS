<?php

declare(strict_types=1);

namespace App\Modules\Files\Infrastructure\Storage;

use App\Modules\Files\Domain\Contract\FileMetadata;
use App\Modules\Files\Domain\Contract\FileStorage;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;

final class S3FileStorage implements FileStorage
{
    public function __construct(
        private readonly string $disk = 's3',
    ) {}

    public function presignedUploadUrl(string $path, string $mimeType, int $expiresInSeconds = 900): string
    {
        /** @var AwsS3V3Adapter $adapter */
        $adapter = Storage::disk($this->disk);

        return $adapter->temporaryUploadUrl(
            $path,
            now()->addSeconds($expiresInSeconds),
            // The signed URL pins the content type: a client that promised a
            // PDF cannot then upload an HTML file to the same URL.
            ['ContentType' => $mimeType],
        )['url'];
    }

    public function presignedDownloadUrl(string $path, string $filename, int $expiresInSeconds = 300): string
    {
        return Storage::disk($this->disk)->temporaryUrl(
            $path,
            now()->addSeconds($expiresInSeconds),
            [
                // Always an attachment, never inline. An inline-rendered HTML
                // or SVG upload executes in the origin that served it
                // (docs/06 §3).
                'ResponseContentDisposition' => 'attachment; filename="'.addslashes($filename).'"',
                'ResponseContentType' => 'application/octet-stream',
            ],
        );
    }

    public function head(string $path): ?FileMetadata
    {
        $disk = Storage::disk($this->disk);

        if (! $disk->exists($path)) {
            return null;
        }

        return new FileMetadata(
            sizeBytes: $disk->size($path),
            mimeType: $disk->mimeType($path) ?: 'application/octet-stream',
        );
    }

    public function delete(string $path): void
    {
        Storage::disk($this->disk)->delete($path);
    }
}
