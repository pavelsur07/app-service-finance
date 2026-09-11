<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Company\Facade\CompanyFacade;
use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\UnlinkDocumentRowsQuery;
use App\Marketplace\Repository\MarketplaceMonthCloseRepository;
use Psr\Log\LoggerInterface;

/**
 * Решает, можно ли заменить строки перезагруженного дня, и готовит их к замене.
 *
 * Зачем. Скользящее окно by-day существует ради правок Ozon задним числом, а
 * замена не трогает строки с проставленным `document_id`. Для окончательно
 * закрытого периода это и требуется. Но текущий месяц закрывается предварительно
 * каждую ночь, и к утру его строки тоже несут `document_id`: замер на проде
 * 11.09.2026 показал, что из 915 продаж за 08.09 привязано 787, из 4710 затрат —
 * 1702. Правка по ним не доезжала вовсе.
 *
 * Две границы, обе жёсткие:
 *
 * 1. Заблокированный период (`Company::financeLockBefore`) не меняется вообще:
 *    ни привязка не снимается, ни строки не удаляются. Ту же границу держит
 *    `ReopenMonthStageAction`, и обойти её замена не вправе.
 * 2. Привязка снимается только у ПРЕДВАРИТЕЛЬНО закрытых этапов —
 *    `settings.last_close_was_preliminary[stage]`. Флаг ведётся per-stage ровно
 *    затем, чтобы предварительность одного этапа не разблокировала финальное
 *    закрытие соседнего в том же месяце.
 *
 * Сам документ ОПиУ не трогается и остаётся со своими цифрами до ночного
 * пересбора (`app:marketplace:month-preliminary-rebuild`). Размен осознанный:
 * предварительный отчёт расходится с источником до ближайшего пересбора, зато
 * перестаёт расходиться с Ozon навсегда.
 */
final readonly class ByDayRowReplacement
{
    public function __construct(
        private MarketplaceMonthCloseRepository $monthCloseRepository,
        private CompanyFacade $companyFacade,
        private UnlinkDocumentRowsQuery $unlinkQuery,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Продажи и возвраты закрываются одним этапом, затраты — другим, и
     * предварительность у них своя: поэтому методы разные, а не один с
     * параметром-таблицей.
     *
     * @return bool можно ли заменять строки этого дня
     */
    public function prepareSales(string $companyId, MarketplaceType $marketplace, \DateTimeImmutable $day, string $rawDocId): bool
    {
        return $this->prepare('marketplace_sales', $companyId, $marketplace, $day, $rawDocId, CloseStage::SALES_RETURNS);
    }

    public function prepareReturns(string $companyId, MarketplaceType $marketplace, \DateTimeImmutable $day, string $rawDocId): bool
    {
        return $this->prepare('marketplace_returns', $companyId, $marketplace, $day, $rawDocId, CloseStage::SALES_RETURNS);
    }

    public function prepareCosts(string $companyId, MarketplaceType $marketplace, \DateTimeImmutable $day, string $rawDocId): bool
    {
        return $this->prepare('marketplace_costs', $companyId, $marketplace, $day, $rawDocId, CloseStage::COSTS);
    }

    private function prepare(
        string $table,
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $day,
        string $rawDocId,
        CloseStage $stage,
    ): bool {
        if ($this->isDayLocked($companyId, $day)) {
            $this->logger->warning('[Ozon by-day] replacement skipped: finance period is locked', [
                'company_id' => $companyId,
                'raw_document_id' => $rawDocId,
                'day' => $day->format('Y-m-d'),
            ]);

            return false;
        }

        $monthClose = $this->monthCloseRepository->findByPeriod(
            $companyId,
            $marketplace,
            (int) $day->format('Y'),
            (int) $day->format('n'),
        );

        if (null === $monthClose || !$monthClose->isStageLastCloseWasPreliminary($stage)) {
            return true;
        }

        // array_values: список приходит из JSON-поля, и его ключи не обязаны
        // быть последовательными.
        $documentIds = array_values($monthClose->getStagePLDocumentIds($stage));
        if ([] === $documentIds) {
            return true;
        }

        $unlinked = $this->unlinkQuery->execute($table, $companyId, $rawDocId, $documentIds);

        if ($unlinked > 0) {
            $this->logger->info('[Ozon by-day] preliminary rows unlinked before replacement', [
                'company_id' => $companyId,
                'raw_document_id' => $rawDocId,
                'stage' => $stage->value,
                'rows' => $unlinked,
            ]);
        }

        return true;
    }

    private function isDayLocked(string $companyId, \DateTimeImmutable $day): bool
    {
        $lockBefore = $this->companyFacade->findById($companyId)?->getFinanceLockBefore();

        return null !== $lockBefore && $day <= $lockBefore;
    }
}
