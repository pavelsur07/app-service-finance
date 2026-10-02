<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Application\Action;

use App\Marketplace\Entity\OzonReconciliationLine;
use App\Marketplace\Entity\OzonReconciliationRun;
use App\Marketplace\Ozon\Application\Reconciliation\DTO\OzonReconciliationInputs;
use App\Marketplace\Ozon\Application\Reconciliation\DTO\ReconciledLine;
use App\Marketplace\Ozon\Application\Reconciliation\OzonRawCoverage;
use App\Marketplace\Ozon\Application\Reconciliation\OzonReconciliationCalculator;
use App\Marketplace\Ozon\Domain\Reconciliation\OzonReconciliationTolerance;
use App\Marketplace\Ozon\Exception\InvalidReconciliationPeriodException;
use App\Marketplace\Ozon\Infrastructure\Query\Reconciliation\OzonLedgerTotalsQuery;
use App\Marketplace\Ozon\Infrastructure\Query\Reconciliation\OzonRawAccrualTotalsQuery;
use App\Marketplace\Ozon\Infrastructure\Query\Reconciliation\OzonRealizationTotalsQuery;
use App\Marketplace\Repository\OzonReconciliationLineRepository;
use App\Marketplace\Repository\OzonReconciliationRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\ClockInterface;
use Webmozart\Assert\Assert;

/**
 * Сверка с Ozon за календарный месяц: собирает три источника, считает и сохраняет снимок.
 *
 * Идемпотентна: на (компания, период) живёт один снимок, повторный запуск пересоздаёт его строки.
 * Параллельные запуски одного периода (кнопка и ночной cron) сериализует транзакционная advisory-блокировка:
 * второй ждёт первого и обновляет уже созданный снимок, а не падает на уникальном индексе.
 */
final class RunOzonReconciliationAction
{
    private const CURRENCY = 'RUB';

    public function __construct(
        private readonly OzonRealizationTotalsQuery $realizationQuery,
        private readonly OzonRawAccrualTotalsQuery $rawQuery,
        private readonly OzonLedgerTotalsQuery $ledgerQuery,
        private readonly OzonReconciliationRunRepository $runRepository,
        private readonly OzonReconciliationLineRepository $lineRepository,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): OzonReconciliationRun
    {
        Assert::uuid($companyId);
        if ($from > $to || $from->format('Y-m') !== $to->format('Y-m')) {
            throw InvalidReconciliationPeriodException::notWithinOneMonth($from, $to);
        }

        $context = ['company_id' => $companyId, 'period_from' => $from->format('Y-m-d'), 'period_to' => $to->format('Y-m-d')];
        $this->logger->info('Ozon reconciliation started', $context);

        $now = $this->clock->now();
        $presentDays = $this->rawQuery->presentDays($companyId, $from, $to);

        $inputs = new OzonReconciliationInputs(
            $this->realizationQuery->fetch($companyId, $from, $to, self::CURRENCY),
            $this->rawQuery->flows($companyId, $from, $to, self::CURRENCY),
            $this->rawQuery->costsByCategory($companyId, $from, $to, self::CURRENCY),
            count($presentDays),
            OzonRawCoverage::expectedDays($from, $to, $now),
            OzonRawCoverage::coversWholeMonth($from),
            $this->ledgerQuery->flows($companyId, $from, $to, self::CURRENCY),
            $this->ledgerQuery->costsByCategory($companyId, $from, $to, self::CURRENCY),
            $this->ledgerQuery->costsOutsideRaw($companyId, $from, $to, self::CURRENCY),
        );

        $result = (new OzonReconciliationCalculator(OzonReconciliationTolerance::oneRuble(), self::CURRENCY))->calculate($inputs);

        $run = $this->em->wrapInTransaction(function () use ($companyId, $from, $to, $inputs, $result, $now): OzonReconciliationRun {
            $this->em->getConnection()->executeStatement(
                'SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))',
                ['key' => sprintf('ozon_reconciliation:%s:%s', $companyId, $from->format('Y-m'))],
            );

            $run = $this->runRepository->findByPeriod($companyId, $from, $to);
            if (null === $run) {
                $run = new OzonReconciliationRun(Uuid::uuid7()->toString(), $companyId, $from, $to, self::CURRENCY);
                $this->runRepository->save($run);
            } else {
                $this->lineRepository->deleteByRun($companyId, $run->getId());
            }

            $run->recordResult(
                $inputs->rawDaysExpected,
                $inputs->rawDaysPresent,
                null !== $inputs->realization,
                $inputs->costsOutsideRaw->net->amountMinor(),
                $result->overall,
                $result->mismatchCount,
                $now,
            );

            foreach ($result->lines as $line) {
                $this->lineRepository->save($this->toEntity($companyId, $run->getId(), $line));
            }

            $this->em->flush();

            return $run;
        });

        $this->logger->info('Ozon reconciliation finished', $context + [
            'status' => $run->getOverallStatus()->value,
            'mismatches' => $run->getMismatchCount(),
            'lines' => count($result->lines),
        ]);

        return $run;
    }

    private function toEntity(string $companyId, string $runId, ReconciledLine $line): OzonReconciliationLine
    {
        return new OzonReconciliationLine(
            Uuid::uuid7()->toString(),
            $companyId,
            $runId,
            $line->check,
            $line->block,
            $line->categoryCode,
            $line->source?->amountMinor(),
            $line->target?->amountMinor(),
            $line->status,
            $line->sourceCount,
            $line->targetCount,
            $line->note,
        );
    }
}
