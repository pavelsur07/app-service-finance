<?php

declare(strict_types=1);

namespace App\Shared\Service;

use Symfony\Component\HttpFoundation\Request;

final class UiModeResolver
{
    public const APP = 'app';
    public const COOKIE_NAME = 'ui_mode';
    public const LEGACY = 'legacy';

    public function current(): string
    {
        return self::LEGACY;
    }

    public function resolve(Request $request): string
    {
        // Старые cookie app/vf остаются допустимыми, но новый UI больше не включают.
        return self::LEGACY;
    }

    public function supports(string $mode): bool
    {
        return \in_array($mode, [self::LEGACY, self::APP], true);
    }
}
