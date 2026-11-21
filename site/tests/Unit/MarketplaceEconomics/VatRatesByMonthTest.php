<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceEconomics;

use App\Company\Enum\TaxRegime;
use App\Company\Enum\VatMode;
use App\Company\Facade\CompanyTaxProfileFacadeInterface;
use App\Company\Facade\DTO\CompanyTaxProfileDTO;
use App\MarketplaceEconomics\Application\Service\VatRatesByMonth;
use PHPUnit\Framework\TestCase;

final class VatRatesByMonthTest extends TestCase
{
    public function testRateOfEachMonthFromProfileOnFirstDayInPeriod(): void
    {
        $facade = new class implements CompanyTaxProfileFacadeInterface {
            /** @var list<string> */
            public array $asked = [];

            public function taxProfileOn(string $companyId, \DateTimeImmutable $date): ?CompanyTaxProfileDTO
            {
                $this->asked[] = $date->format('Y-m-d');

                return match (true) {
                    $date < new \DateTimeImmutable('2025-12-01') => null,
                    $date < new \DateTimeImmutable('2026-02-01') => new CompanyTaxProfileDTO(TaxRegime::OSNO, VatMode::GENERAL, null),
                    default => new CompanyTaxProfileDTO(TaxRegime::USN, VatMode::USN_5, new \DateTimeImmutable('2026-02-01')),
                };
            }
        };

        $rates = (new VatRatesByMonth($facade))->rates('c1', new \DateTimeImmutable('2025-11-15'), new \DateTimeImmutable('2026-02-03'));

        // нет профиля — 0; общая ставка 20 % → 22 % с 01.01.2026; УСН 5 %
        self::assertSame(['2025-11' => 0, '2025-12' => 20, '2026-01' => 22, '2026-02' => 5], $rates);
        self::assertSame(['2025-11-15', '2025-12-01', '2026-01-01', '2026-02-01'], $facade->asked);
    }
}
