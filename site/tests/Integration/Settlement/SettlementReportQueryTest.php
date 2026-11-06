<?php

declare(strict_types=1);

namespace App\Tests\Integration\Settlement;

use App\Settlement\Application\ConfigureSettlementBookAction;
use App\Settlement\Application\CreateSettlementDocumentAction;
use App\Settlement\Application\DTO\CreateSettlementDocumentInput;
use App\Settlement\Application\DTO\SettlementReportFilter;
use App\Settlement\Application\PostSettlementDocumentAction;
use App\Settlement\Enum\SettlementBucket;
use App\Settlement\Enum\SettlementDocumentKind;
use App\Settlement\Facade\SettlementFacadeInterface;
use App\Shared\Domain\Exception\MoneyOverflowException;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class SettlementReportQueryTest extends IntegrationTestCase
{
    private string $companyId;
    private string $actorId;
    private \App\Company\Entity\Company $company;
    private string $partyId;

    protected function setUp(): void
    {
        parent::setUp();
        $actor = UserBuilder::aUser()->build();
        $this->company = CompanyBuilder::aCompany()->withOwner($actor)->build();
        $party = CounterpartyBuilder::aCounterparty()->withCompany($this->company)->build();
        foreach ([$actor, $this->company, $party] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $companyId = $this->company->getId();
        $actorId = $actor->getId();
        self::assertNotNull($companyId);
        self::assertNotNull($actorId);
        $this->companyId = $companyId;
        $this->actorId = $actorId;
        $this->partyId = $party->getId();
        (self::getContainer()->get(ConfigureSettlementBookAction::class))($companyId, $actorId, new \DateTimeImmutable('2026-11-01'));
    }

    private function post(Money $amount, SettlementDocumentKind $kind = SettlementDocumentKind::OBLIGATION, string $date = '2026-11-02', ?string $due = null, ?string $party = null): void
    {
        $draft = (self::getContainer()->get(CreateSettlementDocumentAction::class))($this->companyId, $this->actorId, new CreateSettlementDocumentInput(Uuid::uuid7()->toString(), $kind, $party ?? $this->partyId, SettlementBucket::PAYABLE, $amount, new \DateTimeImmutable($date), null === $due ? null : new \DateTimeImmutable($due), 'Акт'));
        (self::getContainer()->get(PostSettlementDocumentAction::class))($this->companyId, $this->actorId, $draft->documentId, $draft->version);
    }

    public function testOpeningIsNotTurnoverAndDueDateHasAnExactBoundary(): void
    {
        $this->post(Money::fromString('50.00', 'RUB'), SettlementDocumentKind::OPENING, '2026-11-01');
        $this->post(Money::fromString('100.00', 'RUB'), due: '2026-11-05');
        $facade = self::getContainer()->get(SettlementFacadeInterface::class);
        $report = $facade->report($this->companyId, new SettlementReportFilter(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-05'), 'RUB'));
        self::assertSame('50.00', $report->totals->opening->payable->toDecimalString());
        self::assertSame('100.00', $report->totals->increases->payable->toDecimalString());
        self::assertSame('150.00', $report->totals->closing->payable->toDecimalString());
        self::assertSame('0.00', $report->totals->overdue->toDecimalString());
        self::assertSame('50.00', $report->totals->withoutDueDate->toDecimalString());
        $nextDay = $facade->card($this->companyId, $this->partyId, new SettlementReportFilter(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-06'), 'RUB'));
        self::assertSame('100.00', $nextDay->summary->overdue->toDecimalString());
        self::assertCount(2, $nextDay->rows);
    }

    public function testGrandTotalsCoverEveryPageAndKopecksStayExact(): void
    {
        $this->post(Money::fromString('0.10', 'RUB'));
        $this->post(Money::fromString('0.20', 'RUB'));
        for ($i = 0; $i < 25; ++$i) {
            $party = CounterpartyBuilder::aCounterparty()->withId(Uuid::uuid7()->toString())->withCompany($this->company)->withInn(null)->withName('Поставщик '.$i)->build();
            $this->em->persist($party);
            $this->em->flush();
            $this->post(Money::fromString('1.00', 'RUB'), party: $party->getId());
        }
        $report = self::getContainer()->get(SettlementFacadeInterface::class)->report($this->companyId, new SettlementReportFilter(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-06'), 'RUB', null, 2, 25));
        self::assertSame(26, $report->total);
        self::assertCount(1, $report->rows);
        self::assertSame('25.30', $report->totals->closing->payable->toDecimalString());
        $card = self::getContainer()->get(SettlementFacadeInterface::class)->card($this->companyId, $this->partyId, new SettlementReportFilter(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-06'), 'RUB'));
        self::assertSame('0.30', $card->summary->closing->payable->toDecimalString());
    }

    public function testAggregateOverflowIsRejectedBeforeIntConversion(): void
    {
        $this->post(Money::fromMinor(\PHP_INT_MAX, 'RUB'));
        $this->post(Money::fromMinor(1, 'RUB'));
        $this->expectException(MoneyOverflowException::class);
        self::getContainer()->get(SettlementFacadeInterface::class)->report($this->companyId, new SettlementReportFilter(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-06'), 'RUB'));
    }

    public function testForeignCompanyReturnsNoBalancesOrMovements(): void
    {
        $this->post(Money::fromString('100.00', 'RUB'));
        $facade = self::getContainer()->get(SettlementFacadeInterface::class);
        $filter = new SettlementReportFilter(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-06'), 'RUB');
        $foreign = Uuid::uuid7()->toString();
        self::assertSame(0, $facade->report($foreign, $filter)->total);
        self::assertSame(0, $facade->card($foreign, $this->partyId, $filter)->total);
    }
}
