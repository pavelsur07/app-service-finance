<?php

declare(strict_types=1);

namespace App\MoySklad\Application\Action;

use App\Marketplace\DTO\ListingMetaDTO;
use App\Marketplace\Facade\MarketplaceFacade;
use App\MoySklad\Application\Command\ChangeListingMappingCommand;
use App\MoySklad\Application\DTO\ListingMappingState;
use App\MoySklad\Application\DTO\MappingRevisionDTO;
use App\MoySklad\Entity\MoySkladListingMappingRevision;
use App\MoySklad\Enum\ListingMappingOperation;
use App\MoySklad\Exception\ListingMappingException;
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
    ) {
    }

    public function __invoke(ChangeListingMappingCommand $command): ListingMappingState
    {
        try {
            $result = $this->em->wrapInTransaction(function () use ($command): ListingMappingState|ListingMappingException {
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
                        || $accepted->getExpectedRevision() !== $command->expectedRevision) {
                        return new ListingMappingException('idempotency_conflict', 'Ключ запроса уже использован для другого действия.');
                    }

                    return $this->state($accepted);
                }
                $current = $this->revisions->latest($command->companyId, $command->connectionId, $command->listingId);
                $sources = $this->marketplace->getListingsMetaByIds($command->companyId, [$command->listingId]);
                $source = $sources[strtolower($command->listingId)] ?? null;
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
        }
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
