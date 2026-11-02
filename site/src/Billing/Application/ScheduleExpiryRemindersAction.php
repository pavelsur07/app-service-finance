<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Billing\Domain\PaidPeriodCalculator;
use App\Billing\Message\SendBillingExpiryReminderMessage;
use App\Billing\Repository\BillingExpiryReminderRepository;
use App\Billing\Repository\CompanySubscriptionRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Ставит в очередь напоминания об окончании оплаты на сегодня (по Москве).
 *
 * Оплаченный период — за PAID_DAYS дней до «оплачено по», пробный — за TRIAL_DAYS (реже:
 * адрес нового кабинета не подтверждён). Уже отправленные (строка `billing_expiry_reminders`)
 * пропускаются; «не больше одного письма» держит handler, застолбляя строку перед SMTP, —
 * поэтому повтор прогона или двойная постановка дубля не дают, а сбой постановки ничего
 * не застолбляет и лечится повторным запуском. Сбой постановки — ERROR в журнале.
 */
final readonly class ScheduleExpiryRemindersAction
{
    /** Дней до «оплачено по»: 0 — сегодня последний оплаченный день. */
    public const PAID_DAYS = [7, 3, 1, 0];
    public const TRIAL_DAYS = [3, 0];

    public function __construct(
        private CompanySubscriptionRepository $subscriptions,
        private BillingExpiryReminderRepository $reminders,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{due: int, queued: int, skipped: int, failed: int}
     */
    public function __invoke(): array
    {
        $now = $this->clock->now();
        $today = PaidPeriodCalculator::day($now);
        $dates = array_map(static fn (int $days): \DateTimeImmutable => $today->modify(sprintf('+%d days', $days)), self::PAID_DAYS);

        $result = ['due' => 0, 'queued' => 0, 'skipped' => 0, 'failed' => 0];
        $failedCompanies = [];
        foreach ($this->subscriptions->findEndingOn($dates) as $subscription) {
            $paidUntil = $subscription->getPaidUntil();
            if (null === $paidUntil) {
                continue;
            }
            $daysBefore = (int) $today->diff(PaidPeriodCalculator::date($paidUntil))->days;
            if (!\in_array($daysBefore, $subscription->isTrial() ? self::TRIAL_DAYS : self::PAID_DAYS, true)) {
                continue;
            }
            ++$result['due'];

            $companyId = $subscription->getCompanyId();
            try {
                if ($this->reminders->isSent($companyId, $paidUntil, $daysBefore)) {
                    ++$result['skipped'];
                    continue;
                }
                $this->bus->dispatch(new SendBillingExpiryReminderMessage($companyId, $paidUntil->format('Y-m-d'), $daysBefore, $subscription->isTrial()));
                ++$result['queued'];
            } catch (\Throwable $e) {
                ++$result['failed'];
                $failedCompanies[] = $companyId;
                $lastError = $e;
            }
        }

        if ([] !== $failedCompanies) {
            $this->logger->error('Billing: напоминания об окончании оплаты не поставлены в очередь', [
                'failed' => \count($failedCompanies),
                'companyIds' => $failedCompanies,
                'exception' => $lastError ?? null,
            ]);
        }

        return $result;
    }
}
