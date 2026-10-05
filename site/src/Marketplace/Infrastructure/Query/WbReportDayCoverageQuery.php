<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\Enum\FinancialReportSyncStatus;
use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\DBAL\Connection;

/**
 * Данные для проверки готовности WB-дней перед закрытием месяца (только чтение).
 *
 * Все выборки ограничены компанией и периодом. Подключение в ключ не входит: на день
 * существует ровно одна строка статуса (уникальный индекс company+marketplace+report_type+
 * business_date), поэтому замена кабинета не создаёт второй строки на тот же день.
 */
final class WbReportDayCoverageQuery
{
    public const REPORT_TYPE = 'sales_report';

    /**
     * Последний день, за который источником данных WB был легаси-формат reportDetailByPeriod
     * (MarketplaceRawFormat: «документы до 14.05.2026»). Позже такой документ день не покрывает.
     */
    public const LEGACY_FORMAT_LAST_DAY = '2026-05-14';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<string, FinancialReportSyncStatus> ключ — business_date в формате Y-m-d
     */
    public function statusesByDay(string $companyId, string $periodFrom, string $periodTo): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT business_date, status
             FROM marketplace_financial_report_sync_statuses
             WHERE company_id = :companyId
               AND marketplace = :marketplace
               AND report_type = :reportType
               AND business_date BETWEEN :periodFrom AND :periodTo',
            [
                'companyId' => $companyId,
                'marketplace' => MarketplaceType::WILDBERRIES->value,
                'reportType' => self::REPORT_TYPE,
                'periodFrom' => $periodFrom,
                'periodTo' => $periodTo,
            ],
        );

        $result = [];
        foreach ($rows as $row) {
            $status = FinancialReportSyncStatus::tryFrom((string) $row['status']);
            if (null === $status) {
                continue;
            }

            $result[(string) $row['business_date']] = $status;
        }

        return $result;
    }

    /**
     * Завершённые документы легаси-формата reportDetailByPeriod, пересекающие период.
     * До 14.05.2026 именно они были источником данных WB за периоды; статусы подневного
     * загрузчика за те даты отражают только его неудачные попытки (429), а не отсутствие данных.
     *
     * @return list<array{0: string, 1: string}> пары [period_from, period_to] в формате Y-m-d
     */
    public function legacyCompletedPeriods(string $companyId, string $periodFrom, string $periodTo): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT period_from, period_to
             FROM marketplace_raw_documents
             WHERE company_id = :companyId
               AND marketplace = :marketplace
               AND document_type = :documentType
               AND api_endpoint = :apiEndpoint
               AND processing_status = :completed
               AND period_from <= :periodTo
               AND period_to >= :periodFrom
               AND period_to <= :legacyLastDay',
            [
                'companyId' => $companyId,
                'marketplace' => MarketplaceType::WILDBERRIES->value,
                'documentType' => self::REPORT_TYPE,
                'apiEndpoint' => MarketplaceRawFormat::WB_REPORT_DETAIL_BY_PERIOD->value,
                'completed' => 'completed',
                'periodFrom' => $periodFrom,
                'periodTo' => $periodTo,
                'legacyLastDay' => self::LEGACY_FORMAT_LAST_DAY,
            ],
        );

        return array_map(
            static fn (array $row): array => [(string) $row['period_from'], (string) $row['period_to']],
            $rows,
        );
    }
}
