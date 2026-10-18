<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Action;

use App\Ingestion\Exception\OzonAccrualTypeNotConfirmableException;
use App\Ingestion\Repository\OzonAccrualTypeGroupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Webmozart\Assert\Assert;

/**
 * Админ решает спор по группе типа Ozon: принять группу из нового отчёта или
 * оставить действующую. Решение действует для всех компаний — актор пишется в лог.
 */
final readonly class ResolveOzonAccrualTypeGroupDisputeAction
{
    public function __construct(
        private OzonAccrualTypeGroupRepository $typeGroupRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(int $typeId, bool $accept, string $actorUserId): void
    {
        Assert::uuid($actorUserId, 'Решение спора требует пользователя-актора.');

        $typeGroup = $this->typeGroupRepository->findAllIndexedByTypeId()[$typeId] ?? null;
        if (null === $typeGroup || !$typeGroup->isDisputed()) {
            throw new OzonAccrualTypeNotConfirmableException(sprintf('У типа %d нет спорной группы.', $typeId));
        }

        $disputedGroupCode = $typeGroup->getDisputedGroupCode();
        $accept ? $typeGroup->acceptDispute() : $typeGroup->dismissDispute();
        $this->entityManager->flush();

        $this->logger->info('Ozon accrual type group dispute resolved.', [
            'typeId' => $typeId,
            'accepted' => $accept,
            'disputedGroupCode' => $disputedGroupCode,
            'groupCode' => $typeGroup->getGroupCode(),
            'actorUserId' => $actorUserId,
        ]);
    }
}
