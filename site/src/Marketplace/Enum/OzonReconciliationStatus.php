<?php

declare(strict_types=1);

namespace App\Marketplace\Enum;

enum OzonReconciliationStatus: string
{
    case MATCHED = 'matched';
    case WITHIN_TOLERANCE = 'within_tolerance';
    case MISMATCH = 'mismatch';
    case NO_DATA = 'no_data';

    public function getLabel(): string
    {
        return match ($this) {
            self::MATCHED => 'Сошлось',
            self::WITHIN_TOLERANCE => 'В пределах допуска',
            self::MISMATCH => 'Расхождение',
            self::NO_DATA => 'Нет данных',
        };
    }

    public function isOk(): bool
    {
        return self::MATCHED === $this || self::WITHIN_TOLERANCE === $this;
    }

    /**
     * Худший из статусов. Пустой набор — «нет данных», а не «сошлось»:
     * отсутствие проверок не доказывает, что данным можно доверять.
     *
     * @param list<self> $statuses
     */
    public static function worstOf(array $statuses): self
    {
        if ([] === $statuses) {
            return self::NO_DATA;
        }

        $rank = [
            self::MISMATCH->value => 3,
            self::NO_DATA->value => 2,
            self::WITHIN_TOLERANCE->value => 1,
            self::MATCHED->value => 0,
        ];

        $worst = $statuses[0];
        foreach ($statuses as $status) {
            if ($rank[$status->value] > $rank[$worst->value]) {
                $worst = $status;
            }
        }

        return $worst;
    }
}
