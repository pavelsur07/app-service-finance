<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Domain\Reconciliation;

use App\Marketplace\Enum\OzonReconciliationStatus;
use App\Shared\Domain\ValueObject\Money;

/**
 * Допуск сверки: знаковая разница «цель − источник» не больше лимита по модулю.
 *
 * Сравнение знаковое, а не по модулю: перепутанный знак (возврат вместо продажи)
 * при равных модулях не должен выглядеть как «сошлось».
 */
final readonly class OzonReconciliationTolerance
{
    private function __construct(private Money $limit)
    {
        if ($limit->isNegative()) {
            throw new \InvalidArgumentException('Tolerance limit must not be negative.');
        }
    }

    public static function of(Money $limit): self
    {
        return new self($limit);
    }

    /** Решение Владельца: 1 ₽ на строку сверки. */
    public static function oneRuble(): self
    {
        return new self(Money::fromMinor(100, 'RUB'));
    }

    public function limit(): Money
    {
        return $this->limit;
    }

    /**
     * Разница «цель − источник». Null, если нет одной из сторон.
     */
    public function delta(?Money $source, ?Money $target): ?Money
    {
        if (null === $source || null === $target) {
            return null;
        }

        return $target->subtract($source);
    }

    public function evaluate(?Money $source, ?Money $target): OzonReconciliationStatus
    {
        $delta = $this->delta($source, $target);

        if (null === $delta) {
            return OzonReconciliationStatus::NO_DATA;
        }

        if ($delta->isZero()) {
            return OzonReconciliationStatus::MATCHED;
        }

        return $delta->abs()->compareTo($this->limit) <= 0
            ? OzonReconciliationStatus::WITHIN_TOLERANCE
            : OzonReconciliationStatus::MISMATCH;
    }
}
