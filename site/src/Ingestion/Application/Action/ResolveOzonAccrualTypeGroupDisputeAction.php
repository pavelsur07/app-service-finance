<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Action;

use App\Ingestion\Entity\OzonAccrualTypeGroup;
use App\Ingestion\Exception\OzonAccrualTypeNotConfirmableException;
use App\Ingestion\Repository\OzonAccrualTypeGroupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Webmozart\Assert\Assert;

/**
 * Админ решает спор по группе типа Ozon: принять группу из нового отчёта или
 * оставить действующую. Решение действует для всех компаний — актор пишется в лог.
 *
 * Решение относится к той паре групп, которую админ видел на странице: если импорт
 * успел заменить спор или действующую группу, решение отклоняется. Проверка и
 * запись — под той же блокировкой, что и импорт отчёта.
 */
final readonly class ResolveOzonAccrualTypeGroupDisputeAction
{
    public function __construct(
        private OzonAccrualTypeGroupRepository $typeGroupRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(int $typeId, string $expectedGroupCode, string $expectedDisputedGroupCode, bool $accept, string $actorUserId): void
    {
        Assert::uuid($actorUserId, 'Решение спора требует пользователя-актора.');

        $typeGroup = $this->entityManager->wrapInTransaction(function () use ($typeId, $expectedGroupCode, $expectedDisputedGroupCode, $accept): OzonAccrualTypeGroup {
            $this->entityManager->getConnection()->executeStatement(
                'SELECT pg_advisory_xact_lock(hashtextextended(:lockKey, 0))',
                ['lockKey' => ImportOzonAccrualDetailsAction::LOCK_KEY],
            );

            $typeGroup = $this->typeGroupRepository->findAllIndexedByTypeId()[$typeId] ?? null;
            if (null !== $typeGroup) {
                // Сущность могла быть загружена до блокировки — перечитать.
                $this->entityManager->refresh($typeGroup);
            }
            if (null === $typeGroup || !$typeGroup->isDisputed()) {
                throw new OzonAccrualTypeNotConfirmableException(sprintf('У типа %d нет спорной группы.', $typeId));
            }
            if ($expectedGroupCode !== $typeGroup->getGroupCode() || $expectedDisputedGroupCode !== $typeGroup->getDisputedGroupCode()) {
                throw new OzonAccrualTypeNotConfirmableException(sprintf('Спор по типу %d изменился, пока страница была открыта. Обновите страницу и решите заново.', $typeId));
            }

            $accept ? $typeGroup->acceptDispute() : $typeGroup->dismissDispute();
            $this->entityManager->flush();

            return $typeGroup;
        });

        $this->logger->info('Ozon accrual type group dispute resolved.', [
            'typeId' => $typeId,
            'accepted' => $accept,
            'disputedGroupCode' => $expectedDisputedGroupCode,
            'groupCode' => $typeGroup->getGroupCode(),
            'actorUserId' => $actorUserId,
        ]);
    }
}
