<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Action;

use App\Ingestion\Application\Command\EnsureOrdersCursorCommand;
use App\Ingestion\Repository\IngestCursorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Посев курсора заказов.
 *
 * Глубина посева намеренно скромная: заказы нужны для отслеживания статусов,
 * а не для исторической реконструкции. Бэкфилл за прошлые месяцы — отдельная
 * операция с окном, а не побочный эффект первого включения.
 */
final readonly class EnsureOrdersCursorAction
{
    private const SEED_DAYS_BACK = 7;

    public function __construct(
        private IngestCursorRepository $cursorRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(EnsureOrdersCursorCommand $command): void
    {
        if ([] !== $this->cursorRepository->findByResource($command->companyId, $command->connectionRef, $command->resourceType)) {
            return;
        }

        $cursor = $this->cursorRepository->getOrCreate(
            $command->companyId,
            $command->connectionRef,
            $command->resourceType,
            $command->connectionRef,
        );

        $seed = json_encode(
            ['since' => (new \DateTimeImmutable(sprintf('-%d days', self::SEED_DAYS_BACK)))->format(\DATE_ATOM)],
            \JSON_THROW_ON_ERROR,
        );

        $cursor->advance($seed, Uuid::uuid7()->toString());
        $this->entityManager->flush();
    }
}
