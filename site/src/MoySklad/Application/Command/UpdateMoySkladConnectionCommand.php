<?php

declare(strict_types=1);

namespace App\MoySklad\Application\Command;

final readonly class UpdateMoySkladConnectionCommand
{
    public function __construct(
        public string $id,
        public string $companyId,
        public string $name,
        public string $baseUrl,
        public ?string $login,
        public ?string $accessToken,
        public ?string $refreshToken,
        public ?\DateTimeImmutable $tokenExpiresAt,
        public bool $isActive,
    ) {
    }
}
