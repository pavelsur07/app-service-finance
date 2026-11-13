<?php

declare(strict_types=1);

namespace App\Cash\EventListener;

use App\Cash\Application\Service\AutoRule\AutoRuleDispatchGuard;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Shared\Audit\AuditContextProvider;
use App\Shared\Entity\AuditLog;
use App\Shared\Enum\AuditLogAction;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
final class AuditLogListener
{
    public function __construct(
        private readonly AuditContextProvider $auditContextProvider,
        private readonly EntityManagerInterface $entityManager,
        private readonly AutoRuleDispatchGuard $autoRuleDispatchGuard,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof AuditLog) {
            return;
        }

        if (!$entity instanceof CashTransaction) {
            return;
        }

        // ИСПРАВЛЕНИЕ: Используем безопасный метод получения ID компании
        $companyId = $this->resolveCompanyId($entity);

        // Получаем ID пользователя (или null, если это делает система)
        $actorUserId = $this->resolveActorUserId();

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

        if ($this->autoRuleDispatchGuard->isSuppressed()) {
            return;
        }

        // ИСПРАВЛЕНИЕ: Используем безопасный метод получения ID компании
        $companyId = $this->resolveCompanyId($entity);

        $changeSet = $args->getObjectManager()->getUnitOfWork()->getEntityChangeSet($entity);
        $diff = [];
        foreach ($changeSet as $field => $changes) {
            $diff[$field] = [$changes[0], $changes[1]];
        }

        $action = AuditLogAction::UPDATE;
        if (array_key_exists('deletedAt', $changeSet)) {
            $newValue = $changeSet['deletedAt'][1] ?? null;
            $action = null !== $newValue ? AuditLogAction::SOFT_DELETE : AuditLogAction::RESTORE;
        }

        // Получаем ID пользователя (или null, если это делает система)
        $actorUserId = $this->resolveActorUserId();

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

    /**
     * Пытается найти ID компании:
     * 1. Сначала из контекста (сессия пользователя)
     * 2. Если нет (мы в консоли/воркере) — берет из самой сущности
     */
    private function resolveCompanyId(CashTransaction $entity): string
    {
        // 1. Пробуем взять из провайдера (если есть сессия)
        try {
            $contextCompanyId = $this->auditContextProvider->getCompanyId();
            if (null !== $contextCompanyId) {
                return $contextCompanyId;
            }
        } catch (\Throwable $e) {
            // Игнорируем ошибки сессии (SessionNotFoundException и др.)
        }

        // 2. Если контекста нет (мы в Воркере), берем ID из самой транзакции
        return (string) $entity->getCompany()->getId();
    }

    /**
     * Безопасное получение ID пользователя.
     */
    private function resolveActorUserId(): ?string
    {
        try {
            return $this->auditContextProvider->getActorUserId();
        } catch (\Throwable $e) {
            // Если сессии нет, возвращаем null (действие выполнила Система)
            return null;
        }
    }
}
