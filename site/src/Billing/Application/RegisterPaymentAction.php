<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Billing\Application\DTO\RegisterPaymentCommand;
use App\Billing\Domain\PaidPeriodCalculator;
use App\Billing\Entity\BillingPayment;
use App\Billing\Entity\CompanySubscription;
use App\Billing\Entity\Tariff;
use App\Billing\Exception\TariffNotPayableException;
use App\Billing\Repository\BillingPaymentRepository;
use App\Billing\Repository\CompanySubscriptionRepository;
use App\Billing\Repository\TariffRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Webmozart\Assert\Assert;

/**
 * Зачисляет оплату: запись в журнал + создание или продление подписки компании.
 *
 * Точка входа, сама коммитит. Одна транзакция под блокировкой компании
 * (`pg_advisory_xact_lock`, существует и до первой строки подписки). Повтор с тем же
 * `paymentId` или тем же `(source, externalId)` возвращает исходную оплату и ничего не пишет.
 */
final class RegisterPaymentAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TariffRepository $tariffs,
        private readonly CompanySubscriptionRepository $subscriptions,
        private readonly BillingPaymentRepository $payments,
        private readonly PaidPeriodCalculator $calculator,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return string ID оплаты (исходной — при повторе)
     */
    public function __invoke(RegisterPaymentCommand $command): string
    {
        Assert::uuid($command->paymentId);
        Assert::uuid($command->companyId);

        // Проверка тарифа до транзакции: доменное исключение внутри wrapInTransaction()
        // закрыло бы EntityManager вызывающего (HTML-контроллер с flash).
        $tariff = $this->tariffs->findOneByCode($command->tariffCode);
        if (null === $tariff || !$tariff->isActive() || $tariff->isPerpetual()) {
            throw new TariffNotPayableException($command->tariffCode);
        }

        try {
            return $this->entityManager->wrapInTransaction(fn (): string => $this->register($command, $tariff));
        } catch (UniqueConstraintViolationException $exception) {
            // Параллельный повтор: транзакция откачена, EM закрыт — ищем исходную оплату DBAL.
            return $this->concurrentlyRegistered($command) ?? throw $exception;
        }
    }

    private function register(RegisterPaymentCommand $command, Tariff $tariff): string
    {
        $this->entityManager->getConnection()->executeStatement(
            'SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))',
            ['key' => 'billing_subscription:'.$command->companyId],
        );

        $repeated = $this->payments->findOneByIdAndCompanyId($command->paymentId, $command->companyId)
            ?? (null === $command->externalId ? null : $this->payments->findOneByExternalId($command->companyId, $command->source, $command->externalId));
        if (null !== $repeated) {
            if ($repeated->getTariff()->getCode() !== $command->tariffCode
                || $repeated->getMonths() !== $command->months
                || $repeated->getAmountKopecks() !== $command->amountKopecks) {
                $this->logger->warning('Billing: повтор оплаты с другим содержимым — возвращена исходная', [
                    'companyId' => $command->companyId,
                    'paymentId' => $repeated->getId(),
                    'requestedPaymentId' => $command->paymentId,
                ]);
            }

            return $repeated->getId();
        }

        $now = $this->clock->now();
        $subscription = $this->subscriptions->findOneByCompanyId($command->companyId);
        if (null !== $subscription) {
            // Сущность могла попасть в identity map до блокировки (например, через Facade) —
            // гидрация её не обновит; без refresh продление считалось бы от устаревшего paidUntil.
            $this->entityManager->refresh($subscription);
        }
        $period = $this->calculator->next(
            $subscription?->renewalBase($tariff, $command->receivedAt),
            $command->receivedAt,
            $command->months,
        );

        $this->entityManager->persist(new BillingPayment(
            $command->paymentId,
            $command->companyId,
            $tariff,
            $command->months,
            $command->amountKopecks,
            $command->receivedAt,
            $period,
            $command->source,
            $command->externalId,
            $now,
        ));

        if (null === $subscription) {
            $this->entityManager->persist(CompanySubscription::startPaid($command->companyId, $tariff, $period, $now));
        } else {
            $subscription->applyPaidPeriod($tariff, $period, $now);
        }

        $this->logger->info('Billing: оплата зачислена', [
            'companyId' => $command->companyId,
            'paymentId' => $command->paymentId,
            'tariff' => $tariff->getCode()->value,
            'months' => $command->months,
            'periodFrom' => $period->from->format('Y-m-d'),
            'periodUntil' => $period->until->format('Y-m-d'),
        ]);

        return $command->paymentId;
    }

    private function concurrentlyRegistered(RegisterPaymentCommand $command): ?string
    {
        $id = $this->entityManager->getConnection()->fetchOne(
            'SELECT id FROM billing_payments
              WHERE company_id = :companyId
                AND (id = :id OR (source = :source AND external_id = :externalId))
              LIMIT 1',
            [
                'companyId' => $command->companyId,
                'id' => $command->paymentId,
                'source' => $command->source->value,
                'externalId' => $command->externalId,
            ],
        );

        return false === $id ? null : (string) $id;
    }
}
