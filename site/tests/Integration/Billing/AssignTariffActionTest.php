<?php

declare(strict_types=1);

namespace App\Tests\Integration\Billing;

use App\Billing\Application\AssignTariffAction;
use App\Billing\Application\DTO\AssignTariffCommand;
use App\Billing\Entity\CompanySubscription;
use App\Billing\Enum\AccessStatus;
use App\Billing\Enum\TariffCode;
use App\Billing\Exception\InvalidBillingInputException;
use App\Billing\Facade\BillingFacadeInterface;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class AssignTariffActionTest extends IntegrationTestCase
{
    private const COMPANY_ID = '0193bbbb-0000-7000-8000-0000000000a1';
    private const OTHER_COMPANY_ID = '0193bbbb-0000-7000-8000-0000000000a2';

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([self::COMPANY_ID, self::OTHER_COMPANY_ID] as $i => $companyId) {
            $owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail(sprintf('assign-%d@example.test', $i))->build();
            $this->em->persist($owner);
            $this->em->persist(CompanyBuilder::aCompany()->withId($companyId)->withOwner($owner)->build());
        }
        $this->em->flush();
    }

    public function testAssignsPerpetualAndIgnoresUntil(): void
    {
        $this->assign(TariffCode::FRIEND, '2026-11-01', '2027-01-01');

        $subscription = $this->subscription();
        self::assertSame(TariffCode::FRIEND, $subscription->getTariff()->getCode());
        self::assertNull($subscription->getPaidUntil());
    }

    public function testChangesExistingSubscriptionToDatedTariff(): void
    {
        $this->assign(TariffCode::FRIEND, '2026-11-01', null);
        $this->assign(TariffCode::SUPPORT, '2026-12-01', '2027-11-30');
        $this->assign(TariffCode::SUPPORT, '2026-12-01', '2027-11-30'); // повтор — то же состояние

        $subscription = $this->subscription();
        self::assertSame(TariffCode::SUPPORT, $subscription->getTariff()->getCode());
        self::assertSame('2026-12-01', $subscription->getPaidFrom()->format('Y-m-d'));
        self::assertSame('2027-11-30', $subscription->getPaidUntil()?->format('Y-m-d'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM billing_company_subscriptions WHERE company_id = ?', [self::COMPANY_ID]));
    }

    public function testDatedTariffRequiresValidPeriod(): void
    {
        foreach ([['2027-01-01', null, 'дату окончания'], ['2027-02-01', '2027-01-31', 'позже']] as [$from, $until, $message]) {
            try {
                $this->assign(TariffCode::OUTSOURCER, $from, $until);
                self::fail('invalid period must be rejected');
            } catch (InvalidBillingInputException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM billing_company_subscriptions WHERE company_id = ?', [self::COMPANY_ID]));
        self::assertTrue($this->em->isOpen());
    }

    public function testFacadeReturnsAccessForSeveralCompaniesAtOnce(): void
    {
        $this->assign(TariffCode::SUPPORT, '2027-01-01', '2027-01-31');
        $missing = '0193bbbb-0000-7000-8000-0000000000ff';

        $access = self::getContainer()->get(BillingFacadeInterface::class)->accessForCompanies(
            [self::COMPANY_ID, self::OTHER_COMPANY_ID, $missing],
            new \DateTimeImmutable('2027-01-20'),
        );

        self::assertSame([self::COMPANY_ID, self::OTHER_COMPANY_ID, $missing], array_keys($access));
        self::assertSame(AccessStatus::ACTIVE, $access[self::COMPANY_ID]->status);
        self::assertSame('На сопровождении', $access[self::COMPANY_ID]->tariffName);
        self::assertSame(AccessStatus::NONE, $access[self::OTHER_COMPANY_ID]->status);
        self::assertSame(AccessStatus::NONE, $access[$missing]->status);
    }

    private function assign(TariffCode $code, string $from, ?string $until): void
    {
        $zone = new \DateTimeZone('Europe/Moscow');
        self::getContainer()->get(AssignTariffAction::class)(new AssignTariffCommand(
            self::COMPANY_ID,
            $code,
            new \DateTimeImmutable($from, $zone),
            null === $until ? null : new \DateTimeImmutable($until, $zone),
        ));
    }

    private function subscription(): CompanySubscription
    {
        $this->em->clear();
        $subscription = $this->em->getRepository(CompanySubscription::class)->findOneBy(['companyId' => self::COMPANY_ID]);
        self::assertNotNull($subscription);

        return $subscription;
    }
}
