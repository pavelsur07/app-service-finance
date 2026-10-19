<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Enum\IngestSource;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Webmozart\Assert\Assert;

/**
 * Начисления by-day компании за период — индекс для связи строк отчёта кабинета
 * Ozon с типами начислений. Компоненты без `type_id` (продажи, комиссия) тоже
 * учитываются: ключ, на котором они совпали с услугой, неоднозначен, а ключ из
 * одних таких компонентов голоса не даёт.
 *
 * Агрегируется в SQL и только по суммам, которые есть в отчёте: у крупного
 * кабинета ~180 тыс. строк by-day в месяц, тянуть их в PHP поштучно незачем.
 */
final readonly class OzonAccrualByDayTypeLookupQuery
{
    private const AMOUNT_CHUNK = 5000;

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param list<int> $amountsMinor модули сумм из отчёта
     */
    public function build(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, array $amountsMinor): OzonAccrualTypeIndex
    {
        Assert::uuid($companyId);
        Assert::false($to < $from, 'Lookup period end is before its start.');

        $index = new OzonAccrualTypeIndex();
        $amounts = array_values(array_unique(array_map('abs', $amountsMinor)));

        foreach (array_chunk($amounts, self::AMOUNT_CHUNK) as $chunk) {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT TO_CHAR(t.occurred_at, 'YYYY-MM-DD') AS d,
                        COALESCE(t.source_data->>'sku', '') AS sku,
                        ABS(t.amount_minor) AS amount,
                        STRING_AGG(DISTINCT t.source_data->>'_ingestion_type_id', ',')
                            FILTER (WHERE t.source_data->>'_ingestion_type_id' ~ '^[0-9]+$') AS type_ids,
                        BOOL_OR(COALESCE(t.source_data->>'_ingestion_type_id' ~ '^[0-9]+$', FALSE) IS FALSE) AS has_untyped
                 FROM ingest_financial_transactions t
                 WHERE t.company_id = :companyId
                   AND t.source = :source
                   AND t.source_data->>'_ingestion_resource' = :resource
                   AND t.occurred_at >= :from
                   AND t.occurred_at < :toExclusive
                   AND t.amount_minor <> 0
                   AND ABS(t.amount_minor) IN (:amounts)
                 GROUP BY 1, 2, 3",
                [
                    'companyId' => $companyId,
                    'source' => IngestSource::OZON->value,
                    'resource' => OzonResourceType::ACCRUAL_BY_DAY,
                    'from' => $from->format('Y-m-d 00:00:00'),
                    'toExclusive' => $to->modify('+1 day')->format('Y-m-d 00:00:00'),
                    'amounts' => $chunk,
                ],
                ['amounts' => ArrayParameterType::INTEGER],
            );

            foreach ($rows as $row) {
                $typeIds = null !== $row['type_ids'] && '' !== $row['type_ids']
                    ? array_map('intval', explode(',', (string) $row['type_ids']))
                    : [];
                if (true === $row['has_untyped'] || 't' === $row['has_untyped']) {
                    $typeIds[] = OzonAccrualTypeIndex::UNTYPED;
                }
                $date = (string) $row['d'];
                $amount = (int) $row['amount'];
                if ('' !== $row['sku']) {
                    $index->addSku($date, (string) $row['sku'], $amount, $typeIds);
                }
                $index->addAmount($date, $amount, $typeIds);
            }
        }

        return $index;
    }
}
