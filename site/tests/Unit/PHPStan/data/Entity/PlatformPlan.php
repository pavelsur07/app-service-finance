<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan\data\Entity;

/** Фикстура: справочник платформы, компании не принадлежит. */
final class PlatformPlan
{
    private string $code = '';

    public function code(): string
    {
        return $this->code;
    }
}
