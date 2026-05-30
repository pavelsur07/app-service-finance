<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

interface WbFinanceCooldownStorageInterface
{
    public function getUntilTimestamp(string $key): ?int;

    public function setUntilTimestamp(string $key, int $untilTimestamp, int $ttlSeconds): void;
}
