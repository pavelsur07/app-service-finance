<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company\TaxProfile;

use App\Company\Entity\CompanyTaxProfile;
use App\Company\Enum\TaxRegime;
use App\Company\Enum\VatMode;
use App\Company\Exception\InvalidCompanyTaxProfileException;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyTaxProfileBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyTaxProfileTest extends TestCase
{
    /**
     * @return iterable<string, array{TaxRegime, list<VatMode>}>
     */
    public static function matrixProvider(): iterable
    {
        yield 'ОСНО' => [TaxRegime::OSNO, [VatMode::GENERAL, VatMode::EXEMPT]];
        yield 'УСН' => [TaxRegime::USN, [VatMode::EXEMPT, VatMode::USN_5, VatMode::USN_7, VatMode::GENERAL]];
        yield 'АУСН' => [TaxRegime::AUSN, [VatMode::EXEMPT]];
        yield 'Патент' => [TaxRegime::PSN, [VatMode::EXEMPT]];
        yield 'ЕСХН' => [TaxRegime::ESHN, [VatMode::EXEMPT, VatMode::GENERAL]];
    }

    /**
     * @param list<VatMode> $allowed
     */
    #[DataProvider('matrixProvider')]
    public function testAllowsOnlyListedVatModes(TaxRegime $regime, array $allowed): void
    {
        self::assertSame($allowed, $regime->allowedVatModes());
        foreach (VatMode::cases() as $mode) {
            self::assertSame(\in_array($mode, $allowed, true), $regime->allows($mode), $mode->value);
        }
    }

    public function testRejectsDisallowedCombination(): void
    {
        $this->expectException(InvalidCompanyTaxProfileException::class);
        $this->expectExceptionMessage('Режим «Патент» не допускает «НДС по общим ставкам».');

        CompanyTaxProfileBuilder::aProfile()->with(TaxRegime::PSN, VatMode::GENERAL)->build();
    }

    public function testValidFromMustBeFirstDayOfMonth(): void
    {
        $this->expectException(InvalidCompanyTaxProfileException::class);

        CompanyTaxProfileBuilder::aProfile()->validFrom('2026-03-15')->build();
    }

    public function testValidFromIsStoredAsCalendarDateWithoutTime(): void
    {
        $profile = CompanyTaxProfileBuilder::aProfile()->validFrom('2026-03-01 15:30:00')->build();

        self::assertSame('2026-03-01 00:00:00', $profile->getValidFrom()?->format('Y-m-d H:i:s'));
    }

    public function testInitialProfileHasNoValidFrom(): void
    {
        self::assertNull(CompanyTaxProfileBuilder::aProfile()->build()->getValidFrom());
    }

    public function testRejectsNonUuidCompany(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CompanyTaxProfile('not-a-uuid', TaxRegime::USN, VatMode::EXEMPT, null, new \DateTimeImmutable());
    }

    public function testChangeKeepsPeriodAndRejectsDisallowedCombination(): void
    {
        $profile = CompanyTaxProfileBuilder::aProfile()->forCompany(CompanyBuilder::DEFAULT_COMPANY_ID)->build();
        $created = $profile->getUpdatedAt();

        $profile->change(TaxRegime::USN, VatMode::EXEMPT, new \DateTimeImmutable('2026-12-01'));
        self::assertSame($created, $profile->getUpdatedAt(), 'без изменений дата не двигается');

        $profile->change(TaxRegime::OSNO, VatMode::GENERAL, new \DateTimeImmutable('2026-12-01'));
        self::assertSame(TaxRegime::OSNO, $profile->getTaxRegime());
        self::assertSame(VatMode::GENERAL, $profile->getVatMode());
        self::assertNull($profile->getValidFrom());

        $this->expectException(InvalidCompanyTaxProfileException::class);
        $profile->change(TaxRegime::AUSN, VatMode::USN_5, new \DateTimeImmutable());
    }
}
