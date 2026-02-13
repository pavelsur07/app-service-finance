<?php

namespace App\Analytics\Api\Response;

final readonly class SnapshotResponse
{
    public function __construct(
        private SnapshotContextResponse $context,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'context' => $this->context->toArray(),
            'widgets' => new \stdClass(),
        ];
    }
}
