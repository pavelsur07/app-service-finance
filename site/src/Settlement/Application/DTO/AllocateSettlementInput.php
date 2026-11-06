<?php

declare(strict_types=1);

namespace App\Settlement\Application\DTO;

use App\Shared\Domain\ValueObject\Money;

final readonly class AllocateSettlementInput
{
    public function __construct(public string $requestId, public string $obligationItemId, public string $paymentItemId, public Money $amount, public \DateTimeImmutable $effectiveDate)
    {
        foreach ([$requestId, $obligationItemId, $paymentItemId] as $id) {
            if (!\Ramsey\Uuid\Uuid::isValid($id)) {
                throw new \DomainException('Некорректный идентификатор распределения.');
            }
        }
    }
}
