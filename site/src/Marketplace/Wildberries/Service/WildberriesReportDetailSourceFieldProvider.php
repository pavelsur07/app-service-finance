<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Service;

use App\Marketplace\Wildberries\Entity\WildberriesReportDetail;

final class WildberriesReportDetailSourceFieldProvider
{
    /**
     * @return array<string, callable(WildberriesReportDetail): string|int|float|null>
     */
    private function getFields(): array
    {
        return [
            // Цена за единицу
            'retail_price' => static fn (WildberriesReportDetail $row) => $row->getRetailPrice(),
            // Сумма реализации (берём из raw)
            'retail_amount' => static fn (WildberriesReportDetail $row) => $row->getRaw()['retail_amount'] ?? null,
            // Компенсация скидки (кэшбэк)
            'cashback_amount' => static fn (WildberriesReportDetail $row) => $row->getRaw()['cashback_amount'] ?? null,
            // Эквайринг / комиссии за платежи
            'acquiring_fee' => static fn (WildberriesReportDetail $row) => $row->getAcquiringFee(),
            // Цена продажи с учётом скидки
            'retailPriceWithDiscRub' => static fn (WildberriesReportDetail $row) => $row->getRetailPriceWithDiscRub(),
            // Стоимость доставки
            'deliveryRub' => static fn (WildberriesReportDetail $row) => $row->getDeliveryRub(),
            // Плата за хранение
            'storageFee' => static fn (WildberriesReportDetail $row) => $row->getStorageFee(),
            // Штрафы
            'penalty' => static fn (WildberriesReportDetail $row) => $row->getPenalty(),
            // Прочие удержания
            'deduction' => static fn (WildberriesReportDetail $row) => $row->getRaw()['deduction'] ?? null,
            // Эквайринг (альтернативное поле)
            'acquiringFee' => static fn (WildberriesReportDetail $row) => $row->getAcquiringFee(),
            // Вознаграждение за ПВЗ
            'ppvz_reward' => static fn (WildberriesReportDetail $row) => $row->getRaw()['ppvz_reward'] ?? null,
            // Сумма к выплате продавцу
            'ppvz_for_pay' => static fn (WildberriesReportDetail $row) => $row->getRaw()['ppvz_for_pay'] ?? null,
            // Возмещение логистики/склада
            'rebill_logistic_cost' => static fn (WildberriesReportDetail $row) => $row->getRaw()['rebill_logistic_cost'] ?? null,
        ];
    }

    /**
     * @return string[]
     */
    public function getOptions(): array
    {
        return array_keys($this->getFields());
    }

    public function resolveValue(WildberriesReportDetail $row, string $sourceField): ?float
    {
        $fields = $this->getFields();
        $resolver = $fields[$sourceField] ?? null;

        if (null === $resolver) {
            return null;
        }

        $value = $resolver($row);

        if (null === $value) {
            return null;
        }

        return (float) $value;
    }
}
