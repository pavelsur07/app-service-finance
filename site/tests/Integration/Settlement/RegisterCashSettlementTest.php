<?php

declare(strict_types=1);

namespace App\Tests\Integration\Settlement;

use App\Settlement\Application\ConfigureSettlementBookAction;
use App\Settlement\Application\DTO\RegisterCashSettlementInput;
use App\Settlement\Application\DTO\ReverseSettlementInput;
use App\Settlement\Application\RegisterCashSettlementAction;
use App\Settlement\Application\ReverseSettlementDocumentAction;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class RegisterCashSettlementTest extends IntegrationTestCase
{
    public function testPaymentReplayAndReinclusionPreserveHistoricalAmounts(): void
    {
        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $party = CounterpartyBuilder::aCounterparty()->withCompany($company)->build();
        $cash = CashTransactionBuilder::aCashTransaction()->forCompany($company)->withAmount('40000.00')->build();
        $date = new \DateTimeImmutable('2026-11-01');
        $cash->setOccurredAt($date);
        $cash->setCounterparty($party);
        foreach ([$user, $company, $party, $cash->getMoneyAccount(), $cash] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $companyId = $company->getId();
        $actorId = $user->getId();
        $cashId = $cash->getId();
        self::assertNotNull($companyId);
        self::assertNotNull($actorId);
        self::assertNotNull($cashId);
        (self::getContainer()->get(ConfigureSettlementBookAction::class))($companyId, $actorId, $date);
        $action = self::getContainer()->get(RegisterCashSettlementAction::class);
        $input = new RegisterCashSettlementInput(Uuid::uuid7()->toString(), $cashId, false, $date);
        $first = $action($companyId, $actorId, $input);
        $again = $action($companyId, $actorId, $input);
        self::assertSame($first->documentId, $again->documentId);
        self::assertTrue($again->replayed);
        self::assertSame('4000000', (string) $this->connection->fetchOne('SELECT SUM(delta_amount_minor) FROM settlement_movements WHERE company_id = ?', [$companyId]));
        self::assertSame('unallocated_out', $this->connection->fetchOne('SELECT bucket FROM settlement_items WHERE company_id = ?', [$companyId]));
        (self::getContainer()->get(ReverseSettlementDocumentAction::class))($companyId, $actorId, $first->documentId, new ReverseSettlementInput(Uuid::uuid7()->toString(), new \DateTimeImmutable('2026-11-03'), 'Отмена включения'));
        $action($companyId, $actorId, new RegisterCashSettlementInput(Uuid::uuid7()->toString(), $cashId, true, new \DateTimeImmutable('2026-11-04')));
        self::assertSame('0', (string) $this->connection->fetchOne("SELECT SUM(delta_amount_minor) FROM settlement_movements WHERE company_id = ? AND effective_date <= '2026-11-03'", [$companyId]));
        self::assertSame('4000000', (string) $this->connection->fetchOne('SELECT SUM(delta_amount_minor) FROM settlement_movements WHERE company_id = ?', [$companyId]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cash_transaction WHERE company_id = ?', [$companyId]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM documents WHERE company_id = ?', [$companyId]));
    }

    /** @return iterable<string, array{string}> */
    public static function ineligibleSources(): iterable
    {
        foreach (['no party', 'transfer', 'deleted', 'currency', 'before start', 'foreign source'] as $case) {
            yield $case => [$case];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ineligibleSources')]
    public function testIneligibleSourceCannotBecomeSettlementPayment(string $case): void
    {
        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $party = CounterpartyBuilder::aCounterparty()->withCompany($company)->build();
        $cash = CashTransactionBuilder::aCashTransaction()->forCompany($company)->withAmount('40000.00')->withCurrency('currency' === $case ? 'USD' : 'RUB')->build();
        $date = new \DateTimeImmutable('before start' === $case ? '2026-10-31' : '2026-11-01');
        $cash->setOccurredAt($date);
        if ('no party' !== $case) {
            $cash->setCounterparty($party);
        }
        if ('transfer' === $case) {
            $cash->setIsTransfer(true);
        }
        if ('deleted' === $case) {
            $cash->markDeleted($user->getId());
        }
        foreach ([$user, $company, $party, $cash->getMoneyAccount(), $cash] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $companyId = $company->getId();
        $actorId = $user->getId();
        $cashId = $cash->getId();
        self::assertNotNull($companyId);
        self::assertNotNull($actorId);
        self::assertNotNull($cashId);
        (self::getContainer()->get(ConfigureSettlementBookAction::class))($companyId, $actorId, new \DateTimeImmutable('2026-11-01'));
        $this->expectException(\DomainException::class);
        (self::getContainer()->get(RegisterCashSettlementAction::class))($companyId, $actorId, new RegisterCashSettlementInput(Uuid::uuid7()->toString(), 'foreign source' === $case ? Uuid::uuid7()->toString() : $cashId, false, $date));
    }
}
