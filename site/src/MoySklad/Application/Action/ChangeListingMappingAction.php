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
    ) {
    }

    public function __invoke(ChangeListingMappingCommand $command): ListingMappingState
    {
        $catalogLocked = false;
        try {
            $result = $this->em->wrapInTransaction(function () use ($command, &$catalogLocked): ListingMappingState|ListingMappingException {
                $db = $this->em->getConnection();
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
                if (null !== $command->automaticGuard) {
                    // Bound source/target waits while holding the catalog session lock.
                    $db->fetchOne("SELECT set_config('lock_timeout', '2s', true)");
                }
                $current = $this->revisions->latest($command->companyId, $command->connectionId, $command->listingId);
                if (null !== $command->automaticGuard) {
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
                if ((ListingMappingOperation::CLEAR === $command->operation && (null === $current || ListingMappingOperation::CLEAR === $current->getOperation()))
                    || (ListingMappingOperation::SET === $command->operation && $current?->getTargetType() === $command->targetType && $current?->getTargetExternalId() === strtolower($command->targetExternalId ?? ''))) {
                    return null === $current ? new ListingMappingState() : $this->state($current);
                }
                $automaticDecision = null;
                if (null !== $command->automaticGuard && null !== $source) {
                    $guard = $command->automaticGuard;
                    if ($this->automaticPolicy->sourceFingerprint($source) !== $guard->sourceFingerprint
                        || (null !== $guard->sizeCharacteristicId && null === $this->characteristics->find($command->companyId, $command->connectionId, $guard->sizeCharacteristicId))) {
                        return new ListingMappingException('automatic_mapping_conflict', 'Данные листинга или правило размера изменились. Сформируйте новый предпросмотр.');
                    }
                    $article = AutomaticListingMappingPolicy::normalizeKey($source->supplierSku);
                    $groups = $this->automaticCandidates->forArticles($command->companyId, $command->connectionId, null === $article ? [] : [$article]);
                    $automaticDecision = $this->automaticPolicy->decide($source, $groups['article:'.$article] ?? new AutomaticMappingCandidates([], 0), $guard->sizeCharacteristicId);
                    if (AutomaticMappingDecisionStatus::READY !== $automaticDecision->status || $automaticDecision->target?->type !== $command->targetType
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
                $snapshot = null === $source ? $current->getListingSnapshot() : $this->listingSnapshot($source);
                unset($snapshot['autoMapping']);
                if (null !== $command->automaticGuard) {
                    $snapshot['autoMapping'] = $command->automaticGuard->toSnapshot() + ['matchedRules' => $automaticDecision->matchedRules ?? []];
                }
                $revision = new MoySkladListingMappingRevision(Uuid::uuid7()->toString(), $command->companyId, $command->connectionId, $command->listingId,
                    $command->expectedRevision + 1, $command->operation, $command->targetType, $command->targetExternalId,
                    $command->requestId, $command->actorUserId, $command->expectedRevision, $this->clock->now(), $snapshot, $target?->toSnapshot());
                $this->revisions->append($revision);

                return ListingMappingState::fromRevision(MappingRevisionDTO::fromEntity($revision), $target, null !== $source);
            });
            if ($result instanceof ListingMappingException) {
                throw $result;
            }

            return $result;
        } catch (UniqueConstraintViolationException $error) {
            if (!str_contains($error->getMessage(), 'uniq_ms_mapping_request')) {
                throw $error;
            }
            throw new ListingMappingException('idempotency_conflict', 'Ключ запроса уже использован для другого действия.');
        } finally {
            if ($catalogLocked) {
                // Outside callback: Doctrine has already flushed and committed.
                $this->catalogLock->release($command->connectionId);
            }
        }
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
