<?php

declare(strict_types=1);

namespace App\Enum;

enum DocumentType: string
{
    case SALES = 'SALES';             // Сделки — реализация
    case PAYROLL = 'PAYROLL';         // Зарплаты
    case LIABILITIES = 'LIABILITIES'; // Обязательства
    case TAXES = 'TAXES';             // Налоги
    case PROPERTY = 'PROPERTY';       // Имущество
    case LOANS = 'LOANS';             // Кредиты
    case OTHER = 'OTHER';             // Прочее

    public static function fromValue(string $value): self
    {
        $normalized = strtoupper(trim($value));

        $enum = self::tryFrom($normalized);
        if (null !== $enum) {
            return $enum;
        }

        return match ($normalized) {
            'SERVICE_ACT' => self::SALES,
            default => throw new \ValueError(sprintf('"%s" is not a valid backing value for enum %s', $value, self::class)),
        };
    }

    public static function fromLegacy(string $value): self
    {
        $v = strtoupper(trim($value));

        return match ($v) {
            'НАКЛАДНАЯ', 'ТОРГ-12', 'УПД', 'СЧЕТ-ФАКТУРА', 'РЕАЛИЗАЦИЯ', 'ЧЕК', 'ККТ',
            'АКТ', 'АКТ ВЫПОЛНЕННЫХ РАБОТ', 'АКТ ОКАЗАННЫХ УСЛУГ', 'SERVICE_ACT',
            'ОТЧЕТ КОМИССИОНЕРА', 'ОТЧЕТ АГЕНТА', 'ОТЧЕТ МАРКЕТПЛЕЙСА', 'WB', 'OZON', 'YANDEX',
            'ВОЗВРАТ ОТ ПОКУПАТЕЛЯ', 'ВОЗВРАТ ПОСТАВЩИКУ', 'ВОЗВРАТ'
                => self::SALES,
            'ВЕДОМОСТЬ ЗП', 'ЗАРПЛАТА', 'НАЧИСЛЕНИЕ ЗАРПЛАТЫ'
                => self::PAYROLL,
            'СЧЕТ ПОСТАВЩИКА', 'СФ ПОСТАВЩИКА',
            'КУРСОВЫЕ РАЗНИЦЫ', 'ШТРАФЫ', 'ПЕНИ'
                => self::LIABILITIES,
            'НАЛОГИ', 'ВЗНОСЫ', 'НАЧИСЛЕНИЕ НАЛОГОВ', 'НАЧИСЛЕНИЕ ВЗНОСОВ'
                => self::TAXES,
            'АКТ ПОДРЯДЧИКА', 'ПОШИВ', 'АКТ ПРИЕМКИ-ПЕРЕДАЧИ',
            'СПИСАНИЕ МАТЕРИАЛОВ', 'АКТ СПИСАНИЯ',
            'ИНВЕНТАРИЗАЦИЯ', 'ИНВЕНТАРИЗАЦИОННАЯ ОПИСЬ',
            'АМОРТИЗАЦИЯ', 'АМОРТИЗАЦИЯ ОС'
                => self::PROPERTY,
            'ПРОЦЕНТЫ ПО КРЕДИТУ', 'КРЕДИТНЫЙ ДОГОВОР', 'ГРАФИК ПЛАТЕЖЕЙ'
                => self::LOANS,
            default => self::OTHER,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::SALES => 'Сделки - реализация',
            self::PAYROLL => 'Зарплаты',
            self::LIABILITIES => 'Обязательства',
            self::TAXES => 'Налоги',
            self::PROPERTY => 'Имущество',
            self::LOANS => 'Кредиты',
            self::OTHER => 'Прочее',
        };
    }

    /**
     * @return array<string, self>
     */
    public static function choices(): array
    {
        return [
            self::SALES->label() => self::SALES,
            self::PAYROLL->label() => self::PAYROLL,
            self::LIABILITIES->label() => self::LIABILITIES,
            self::TAXES->label() => self::TAXES,
            self::PROPERTY->label() => self::PROPERTY,
            self::LOANS->label() => self::LOANS,
            self::OTHER->label() => self::OTHER,
        ];
    }
}
