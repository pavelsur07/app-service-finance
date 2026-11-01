<?php

declare(strict_types=1);

namespace App\Tests\Support\Billing;

/**
 * Копия справочника тарифов из миграции Version20261101210000 — для DbReset, который
 * восстанавливает засеянные миграциями данные после TRUNCATE. Совпадение с миграцией
 * проверяет BillingFacadeTest::testSeededTariffsMatchMigration().
 */
final class TariffSeed
{
    /**
     * @return array<string, array{code: string, name: string, public: bool, perpetual: bool, sortOrder: int}>
     */
    public static function tariffs(): array
    {
        return [
            '01a18a1b-3000-7000-8000-000000000001' => ['code' => 'start', 'name' => 'Старт', 'public' => true, 'perpetual' => false, 'sortOrder' => 10],
            '01a18a1b-3000-7000-8000-000000000002' => ['code' => 'base', 'name' => 'База', 'public' => true, 'perpetual' => false, 'sortOrder' => 20],
            '01a18a1b-3000-7000-8000-000000000003' => ['code' => 'pro', 'name' => 'Профи', 'public' => true, 'perpetual' => false, 'sortOrder' => 30],
            '01a18a1b-3000-7000-8000-000000000004' => ['code' => 'friend', 'name' => 'Друг', 'public' => false, 'perpetual' => true, 'sortOrder' => 100],
            '01a18a1b-3000-7000-8000-000000000005' => ['code' => 'support', 'name' => 'На сопровождении', 'public' => false, 'perpetual' => false, 'sortOrder' => 110],
            '01a18a1b-3000-7000-8000-000000000006' => ['code' => 'outsourcer', 'name' => 'Аутсорсер', 'public' => false, 'perpetual' => false, 'sortOrder' => 120],
        ];
    }

    /**
     * @return array<string, array{tariffId: string, months: int, amountKopecks: int}>
     */
    public static function prices(): array
    {
        $rows = [];
        $amounts = [
            ['01a18a1b-3000-7000-8000-000000000001', 0x120, [497000, 1416000, 2684000, 4771000]],
            ['01a18a1b-3000-7000-8000-000000000002', 0x130, [995000, 2836000, 5373000, 9552000]],
            ['01a18a1b-3000-7000-8000-000000000003', 0x140, [1479000, 4215000, 7987000, 14198000]],
        ];
        foreach ($amounts as [$tariffId, $base, $kopecks]) {
            foreach ([1, 3, 6, 12] as $i => $months) {
                $rows[sprintf('01a18a1b-3000-7000-8000-%012x', $base + $months)] = ['tariffId' => $tariffId, 'months' => $months, 'amountKopecks' => $kopecks[$i]];
            }
        }

        return $rows;
    }
}
