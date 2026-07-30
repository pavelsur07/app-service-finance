<?php

declare(strict_types=1);

namespace App\Company\Application;

use App\Company\Application\DTO\CounterpartyBackfillResult;
use App\Company\Domain\Service\CounterpartyNameNormalizer;
use App\Company\Repository\CounterpartyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Пересчёт производных полей названия у существующих контрагентов.
 *
 * Идемпотентно: повторный прогон на тех же данных не меняет ничего.
 * updatedAt не трогаем — это не правка пользователя.
 */
final class BackfillCounterpartyNamesAction
{
    public function __construct(
        private readonly CounterpartyRepository $repository,
        private readonly CounterpartyNameNormalizer $normalizer,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(bool $dryRun): CounterpartyBackfillResult
    {
        $result = new CounterpartyBackfillResult();

        foreach ($this->repository->findAllForBackfill() as $counterparty) {
            ++$result->processed;

            try {
                $name = $this->normalizer->normalize($counterparty->getName());
            } catch (\InvalidArgumentException $e) {
                // Ожидаемое доменное условие: пустое или мусорное название.
                $this->logger->warning('Counterparty name cannot be normalized, row skipped.', [
                    'counterpartyId' => $counterparty->getId(),
                    'reason' => $e->getMessage(),
                ]);
                $result->skipped[] = $counterparty->getId();

                continue;
            }

            // Сравниваем с подсказкой после кросс-проверки по ИНН, иначе backfill
            // будет возвращать подсказку, сброшенную при сохранении, и считать это
            // изменением на каждом прогоне.
            $isUpToDate = $name->core === $counterparty->getNameCore()
                && $counterparty->effectiveLegalFormHint($name) === $counterparty->getLegalFormHint();

            if ($isUpToDate) {
                ++$result->unchanged;

                continue;
            }

            ++$result->updated;

            if (!$dryRun) {
                $counterparty->refreshNormalizedName($name);
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $this->logger->info('Counterparty name backfill finished.', [
            'dryRun' => $dryRun,
            'processed' => $result->processed,
            'updated' => $result->updated,
            'unchanged' => $result->unchanged,
            'skipped' => count($result->skipped),
        ]);

        return $result;
    }
}
