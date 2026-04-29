<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Action;

use App\Marketplace\Application\Command\ApplyDefaultCostMappingCommand;
use App\Marketplace\Application\Command\PreviewDefaultCostMappingCommand;
use App\Marketplace\Application\DTO\DefaultCostMappingApplyResult;
use App\Marketplace\Enum\DefaultCostMappingPreviewStatus;
use App\Marketplace\Infrastructure\Writer\DefaultCostMappingWriter;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final readonly class ApplyDefaultCostMappingAction
{
    public function __construct(
        private PreviewDefaultCostMappingAction $previewAction,
        private DefaultCostMappingWriter $writer,
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ApplyDefaultCostMappingCommand $command): DefaultCostMappingApplyResult
    {
        $preview = ($this->previewAction)(new PreviewDefaultCostMappingCommand($command->companyId, $command->marketplace));

        if ($preview->hasBlockingIssues()) {
            throw new \DomainException('Базовый маппинг не может быть применён: есть отсутствующие или невалидные категории ОПиУ.');
        }

        $created = [];
        $updated = [];
        $skipped = [];
        $blocked = [];

        $this->connection->transactional(function () use ($command, $preview, &$created, &$updated, &$skipped, &$blocked): void {
            foreach ($preview->getItems() as $item) {
                $status = $item->getStatus();
                $costCode = $item->getCostCode();

                if ($status === DefaultCostMappingPreviewStatus::WILL_CREATE) {
                    if ($item->getCostCategoryId() !== null && $item->getPlCategoryId() !== null) {
                        $this->writer->createMapping($command->companyId, $item->getCostCategoryId(), $item->getPlCategoryId(), $item->isIncludeInPl(), $item->isNegative());
                        $created[] = $costCode;
                    }

                    continue;
                }

                if ($status === DefaultCostMappingPreviewStatus::WILL_FILL_EMPTY) {
                    if ($item->getExistingMappingId() !== null && $item->getPlCategoryId() !== null) {
                        $affected = $this->writer->fillEmptyMapping($command->companyId, $item->getExistingMappingId(), $item->getPlCategoryId(), $item->isIncludeInPl(), $item->isNegative());
                        if ($affected > 0) {
                            $updated[] = $costCode;
                        } else {
                            $skipped[] = $costCode;
                        }
                    }

                    continue;
                }

                if ($status === DefaultCostMappingPreviewStatus::MISSING_PL_CATEGORY || $status === DefaultCostMappingPreviewStatus::INVALID_TARGET_CATEGORY) {
                    $blocked[] = $costCode;
                    continue;
                }

                $skipped[] = $costCode;
            }
        });

        $result = new DefaultCostMappingApplyResult($preview->getMarketplace(), $preview, $created, $updated, $skipped, $blocked);

        $this->logger->info('Default marketplace cost mapping has been applied.', [
            'company_id' => $command->companyId,
            'marketplace' => $command->marketplace,
            'actor_user_id' => $command->actorUserId,
            'created_count' => $result->getCreatedCount(),
            'updated_count' => $result->getUpdatedCount(),
            'skipped_count' => $result->getSkippedCount(),
            'blocked_count' => $result->getBlockedCount(),
        ]);

        return $result;
    }
}
