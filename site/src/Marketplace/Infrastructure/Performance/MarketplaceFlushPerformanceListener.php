<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Performance;

use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceOzonRealization;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Entity\MarketplaceReturn;
use App\Marketplace\Entity\MarketplaceSale;
use App\Shared\Infrastructure\Performance\PerformanceRecorder;
use App\Shared\Infrastructure\Performance\PerformanceStage;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Время Doctrine flush внутри области замера M1, по содержимому unit of work:
 *  - есть MarketplaceRawDocument → `storage_write`, backend=postgres (raw хранится JSON-колонкой
 *    в PostgreSQL, не в S3; json_encode payload выполняется здесь же);
 *  - есть строки учёта (продажи, возвраты, затраты, реализация) или сущности Finance →
 *    `financial_posting`, rows = число вставок/изменений/удалений этих сущностей;
 *  - прочие flush (статусы, логи) не замеряются.
 *
 * Сбойный flush не доходит до postFlush и не попадает в замер — его видно по outcome
 * события `handler`. Время commit внешней транзакции DBAL сюда не входит.
 */
#[AsDoctrineListener(event: Events::preFlush)]
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class MarketplaceFlushPerformanceListener
{
    private ?int $startedAt = null;
    private ?PerformanceStage $stage = null;
    private int $rows = 0;

    public function __construct(private readonly PerformanceRecorder $recorder)
    {
    }

    public function preFlush(PreFlushEventArgs $args): void
    {
        $this->startedAt = $this->recorder->start();
        $this->stage = null;
        $this->rows = 0;
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if (null === $this->startedAt) {
            return;
        }

        try {
            $uow = $args->getObjectManager()->getUnitOfWork();
            $raw = false;
            $posting = 0;

            foreach ([$uow->getScheduledEntityInsertions(), $uow->getScheduledEntityUpdates(), $uow->getScheduledEntityDeletions()] as $entities) {
                foreach ($entities as $entity) {
                    if ($entity instanceof MarketplaceRawDocument) {
                        $raw = true;
                    } elseif (
                        $entity instanceof MarketplaceSale
                        || $entity instanceof MarketplaceReturn
                        || $entity instanceof MarketplaceCost
                        || $entity instanceof MarketplaceOzonRealization
                        // Finance — чужой модуль: по имени класса, без импорта. str_contains — для proxy.
                        || str_contains($entity::class, 'App\\Finance\\Entity\\')
                    ) {
                        ++$posting;
                    }
                }
            }

            if ($raw) {
                $this->stage = PerformanceStage::StorageWrite;
            } elseif ($posting > 0) {
                $this->stage = PerformanceStage::FinancialPosting;
                $this->rows = $posting;
            }
        } catch (\Throwable $e) {
            $this->startedAt = null;
            $this->recorder->reportFailure($e);
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if (null !== $this->stage) {
            $this->recorder->add(
                $this->stage,
                $this->startedAt,
                rows: PerformanceStage::StorageWrite === $this->stage ? null : $this->rows,
                backend: PerformanceStage::StorageWrite === $this->stage ? 'postgres' : null,
            );
        }

        $this->startedAt = null;
        $this->stage = null;
    }
}
