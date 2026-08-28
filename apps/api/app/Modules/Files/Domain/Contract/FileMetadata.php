<?php

declare(strict_types=1);

namespace App\Modules\Files\Domain\Contract;

final readonly class FileMetadata
{
    public function __construct(
        public int $sizeBytes,
        public string $mimeType,
        public ?string $checksumSha256 = null,
    ) {}
}
