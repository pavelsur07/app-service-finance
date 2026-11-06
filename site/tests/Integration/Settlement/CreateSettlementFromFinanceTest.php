<?php

declare(strict_types=1);

namespace App\Tests\Integration\Settlement;

use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Settlement\Application\ConfigureSettlementBookAction;
use App\Settlement\Application\CreateSettlementFromFinanceAction;
use App\Settlement\Application\DTO\CreateSettlementFromFinanceInput;
use App\Settlement\Application\PostSettlementDocumentAction;
use App\Settlement\Enum\SettlementBucket;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class CreateSettlementFromFinanceTest extends IntegrationTestCase
{
    public function testFullSettlementAmountIsIndependentFromPnlAndPostingIsExplicit(): void
    {
        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $party = CounterpartyBuilder::aCounterparty()->withCompany($company)->build();
        $date = new \DateTimeImmutable('2026-11-01');
        $source = new Document(Uuid::uuid7()->toString(), $company);
        $source->setDate($date)->setCounterparty($party);
        $operation = new DocumentOperation();
        $operation->setDocument($source)->setAmount('100000.00');
        foreach ([$user, $company, $party, $source, $operation] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $companyId = $company->getId();
        $actorId = $user->getId();
        $sourceId = $source->getId();
        self::assertNotNull($companyId);
        self::assertNotNull($actorId);
        self::assertNotNull($sourceId);
        (self::getContainer()->get(ConfigureSettlementBookAction::class))($companyId, $actorId, $date);
        $input = new CreateSettlementFromFinanceInput(Uuid::uuid7()->toString(), $sourceId, SettlementBucket::PAYABLE, Money::fromString('120000.00', 'RUB'), $date, null, 'Полная сумма к оплате');
        $create = self::getContainer()->get(CreateSettlementFromFinanceAction::class);
        $draft = $create($companyId, $actorId, $input);
        $repeat = $create($companyId, $actorId, $input);
        self::assertSame($draft->documentId, $repeat->documentId);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM settlement_movements WHERE company_id = ?', [$companyId]));
        (self::getContainer()->get(PostSettlementDocumentAction::class))($companyId, $actorId, $draft->documentId, $draft->version);
        self::assertSame('12000000', (string) $this->connection->fetchOne('SELECT SUM(delta_amount_minor) FROM settlement_movements WHERE company_id = ?', [$companyId]));
        self::assertSame('100000.00', (string) $this->connection->fetchOne('SELECT amount FROM document_operations WHERE id = ?', [$operation->getId()]));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDraftSources(): iterable
    {
        yield 'changed source date' => ['date'];
        yield 'changed operation amount' => ['amount'];
        yield 'already posted obligation' => ['duplicate'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidDraftSources')]
    public function testPostingRevalidatesSourceAndRejectsDuplicateObligation(string $case): void
    {
        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $party = CounterpartyBuilder::aCounterparty()->withCompany($company)->build();
        $date = new \DateTimeImmutable('2026-11-01');
        $source = (new Document(Uuid::uuid7()->toString(), $company))->setDate($date)->setCounterparty($party);
        $operation = (new DocumentOperation())->setDocument($source)->setAmount('100000.00');
        foreach ([$user, $company, $party, $source, $operation] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $companyId = $company->getId();
        $actorId = $user->getId();
        $sourceId = $source->getId();
        self::assertNotNull($companyId);
        self::assertNotNull($actorId);
        self::assertNotNull($sourceId);
        (self::getContainer()->get(ConfigureSettlementBookAction::class))($companyId, $actorId, $date);
        $create = self::getContainer()->get(CreateSettlementFromFinanceAction::class);
        $post = self::getContainer()->get(PostSettlementDocumentAction::class);
        $draft = $create($companyId, $actorId, new CreateSettlementFromFinanceInput(Uuid::uuid7()->toString(), $sourceId, SettlementBucket::PAYABLE, Money::fromString('120000.00', 'RUB'), $date, null, 'Полная сумма'));
        if ('duplicate' === $case) {
            $post($companyId, $actorId, $draft->documentId, $draft->version);
            $draft = $create($companyId, $actorId, new CreateSettlementFromFinanceInput(Uuid::uuid7()->toString(), $sourceId, SettlementBucket::PAYABLE, Money::fromString('120000.00', 'RUB'), $date, null, 'Второе основание'));
        } elseif ('date' === $case) {
            $this->connection->executeStatement("UPDATE documents SET date = '2026-11-02' WHERE company_id = ? AND id = ?", [$companyId, $sourceId]);
        } else {
            $this->connection->executeStatement("UPDATE document_operations SET amount = '100001.00' WHERE document_id = ?", [$sourceId]);
        }
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('duplicate' === $case ? 'Источник уже участвует во взаиморасчётах.' : 'Источник ОПиУ изменён после создания черновика.');
        $post($companyId, $actorId, $draft->documentId, $draft->version);
    }
}
