<?php

declare(strict_types=1);

namespace App\Enum;

enum DocumentType: string
{
    // Доходы
    case SALES_INVOICE = 'SALES_INVOICE';            // Счет-фактура/реализация
    case DELIVERY_NOTE = 'DELIVERY_NOTE';            // Накладная / УПД
    case SERVICE_ACT = 'SERVICE_ACT';                // Акт выполненных работ
    case COMMISSION_REPORT = 'COMMISSION_REPORT';    // Отчет комиссионера/агента
    case MARKETPLACE_REPORT = 'MARKETPLACE_REPORT';  // Отчет маркетплейса
    case CASH_RECEIPT = 'CASH_RECEIPT';              // ККТ чек (прямые продажи)

    // Закупки/COGS
    case SUPPLIER_INVOICE = 'SUPPLIER_INVOICE';      // Счет/сф от поставщика
    case MATERIAL_WRITE_OFF_ACT = 'MATERIAL_WRITE_OFF_ACT';
    case MANUFACTURING_ACT = 'MANUFACTURING_ACT';    // Акт подрядчика (пошив)
    case COST_ALLOCATION = 'COST_ALLOCATION';        // Калькуляция/распределение

    // OPEX
    case AD_ACT = 'AD_ACT';                          // Реклама/инфлюенсеры
    case RENT_ACT = 'RENT_ACT';
    case UTILITIES_ACT = 'UTILITIES_ACT';            // Коммуналка/связь/интернет
    case BANK_FEES_ACT = 'BANK_FEES_ACT';            // Банковские комиссии
    case PAYROLL_SHEET = 'PAYROLL_SHEET';
    case ADVANCE_REPORT = 'ADVANCE_REPORT';

    // Финансовые
    case BANK_STATEMENT = 'BANK_STATEMENT';
    case LOAN_INTEREST_STATEMENT = 'LOAN_INTEREST_STATEMENT';
    case FX_REVALUATION_ACT = 'FX_REVALUATION_ACT';

    // Прочее
    case OTHER = 'OTHER';

    public static function fromLegacy(string $value): self
    {
        $v = strtoupper(trim($value));

        return match ($v) {
            'НАКЛАДНАЯ', 'ТОРГ-12', 'УПД' => self::DELIVERY_NOTE,
            'ОТЧЕТ КОМИССИОНЕРА', 'ОТЧЕТ АГЕНТА' => self::COMMISSION_REPORT,
            'ОТЧЕТ МАРКЕТПЛЕЙСА', 'WB', 'OZON', 'YANDEX' => self::MARKETPLACE_REPORT,
            'АКТ', 'АКТ ВЫПОЛНЕННЫХ РАБОТ' => self::SERVICE_ACT,
            'СЧЕТ-ФАКТУРА', 'РЕАЛИЗАЦИЯ' => self::SALES_INVOICE,
            'СЧЕТ ПОСТАВЩИКА', 'СФ ПОСТАВЩИКА' => self::SUPPLIER_INVOICE,
            'АКТ ПОДРЯДЧИКА', 'ПОШИВ' => self::MANUFACTURING_ACT,
            'СПИСАНИЕ МАТЕРИАЛОВ' => self::MATERIAL_WRITE_OFF_ACT,
            'КАЛЬКУЛЯЦИЯ', 'РАСПРЕДЕЛЕНИЕ ЗАТРАТ' => self::COST_ALLOCATION,
            'РЕКЛАМА', 'АКТ РЕКЛАМЫ' => self::AD_ACT,
            'АРЕНДА' => self::RENT_ACT,
            'КОММУНАЛЬНЫЕ', 'СВЯЗЬ', 'ИНТЕРНЕТ' => self::UTILITIES_ACT,
            'КОМИССИЯ БАНКА' => self::BANK_FEES_ACT,
            'ВЕДОМОСТЬ ЗП', 'ЗАРПЛАТА' => self::PAYROLL_SHEET,
            'АВАНСОВЫЙ ОТЧЕТ' => self::ADVANCE_REPORT,
            'ВЫПИСКА БАНКА' => self::BANK_STATEMENT,
            'ПРОЦЕНТЫ ПО КРЕДИТУ' => self::LOAN_INTEREST_STATEMENT,
            'КУРСОВЫЕ РАЗНИЦЫ' => self::FX_REVALUATION_ACT,
            'ЧЕК', 'ККТ' => self::CASH_RECEIPT,
            default => self::OTHER,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::SALES_INVOICE => 'Счет-фактура / Реализация',
            self::DELIVERY_NOTE => 'Накладная / УПД',
            self::SERVICE_ACT => 'Акт выполненных работ',
            self::COMMISSION_REPORT => 'Отчет комиссионера / агента',
            self::MARKETPLACE_REPORT => 'Отчет маркетплейса',
            self::CASH_RECEIPT => 'ККТ чек (прямые продажи)',
            self::SUPPLIER_INVOICE => 'Счет поставщика',
            self::MATERIAL_WRITE_OFF_ACT => 'Списание материалов',
            self::MANUFACTURING_ACT => 'Акт подрядчика (пошив)',
            self::COST_ALLOCATION => 'Калькуляция / распределение',
            self::AD_ACT => 'Реклама / инфлюенсеры',
            self::RENT_ACT => 'Аренда',
            self::UTILITIES_ACT => 'Коммунальные / связь / интернет',
            self::BANK_FEES_ACT => 'Банковские комиссии',
            self::PAYROLL_SHEET => 'Ведомость зарплаты',
            self::ADVANCE_REPORT => 'Авансовый отчет',
            self::BANK_STATEMENT => 'Выписка банка',
            self::LOAN_INTEREST_STATEMENT => 'Проценты по кредиту',
            self::FX_REVALUATION_ACT => 'Курсовые разницы',
            self::OTHER => 'Прочее',
        };
    }

    /**
     * @return array<string, self>
     */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) {
            $choices[$case->label()] = $case;
        }

        return $choices;
    }
}
