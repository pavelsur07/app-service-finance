<?php

declare(strict_types=1);

namespace App\Settlement\Application\DTO;

final readonly class ReverseSettlementInput
{
    public function __construct(public string $requestId, public \DateTimeImmutable $effectiveDate, public string $reason)
    {
        if (!\Ramsey\Uuid\Uuid::isValid($requestId)) {
            throw new \DomainException('Некорректный ключ повтора.');
        }
    }
}
