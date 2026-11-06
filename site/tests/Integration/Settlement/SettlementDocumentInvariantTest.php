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
        $this->expectExceptionMessage('Ключ повтора использован с другими данными.');
        $this->draft($key, '101.00');
    }

    public function testClosedDateIsCheckedAtPosting(): void
    {
        $draft = $this->draft();
        $this->connection->executeStatement("UPDATE companies SET finance_lock_before = '2026-11-02' WHERE id = ?", [$this->companyId]);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Дата вне открытого периода учёта.');
        (self::getContainer()->get(PostSettlementDocumentAction::class))($this->companyId, $this->actorId, $draft->documentId, $draft->version);
    }

    public function testFutureDraftCannotBePosted(): void
    {
        $draft = $this->draft(date: '2099-11-01');
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Дата вне открытого периода учёта.');
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
        $this->expectExceptionMessage('Нельзя корректировать отменённую позицию.');
        (self::getContainer()->get(CorrectSettlementItemAction::class))($this->companyId, $this->actorId, new CorrectSettlementItemInput(Uuid::uuid7()->toString(), $posted->itemId, Money::fromString('1.00', 'RUB'), true, new \DateTimeImmutable('2026-11-03'), 'Уточнение'));
    }

    public function testFourOpeningBucketsAndRepeatedPostingDoNotDuplicateMovements(): void
    {
        $create = self::getContainer()->get(CreateSettlementDocumentAction::class);
        $post = self::getContainer()->get(PostSettlementDocumentAction::class);
        foreach ([SettlementBucket::RECEIVABLE, SettlementBucket::PAYABLE, SettlementBucket::ADVANCE_GIVEN, SettlementBucket::ADVANCE_RECEIVED] as $bucket) {
            $draft = $create($this->companyId, $this->actorId, new CreateSettlementDocumentInput(Uuid::uuid7()->toString(), SettlementDocumentKind::OPENING, $this->partyId, $bucket, Money::fromString('25.01', 'RUB'), new \DateTimeImmutable('2026-11-01'), null, 'Ввод остатков'));
            $first = $post($this->companyId, $this->actorId, $draft->documentId, $draft->version);
            $again = $post($this->companyId, $this->actorId, $draft->documentId, $draft->version);
            self::assertSame($first->itemId, $again->itemId);
            self::assertTrue($again->replayed);
        }
        self::assertSame(4, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM settlement_movements WHERE company_id = ?', [$this->companyId]));
        self::assertSame('10004', (string) $this->connection->fetchOne('SELECT SUM(delta_amount_minor) FROM settlement_movements WHERE company_id = ?', [$this->companyId]));
    }

    public function testCrossCompanyDocumentCannotBePosted(): void
    {
        $draft = $this->draft();
        $owner = $this->company->getUser();
        self::assertNotNull($owner);
        $other = CompanyBuilder::aCompany()->withIndex(2)->withOwner($owner)->build();
        $this->em->persist($other);
        $this->em->flush();
        $otherId = $other->getId();
        self::assertNotNull($otherId);
        (self::getContainer()->get(ConfigureSettlementBookAction::class))($otherId, $this->actorId, new \DateTimeImmutable('2026-11-01'));
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Документ не найден.');
        (self::getContainer()->get(PostSettlementDocumentAction::class))($otherId, $this->actorId, $draft->documentId, $draft->version);
    }

    public function testRevokedRightsAreRecheckedBeforePosting(): void
    {
        $draft = $this->draft();
        $other = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('other@example.test')->build();
        $this->em->persist($other);
        $this->em->flush();
        $this->connection->executeStatement('UPDATE companies SET user_id = ? WHERE id = ?', [$other->getId(), $this->companyId]);
        $this->expectException(AccessDeniedException::class);
        (self::getContainer()->get(PostSettlementDocumentAction::class))($this->companyId, $this->actorId, $draft->documentId, $draft->version);
    }

    public function testPostingOrderDoesNotDependOnMovementUuid(): void
    {
        $draft = $this->draft();
        $posted = (self::getContainer()->get(PostSettlementDocumentAction::class))($this->companyId, $this->actorId, $draft->documentId, $draft->version);
        self::assertNotNull($posted->itemId);
        $correct = self::getContainer()->get(CorrectSettlementItemAction::class);
        $increase = $correct($this->companyId, $this->actorId, new CorrectSettlementItemInput(Uuid::uuid7()->toString(), $posted->itemId, Money::fromString('100.00', 'RUB'), true, new \DateTimeImmutable('2026-11-01'), 'Увеличение'));
        $this->connection->executeStatement("UPDATE settlement_movements SET id = 'ffffffff-ffff-7fff-afff-ffffffffffff' WHERE company_id = ? AND document_id = ?", [$this->companyId, $draft->documentId]);
        $this->connection->executeStatement("UPDATE settlement_movements SET id = 'eeeeeeee-eeee-7eee-aeee-eeeeeeeeeeee' WHERE company_id = ? AND document_id = ?", [$this->companyId, $increase->documentId]);
        $this->em->clear();
        $correct($this->companyId, $this->actorId, new CorrectSettlementItemInput(Uuid::uuid7()->toString(), $posted->itemId, Money::fromString('150.00', 'RUB'), false, new \DateTimeImmutable('2026-11-01'), 'Уменьшение'));
        self::assertSame('5000', (string) $this->connection->fetchOne('SELECT SUM(delta_amount_minor) FROM settlement_movements WHERE company_id = ?', [$this->companyId]));
    }

    public function testBackdatedCorrectionCannotBreakLaterInterval(): void
    {
        $draft = $this->draft();
        $posted = (self::getContainer()->get(PostSettlementDocumentAction::class))($this->companyId, $this->actorId, $draft->documentId, $draft->version);
        self::assertNotNull($posted->itemId);
        $correct = self::getContainer()->get(CorrectSettlementItemAction::class);
        $decrease = $correct($this->companyId, $this->actorId, new CorrectSettlementItemInput(Uuid::uuid7()->toString(), $posted->itemId, Money::fromString('100.00', 'RUB'), false, new \DateTimeImmutable('2026-11-02'), 'Уменьшение'));
        (self::getContainer()->get(ReverseSettlementDocumentAction::class))($this->companyId, $this->actorId, $decrease->documentId, new ReverseSettlementInput(Uuid::uuid7()->toString(), new \DateTimeImmutable('2026-11-04'), 'Отмена'));
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Изменение создаёт отрицательный остаток в истории.');
        $correct($this->companyId, $this->actorId, new CorrectSettlementItemInput(Uuid::uuid7()->toString(), $posted->itemId, Money::fromString('1.00', 'RUB'), false, new \DateTimeImmutable('2026-11-03'), 'Ошибка задним числом'));
    }

    public function testReadOnlyMemberCannotPostDocument(): void
    {
        $draft = $this->draft();
        $reader = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('reader@example.test')->build();
        $role = new \App\Company\Entity\CompanyRole(Uuid::uuid7()->toString(), 'Чтение', ['finance' => 'read'], $this->company);
        $member = \App\Tests\Builders\Company\CompanyMemberBuilder::aMember()->withCompany($this->company)->withUser($reader)->withAccessRole($role)->build();
        foreach ([$reader, $role, $member] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $readerId = $reader->getId();
        self::assertNotNull($readerId);
        $this->expectException(AccessDeniedException::class);
        (self::getContainer()->get(PostSettlementDocumentAction::class))($this->companyId, $readerId, $draft->documentId, $draft->version);
    }

    /** @return array{SettlementResult, SettlementResult} */
    private function obligationWithCorrection(): array
    {
        $draft = $this->draft();
        $posted = (self::getContainer()->get(PostSettlementDocumentAction::class))($this->companyId, $this->actorId, $draft->documentId, $draft->version);
        self::assertNotNull($posted->itemId);
        $correction = (self::getContainer()->get(CorrectSettlementItemAction::class))($this->companyId, $this->actorId, new CorrectSettlementItemInput(Uuid::uuid7()->toString(), $posted->itemId, Money::fromString('10.00', 'RUB'), true, new \DateTimeImmutable('2026-11-02'), 'Увеличение'));

        return [$posted, $correction];
    }

    public function testActiveCorrectionBlocksObligationReversal(): void
    {
        [$posted] = $this->obligationWithCorrection();
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Сначала отмените зависимые события с допустимой датой.');
        (self::getContainer()->get(ReverseSettlementDocumentAction::class))($this->companyId, $this->actorId, $posted->documentId, new ReverseSettlementInput(Uuid::uuid7()->toString(), new \DateTimeImmutable('2026-11-03'), 'Отмена'));
    }

    public function testDependencyReversalLaterThanObligationReversalIsRejected(): void
    {
        [$posted, $correction] = $this->obligationWithCorrection();
        $reverse = self::getContainer()->get(ReverseSettlementDocumentAction::class);
        $reverse($this->companyId, $this->actorId, $correction->documentId, new ReverseSettlementInput(Uuid::uuid7()->toString(), new \DateTimeImmutable('2026-11-04'), 'Отмена уточнения'));
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Сначала отмените зависимые события с допустимой датой.');
        $reverse($this->companyId, $this->actorId, $posted->documentId, new ReverseSettlementInput(Uuid::uuid7()->toString(), new \DateTimeImmutable('2026-11-03'), 'Отмена основания'));
    }

    public function testObligationReversalAfterAllDependenciesLeavesZeroBalance(): void
    {
        [$posted, $correction] = $this->obligationWithCorrection();
        $reverse = self::getContainer()->get(ReverseSettlementDocumentAction::class);
        $reverse($this->companyId, $this->actorId, $correction->documentId, new ReverseSettlementInput(Uuid::uuid7()->toString(), new \DateTimeImmutable('2026-11-04'), 'Отмена уточнения'));
        $reverse($this->companyId, $this->actorId, $posted->documentId, new ReverseSettlementInput(Uuid::uuid7()->toString(), new \DateTimeImmutable('2026-11-04'), 'Отмена основания'));
        self::assertSame('0', (string) $this->connection->fetchOne('SELECT SUM(delta_amount_minor) FROM settlement_movements WHERE company_id = ?', [$this->companyId]));
    }

    public function testForeignCounterpartyCannotBeUsedInObligation(): void
    {
        $owner = $this->company->getUser();
        self::assertNotNull($owner);
        $other = CompanyBuilder::aCompany()->withIndex(2)->withOwner($owner)->build();
        $party = CounterpartyBuilder::aCounterparty()->withId(Uuid::uuid7()->toString())->withCompany($other)->build();
        $this->em->persist($other);
        $this->em->persist($party);
        $this->em->flush();
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Неверное основание или контрагент.');
        (self::getContainer()->get(CreateSettlementDocumentAction::class))($this->companyId, $this->actorId, new CreateSettlementDocumentInput(Uuid::uuid7()->toString(), SettlementDocumentKind::OBLIGATION, $party->getId(), SettlementBucket::PAYABLE, Money::fromString('100.00', 'RUB'), new \DateTimeImmutable('2026-11-01'), null, 'Акт'));
    }
}
