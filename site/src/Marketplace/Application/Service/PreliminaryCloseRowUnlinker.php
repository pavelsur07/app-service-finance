<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\UnlinkDocumentRowsQuery;
use App\Marketplace\Repository\MarketplaceMonthCloseRepository;
use Psr\Log\LoggerInterface;

/**
 * Снимает привязку к ОПиУ со строк raw-документа, закрытых ПРЕДВАРИТЕЛЬНО.
 *
 * Зачем. Скользящее окно by-day существует ради правок Ozon задним числом, а
 * замена строк документа не трогает те, у которых проставлен `document_id`. Для
 * окончательно закрытого периода это и требуется. Но текущий месяц закрывается
 * предварительно каждую ночь, и к утру его строки тоже несут `document_id`:
 * замер на проде 11.09.2026 показал, что из 915 продаж за 08.09 привязано 787,
 * то есть правка по ним не доехала бы вовсе, а перезалив починил бы только
 * непривязанное меньшинство.
 *
 * Что считается предварительным: этап, у которого
 * `settings.last_close_was_preliminary[stage]` истинно. Флаг ведётся per-stage
 * ровно затем, чтобы предварительность одного этапа не маскировала финальное
 * закрытие соседнего в том же месяце.
 *
 * Документ ОПиУ при этом не трогается и остаётся со своими цифрами до ночного
 * пересбора (`app:marketplace:month-preliminary-rebuild`), который переоткрывает
 * и закрывает предварительные этапы заново. Осознанный размен: предварительный
 * отчёт расходится с источником до ближайшего пересбора, зато перестаёт
 * расходиться с Ozon навсегда.
 */
final readonly class PreliminaryCloseRowUnlinker
{
    public function __construct(
        private MarketplaceMonthCloseRepository $monthCloseRepository,
        private UnlinkDocumentRowsQuery $unlinkQuery,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Продажи и возвраты закрываются одним этапом, затраты — другим, и
     * предварительность у них своя: поэтому методы разные, а не один с
     * параметром-таблицей.
     */
    public function unlinkSales(string $companyId, MarketplaceType $marketplace, \DateTimeImmutable $day, string $rawDocId): int
    {
        return $this->unlink('marketplace_sales', $companyId, $marketplace, $day, $rawDocId, CloseStage::SALES_RETURNS);
    }

    public function unlinkReturns(string $companyId, MarketplaceType $marketplace, \DateTimeImmutable $day, string $rawDocId): int
    {
        return $this->unlink('marketplace_returns', $companyId, $marketplace, $day, $rawDocId, CloseStage::SALES_RETURNS);
    }

    public function unlinkCosts(string $companyId, MarketplaceType $marketplace, \DateTimeImmutable $day, string $rawDocId): int
    {
        return $this->unlink('marketplace_costs', $companyId, $marketplace, $day, $rawDocId, CloseStage::COSTS);
    }

    private function unlink(
        string $table,
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $day,
        string $rawDocId,
        CloseStage $stage,
    ): int {
        $monthClose = $this->monthCloseRepository->findByPeriod(
            $companyId,
            $marketplace,
            (int) $day->format('Y'),
            (int) $day->format('n'),
        );

        if (null === $monthClose || !$monthClose->isStageLastCloseWasPreliminary($stage)) {
            return 0;
        }

        // array_values: getStagePLDocumentIds отдаёт массив из JSON-поля, и его
        // ключи не обязаны быть последовательными.
        $documentIds = array_values($monthClose->getStagePLDocumentIds($stage));
        if ([] === $documentIds) {
            return 0;
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

        return $unlinked;
    }
}
