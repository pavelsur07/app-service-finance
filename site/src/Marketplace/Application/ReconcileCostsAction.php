<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Application\Reconciliation\OzonReportParserFacade;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\CostReconciliationQuery;
use App\Marketplace\Repository\MarketplaceMonthCloseRepository;
use App\Shared\Service\Storage\StorageService;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Use-case: загрузить xlsx и выполнить сверку затрат за период.
 *
 * Поток:
 *   1. Сохранить файл через StorageService
 *   2. Распарсить через OzonReportParserFacade
 *   3. Сверить с данными из marketplace_costs через CostReconciliationQuery
 *   4. Сохранить результат в MarketplaceMonthClose.settings
 */
final class ReconcileCostsAction
{
    public function __construct(
        private readonly OzonReportParserFacade          $parserFacade,
        private readonly CostReconciliationQuery         $reconciliationQuery,
        private readonly MarketplaceMonthCloseRepository $monthCloseRepository,
        private readonly StorageService                  $storageService,
    ) {
    }

    /**
     * @return array{status: string, delta: float, result: array<string, mixed>}
     * @throws \DomainException если период не найден
     */
    public function __invoke(
        string $companyId,
        string $marketplace,
        int $year,
        int $month,
        UploadedFile $file,
    ): array {
        $marketplaceType = MarketplaceType::from($marketplace);
        $periodFrom      = sprintf('%d-%02d-01', $year, $month);
        $periodTo        = (new \DateTimeImmutable($periodFrom))->modify('last day of this month')->format('Y-m-d');

        // 1. Сохранить файл
        $relativePath = sprintf(
            'marketplace/reconciliation/%s/%d-%02d/%s.xlsx',
            $marketplace,
            $year,
            $month,
            Uuid::uuid4()->toString(),
        );
        $stored = $this->storageService->storeUploadedFile($file, $relativePath);

        // 2. Распарсить xlsx
        $reportResult = $this->parserFacade->parseFromStoragePath($stored['storagePath']);

        // 3. Сверить с API данными
        $reconciliationResult = $this->reconciliationQuery->reconcile(
            $companyId,
            $marketplace,
            $periodFrom,
            $periodTo,
            $reportResult,
        );

        // 4. Найти и обновить MonthClose
        $monthClose = $this->monthCloseRepository->findByPeriod(
            $companyId, $marketplaceType, $year, $month,
        );

        if ($monthClose === null) {
            throw new \DomainException('Период не найден. Сначала закройте этап затрат.');
        }

        $monthClose->setCostsReconciliation(array_merge($reconciliationResult, [
            'file_path'         => $stored['storagePath'],
            'file_hash'         => $stored['fileHash'],
            'original_filename' => $stored['originalFilename'],
            'reconciled_at'     => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]));

        $this->monthCloseRepository->save($monthClose);

        return [
            'status' => $reconciliationResult['status'],
            'delta'  => $reconciliationResult['delta'],
            'result' => $reconciliationResult,
        ];
    }
}
