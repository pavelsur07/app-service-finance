<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Action;

use App\Marketplace\Application\Command\ApplyDefaultCostMappingCommand;
use App\Marketplace\Application\Command\PreviewDefaultCostMappingCommand;
use App\Marketplace\Application\DTO\DefaultCostMappingApplyResult;
use App\Marketplace\Application\DTO\DefaultCostMappingPreviewItem;
use App\Marketplace\Application\Service\DefaultCostMappingSiblingResolver;
use App\Marketplace\Enum\DefaultCostMappingPreviewStatus;
use App\Marketplace\Infrastructure\Provider\DefaultCostMappingYamlProvider;
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
        private DefaultCostMappingYamlProvider $templateProvider,
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
        $crossMarketplace = [];

        $this->connection->transactional(function () use ($command, $preview, &$created, &$updated, &$skipped, &$blocked, &$inferred, &$crossMarketplace): void {
            // Образцы читаются в той же транзакции, что и запись, — окно между
            // выводом статьи и записью правила минимально.
            $siblingTargets = $command->partial ? $this->siblingTargets($command, $preview->getItems()) : [];

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
                    $target = DefaultCostMappingPreviewStatus::MISSING_PL_CATEGORY === $status
                        ? $siblingTargets[$item->getPlCode()] ?? null
                        : null;
                    $plCategoryId = $target['pl_category_id'] ?? null;
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
                    } else {
                        if (null === $item->getExistingMappingId()) {
                            $created[] = $costCode;
                        } else {
                            $updated[] = $costCode;
                        }
                        $inferred[$costCode] = $plCategoryId;
                        if (true === ($target['cross_marketplace'] ?? false)) {
                            $crossMarketplace[] = $costCode;
                        }
                    }

                    continue;
                }

                $skipped[] = $costCode;
            }
        });

        $result = new DefaultCostMappingApplyResult($preview->getMarketplace(), $preview, $created, $updated, $skipped, $blocked, array_keys($inferred), $crossMarketplace);

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
                'cross_marketplace' => $crossMarketplace,
            ]);
        }

        return $result;
    }

    /**
     * Статьи «по образцу компании» для pl_code шаблона, которых у компании нет
     * вовсе (MISSING_PL_CATEGORY): сначала по своему маркетплейсу, при отсутствии
     * образцов — по другим маркетплейсам компании.
     * Считаются, только если такие правила в превью есть.
     *
     * @param list<DefaultCostMappingPreviewItem> $items
     *
     * @return array<string, array{pl_category_id: string, cross_marketplace: bool}> pl_code шаблона => статья ОПиУ компании
     */
    private function siblingTargets(ApplyDefaultCostMappingCommand $command, array $items): array
    {
        $needed = false;
        foreach ($items as $item) {
            $needed = $needed || DefaultCostMappingPreviewStatus::MISSING_PL_CATEGORY === $item->getStatus();
        }

        if (!$needed) {
            return [];
        }

        $templatePlCodes = [];
        foreach ($this->templateProvider->getAll() as $marketplace => $ruleSet) {
            foreach ($ruleSet->getRules() as $rule) {
                $templatePlCodes[$marketplace][$rule->getCostCode()] = $rule->getPlCode();
            }
        }

        return $this->siblingResolver->resolve($command->marketplace, $templatePlCodes, $this->companyTargetsQuery->fetch($command->companyId));
    }
}
