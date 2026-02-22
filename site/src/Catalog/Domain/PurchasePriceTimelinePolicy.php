<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

final class PurchasePriceTimelinePolicy
{
    public function assertNoOverlapWithNext(?\DateTimeImmutable $nextFrom, \DateTimeImmutable $newFrom): void
    {
        if (null !== $nextFrom && $newFrom >= $nextFrom) {
            throw new \DomainException(sprintf(
                'Нельзя установить цену с даты %s, потому что уже есть цена начиная с %s.',
                $newFrom->format('Y-m-d'),
                $nextFrom->format('Y-m-d')
            ));
        }
    }
}
