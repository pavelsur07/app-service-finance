<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Billing\Application\DTO\RegisterPaymentCommand;
use App\Billing\Domain\PaymentPlanKind;
use App\Billing\Entity\BillingPayment;
use App\Billing\Entity\CompanySubscription;
use App\Billing\Entity\Tariff;
use App\Billing\Exception\InvalidBillingInputException;
use App\Billing\Exception\PerpetualTariffChangeNotConfirmedException;
use App\Billing\Exception\TariffChangeNotSupportedException;
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
 * Зачисляет оплату: запись в журнал + подписка по плану оплаты (`PaymentPlanner`: новый период,
 * продление, повышение тарифа с переносом остатка, понижение со следующего периода).
 *
 * Точка входа, сама коммитит. Одна транзакция под блокировкой компании
 * (`pg_advisory_xact_lock`, существует и до первой строки подписки). Повтор с тем же
 * `paymentId` или тем же `externalId` в компании (любым способом оплаты) возвращает исходную
 * оплату и ничего не пишет.
 */
final class RegisterPaymentAction
{
    /** Верхняя граница колонки INT `amount_kopecks`. */
    public const MAX_AMOUNT_KOPECKS = 2_147_483_647;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TariffRepository $tariffs,
        private readonly CompanySubscriptionRepository $subscriptions,
        private readonly BillingPaymentRepository $payments,
        private readonly PaymentPlanResolver $planResolver,
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

        // Проверки до транзакции: доменное исключение внутри wrapInTransaction()
        // закрыло бы EntityManager вызывающего (HTML-контроллер с flash).
        if ($command->months < 1 || $command->months > 36) {
            throw new InvalidBillingInputException('Срок оплаты — от 1 до 36 месяцев.');
        }
        if ($command->amountKopecks < 0 || $command->amountKopecks > self::MAX_AMOUNT_KOPECKS) {
            throw new InvalidBillingInputException('Сумма оплаты — от 0 до 21 474 836,47 ₽.');
        }
        if (null !== $command->externalId && ('' === trim($command->externalId) || mb_strlen($command->externalId) > 128)) {
            throw new InvalidBillingInputException('Номер счёта — от 1 до 128 символов.');
        }

        $tariff = $this->tariffs->findOneByCode($command->tariffCode);
        if (null === $tariff || !$tariff->isActive() || $tariff->isPerpetual()) {
            throw new TariffNotPayableException($command->tariffCode);
        }

        // Повтор — исходная оплата, даже если подписка с тех пор изменилась (переход запланирован,
        // тариф повышен) и та же оплата сейчас была бы отказом. Под блокировкой проверяется ещё раз.
        $repeated = $this->findRepeated($command);
        if (null !== $repeated) {
            return $repeated->getId();
        }

        $current = $this->subscriptions->findOneByCompanyId($command->companyId);
        if (null !== $current) {
            // Отказы — до транзакции.
            $this->assertMayLeavePerpetual($current, $command);
            $this->assertNotOlderThanTariffChange($command, $tariff);
            $this->planResolver->plan($current, $tariff, $command->receivedAt, $command->months);
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

        // Повтор под блокировкой — второго продления нет.
        $repeated = $this->findRepeated($command);
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
            // Повтор под блокировкой: «Друга» могли назначить между проверкой выше и блокировкой.
            $this->assertMayLeavePerpetual($subscription, $command);
            $this->assertNotOlderThanTariffChange($command, $tariff);
        }
        // Под блокировкой: подписка и журнал оплат уже не изменятся до commit.
        $plan = $this->planResolver->plan($subscription, $tariff, $command->receivedAt, $command->months);
        $period = $plan->period;
        // Остаток этих оплат уходит в дни повышения — повторно в остаток они не войдут. Считать до applyPlan():
        // тот меняет тариф и начало отрезка.
        $superseded = null !== $subscription && PaymentPlanKind::UPGRADE === $plan->kind
            ? $this->planResolver->counted($subscription, $command->receivedAt)
            : [];

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
            $plan->carriedDays,
            $plan->carriedKopecks,
        ));

        if (null === $subscription) {
            $this->entityManager->persist(CompanySubscription::startPaid($command->companyId, $tariff, $period, $now));
        } else {
            $subscription->applyPlan($tariff, $plan, $command->receivedAt, $now);
        }
        foreach ($superseded as $payment) {
            $payment->supersede($command->paymentId);
        }

        $this->logger->info('Billing: оплата зачислена', [
            'companyId' => $command->companyId,
            'paymentId' => $command->paymentId,
            'tariff' => $tariff->getCode()->value,
            'months' => $command->months,
            'plan' => $plan->kind->value,
            'carriedDays' => $plan->carriedDays,
            'periodFrom' => $period->from->format('Y-m-d'),
            'periodUntil' => $period->until->format('Y-m-d'),
        ]);

        return $command->paymentId;
    }

    private function findRepeated(RegisterPaymentCommand $command): ?BillingPayment
    {
        // Номер сверяется без учёта способа: онлайн-оплату ЮKassa администратор мог уже отметить
        // вручную с её ID в номере (и наоборот).
        return $this->payments->findOneByIdAndCompanyId($command->paymentId, $command->companyId)
            ?? (null === $command->externalId ? null : $this->payments->findOneByCompanyAndExternalIdAnySource($command->companyId, $command->externalId));
    }

    /**
     * Оплата, полученная раньше уже зачисленной оплаты другого тарифа (уведомления пришли не по
     * порядку, счёт отмечен с опозданием), считалась бы от более позднего тарифа — отказ, решает
     * администратор.
     */
    private function assertNotOlderThanTariffChange(RegisterPaymentCommand $command, Tariff $tariff): void
    {
        if ($this->payments->existsReceivedAfterWithOtherTariff($command->companyId, $command->receivedAt, $tariff)) {
            throw new TariffChangeNotSupportedException('Оплата получена раньше уже зачисленной оплаты другого тарифа — зачислите её вручную через «Назначить тариф».');
        }
    }

    private function assertMayLeavePerpetual(CompanySubscription $subscription, RegisterPaymentCommand $command): void
    {
        if ($subscription->getTariff()->isPerpetual() && !$command->confirmLeavePerpetual) {
            throw new PerpetualTariffChangeNotConfirmedException($subscription->getTariff()->getName());
        }
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
