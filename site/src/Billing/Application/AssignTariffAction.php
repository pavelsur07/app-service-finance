<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Billing\Application\DTO\AssignTariffCommand;
use App\Billing\Entity\CompanySubscription;
use App\Billing\Exception\InvalidBillingInputException;
use App\Billing\Repository\CompanySubscriptionRepository;
use App\Billing\Repository\TariffRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Webmozart\Assert\Assert;

/**
 * Назначает кабинету тариф с периодом (или бессрочно) — правка администратора.
 *
 * Точка входа, коммитит сама; та же блокировка компании, что у RegisterPaymentAction.
 * Ввод проверяется до транзакции: доменное исключение внутри неё закрыло бы EntityManager.
 * Повтор с теми же данными даёт то же состояние.
 */
final class AssignTariffAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TariffRepository $tariffs,
        private readonly CompanySubscriptionRepository $subscriptions,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(AssignTariffCommand $command): void
    {
        Assert::uuid($command->companyId);

        $tariff = $this->tariffs->findOneByCode($command->tariffCode);
        if (null === $tariff || !$tariff->isActive()) {
            throw new InvalidBillingInputException('Тариф не найден или отключён.');
        }
        $until = $tariff->isPerpetual() ? null : $command->until;
        if (!$tariff->isPerpetual()) {
            if (null === $until) {
                throw new InvalidBillingInputException('Укажите дату окончания периода.');
            }
            if ($command->from->format('Y-m-d') > $until->format('Y-m-d')) {
                throw new InvalidBillingInputException('Дата начала позже даты окончания.');
            }
        }

        $this->entityManager->wrapInTransaction(function () use ($command, $tariff, $until): void {
            $this->entityManager->getConnection()->executeStatement(
                'SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))',
                ['key' => 'billing_subscription:'.$command->companyId],
            );

            $now = $this->clock->now();
            $subscription = $this->subscriptions->findOneByCompanyId($command->companyId);
            if (null === $subscription) {
                $this->entityManager->persist(CompanySubscription::assignNew($command->companyId, $tariff, $command->from, $until, $now));
            } else {
                $this->entityManager->refresh($subscription);
                $subscription->assign($tariff, $command->from, $until, $now);
            }
        });

        $this->logger->info('Billing: тариф назначен администратором', [
            'companyId' => $command->companyId,
            'tariff' => $tariff->getCode()->value,
            'from' => $command->from->format('Y-m-d'),
            'until' => $until?->format('Y-m-d'),
        ]);
    }
}
