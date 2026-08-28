<?php

declare(strict_types=1);

namespace App\Modules\Files\Application\Service;

use App\Modules\Files\Domain\Contract\FileStorage;
use App\Modules\Files\Domain\Exception\FileNotClean;
use App\Modules\Files\Domain\Exception\UnsupportedFileType;
use App\Modules\Files\Infrastructure\Eloquent\FileModel;
use App\Modules\Files\Infrastructure\Job\ScanUploadedFile;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * The three-step upload (docs/05 §6).
 *
 *   1. reserve()  — validate policy, create a pending row, return a signed URL
 *   2. client PUTs the bytes straight to object storage
 *   3. complete() — verify what landed, queue the scan
 *
 * Every part of the policy is enforced BEFORE a single byte is accepted, and
 * the file is unavailable until it has been scanned. The failure mode is "not
 * yet available", never "served an infected file".
 */
final class UploadService
{
    /**
     * An allowlist, not a denylist. A denylist of dangerous types always misses
     * one, and the miss is the whole exploit.
     */
    private const ALLOWED_MIME_TYPES = [
        'image/png', 'image/jpeg', 'image/gif', 'image/webp',
        'application/pdf',
        'text/plain', 'text/csv', 'text/markdown',
        'application/zip',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    /**
     * SVG is deliberately absent from the allowlist above.
     *
     * An SVG is a script container. Even served as an attachment it is one
     * misconfiguration away from executing, and there is no safe way to render
     * user SVG inline without a full sanitizer pass.
     */
    private const MAX_BYTES = 209_715_200; // 200 MB

    public function __construct(
        private readonly FileStorage $storage,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @return array{file_id: string, upload_url: string, expires_in: int}
     */
    public function reserve(string $originalName, string $mimeType, int $sizeBytes): array
    {
        if (! in_array($mimeType, self::ALLOWED_MIME_TYPES, strict: true)) {
            throw new UnsupportedFileType('That file type is not accepted.', [
                'mime_type' => $mimeType,
                'allowed' => self::ALLOWED_MIME_TYPES,
            ]);
        }

        if ($sizeBytes <= 0 || $sizeBytes > self::MAX_BYTES) {
            throw new UnsupportedFileType('That file is too large.', [
                'size_bytes' => $sizeBytes,
                'max_bytes' => self::MAX_BYTES,
            ]);
        }

        $id = (string) new UuidV7;

        // The stored path is generated, never derived from the user's filename:
        // a path built from user input is a traversal waiting to happen, and
        // the original name is kept as metadata for the download header.
        $path = sprintf(
            'org/%s/%s/%s',
            $this->tenant->organizationId(),
            now()->format('Y/m'),
            $id,
        );

        $file = new FileModel;
        $file->forceFill([
            'id' => $id,
            'disk' => 's3',
            'path' => $path,
            'original_name' => $this->sanitizeName($originalName),
            'mime_type' => $mimeType,
            'size_bytes' => $sizeBytes,
            'uploaded_by_membership_id' => $this->tenant->membershipId(),
            'upload_state' => 'pending',
            'scan_status' => 'pending',
        ])->save();

        return [
            'file_id' => $id,
            'upload_url' => $this->storage->presignedUploadUrl($path, $mimeType),
            'expires_in' => 900,
        ];
    }

    /**
     * Confirm the object landed.
     *
     * The size is re-read from storage rather than trusted from the client: the
     * declared size gated the policy check, and a client that lied about it
     * must not end up with a 2 GB object recorded as 1 KB.
     */
    public function complete(FileModel $file): FileModel
    {
        return DB::transaction(function () use ($file): FileModel {
            $metadata = $this->storage->head($file->path);

            if ($metadata === null) {
                $file->forceFill(['upload_state' => 'failed'])->save();

                throw new UnsupportedFileType('The upload did not complete.');
            }

            $file->forceFill([
                'size_bytes' => $metadata->sizeBytes,
                'checksum_sha256' => $metadata->checksumSha256,
                'upload_state' => 'complete',
            ])->save();

            // Scanning is asynchronous, so the file is quarantined rather than
            // blocked at upload — a documented residual risk (docs/06 §4).
            ScanUploadedFile::dispatch((string) $file->getKey());

            return $file;
        });
    }

    /**
     * A download URL, issued only for a file that is complete and clean.
     */
    public function downloadUrl(FileModel $file): string
    {
        if ($file->upload_state !== 'complete') {
            throw new FileNotClean('That file has not finished uploading.');
        }

        if ($file->scan_status === 'infected') {
            throw new FileNotClean('That file was rejected by the malware scanner.');
        }

        if ($file->scan_status === 'pending') {
            throw new FileNotClean('That file is still being scanned. Try again shortly.');
        }

        return $this->storage->presignedDownloadUrl($file->path, $file->original_name);
    }

    private function sanitizeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\p{L}\p{N}._ -]/u', '_', $name) ?? 'file';

        return mb_substr(trim($name) ?: 'file', 0, 255);
    }
}
