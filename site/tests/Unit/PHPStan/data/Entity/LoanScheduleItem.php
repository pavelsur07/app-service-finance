<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan\data\Entity;

/**
 * Фикстура: собственного поля компании нет, но сущность принадлежит `Order`,
 * у которого оно есть. Транзитивное владение на один уровень.
 */
final class LoanScheduleItem
{
    private ?Order $order = null;

    public function order(): ?Order
    {
        return $this->order;
    }
}
