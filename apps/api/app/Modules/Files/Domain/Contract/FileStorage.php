<?php

declare(strict_types=1);

namespace App\Modules\Files\Domain\Contract;

/**
 * Storage is an abstraction so business logic never learns what S3 is
 * (docs/03 §5).
 *
 * Two implementations exist: S3-compatible for every real environment, and
 * local for tests. The interface is deliberately narrow — a wider one leaks
 * driver behaviour into callers and makes the swap that this exists for
 * impossible.
 */
interface FileStorage
{
    /**
     * A short-lived URL the browser PUTs bytes to directly.
     *
     * Uploads never stream through the API process: a 200 MB file would
     * otherwise occupy a PHP worker for its whole duration (docs/05 §6).
     */
    public function presignedUploadUrl(string $path, string $mimeType, int $expiresInSeconds = 900): string;

    /**
     * A short-lived URL for reading, issued only after an authorization check.
     * The bucket itself is never public.
     */
    public function presignedDownloadUrl(string $path, string $filename, int $expiresInSeconds = 300): string;

    /** Confirm the object actually landed, and how big it really is. */
    public function head(string $path): ?FileMetadata;

    public function delete(string $path): void;
}
