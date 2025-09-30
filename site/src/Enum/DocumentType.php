<?php

declare(strict_types=1);

namespace App\Enum;

enum DocumentType: string
{
    // Доходы / реализация
    case SERVICE_ACT = 'SERVICE_ACT';                       // Акт выполненных работ / оказанных услуг
    case SALES_DELIVERY_NOTE = 'SALES_DELIVERY_NOTE';       // Товарная накладная (реализация)
    case COMMISSION_REPORT = 'COMMISSION_REPORT';           // Отчёт комиссионера / агента

    // Закупки / COGS / склад
    case PURCHASE_INVOICE = 'PURCHASE_INVOICE';             // Накладная от поставщика (закупка)
    case ACCEPTANCE_ACT = 'ACCEPTANCE_ACT';                 // Акт приёмки-передачи
    case WRITE_OFF_ACT = 'WRITE_OFF_ACT';                   // Акт списания
    case INVENTORY_SHEET = 'INVENTORY_SHEET';               // Инвентаризационная опись

    // Финансовые / операционные / прочие
    case LOAN_AND_SCHEDULE = 'LOAN_AND_SCHEDULE';           // Кредитный договор / график платежей
    case PAYROLL_ACCRUAL = 'PAYROLL_ACCRUAL';               // Начисление заработной платы
    case DEPRECIATION = 'DEPRECIATION';                     // Амортизация ОС
    case TAXES_AND_CONTRIBUTIONS = 'TAXES_AND_CONTRIBUTIONS'; // Начисление налогов и взносов
    case FX_PENALTIES = 'FX_PENALTIES';                     // Курсовые разницы, штрафы, пени
    case SALES_OR_PURCHASE_RETURN = 'SALES_OR_PURCHASE_RETURN'; // Возврат от покупателя / поставщику

    /** @deprecated Только для обратной совместимости со старыми записями. Не использовать в новом коде и не показывать в UI. */
    case OTHER = 'OTHER';

    public static function fromLegacy(string $value): self
    {
        $v = strtoupper(trim($value));

        return match ($v) {
            'НАКЛАДНАЯ', 'ТОРГ-12', 'УПД', 'СЧЕТ-ФАКТУРА', 'РЕАЛИЗАЦИЯ', 'ЧЕК', 'ККТ' => self::SALES_DELIVERY_NOTE,
            'АКТ', 'АКТ ВЫПОЛНЕННЫХ РАБОТ', 'АКТ ОКАЗАННЫХ УСЛУГ' => self::SERVICE_ACT,
            'ОТЧЕТ КОМИССИОНЕРА', 'ОТЧЕТ АГЕНТА', 'ОТЧЕТ МАРКЕТПЛЕЙСА', 'WB', 'OZON', 'YANDEX' => self::COMMISSION_REPORT,
            'СЧЕТ ПОСТАВЩИКА', 'СФ ПОСТАВЩИКА' => self::PURCHASE_INVOICE,
            'АКТ ПОДРЯДЧИКА', 'ПОШИВ', 'АКТ ПРИЕМКИ-ПЕРЕДАЧИ' => self::ACCEPTANCE_ACT,
            'СПИСАНИЕ МАТЕРИАЛОВ', 'АКТ СПИСАНИЯ' => self::WRITE_OFF_ACT,
            'ИНВЕНТАРИЗАЦИЯ', 'ИНВЕНТАРИЗАЦИОННАЯ ОПИСЬ' => self::INVENTORY_SHEET,
            'ПРОЦЕНТЫ ПО КРЕДИТУ', 'КРЕДИТНЫЙ ДОГОВОР', 'ГРАФИК ПЛАТЕЖЕЙ' => self::LOAN_AND_SCHEDULE,
            'ВЕДОМОСТЬ ЗП', 'ЗАРПЛАТА', 'НАЧИСЛЕНИЕ ЗАРПЛАТЫ' => self::PAYROLL_ACCRUAL,
            'АМОРТИЗАЦИЯ', 'АМОРТИЗАЦИЯ ОС' => self::DEPRECIATION,
            'НАЛОГИ', 'ВЗНОСЫ', 'НАЧИСЛЕНИЕ НАЛОГОВ', 'НАЧИСЛЕНИЕ ВЗНОСОВ' => self::TAXES_AND_CONTRIBUTIONS,
            'КУРСОВЫЕ РАЗНИЦЫ', 'ШТРАФЫ', 'ПЕНИ' => self::FX_PENALTIES,
            'ВОЗВРАТ ОТ ПОКУПАТЕЛЯ', 'ВОЗВРАТ ПОСТАВЩИКУ', 'ВОЗВРАТ' => self::SALES_OR_PURCHASE_RETURN,
            default => self::OTHER,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::SERVICE_ACT => 'Акт выполненных работ / оказанных услуг',
            self::SALES_DELIVERY_NOTE => 'Товарная накладная (реализация)',
            self::COMMISSION_REPORT => 'Отчёт комиссионера / агента',
            self::PURCHASE_INVOICE => 'Накладная от поставщика (закупка)',
            self::ACCEPTANCE_ACT => 'Акт приёмки-передачи',
            self::WRITE_OFF_ACT => 'Акт списания',
            self::INVENTORY_SHEET => 'Инвентаризационная опись',
            self::LOAN_AND_SCHEDULE => 'Кредитный договор / график платежей',
            self::PAYROLL_ACCRUAL => 'Начисление заработной платы',
            self::DEPRECIATION => 'Амортизация ОС',
            self::TAXES_AND_CONTRIBUTIONS => 'Начисление налогов и взносов',
            self::FX_PENALTIES => 'Курсовые разницы, штрафы, пени',
            self::SALES_OR_PURCHASE_RETURN => 'Возврат от покупателя / поставщику',
            self::OTHER => 'Прочее',
        };
    }

    /**
     * @return array<string, self>
     */
    public static function choices(): array
    {
        return [
            self::SERVICE_ACT->label() => self::SERVICE_ACT,
            self::SALES_DELIVERY_NOTE->label() => self::SALES_DELIVERY_NOTE,
            self::COMMISSION_REPORT->label() => self::COMMISSION_REPORT,
            self::PURCHASE_INVOICE->label() => self::PURCHASE_INVOICE,
            self::ACCEPTANCE_ACT->label() => self::ACCEPTANCE_ACT,
            self::WRITE_OFF_ACT->label() => self::WRITE_OFF_ACT,
            self::INVENTORY_SHEET->label() => self::INVENTORY_SHEET,
            self::LOAN_AND_SCHEDULE->label() => self::LOAN_AND_SCHEDULE,
            self::PAYROLL_ACCRUAL->label() => self::PAYROLL_ACCRUAL,
            self::DEPRECIATION->label() => self::DEPRECIATION,
            self::TAXES_AND_CONTRIBUTIONS->label() => self::TAXES_AND_CONTRIBUTIONS,
            self::FX_PENALTIES->label() => self::FX_PENALTIES,
            self::SALES_OR_PURCHASE_RETURN->label() => self::SALES_OR_PURCHASE_RETURN,
        ];
    }
}
