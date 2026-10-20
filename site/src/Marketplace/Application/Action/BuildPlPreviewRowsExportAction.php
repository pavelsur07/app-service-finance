<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Action;

use App\Marketplace\Application\Command\BuildMarketplacePlPreviewCommand;
use App\Marketplace\Application\DTO\MarketplacePlPreviewReport;
use App\Marketplace\Application\DTO\PlPreviewRowsExport;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceConnectionRepository;

/**
 * Предпросмотр ОПиУ по каналам для выгрузки строками. Расчёт — тот же BuildMarketplacePlPreviewAction,
 * что у экрана и широкой выгрузки; здесь только выбор каналов.
 *
 * Канал не задан — каналы с SELLER-подключением и каналы с данными периода (затраты, продажи,
 * реклама общего отчёта): данные без подключения не теряются, подключение без данных даёт нулевой
 * канал. Общий отчёт остаётся в результате для контроля суммы каналов.
 */
final readonly class BuildPlPreviewRowsExportAction
{
    public function __construct(
        private BuildMarketplacePlPreviewAction $buildPreview,
        private MarketplaceConnectionRepository $connections,
    ) {
    }

    public function __invoke(BuildMarketplacePlPreviewCommand $command): PlPreviewRowsExport
    {
        if (null !== $command->marketplace) {
            $report = ($this->buildPreview)($command);

            return new PlPreviewRowsExport($report->from, $report->to, [$report], null);
        }

        $all = ($this->buildPreview)($command);

        $channels = [];
        foreach ($this->marketplaces($command->companyId, $all) as $marketplace) {
            $channels[] = ($this->buildPreview)(new BuildMarketplacePlPreviewCommand(
                $command->companyId,
                $command->from,
                $command->to,
                $marketplace,
                $command->tagIds,
            ));
        }

        return new PlPreviewRowsExport($all->from, $all->to, $channels, $all);
    }

    /**
     * @return list<MarketplaceType> по значению канала
     */
    private function marketplaces(string $companyId, MarketplacePlPreviewReport $all): array
    {
        $marketplaces = [];
        foreach ([...$all->costCoverage, ...$all->salesCoverage, ...$all->adReconciliation] as $row) {
            $marketplaces[$row->marketplace->value] = $row->marketplace;
        }
        foreach (MarketplaceType::cases() as $marketplace) {
            if (!isset($marketplaces[$marketplace->value])
                && null !== $this->connections->findByCompanyIdAndMarketplace($companyId, $marketplace, MarketplaceConnectionType::SELLER)) {
                $marketplaces[$marketplace->value] = $marketplace;
            }
        }
        ksort($marketplaces);

        return array_values($marketplaces);
    }
}
