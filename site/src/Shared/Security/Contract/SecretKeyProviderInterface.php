<?php

declare(strict_types=1);

namespace App\Shared\Security\Contract;

interface SecretKeyProviderInterface
{
    public function getActiveKeyVersion(): string;

    public function getKeyByVersion(string $keyVersion): string;
}
