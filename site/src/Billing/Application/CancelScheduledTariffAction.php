<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Billing\Domain\PaidPeriodCalculator;
use App\Billing\Entity\CompanySubscription;
use App\Billing\Exception\InvalidBillingInputException;
use App\Billing\Repository\CompanySubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Webmozart\Assert\Assert;

/**
 * Отменяет запланированный переход на другой тариф (понижение со следующего периода) — правка
 * администратора. До «оплачено по» остаётся текущий тариф; оплата понижения остаётся в журнале.
 *
 * Точка входа, коммитит сама; та же блокировка компании, что у зачисления и назначения тарифа.
 * Перехода нет или он уже наступил — отказ (проверка до транзакции и повторно под блокировкой,
 * отказ под блокировкой возвращается значением: исключение внутри транзакции закрыло бы EntityManager).
 */
final class CancelScheduledTariffAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CompanySubscriptionRepository $subscriptions,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws InvalidBillingInputException перехода нет или он уже наступил
     */
    public function __invoke(string $companyId): void
    {
        Assert::uuid($companyId);

        $this->assertScheduled($this->subscriptions->findOneByCompanyId($companyId));

        $cancelled = $this->entityManager->wrapInTransaction(function () use ($companyId): ?string {
            $this->entityManager->getConnection()->executeStatement(
                'SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))',
                ['key' => 'billing_subscription:'.$companyId],
            );

            $subscription = $this->subscriptions->findOneByCompanyId($companyId);
            if (null === $subscription) {
                return null;
            }
            $this->entityManager->refresh($subscription);
            $now = $this->clock->now();
            $next = $subscription->scheduledTariffAfter(PaidPeriodCalculator::day($now));
            if (null === $next) {
                return null;
            }
            $subscription->cancelScheduled(PaidPeriodCalculator::day($now), $now);

            return $next->getCode()->value;
        });
        if (null === $cancelled) {
            throw new InvalidBillingInputException('Запланированного перехода нет или он уже наступил.');
        }

        $this->logger->info('Billing: запланированный переход отменён администратором', [
            'companyId' => $companyId,
            'cancelledTariff' => $cancelled,
        ]);
    }

    private function assertScheduled(?CompanySubscription $subscription): void
    {
        if (null === $subscription?->scheduledTariffAfter(PaidPeriodCalculator::day($this->clock->now()))) {
            throw new InvalidBillingInputException('Запланированного перехода нет или он уже наступил.');
        }
    }
}
