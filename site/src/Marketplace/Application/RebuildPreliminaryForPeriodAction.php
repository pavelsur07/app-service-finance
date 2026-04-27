<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Application\Command\CloseMonthStageCommand;
use App\Marketplace\Application\Command\PreflightMonthCloseCommand;
use App\Marketplace\Application\Command\RebuildPreliminaryForPeriodCommand;
use App\Marketplace\Application\Command\ReopenMonthStageCommand;
use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\MonthCloseStageStatus;
use App\Marketplace\Repository\MarketplaceMonthCloseRepository;
use Psr\Log\LoggerInterface;

/**
 * Оркестратор «Оперативного закрытия месяца» (предзакрытия).
 *
 * Для каждого этапа (sales_returns, costs):
 *   - если этап CLOSED и предыдущее закрытие было предварительным → переоткрыть;
 *   - если этап CLOSED финально (флаг last_close_was_preliminary=false) → пропустить;
 *   - если PENDING/REOPENED → продолжить;
 *   - запустить preflight: !canClose() → пропустить с warning;
 *   - вызвать CloseMonthStageAction(preliminary=true).
 *
 * Не использует ActiveCompanyService — companyId через Command.
 * Worker-safe: вызывается из Messenger Handler.
 */
final class RebuildPreliminaryForPeriodAction
{
    public function __construct(
        private readonly MarketplaceMonthCloseRepository $monthCloseRepository,
        private readonly ReopenMonthStageAction         $reopenAction,
        private readonly MonthClosePreflightAction      $preflightAction,
        private readonly CloseMonthStageAction          $closeAction,
        private readonly LoggerInterface                $logger,
    ) {
    }

    public function __invoke(RebuildPreliminaryForPeriodCommand $command): void
    {
        $marketplace = MarketplaceType::from($command->marketplace);

        $this->logger->info('[PreliminaryRebuild] Started', [
            'company_id'  => $command->companyId,
            'marketplace' => $command->marketplace,
            'year'        => $command->year,
            'month'       => $command->month,
        ]);

        foreach ([CloseStage::SALES_RETURNS, CloseStage::COSTS] as $stage) {
            $this->rebuildStage($command, $marketplace, $stage);
        }

        $this->logger->info('[PreliminaryRebuild] Finished', [
            'company_id'  => $command->companyId,
            'marketplace' => $command->marketplace,
            'year'        => $command->year,
            'month'       => $command->month,
        ]);
    }

    private function rebuildStage(
        RebuildPreliminaryForPeriodCommand $command,
        MarketplaceType $marketplace,
        CloseStage $stage,
    ): void {
        $monthClose    = $this->monthCloseRepository->findByPeriod(
            $command->companyId,
            $marketplace,
            $command->year,
            $command->month,
        );
        $stageStatus   = $monthClose?->getStageStatus($stage) ?? MonthCloseStageStatus::PENDING;
        $wasPreliminary = $monthClose?->isLastCloseWasPreliminary() ?? false;

        // Если этап закрыт и последнее закрытие НЕ было предварительным —
        // не трогаем (финальное закрытие остаётся неприкосновенным).
        if ($stageStatus === MonthCloseStageStatus::CLOSED && !$wasPreliminary) {
            $this->logger->info('[PreliminaryRebuild] Stage closed manually, skip', [
                'company_id'  => $command->companyId,
                'marketplace' => $command->marketplace,
                'stage'       => $stage->value,
            ]);

            return;
        }

        // Если этап CLOSED и предыдущее закрытие было предварительным —
        // переоткрываем перед новым предзакрытием.
        if ($stageStatus === MonthCloseStageStatus::CLOSED && $wasPreliminary) {
            try {
                ($this->reopenAction)(new ReopenMonthStageCommand(
                    companyId:   $command->companyId,
                    marketplace: $command->marketplace,
                    year:        $command->year,
                    month:       $command->month,
                    stage:       $stage,
                ));
            } catch (\DomainException $e) {
                $this->logger->warning('[PreliminaryRebuild] Reopen failed, skip stage', [
                    'company_id'  => $command->companyId,
                    'marketplace' => $command->marketplace,
                    'stage'       => $stage->value,
                    'error'       => $e->getMessage(),
                ]);

                return;
            }
        }

        // Preflight — не закрываем, если есть блокирующие ошибки.
        $preflightResult = ($this->preflightAction)(new PreflightMonthCloseCommand(
            companyId:   $command->companyId,
            marketplace: $command->marketplace,
            year:        $command->year,
            month:       $command->month,
            stage:       $stage,
        ));

        if (!$preflightResult->canClose()) {
            $errorKeys = array_map(
                static fn ($c) => $c->key,
                $preflightResult->getErrors(),
            );

            $this->logger->warning('[PreliminaryRebuild] Preflight failed, skip stage', [
                'company_id'  => $command->companyId,
                'marketplace' => $command->marketplace,
                'stage'       => $stage->value,
                'errors'      => $errorKeys,
            ]);

            return;
        }

        // Запускаем закрытие этапа в режиме предзакрытия.
        try {
            ($this->closeAction)(new CloseMonthStageCommand(
                companyId:   $command->companyId,
                marketplace: $command->marketplace,
                year:        $command->year,
                month:       $command->month,
                stage:       $stage->value,
                actorUserId: $command->actorUserId,
                preliminary: true,
            ));
        } catch (\DomainException $e) {
            // Данные не готовы — логируем и продолжаем со следующим этапом.
            $this->logger->warning('[PreliminaryRebuild] Close skipped (domain)', [
                'company_id'  => $command->companyId,
                'marketplace' => $command->marketplace,
                'stage'       => $stage->value,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
