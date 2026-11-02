<?php

declare(strict_types=1);

namespace App\Billing\Domain;

use Webmozart\Assert\Assert;

/**
 * Неиспользованная часть оплат текущего тарифа с дня зачисления: сумма в копейках и до какой
 * даты непрерывно её дни подкреплены оплатами (null — оплат нет).
 */
final readonly class UnusedBalance
{
    public function __construct(
        public int $kopecks,
        public ?\DateTimeImmutable $coveredUntil,
    ) {
        Assert::greaterThanEq($kopecks, 0);
    }

    public static function none(): self
    {
        return new self(0, null);
    }
}
