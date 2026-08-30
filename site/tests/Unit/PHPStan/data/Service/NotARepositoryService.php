<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan\data\Service;

/** Фикстура: класс не Repository — правило его не касается. */
final class NotARepositoryService
{
    public function findByStatus(string $status): array
    {
        return [$status];
    }
}
