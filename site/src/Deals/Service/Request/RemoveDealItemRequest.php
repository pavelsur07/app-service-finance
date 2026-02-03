<?php

namespace App\Deals\Service\Request;

final class RemoveDealItemRequest
{
    public function __construct(
        public readonly string $itemId,
    ) {
    }
}
