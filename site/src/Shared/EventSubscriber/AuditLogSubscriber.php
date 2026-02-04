<?php

declare(strict_types=1);

namespace App\Shared\EventSubscriber;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Shared\Audit\AuditContextProvider;
use App\Shared\Entity\AuditLog;
use App\Shared\Enum\AuditLogAction;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;

#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
final class AuditLogSubscriber implements EventSubscriber
{
    public function __construct(
        private readonly AuditContextProvider $auditContextProvider,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getSubscribedEvents(): array
    {
        return [Events::postPersist, Events::postUpdate];
    }

    public function postPersist(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof AuditLog) {
            return;
        }

        if (!$entity instanceof CashTransaction) {
            return;
        }

        $companyId = $this->auditContextProvider->getCompanyId();
        if ($companyId === null) {
            return;
        }

        $actorUserId = $this->auditContextProvider->getActorUserId();

        $auditLog = new AuditLog(
            $companyId,
            $entity::class,
            (string) $entity->getId(),
            AuditLogAction::CREATE,
            null,
            $actorUserId,
        );

        $this->entityManager->persist($auditLog);
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof AuditLog) {
            return;
        }

        if (!$entity instanceof CashTransaction) {
            return;
        }

        $companyId = $this->auditContextProvider->getCompanyId();
        if ($companyId === null) {
            return;
        }

        $changeSet = $args->getObjectManager()->getUnitOfWork()->getEntityChangeSet($entity);
        $diff = [];
        foreach ($changeSet as $field => $changes) {
            $diff[$field] = [$changes[0], $changes[1]];
        }

        $action = AuditLogAction::UPDATE;
        if (array_key_exists('deletedAt', $changeSet)) {
            $newValue = $changeSet['deletedAt'][1] ?? null;
            $action = $newValue !== null ? AuditLogAction::SOFT_DELETE : AuditLogAction::RESTORE;
        }

        $actorUserId = $this->auditContextProvider->getActorUserId();

        $auditLog = new AuditLog(
            $companyId,
            $entity::class,
            (string) $entity->getId(),
            $action,
            $diff,
            $actorUserId,
        );

        $this->entityManager->persist($auditLog);
    }
}
