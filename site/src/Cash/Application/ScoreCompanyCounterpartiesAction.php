<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Application\Command\ScoreCompanyCounterpartiesCommand;
use App\Cash\Domain\Service\CounterpartyScoringMath;
use App\Cash\Infrastructure\Query\CounterpartyHistoryQuery;
use App\Repository\CounterpartyRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ScoreCompanyCounterpartiesAction
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly CounterpartyHistoryQuery $historyQuery,
        private readonly CounterpartyRepository $counterpartyRepository, // <- Правильная зависимость
        private readonly CounterpartyScoringMath $scoringMath,
        private readonly EntityManagerInterface $em // <- Оставляем только для flush/clear батчей
    ) {}

    public function __invoke(ScoreCompanyCounterpartiesCommand $command): void
    {
        $since = (new \DateTimeImmutable())->modify('-6 months');

        // Быстрое чтение истории
        $historyData = $this->historyQuery->getDelaysGroupedByCounterparty($command->companyId, $since);

        if (empty($historyData)) {
            return;
        }

        $now = new \DateTimeImmutable();
        $counter = 0;

        foreach ($historyData as $counterpartyId => $delays) {
            if (count($delays) < 3) {
                continue;
            }

            // Получаем сущность через репозиторий
            $counterparty = $this->counterpartyRepository->find($counterpartyId);

            // Жесткая проверка контекста компании (SaaS Security)
            if (!$counterparty || $counterparty->getCompany()->getId() !== $command->companyId) {
                continue;
            }

            $medianDelay = $this->scoringMath->calculateMedianDelay($delays);
            $reliabilityScore = $this->scoringMath->calculateReliabilityScore($delays);

            $counterparty->setAverageDelayDays($medianDelay);
            $counterparty->setReliabilityScore($reliabilityScore);
            $counterparty->setLastScoredAt($now);

            $counter++;

            // Защита памяти воркера
            if (($counter % self::BATCH_SIZE) === 0) {
                $this->em->flush();
                $this->em->clear();
            }
        }

        $this->em->flush();
        $this->em->clear();
    }
}
