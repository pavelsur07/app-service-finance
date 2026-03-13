<?php

declare(strict_types=1);

namespace App\MoySklad\Application\Command;

final readonly class DeleteMoySkladConnectionCommand
{
    public function __construct(
        public string $id,
        public string $companyId,
    ) {
    }
}
