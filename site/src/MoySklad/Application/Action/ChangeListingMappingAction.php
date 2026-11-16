<?php

declare(strict_types=1);

namespace App\MoySklad\Application\Action;

use App\Marketplace\DTO\ListingMetaDTO;
use App\Marketplace\Facade\MarketplaceFacade;
use App\MoySklad\Application\Command\ChangeListingMappingCommand;
use App\MoySklad\Application\DTO\AutomaticMappingCandidates;
use App\MoySklad\Application\DTO\ListingMappingState;
use App\MoySklad\Application\DTO\MappingRevisionDTO;
use App\MoySklad\Domain\AutomaticListingMappingPolicy;
use App\MoySklad\Entity\MoySkladListingMappingRevision;
use App\MoySklad\Enum\AutomaticMappingDecisionStatus;
use App\MoySklad\Enum\ListingMappingOperation;
use App\MoySklad\Exception\ListingMappingException;
use App\MoySklad\Infrastructure\Lock\MoySkladCatalogLock;
use App\MoySklad\Infrastructure\Query\AutomaticMappingCandidatesQuery;
use App\MoySklad\Infrastructure\Query\MappingCharacteristicsQuery;
use App\MoySklad\Infrastructure\Query\MoySkladCatalogTargetsQuery;
use App\MoySklad\Infrastructure\Repository\MoySkladListingMappingRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\ClockInterface;

