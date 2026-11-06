<?php

declare(strict_types=1);

namespace App\Tests\Integration\Settlement;

use App\Company\Entity\Company;
use App\Settlement\Application\ConfigureSettlementBookAction;
use App\Settlement\Application\CorrectSettlementItemAction;
use App\Settlement\Application\CreateSettlementDocumentAction;
use App\Settlement\Application\DTO\CorrectSettlementItemInput;
use App\Settlement\Application\DTO\CreateSettlementDocumentInput;
use App\Settlement\Application\DTO\ReverseSettlementInput;
use App\Settlement\Application\DTO\SettlementResult;
use App\Settlement\Application\PostSettlementDocumentAction;
use App\Settlement\Application\ReverseSettlementDocumentAction;
use App\Settlement\Enum\SettlementBucket;
use App\Settlement\Enum\SettlementDocumentKind;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class SettlementDocumentInvariantTest extends IntegrationTestCase
{
    private string $companyId;
    private string $actorId;
    private string $partyId;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $user = UserBuilder::aUser()->build();
        $this->company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $party = CounterpartyBuilder::aCounterparty()->withCompany($this->company)->build();
        foreach ([$user, $this->company, $party] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $companyId = $this->company->getId();
        self::assertNotNull($companyId);
        self::assertNotNull($user->getId());
        $this->companyId = $companyId;
        $this->actorId = $user->getId();
        $this->partyId = $party->getId();
        (self::getContainer()->get(ConfigureSettlementBookAction::class))($this->companyId, $this->actorId, new \DateTimeImmutable('2026-11-01'));
    }

    private function draft(?string $requestId = null, string $amount = '100.00', string $date = '2026-11-01'): SettlementResult
    {
        return (self::getContainer()->get(CreateSettlementDocumentAction::class))($this->companyId, $this->actorId, new CreateSettlementDocumentInput($requestId ?? Uuid::uuid7()->toString(), SettlementDocumentKind::OBLIGATION, $this->partyId, SettlementBucket::PAYABLE, Money::fromString($amount, 'RUB'), new \DateTimeImmutable($date), null, 'Акт'));
    }

    public function testUnrelatedActorCannotConfigureBook(): void
    {
        $this->expectException(AccessDeniedException::class);
        (self::getContainer()->get(ConfigureSettlementBookAction::class))($this->companyId, Uuid::uuid7()->toString(), new \DateTimeImmutable('2026-11-01'));
    }

    public function testRequestKeyCannotBeReusedWithDifferentAmount(): void
    {
        $key = Uuid::uuid7()->toString();
        $this->draft($key);
        $this->expectException(\DomainException::class);
        $this->draft($key, '101.00');
    }

    public function testClosedDateIsCheckedAtPosting(): void
    {
        $draft = $this->draft();
        $this->connection->executeStatement("UPDATE companies SET finance_lock_before = '2026-11-02' WHERE id = ?", [$this->companyId]);
        $this->expectException(\DomainException::class);
        (self::getContainer()->get(PostSettlementDocumentAction::class))($this->companyId, $this->actorId, $draft->documentId, $draft->version);
    }

    public function testFutureDraftCannotBePosted(): void
    {
        $draft = $this->draft(date: '2099-11-01');
        $this->expectException(\DomainException::class);
        (self::getContainer()->get(PostSettlementDocumentAction::class))($this->companyId, $this->actorId, $draft->documentId, $draft->version);
    }

    public function testCorrectionCannotMakeBalanceNegative(): void
    {
        $draft = $this->draft();
        $posted = (self::getContainer()->get(PostSettlementDocumentAction::class))($this->companyId, $this->actorId, $draft->documentId, $draft->version);
        self::assertNotNull($posted->itemId);
        try {
            (self::getContainer()->get(CorrectSettlementItemAction::class))($this->companyId, $this->actorId, new CorrectSettlementItemInput(Uuid::uuid7()->toString(), $posted->itemId, Money::fromString('100.01', 'RUB'), false, new \DateTimeImmutable('2026-11-02'), 'Уточнение'));
            self::fail('Negative balance must be rejected.');
        } catch (\DomainException) {
            self::assertSame('10000', (string) $this->connection->fetchOne('SELECT SUM(delta_amount_minor) FROM settlement_movements WHERE company_id = ?', [$this->companyId]));
        }
    }

    public function testReversedObligationCannotBeCorrected(): void
    {
        $draft = $this->draft();
        $posted = (self::getContainer()->get(PostSettlementDocumentAction::class))($this->companyId, $this->actorId, $draft->documentId, $draft->version);
        self::assertNotNull($posted->itemId);
        (self::getContainer()->get(ReverseSettlementDocumentAction::class))($this->companyId, $this->actorId, $draft->documentId, new ReverseSettlementInput(Uuid::uuid7()->toString(), new \DateTimeImmutable('2026-11-02'), 'Отмена'));
        $this->expectException(\DomainException::class);
        (self::getContainer()->get(CorrectSettlementItemAction::class))($this->companyId, $this->actorId, new CorrectSettlementItemInput(Uuid::uuid7()->toString(), $posted->itemId, Money::fromString('1.00', 'RUB'), true, new \DateTimeImmutable('2026-11-03'), 'Уточнение'));
    }
}
