<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Billing\Domain\PaidPeriodCalculator;
use App\Billing\Entity\CompanySubscription;
use App\Billing\Enum\TariffCode;
use App\Billing\Repository\CompanySubscriptionRepository;
use App\Billing\Repository\TariffRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Webmozart\Assert\Assert;

/**
 * Пробный период кабинета без подписки: TRIAL_TARIFF на TRIAL_DAYS дней с сегодняшнего дня по Москве.
 *
 * Не точка входа: вызывается из транзакции создания компании (CompanyCreatedEvent) или
 * дозаполнения (CompanyOnboardingBackfillEvent), только persist() — коммитит вызывающий.
 * Блокировка компании та же, что у оплаты и назначения тарифа, — снимется с транзакцией вызывающего.
 */
final readonly class StartTrialAction
{
    public const TRIAL_TARIFF = TariffCode::BASE;
    public const TRIAL_DAYS = 14;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TariffRepository $tariffs,
        private CompanySubscriptionRepository $subscriptions,
        private PaidPeriodCalculator $calculator,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return int 1 — пробный период начат (или был бы начат при $dryRun), 0 — подписка уже есть
     */
    public function __invoke(string $companyId, bool $dryRun = false): int
    {
        Assert::uuid($companyId);
        if (!$dryRun) {
            $this->entityManager->getConnection()->executeStatement(
                'SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))',
                ['key' => 'billing_subscription:'.$companyId],
            );
        }
        if (null !== $this->subscriptions->findOneByCompanyId($companyId)) {
            return 0;
        }
        if ($dryRun) {
            return 1;
        }

        $tariff = $this->tariffs->findOneByCode(self::TRIAL_TARIFF)
            ?? throw new \LogicException(sprintf('Тариф пробного периода «%s» отсутствует в справочнике.', self::TRIAL_TARIFF->value));
        $now = $this->clock->now();
        $period = $this->calculator->trial($now, self::TRIAL_DAYS);

        $this->entityManager->persist(CompanySubscription::startTrial($companyId, $tariff, $period, $now));

        $this->logger->info('Billing: начат пробный период', [
            'companyId' => $companyId,
            'tariff' => $tariff->getCode()->value,
            'paidUntil' => $period->until->format('Y-m-d'),
        ]);

        return 1;
    }
}
