<?php

declare(strict_types=1);

namespace App\MoySklad\Application\Command;

final readonly class ManageConnectionCommand
{
    public function __construct(
        public string $companyId,
        public string $actorId,
        public string $operation,
        public ?string $id = null,
        public string $name = '',
        #[\SensitiveParameter] public string $token = '',
        public int $version = 0,
    ) {
    }
}
