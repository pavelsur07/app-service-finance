<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Action;

use App\Marketplace\Application\Command\ApplyDefaultCostMappingCommand;
use App\Marketplace\Application\Command\PreviewDefaultCostMappingCommand;
use App\Marketplace\Application\DTO\DefaultCostMappingApplyResult;
use App\Marketplace\Application\DTO\DefaultCostMappingPreviewItem;
use App\Marketplace\Application\Service\DefaultCostMappingSiblingResolver;
use App\Marketplace\Enum\DefaultCostMappingPreviewStatus;
use App\Marketplace\Infrastructure\Query\CompanyCostPlTargetsQuery;
use App\Marketplace\Infrastructure\Writer\DefaultCostMappingWriter;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final readonly class ApplyDefaultCostMappingAction
{
    public function __construct(
        private PreviewDefaultCostMappingAction $previewAction,
        private DefaultCostMappingWriter $writer,
        private CompanyCostPlTargetsQuery $companyTargetsQuery,
        private DefaultCostMappingSiblingResolver $siblingResolver,
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ApplyDefaultCostMappingCommand $command): DefaultCostMappingApplyResult
    {
        $preview = ($this->previewAction)(new PreviewDefaultCostMappingCommand($command->companyId, $command->marketplace));

        if (!$command->partial && $preview->hasBlockingIssues()) {
            throw new \DomainException('Базовый маппинг не может быть применён: есть отсутствующие или невалидные категории ОПиУ.');
        }

        $created = [];
        $updated = [];
        $skipped = [];
        $blocked = [];
        $inferred = [];

        $siblingTargets = $command->partial ? $this->siblingTargets($command, $preview->getItems()) : [];

        $this->connection->transactional(function () use ($command, $preview, $siblingTargets, &$created, &$updated, &$skipped, &$blocked, &$inferred): void {
            foreach ($preview->getItems() as $item) {
                $status = $item->getStatus();
                $costCode = $item->getCostCode();

                if (DefaultCostMappingPreviewStatus::WILL_CREATE === $status) {
                    if (null !== $item->getCostCategoryId() && null !== $item->getPlCategoryId()) {
                        $affected = $this->writer->createMapping($command->companyId, $item->getCostCategoryId(), $item->getPlCategoryId(), $item->isIncludeInPl(), $item->isNegative());
                        if ($affected > 0) {
                            $created[] = $costCode;
                        } else {
                            $skipped[] = $costCode;
                        }
                    }

                    continue;
                }

                if (DefaultCostMappingPreviewStatus::WILL_FILL_EMPTY === $status) {
                    if (null !== $item->getExistingMappingId() && null !== $item->getPlCategoryId()) {
                        $affected = $this->writer->fillEmptyMapping($command->companyId, $item->getExistingMappingId(), $item->getPlCategoryId(), $item->isIncludeInPl(), $item->isNegative());
                        if ($affected > 0) {
                            $updated[] = $costCode;
                        } else {
                            $skipped[] = $costCode;
                        }
                    }

                    continue;
                }

                if (DefaultCostMappingPreviewStatus::MISSING_PL_CATEGORY === $status
                    || DefaultCostMappingPreviewStatus::INVALID_TARGET_CATEGORY === $status) {
                    // Сюда доходит только частичный режим: полный бросил выше.
                    // Статья у правила уже назначена вручную — решение есть.
                    if (null !== $item->getExistingPlCategoryId()) {
                        $skipped[] = $costCode;

                        continue;
                    }

                    // Статьи шаблона у компании нет — пробуем статью по образцу.
                    // Статья есть, но не LEAF_INPUT (компания разбила её на дочерние)
                    // или дублируется — по образцу не угадываем: выбор подстатьи за человеком.
                    $plCategoryId = DefaultCostMappingPreviewStatus::MISSING_PL_CATEGORY === $status
                        ? $siblingTargets[$item->getPlCode()] ?? null
                        : null;
                    if (null === $plCategoryId || null === $item->getCostCategoryId()) {
                        $blocked[] = $costCode;

                        continue;
                    }

                    if (null === $item->getExistingMappingId()) {
                        $affected = $this->writer->createMapping($command->companyId, $item->getCostCategoryId(), $plCategoryId, $item->isIncludeInPl(), $item->isNegative());
                    } else {
                        // Ручное или отключённое правило writer не тронет: affected = 0.
                        $affected = $this->writer->fillEmptyMapping($command->companyId, $item->getExistingMappingId(), $plCategoryId, $item->isIncludeInPl(), $item->isNegative());
                    }

                    if (0 === $affected) {
                        $skipped[] = $costCode;
                    } elseif (null === $item->getExistingMappingId()) {
                        $created[] = $costCode;
                        $inferred[$costCode] = $plCategoryId;
                    } else {
                        $updated[] = $costCode;
                        $inferred[$costCode] = $plCategoryId;
                    }

                    continue;
                }

                $skipped[] = $costCode;
            }
        });

        $result = new DefaultCostMappingApplyResult($preview->getMarketplace(), $preview, $created, $updated, $skipped, $blocked, array_keys($inferred));

        $this->logger->info('Default marketplace cost mapping has been applied.', [
            'company_id' => $command->companyId,
            'marketplace' => $command->marketplace,
            'actor_user_id' => $command->actorUserId,
            'partial' => $command->partial,
            'created_count' => $result->getCreatedCount(),
            'updated_count' => $result->getUpdatedCount(),
            'skipped_count' => $result->getSkippedCount(),
            'blocked_count' => $result->getBlockedCount(),
            'inferred_count' => \count($inferred),
        ]);

        if ([] !== $inferred) {
            // Аудит финансового решения: какая затрата в какую статью ушла по образцу.
            $this->logger->info('Default marketplace cost mapping inferred P&L lines from company samples.', [
                'company_id' => $command->companyId,
                'marketplace' => $command->marketplace,
                'inferred' => $inferred,
            ]);
        }

        return $result;
    }

    /**
     * Статьи «по образцу компании» для pl_code шаблона, которых у компании нет
     * вовсе (MISSING_PL_CATEGORY).
     * Считаются, только если такие правила в превью есть.
     *
     * @param list<DefaultCostMappingPreviewItem> $items
     *
     * @return array<string, string> pl_code шаблона => статья ОПиУ компании
     */
    private function siblingTargets(ApplyDefaultCostMappingCommand $command, array $items): array
    {
        $templatePlCodes = [];
        $needed = false;
        foreach ($items as $item) {
            $templatePlCodes[$item->getCostCode()] = $item->getPlCode();
            $needed = $needed || DefaultCostMappingPreviewStatus::MISSING_PL_CATEGORY === $item->getStatus();
        }

        if (!$needed) {
            return [];
        }

        return $this->siblingResolver->resolve($templatePlCodes, $this->companyTargetsQuery->fetch($command->companyId, $command->marketplace));
    }
}
