<?php

declare(strict_types=1);

namespace App\Billing\Fixture;

use App\Billing\Entity\Feature;
use App\Billing\Entity\Integration;
use App\Billing\Entity\Plan;
use App\Billing\Entity\PlanFeature;
use App\Billing\Enum\BillingPeriod;
use App\Billing\Enum\FeatureType;
use App\Billing\Enum\IntegrationBillingType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Ramsey\Uuid\Uuid;

final class BillingFixture extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $now = new \DateTimeImmutable('now');

        $plans = [
            'starter_2026' => new Plan(
                id: Uuid::uuid4()->toString(),
                code: 'starter_2026',
                name: 'Starter 2026',
                priceAmount: 0,
                priceCurrency: 'RUB',
                billingPeriod: BillingPeriod::MONTH,
                isActive: true,
                createdAt: $now,
            ),
            'pro_2026' => new Plan(
                id: Uuid::uuid4()->toString(),
                code: 'pro_2026',
                name: 'Pro 2026',
                priceAmount: 1990,
                priceCurrency: 'RUB',
                billingPeriod: BillingPeriod::MONTH,
                isActive: true,
                createdAt: $now,
            ),
            'cfo_2026' => new Plan(
                id: Uuid::uuid4()->toString(),
                code: 'cfo_2026',
                name: 'CFO 2026',
                priceAmount: 4990,
                priceCurrency: 'RUB',
                billingPeriod: BillingPeriod::MONTH,
                isActive: true,
                createdAt: $now,
            ),
        ];

        foreach ($plans as $plan) {
            $manager->persist($plan);
        }

        $features = [
            'cash.view' => new Feature(
                id: Uuid::uuid4()->toString(),
                code: 'cash.view',
                type: FeatureType::BOOLEAN,
                name: 'Cash: view',
                description: 'Просмотр кассовых операций',
            ),
            'cash.write' => new Feature(
                id: Uuid::uuid4()->toString(),
                code: 'cash.write',
                type: FeatureType::BOOLEAN,
                name: 'Cash: write',
                description: 'Создание и редактирование кассовых операций',
            ),
            'pnl.view' => new Feature(
                id: Uuid::uuid4()->toString(),
                code: 'pnl.view',
                type: FeatureType::BOOLEAN,
                name: 'P&L: view',
                description: 'Просмотр P&L отчетов',
            ),
            'balance.view' => new Feature(
                id: Uuid::uuid4()->toString(),
                code: 'balance.view',
                type: FeatureType::BOOLEAN,
                name: 'Balance: view',
                description: 'Просмотр баланса',
            ),
            'users.limit' => new Feature(
                id: Uuid::uuid4()->toString(),
                code: 'users.limit',
                type: FeatureType::LIMIT,
                name: 'Users limit',
                description: 'Лимит пользователей',
            ),
            'transactions.monthly' => new Feature(
                id: Uuid::uuid4()->toString(),
                code: 'transactions.monthly',
                type: FeatureType::LIMIT,
                name: 'Transactions monthly',
                description: 'Лимит транзакций в месяц',
            ),
            'ai.tokens' => new Feature(
                id: Uuid::uuid4()->toString(),
                code: 'ai.tokens',
                type: FeatureType::LIMIT,
                name: 'AI tokens',
                description: 'Лимит AI токенов',
            ),
            'billing.view' => new Feature(
                id: Uuid::uuid4()->toString(),
                code: 'billing.view',
                type: FeatureType::BOOLEAN,
                name: 'Billing: view',
                description: 'Просмотр биллинга',
            ),
        ];

        foreach ($features as $feature) {
            $manager->persist($feature);
        }

        $integrations = [
            new Integration(
                id: Uuid::uuid4()->toString(),
                code: 'telegram',
                name: 'Telegram',
                billingType: IntegrationBillingType::INCLUDED,
                priceAmount: null,
                priceCurrency: null,
                isActive: true,
            ),
            new Integration(
                id: Uuid::uuid4()->toString(),
                code: 'bank_alpha',
                name: 'Alfa Bank',
                billingType: IntegrationBillingType::INCLUDED,
                priceAmount: null,
                priceCurrency: null,
                isActive: true,
            ),
            new Integration(
                id: Uuid::uuid4()->toString(),
                code: 'wb',
                name: 'Wildberries',
                billingType: IntegrationBillingType::INCLUDED,
                priceAmount: null,
                priceCurrency: null,
                isActive: true,
            ),
            new Integration(
                id: Uuid::uuid4()->toString(),
                code: 'ozon',
                name: 'Ozon',
                billingType: IntegrationBillingType::INCLUDED,
                priceAmount: null,
                priceCurrency: null,
                isActive: true,
            ),
            new Integration(
                id: Uuid::uuid4()->toString(),
                code: 'ai_addon',
                name: 'AI Add-on',
                billingType: IntegrationBillingType::ADDON,
                priceAmount: 990,
                priceCurrency: 'RUB',
                isActive: true,
            ),
        ];

        foreach ($integrations as $integration) {
            $manager->persist($integration);
        }

        $planFeatureMatrix = [
            'starter_2026' => [
                'cash.view' => ['value' => 'true'],
                'cash.write' => ['value' => 'false'],
                'pnl.view' => ['value' => 'true'],
                'balance.view' => ['value' => 'true'],
                'billing.view' => ['value' => 'true'],
                'users.limit' => ['value' => '3', 'soft' => 3, 'hard' => 3],
                'transactions.monthly' => ['value' => '500', 'soft' => 500, 'hard' => 500],
                'ai.tokens' => ['value' => '1000', 'soft' => 1000, 'hard' => 1000],
            ],
            'pro_2026' => [
                'cash.view' => ['value' => 'true'],
                'cash.write' => ['value' => 'true'],
                'pnl.view' => ['value' => 'true'],
                'balance.view' => ['value' => 'true'],
                'billing.view' => ['value' => 'true'],
                'users.limit' => ['value' => '10', 'soft' => 10, 'hard' => 10],
                'transactions.monthly' => ['value' => '5000', 'soft' => 5000, 'hard' => 5000],
                'ai.tokens' => ['value' => '10000', 'soft' => 10000, 'hard' => 10000],
            ],
            'cfo_2026' => [
                'cash.view' => ['value' => 'true'],
                'cash.write' => ['value' => 'true'],
                'pnl.view' => ['value' => 'true'],
                'balance.view' => ['value' => 'true'],
                'billing.view' => ['value' => 'true'],
                'users.limit' => ['value' => '50', 'soft' => 50, 'hard' => 50],
                'transactions.monthly' => ['value' => '20000', 'soft' => 20000, 'hard' => 20000],
                'ai.tokens' => ['value' => '50000', 'soft' => 50000, 'hard' => 50000],
            ],
        ];

        foreach ($planFeatureMatrix as $planCode => $featureConfig) {
            $plan = $plans[$planCode];
            foreach ($featureConfig as $featureCode => $config) {
                $manager->persist(new PlanFeature(
                    id: Uuid::uuid4()->toString(),
                    plan: $plan,
                    feature: $features[$featureCode],
                    value: $config['value'],
                    softLimit: $config['soft'] ?? null,
                    hardLimit: $config['hard'] ?? null,
                ));
            }
        }

        $manager->flush();
    }
}
