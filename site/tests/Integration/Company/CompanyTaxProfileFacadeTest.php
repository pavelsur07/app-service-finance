<?php

declare(strict_types=1);

namespace App\Tests\Integration\Company;

use App\Company\Application\SaveCompanyTaxProfileAction;
use App\Company\Enum\TaxRegime;
use App\Company\Enum\VatMode;
use App\Company\Facade\CompanyTaxProfileFacadeInterface;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyTaxProfileBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;

final class CompanyTaxProfileFacadeTest extends IntegrationTestCase
{
    private const COMPANY_ID = '0193cccc-0000-7000-8000-0000000000c1';

    protected function setUp(): void
    {
        parent::setUp();
        $owner = UserBuilder::aUser()->build();
        $this->em->persist($owner);
        $this->em->persist(CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->withOwner($owner)->build());
        $this->em->flush();
    }

    public function testNoProfileMeansNull(): void
    {
        self::assertNull($this->facade()->taxProfileOn(self::COMPANY_ID, new \DateTimeImmutable('2026-05-10')));
    }

    public function testInitialProfileCoversDatesBeforeFirstPeriod(): void
    {
        $this->persistProfiles();

        $before = $this->facade()->taxProfileOn(self::COMPANY_ID, new \DateTimeImmutable('2019-01-15'));
        self::assertSame(VatMode::EXEMPT, $before?->vatMode);
        self::assertNull($before->validFrom);
        self::assertSame('УСН, без НДС', $before->label());
    }

    public function testDatedPeriodStartsOnFirstDayOfMonth(): void
    {
        $this->persistProfiles();

        self::assertSame(VatMode::EXEMPT, $this->facade()->taxProfileOn(self::COMPANY_ID, new \DateTimeImmutable('2026-02-28 23:59:59'))?->vatMode);
        self::assertSame(VatMode::USN_5, $this->facade()->taxProfileOn(self::COMPANY_ID, new \DateTimeImmutable('2026-03-01 00:00:00'))?->vatMode);
        self::assertSame(VatMode::USN_5, $this->facade()->taxProfileOn(self::COMPANY_ID, new \DateTimeImmutable('2026-06-30'))?->vatMode);
        self::assertSame(VatMode::GENERAL, $this->facade()->taxProfileOn(self::COMPANY_ID, new \DateTimeImmutable('2026-07-01'))?->vatMode);
        self::assertSame(TaxRegime::OSNO, $this->facade()->taxProfileOn(self::COMPANY_ID, new \DateTimeImmutable('2030-01-01'))?->taxRegime);
    }

    public function testDateIsTakenInItsOwnTimezone(): void
    {
        $this->persistProfiles();

        // 01.03.2026 05:00 по Владивостоку — ещё 28.02 в UTC; профиль — по календарной дате операции.
        $profile = $this->facade()->taxProfileOn(self::COMPANY_ID, new \DateTimeImmutable('2026-03-01 05:00:00', new \DateTimeZone('Asia/Vladivostok')));
        self::assertSame(VatMode::USN_5, $profile?->vatMode);
    }

    public function testOnlyOwnCompanyProfilesAreRead(): void
    {
        $owner = UserBuilder::aUser()->withIndex(2)->build();
        $other = CompanyBuilder::aCompany()->withIndex(2)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($other);
        $this->em->persist(CompanyTaxProfileBuilder::aProfile()->forCompany((string) $other->getId())->with(TaxRegime::OSNO, VatMode::GENERAL)->build());
        $this->em->flush();

        self::assertNull($this->facade()->taxProfileOn(self::COMPANY_ID, new \DateTimeImmutable('2026-05-10')));
    }

    /**
     * Запись периода берёт блокировку компании: пока её держит другой запрос, сохранение ждёт
     * и ничего не пишет (здесь — до lock_timeout).
     */
    public function testSaveWaitsForConcurrentWriterOfSameCompany(): void
    {
        $worker = DriverManager::getConnection($this->connection->getParams());
        try {
            $worker->beginTransaction();
            $worker->executeStatement('SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))', ['key' => 'company_tax_profile:'.self::COMPANY_ID]);
            $this->connection->executeStatement("SET lock_timeout = '300ms'");

            try {
                self::getContainer()->get(SaveCompanyTaxProfileAction::class)(self::COMPANY_ID, TaxRegime::USN, VatMode::EXEMPT, null);
                self::fail('save must wait for the concurrent writer');
            } catch (DriverException $exception) {
                self::assertSame('55P03', $exception->getSQLState(), 'lock timeout');
            }

            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM company_tax_profiles WHERE company_id = ?', [self::COMPANY_ID]));
        } finally {
            $this->connection->executeStatement('SET lock_timeout = 0');
            $worker->rollBack();
            $worker->close();
        }
    }

    public function testRepeatedSaveReturnsSameProfileAndRowCount(): void
    {
        $save = self::getContainer()->get(SaveCompanyTaxProfileAction::class);

        $first = $save(self::COMPANY_ID, TaxRegime::USN, VatMode::USN_5, new \DateTimeImmutable('2026-03-01'));
        $second = $save(self::COMPANY_ID, TaxRegime::USN, VatMode::USN_5, new \DateTimeImmutable('2026-03-01'));

        self::assertSame($first, $second);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM company_tax_profiles WHERE company_id = ?', [self::COMPANY_ID]));
    }

    private function persistProfiles(): void
    {
        $base = CompanyTaxProfileBuilder::aProfile()->forCompany(self::COMPANY_ID);
        $this->em->persist($base->withId('0193cccc-0000-7000-8000-0000000000a1')->with(TaxRegime::USN, VatMode::EXEMPT)->build());
        $this->em->persist($base->withId('0193cccc-0000-7000-8000-0000000000a2')->with(TaxRegime::USN, VatMode::USN_5)->validFrom('2026-03-01')->build());
        $this->em->persist($base->withId('0193cccc-0000-7000-8000-0000000000a3')->with(TaxRegime::OSNO, VatMode::GENERAL)->validFrom('2026-07-01')->build());
        $this->em->flush();
        $this->em->clear();
    }

    private function facade(): CompanyTaxProfileFacadeInterface
    {
        return self::getContainer()->get(CompanyTaxProfileFacadeInterface::class);
    }
}
