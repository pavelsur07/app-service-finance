<?php

declare(strict_types=1);

namespace App\Tests\Integration\Settlement;

use App\Settlement\Application\ConfigureSettlementBookAction;
use App\Settlement\Application\CreateSettlementDocumentAction;
use App\Settlement\Application\DTO\CreateSettlementDocumentInput;
use App\Settlement\Application\PostSettlementDocumentAction;
use App\Settlement\Command\RebuildSettlementRegisterCommand;
use App\Settlement\Command\VerifySettlementRegisterCommand;
use App\Settlement\Enum\SettlementBucket;
use App\Settlement\Enum\SettlementDocumentKind;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Tester\CommandTester;

final class SettlementRegisterVerificationTest extends IntegrationTestCase
{
    public function testIndependentVerificationAndRepeatableRebuildRepairJournalOnly(): void
    {
        $actor = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($actor)->build();
        $party = CounterpartyBuilder::aCounterparty()->withCompany($company)->build();
        foreach ([$actor, $company, $party] as $entity) {
            $this->em->persist($entity);
        } $this->em->flush();
        $companyId = $company->getId();
        $actorId = $actor->getId();
        self::assertNotNull($companyId);
        self::assertNotNull($actorId);
        (self::getContainer()->get(ConfigureSettlementBookAction::class))($companyId, $actorId, new \DateTimeImmutable('2026-11-01'));
        $draft = (self::getContainer()->get(CreateSettlementDocumentAction::class))($companyId, $actorId, new CreateSettlementDocumentInput(Uuid::uuid7()->toString(), SettlementDocumentKind::OBLIGATION, $party->getId(), SettlementBucket::PAYABLE, Money::fromString('100.00', 'RUB'), new \DateTimeImmutable('2026-11-02'), null, 'Акт'));
        (self::getContainer()->get(PostSettlementDocumentAction::class))($companyId, $actorId, $draft->documentId, $draft->version);
        $verify = new CommandTester(self::getContainer()->get(VerifySettlementRegisterCommand::class));
        self::assertSame(0, $verify->execute(['companyId' => $companyId]));
        $this->connection->executeStatement('UPDATE settlement_movements SET delta_amount_minor=1 WHERE company_id=?', [$companyId]);
        self::assertSame(1, $verify->execute(['companyId' => $companyId]));
        $rebuild = new CommandTester(self::getContainer()->get(RebuildSettlementRegisterCommand::class));
        self::assertSame(0, $rebuild->execute(['companyId' => $companyId]));
        self::assertSame('1', (string) $this->connection->fetchOne('SELECT delta_amount_minor FROM settlement_movements WHERE company_id=?', [$companyId]));
        self::assertSame(0, $rebuild->execute(['companyId' => $companyId, '--execute' => true]));
        self::assertSame(0, $verify->execute(['companyId' => $companyId]));
        $rows = $this->connection->fetchAllAssociative('SELECT document_id,item_id,bucket,effective_date,delta_amount_minor FROM settlement_movements WHERE company_id=?', [$companyId]);
        self::assertSame(0, $rebuild->execute(['companyId' => $companyId, '--execute' => true]));
        self::assertSame($rows, $this->connection->fetchAllAssociative('SELECT document_id,item_id,bucket,effective_date,delta_amount_minor FROM settlement_movements WHERE company_id=?', [$companyId]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM settlement_documents WHERE company_id=?', [$companyId]));
        $this->connection->executeStatement('DELETE FROM settlement_movements WHERE company_id=?', [$companyId]);
        self::assertSame(1, $verify->execute(['companyId' => $companyId]));
        self::assertSame(0, $rebuild->execute(['companyId' => $companyId, '--execute' => true]));
        self::assertSame(0, $verify->execute(['companyId' => $companyId]));
    }
}
