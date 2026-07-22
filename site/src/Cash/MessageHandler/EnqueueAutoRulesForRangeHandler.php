<?php

namespace App\Cash\MessageHandler;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Message\ApplyAutoRulesForTransaction;
use App\Cash\Message\EnqueueAutoRulesForRange;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Company\Entity\Company;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class EnqueueAutoRulesForRangeHandler
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly CashTransactionRepository $transactionRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(EnqueueAutoRulesForRange $message): void
    {
        $startTime = microtime(true);
        $correlationId = $message->correlationId ?? Uuid::uuid7()->toString();

        $company = $this->entityManager->getReference(Company::class, $message->companyId);

        $qb = $this->transactionRepository->createQueryBuilder('t')
            ->where('t.company = :company')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->orderBy('t.occurredAt', 'ASC');

        $lockBefore = $company->getFinanceLockBefore();
        if (null !== $lockBefore) {
            $qb
                ->andWhere('t.occurredAt >= :lockBefore')
                ->setParameter('lockBefore', $lockBefore->setTime(0, 0));
        }

        if ($message->from instanceof \DateTimeImmutable) {
            $qb
                ->andWhere('t.occurredAt >= :from')
                ->setParameter('from', $message->from->setTime(0, 0, 0));
        }

        if ($message->to instanceof \DateTimeImmutable) {
            $qb
                ->andWhere('t.occurredAt <= :to')
                ->setParameter('to', $message->to->setTime(23, 59, 59));
        }

        if (null !== $message->moneyAccountIds && [] !== $message->moneyAccountIds) {
            $qb
                ->andWhere('t.moneyAccount IN (:accounts)')
                ->setParameter('accounts', $message->moneyAccountIds);
        }

        $query = $qb->getQuery();
        $query->setHint(Query::HINT_READ_ONLY, true);

        $selected = 0;
        $enqueued = 0;

        foreach ($query->toIterable() as $transaction) {
            ++$selected;

            if (!$transaction instanceof CashTransaction) {
                continue;
            }

            $this->bus->dispatch(new ApplyAutoRulesForTransaction(
                (string) $transaction->getId(),
                $message->companyId,
                new \DateTimeImmutable(),
                $correlationId,
                $message->mode,
                $message->initiatedByUserId,
            ));

            ++$enqueued;

            if (0 === $selected % self::BATCH_SIZE) {
                $this->entityManager->clear(CashTransaction::class);
            }
        }

        $this->logger->info('Cash auto rules enqueue completed', [
            'companyId' => $message->companyId,
            'correlationId' => $correlationId,
            'selected' => $selected,
            'enqueued' => $enqueued,
            'from' => $message->from?->format(\DATE_ATOM),
            'to' => $message->to?->format(\DATE_ATOM),
            'moneyAccountIds' => $message->moneyAccountIds,
            'mode' => $message->mode->value,
            'initiatedByUserId' => $message->initiatedByUserId,
            'durationMs' => (int) ((microtime(true) - $startTime) * 1000),
        ]);

        $this->entityManager->clear(CashTransaction::class);
    }
}
