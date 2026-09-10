<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Normalizer\Ozon;

use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Infrastructure\Normalizer\Contract\RowClassifierInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Разбор начислений Ozon из /v1/finance/accrual/by-day по корзинам.
 *
 * Строка by-day — это начисление, а не операция: одно начисление POSTING несёт
 * и выручку, и комиссию, и услуги доставки. Поэтому здесь решается только
 * вопрос «продажа или возврат»; затраты читаются из документа целиком
 * отдельным шагом, как это уже сделано для легаси-формата.
 *
 * Правила выведены из выгрузки за июнь 2026 и подтверждены сверкой с месячным
 * отчётом «Реализация»: суммы сошлись до копейки.
 */
#[AutoconfigureTag('marketplace.row_classifier')]
final readonly class OzonAccrualByDayRowClassifier implements RowClassifierInterface
{
    private const CATEGORY_POSTING = 'POSTING';

    public function supports(MarketplaceType $type, ?MarketplaceRawFormat $format = null): bool
    {
        return MarketplaceType::OZON === $type
            && MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY === $format;
    }

    public function classify(array $rawRow): StagingRecordType
    {
        if (self::CATEGORY_POSTING !== ($rawRow['accrued_category'] ?? null)) {
            // ITEM и NON_ITEM — начисления по услугам, выручки в них нет.
            return StagingRecordType::OTHER;
        }

        $saleAmount = $this->saleAmount($rawRow);

        if (null === $saleAmount) {
            // Отправление без выручки: только логистика и услуги. В июньской
            // выгрузке таких 191 товар из 234 — продажу по ним заводить нельзя.
            return StagingRecordType::OTHER;
        }

        // Признак возврата — знак sale_amount. Знак вычисленного количества не
        // годится: у возврата отрицательны и sale_amount, и seller_price, и их
        // частное даёт +1.
        return $saleAmount < 0 ? StagingRecordType::RETURN : StagingRecordType::SALE;
    }

    /**
     * @param array<string, mixed> $rawRow
     */
    private function saleAmount(array $rawRow): ?float
    {
        $products = $rawRow['posting']['products'] ?? null;

        if (!is_array($products)) {
            return null;
        }

        foreach ($products as $product) {
            $amount = $product['commission']['sale_amount']['amount'] ?? null;

            if (is_numeric($amount)) {
                return (float) $amount;
            }
        }

        return null;
    }
}
