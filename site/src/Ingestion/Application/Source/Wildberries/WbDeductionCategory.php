<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Wildberries;

final readonly class WbDeductionCategory
{
    private function __construct(
        public string $code,
        public string $label,
        public string $group,
    ) {
    }

    public static function resolve(?string $reason): ?self
    {
        // Keep this resolver aligned with WbCostCategory::forDeductionName();
        // WbCostCategoryTest::knownDeductionReasonProvider guards cross-pipeline parity.
        $reason = self::normalize($reason);
        if (null === $reason) {
            return null;
        }

        if (str_starts_with($reason, 'добровольная выплата за товары')) {
            return new self(
                code: 'wb_dobrovolnaya_vyplata_za_tovary',
                label: 'Добровольная выплата за товары',
                group: 'Компенсации и декомпенсации',
            );
        }

        if (str_starts_with($reason, 'отчет об утилизированном товаре')) {
            return new self(
                code: 'wb_warehouse_disposal',
                label: 'Утилизация товара на складе WB',
                group: 'Другие услуги и штрафы',
            );
        }

        return null;
    }

    private static function normalize(?string $reason): ?string
    {
        $reason = trim((string) $reason);
        if ('' === $reason) {
            return null;
        }

        $reason = str_replace('ё', 'е', mb_strtolower($reason));

        return preg_replace('/\s+/u', ' ', $reason) ?? $reason;
    }
}
