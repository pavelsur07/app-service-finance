<?php

namespace App\MessageHandler;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Company\Entity\Company;
use App\Message\ApplyAutoRulesForTransaction;
use App\Message\EnqueueAutoRulesForRange;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Psr\Log\LoggerInterface;
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

        $company = $this->entityManager->getReference(Company::class, $message->companyId);

        $qb = $this->transactionRepository->createQueryBuilder('t')
            ->where('t.company = :company')
            ->setParameter('company', $company)
            ->orderBy('t.occurredAt', 'ASC');

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
            ));

            ++$enqueued;

            if (0 === $selected % self::BATCH_SIZE) {
                $this->entityManager->clear(CashTransaction::class);
            }
        }

        $this->logger->info('Cash auto rules enqueue completed', [
            'companyId' => $message->companyId,
            'selected' => $selected,
            'enqueued' => $enqueued,
            'from' => $message->from?->format(\DATE_ATOM),
            'to' => $message->to?->format(\DATE_ATOM),
            'moneyAccountIds' => $message->moneyAccountIds,
            'durationMs' => (int) ((microtime(true) - $startTime) * 1000),
        ]);

        $this->entityManager->clear(CashTransaction::class);
    }
}
