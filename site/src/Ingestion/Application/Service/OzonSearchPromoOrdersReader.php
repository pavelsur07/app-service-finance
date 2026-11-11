<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Service;

use App\Ingestion\Application\DTO\OzonSearchPromoOrderRow;
use App\Ingestion\Application\DTO\OzonSearchPromoOrders;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Repository\IngestRawRecordRepository;

/**
 * Сохранённые отчёты Ozon Performance по заказам «Оплаты за заказ» (`…:orders:…`) кабинета.
 *
 * Строка заказа — (ID заказа, SKU, Дата); при перекрытии окон берётся последняя загруженная версия.
 */
final readonly class OzonSearchPromoOrdersReader
{
    private const string ORDERS_EXTERNAL_ID_LIKE = 'performance-search-promo-stats:%:orders:%';

    public function __construct(
        private IngestRawRecordRepository $rawRecordRepository,
        private RawStorageFacade $rawStorageFacade,
    ) {
    }

    public function read(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): OzonSearchPromoOrders
    {
        $recordIds = $this->rawRecordRepository->findLatestIdsByCompanyAndResource(
            $companyId,
            OzonResourceType::PERFORMANCE_SEARCH_PROMO_STATISTICS,
            self::ORDERS_EXTERNAL_ID_LIKE,
        );

        // ponytail: читаются все отчёты кабинета с июня (сотни объектов); при росте — отбор окон по
        // датам из external_id.
        $orders = [];
        $unreadable = 0;
        foreach ($recordIds as $recordId) {
            foreach ($this->rawStorageFacade->read($recordId, $companyId) as $row) {
                $day = self::day($row['Дата'] ?? null);
                $expense = self::money($row['Расход, ₽'] ?? null);
                if (null === $day || null === $expense) {
                    ++$unreadable;
                    continue;
                }
                $sku = trim((string) ($row['SKU'] ?? ''));
                $orders[(string) ($row['ID заказа'] ?? '').'|'.$sku.'|'.$day] = new OzonSearchPromoOrderRow(
                    $day,
                    $sku,
                    trim((string) ($row['SKU продвигаемого товара'] ?? '')),
                    $expense,
                );
            }
        }

        $fromDay = $from->format('Y-m-d');
        $toDay = $to->format('Y-m-d');

        return new OzonSearchPromoOrders(
            array_values(array_filter(
                $orders,
                static fn (OzonSearchPromoOrderRow $order): bool => $order->day >= $fromDay && $order->day <= $toDay,
            )),
            count($recordIds),
            $unreadable,
        );
    }

    private static function day(mixed $value): ?string
    {
        $value = trim((string) $value);
        foreach (['!d.m.Y', '!Y-m-d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if (false !== $date && $date->format(str_replace('!', '', $format)) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    /**
     * @return numeric-string|null
     */
    private static function money(mixed $value): ?string
    {
        $value = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim((string) $value));

        return is_numeric($value) ? bcadd($value, '0', 2) : null;
    }
}
