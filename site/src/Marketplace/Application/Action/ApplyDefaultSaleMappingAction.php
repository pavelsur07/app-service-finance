<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Action;

use App\Marketplace\Application\Command\ApplyDefaultSaleMappingCommand;
use App\Marketplace\Application\Command\PreviewDefaultSaleMappingCommand;
use App\Marketplace\Application\DTO\DefaultSaleMappingApplyResult;
use App\Marketplace\Enum\DefaultSaleMappingPreviewStatus;
use App\Marketplace\Infrastructure\Writer\DefaultSaleMappingWriter;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final readonly class ApplyDefaultSaleMappingAction
{
    public function __construct(
        private PreviewDefaultSaleMappingAction $previewAction,
        private DefaultSaleMappingWriter $writer,
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ApplyDefaultSaleMappingCommand $command): DefaultSaleMappingApplyResult
    {
        $created = [];
        $skipped = [];
        $marketplace = null;

        // Preview читается внутри той же транзакции, что и запись: иначе решение
        // о блокировке принималось бы по состоянию, которого уже нет.
        $this->connection->transactional(function () use ($command, &$created, &$skipped, &$marketplace): void {
            $preview = ($this->previewAction)(new PreviewDefaultSaleMappingCommand($command->companyId, $command->marketplace));
            $marketplace = $preview->getMarketplace();

            if ($preview->hasBlockingIssues()) {
                throw new \DomainException('Базовый маппинг не может быть применён: есть отсутствующие или невалидные категории ОПиУ.');
            }

            foreach ($preview->getItems() as $item) {
                $amountSource = $item->getAmountSource();

                if (DefaultSaleMappingPreviewStatus::WILL_CREATE !== $item->getStatus() || null === $item->getPlCategoryId()) {
                    $skipped[] = $amountSource->value;

                    continue;
                }

                $affected = $this->writer->createMapping(
                    $command->companyId,
                    $item->getMarketplace(),
                    $amountSource,
                    $item->getPlCategoryId(),
                    $item->getPlCode(),
                    $item->isNegative(),
                    $item->getDescription(),
                );

                if ($affected > 0) {
                    $created[] = $amountSource->value;
                } else {
                    $skipped[] = $amountSource->value;
                }
            }
        });

        if (null === $marketplace) {
            throw new \DomainException(sprintf('Unknown marketplace "%s".', $command->marketplace));
        }

        $result = new DefaultSaleMappingApplyResult($marketplace, $created, $skipped);

        $this->logger->info('Default marketplace sale mapping has been applied.', [
            'company_id' => $command->companyId,
            'marketplace' => $command->marketplace,
            'actor_user_id' => $command->actorUserId,
            'created_count' => $result->getCreatedCount(),
            'skipped_count' => $result->getSkippedCount(),
        ]);

        return $result;
    }
}