final readonly class ChangeListingMappingAction
{
    public function __construct(
        private EntityManagerInterface $em, private MarketplaceFacade $marketplace,
        private MoySkladListingMappingRepository $revisions, private MoySkladCatalogTargetsQuery $targets, private ClockInterface $clock,
        private MoySkladCatalogLock $catalogLock, private AutomaticListingMappingPolicy $automaticPolicy,
        private AutomaticMappingCandidatesQuery $automaticCandidates, private MappingCharacteristicsQuery $characteristics,
        private \App\MoySklad\Infrastructure\Query\MappingSettingsQuery $settings,
    ) {
    }

    public function __invoke(ChangeListingMappingCommand $command): ListingMappingState
    {
        $catalogLocked = false;
        try {
            $result = $this->em->wrapInTransaction(function () use ($command, &$catalogLocked): ListingMappingState|ListingMappingException {
                $db = $this->em->getConnection();
                if (ListingMappingOperation::SET === $command->operation) {
                    $db->fetchOne("SELECT set_config('lock_timeout', '2s', true)");
                }
                $connection = $db->fetchAssociative('SELECT id FROM moysklad_connections WHERE company_id=:company AND id=:id FOR SHARE',
                    ['company' => $command->companyId, 'id' => $command->connectionId]);
                if (false === $connection) {
                    return $this->notFound();
                }
                $db->fetchOne('SELECT pg_advisory_xact_lock(hashtext(:namespace), hashtext(:key))',
                    ['namespace' => 'moysklad-listing-mapping', 'key' => strtolower($command->companyId.':'.$command->connectionId.':'.$command->listingId)]);
                $accepted = $this->revisions->acceptedRequest($command->companyId, $command->requestId);
                if (null !== $accepted) {
                    if ($accepted->getConnectionId() !== strtolower($command->connectionId) || $accepted->getListingId() !== strtolower($command->listingId)
                        || $accepted->getActorUserId() !== strtolower($command->actorUserId) || $accepted->getOperation() !== $command->operation
                        || $accepted->getTargetType() !== $command->targetType || $accepted->getTargetExternalId() !== (null === $command->targetExternalId ? null : strtolower($command->targetExternalId))
                        || $accepted->getExpectedRevision() !== $command->expectedRevision
                        || !$this->sameGuard($accepted->getListingSnapshot()['autoMapping'] ?? null, $command)) {
                        return new ListingMappingException('idempotency_conflict', 'Ключ запроса уже использован для другого действия.');
                    }

                    return $this->state($accepted);
                }
                if (null !== $command->automaticGuard) {
                    $catalogLocked = $this->catalogLock->tryAcquire($command->connectionId);
                    if (!$catalogLocked) {
                        return new ListingMappingException('catalog_busy', 'Каталог синхронизируется. Повторите применение.');
                    }
                }
                $current = $this->revisions->latest($command->companyId, $command->connectionId, $command->listingId);
                if (ListingMappingOperation::SET === $command->operation) {
                    $source = $this->marketplace->lockListingMetaForExternalMapping($command->companyId, $command->listingId);
                } else {
                    $sources = $this->marketplace->getListingsMetaByIds($command->companyId, [$command->listingId]);
                    $source = $sources[strtolower($command->listingId)] ?? null;
                }
                if (null === $source && (ListingMappingOperation::SET === $command->operation || null === $current)) {
                    return $this->notFound();
                }
                if (($current?->getRevision() ?? 0) !== $command->expectedRevision) {
                    return new ListingMappingException('stale_mapping', 'Связь изменилась. Обновите страницу.');
                }
                if (ListingMappingOperation::CLEAR === $command->operation && (null === $current || ListingMappingOperation::CLEAR === $current->getOperation())) {
                    return null === $current ? new ListingMappingState() : $this->state($current);
                }
                if (!$command->packageReviewConfirmed && null === $command->automaticGuard && ListingMappingOperation::SET === $command->operation
                    && $current?->getTargetType() === $command->targetType && $current?->getTargetExternalId() === strtolower($command->targetExternalId ?? '')) {
                    return $this->state($current);
                }
                $automaticDecision = null;
                if (null !== $command->automaticGuard && null !== $source) {
                    $guard = $command->automaticGuard;
                    $barcodes = $this->marketplace->getListingBarcodesByIds($command->companyId, [$source->id])[$source->id] ?? [];
                    // Settings writer must take the connection FOR UPDATE; our SHARE lock keeps this version stable.
                    $settings = $this->settings->get($command->companyId, $command->connectionId, $source->marketplace);
                    if ($this->automaticPolicy->sourceFingerprint($source, $barcodes, $settings) !== $guard->sourceFingerprint
                        || (null !== $guard->sizeCharacteristicId && null === $this->characteristics->find($command->companyId, $command->connectionId, $guard->sizeCharacteristicId))) {
                        return new ListingMappingException('automatic_mapping_conflict', 'Данные листинга или правило размера изменились. Сформируйте новый предпросмотр.');
                    }
                    $groups = $this->automaticCandidates->forListings($command->companyId, $command->connectionId, [$source], [$source->id => $barcodes], $settings->wbTrailingW);
                    $automaticDecision = $this->automaticPolicy->decide($source, $groups[$source->id] ?? new AutomaticMappingCandidates([], 0), $guard->sizeCharacteristicId, $barcodes, $settings->wbTrailingW);
                    if ($automaticDecision->fingerprint() !== $guard->candidateFingerprint
                        || AutomaticMappingDecisionStatus::READY !== $automaticDecision->status || $automaticDecision->target?->type !== $command->targetType
                        || $automaticDecision->target?->externalId !== strtolower($command->targetExternalId ?? '')) {
                        return new ListingMappingException('automatic_mapping_conflict', 'Соответствие изменилось. Сформируйте новый предпросмотр.');
                    }
                }
                $target = null;
                if (ListingMappingOperation::SET === $command->operation) {
                    if (!$this->targets->isReady($command->companyId, $command->connectionId)) {
                        return new ListingMappingException('catalog_not_ready', 'Подключите МойСклад и дождитесь загрузки товаров и модификаций.');
                    }
                    $target = $this->targets->lock($command->companyId, $command->connectionId, $command->targetType ?? '', strtolower($command->targetExternalId ?? ''));
                    if (null === $target) {
                        return $this->notFound();
                    }
                    if (!$target->selectable) {
                        return new ListingMappingException('target_unavailable', 'Позиция недоступна. Выберите неархивный товар или конкретную модификацию.');
                    }
                }
                $packageReview = null !== $source && null !== $target && AutomaticListingMappingPolicy::packageNeedsReview($source->title, $target->name);
                $reviewBarcodes = !$packageReview ? [] : ($barcodes ?? ($this->marketplace->getListingBarcodesByIds($command->companyId, [$source->id])[$source->id] ?? []));
                if ($packageReview) {
                    if (!$command->packageReviewConfirmed || null !== $command->automaticGuard) {
                        return new ListingMappingException('package_review_required', 'Проверить комплектацию: подтвердите проверку количества штук.');
                    }
                    if ($command->packageReviewFingerprint !== $this->automaticPolicy->packageReviewFingerprint($source, $target, $reviewBarcodes)) {
                        return new ListingMappingException('stale_package_review', 'Данные комплектации изменились. Обновите страницу и проверьте их снова.');
                    }
                }
                if (ListingMappingOperation::SET === $command->operation && $current?->getTargetType() === $command->targetType && $current?->getTargetExternalId() === strtolower($command->targetExternalId ?? '')) {
                    if ($packageReview) {
                        $this->recordPackageReview($command, $current->getRevision(), $source, $target, $reviewBarcodes);
                    }

                    return $this->state($current);
                }
                $snapshot = null === $source ? $current->getListingSnapshot() : $this->listingSnapshot($source);
                unset($snapshot['autoMapping']);
                if (null !== $command->automaticGuard) {
                    $snapshot['autoMapping'] = $command->automaticGuard->toSnapshot() + ['matchedRules' => $automaticDecision->matchedRules ?? []];
                }
                $revision = new MoySkladListingMappingRevision(Uuid::uuid7()->toString(), $command->companyId, $command->connectionId, $command->listingId,
                    $command->expectedRevision + 1, $command->operation, $command->targetType, $command->targetExternalId,
                    $command->requestId, $command->actorUserId, $command->expectedRevision, $this->clock->now(), $snapshot, $target?->toSnapshot());
                $this->revisions->append($revision);
                if ($packageReview) {
                    // Make the revision visible to the composite FK within this same transaction.
                    $this->em->flush();
                    $this->recordPackageReview($command, $revision->getRevision(), $source, $target, $reviewBarcodes);
                }

                return ListingMappingState::fromRevision(MappingRevisionDTO::fromEntity($revision), $target, null !== $source);
            });
            if ($result instanceof ListingMappingException) {
                throw $result;
            }

            return $result;
        } catch (\Doctrine\DBAL\Exception\DriverException $error) {
            if (in_array($error->getSQLState(), ['55P03', '40P01'], true)) {
                throw new ListingMappingException('listing_busy', 'Листинг занят обновлением данных. Повторите запрос после обновления страницы.');
            }
            if ($error instanceof UniqueConstraintViolationException && str_contains($error->getMessage(), 'uniq_ms_mapping_request')) {
                throw new ListingMappingException('idempotency_conflict', 'Ключ запроса уже использован для другого действия.');
            }
            throw $error;
        } finally {
            if ($catalogLocked) {
                // Outside callback: Doctrine has already flushed and committed.
                $this->catalogLock->release($command->connectionId);
            }
        }
    }

    /** @param list<string> $barcodes */
    private function recordPackageReview(ChangeListingMappingCommand $command, int $revision, ListingMetaDTO $source, \App\MoySklad\Application\DTO\CatalogTargetDTO $target, array $barcodes): void
    {
        $this->em->getConnection()->executeStatement('INSERT INTO moysklad_mapping_package_reviews
            (company_id, connection_id, listing_id, revision, source_fingerprint, target_fingerprint, actor_user_id, checked_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT DO NOTHING',
            [$command->companyId, $command->connectionId, $command->listingId, $revision,
                $this->automaticPolicy->sourceFingerprint($source, $barcodes), AutomaticListingMappingPolicy::targetFingerprint($target),
                $command->actorUserId, $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v')]);
    }

    /** @param array<string,mixed>|null $snapshot */
    private function sameGuard(?array $snapshot, ChangeListingMappingCommand $command): bool
    {
        if (null === $command->automaticGuard) {
            return null === $snapshot;
        }
        if (null === $snapshot) {
            return false;
        }
        foreach ($command->automaticGuard->toSnapshot() as $key => $value) {
            if (!array_key_exists($key, $snapshot) || $snapshot[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    private function state(MoySkladListingMappingRevision $revision): ListingMappingState
    {
        $target = null === $revision->getTargetType() ? null : $this->targets->find($revision->getCompanyId(), $revision->getConnectionId(), $revision->getTargetType(), $revision->getTargetExternalId() ?? '');

        return ListingMappingState::fromRevision(MappingRevisionDTO::fromEntity($revision), $target);
    }

    /** @return array<string,mixed> */
    private function listingSnapshot(ListingMetaDTO $source): array
    {
        return ['id' => $source->id, 'marketplace' => $source->marketplace, 'sku' => $source->sku, 'supplierSku' => $source->supplierSku,
            'size' => $source->size, 'variantId' => $source->marketplaceVariantId, 'title' => $source->title];
    }

    private function notFound(): ListingMappingException
    {
        return new ListingMappingException('not_found', 'Листинг, подключение или позиция не найдены.');
    }
}
