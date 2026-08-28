<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Response;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The single response envelope for the whole API.
 *
 * A client that can parse one endpoint can parse all of them
 * (docs/05-api-architecture.md §3).
 */
final class ApiResponse implements Responsable
{
    /**
     * @param  array<string, mixed>|list<mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    private function __construct(
        private readonly array|\JsonSerializable|null $data,
        private readonly array $meta = [],
        private readonly int $status = 200,
    ) {}

    public static function item(mixed $data, int $status = 200): self
    {
        return new self($data, [], $status);
    }

    /** @param array<string, mixed> $meta */
    public static function collection(mixed $data, array $meta = []): self
    {
        return new self($data, $meta);
    }

    public static function noContent(): self
    {
        return new self(null, [], 204);
    }

    public function toResponse($request): JsonResponse
    {
        if ($this->status === 204) {
            return response()->json(null, 204);
        }

        return response()->json([
            'data' => $this->data,
            'meta' => $this->meta + ['request_id' => $this->requestId($request)],
        ], $this->status);
    }

    private function requestId(Request $request): string
    {
        return (string) ($request->attributes->get('request_id') ?? '');
    }
}
