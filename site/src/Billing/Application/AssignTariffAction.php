<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Billing\Application\DTO\AssignTariffCommand;
use App\Billing\Domain\PaidPeriodCalculator;
use App\Billing\Entity\CompanySubscription;
use App\Billing\Exception\InvalidBillingInputException;
use App\Billing\Repository\BillingPaymentRepository;
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
 * Повтор с теми же данными даёт то же состояние. Тариф с периодом, окончание которого раньше
 * последнего оплаченного дня кабинета, назначается только с `confirmShorten` (сокращение после
 * возврата и т. п.) — иначе оплаченные клиентом дни пропали бы молча.
 */
final class AssignTariffAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TariffRepository $tariffs,
        private readonly CompanySubscriptionRepository $subscriptions,
        private readonly BillingPaymentRepository $payments,
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

        $this->throwIfShortens($until, $this->shortenedPaidUntil($command, $until, $this->subscriptions->findOneByCompanyId($command->companyId)));

        // Под блокировкой проверка повторяется (оплату могли зачислить между ними); отказ возвращается
        // значением — исключение внутри транзакции закрыло бы EntityManager.
        $shortened = $this->entityManager->wrapInTransaction(function () use ($command, $tariff, $until): ?\DateTimeImmutable {
            $this->entityManager->getConnection()->executeStatement(
                'SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))',
                ['key' => 'billing_subscription:'.$command->companyId],
            );

            $now = $this->clock->now();
            $subscription = $this->subscriptions->findOneByCompanyId($command->companyId);
            if (null !== $subscription) {
                $this->entityManager->refresh($subscription);
            }
            $shortened = $this->shortenedPaidUntil($command, $until, $subscription);
            if (null !== $shortened) {
                return $shortened;
            }

            if (null === $subscription) {
                $this->entityManager->persist(CompanySubscription::assignNew($command->companyId, $tariff, $command->from, $until, $now));
            } else {
                $subscription->assign($tariff, $command->from, $until, $now);
            }

            return null;
        });
        $this->throwIfShortens($until, $shortened);

        $this->logger->info('Billing: тариф назначен администратором', [
            'companyId' => $command->companyId,
            'tariff' => $tariff->getCode()->value,
            'from' => $command->from->format('Y-m-d'),
            'until' => $until?->format('Y-m-d'),
            'confirmShorten' => $command->confirmShorten,
        ]);
    }

    /**
     * @throws InvalidBillingInputException
     */
    private function throwIfShortens(?\DateTimeImmutable $until, ?\DateTimeImmutable $paidUntil): void
    {
        if (null !== $until && null !== $paidUntil) {
            throw new InvalidBillingInputException(sprintf('Клиент оплатил по %s, а новое окончание — %s. Если сокращение намеренное (например, возврат), отметьте «Сократить оплаченный период».', $paidUntil->format('d.m.Y'), $until->format('d.m.Y')));
        }
    }

    /**
     * Оплаченный и выданный последний день, если назначение его обрезает без подтверждения; иначе null.
     * Граница — последний оплаченный день журнала, но не позже текущего «оплачено по»: дни, уже
     * снятые подтверждённым сокращением, повторно флажка не требуют.
     */
    private function shortenedPaidUntil(AssignTariffCommand $command, ?\DateTimeImmutable $until, ?CompanySubscription $subscription): ?\DateTimeImmutable
    {
        if (null === $until || $command->confirmShorten) {
            return null;
        }
        $paid = $this->payments->findLatestPaidUntil($command->companyId);
        if (null === $paid) {
            return null;
        }
        $current = $subscription?->getPaidUntil();
        if (null !== $current && $current->format('Y-m-d') < $paid->format('Y-m-d')) {
            $paid = PaidPeriodCalculator::date($current);
        }

        return $until->format('Y-m-d') < $paid->format('Y-m-d') ? $paid : null;
    }
}
