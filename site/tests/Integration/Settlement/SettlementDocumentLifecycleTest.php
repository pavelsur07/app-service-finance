<?php

declare(strict_types=1);

namespace App\Tests\Integration\Settlement;

use App\Settlement\Application\ConfigureSettlementBookAction;
use App\Settlement\Application\CorrectSettlementItemAction;
use App\Settlement\Application\CreateSettlementDocumentAction;
use App\Settlement\Application\DTO\CorrectSettlementItemInput;
use App\Settlement\Application\DTO\CreateSettlementDocumentInput;
use App\Settlement\Application\PostSettlementDocumentAction;
use App\Settlement\Enum\SettlementBucket;
use App\Settlement\Enum\SettlementDocumentKind;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class SettlementDocumentLifecycleTest extends IntegrationTestCase
{
    public function testPostingCorrectionAndReplayKeepOneExactBalance(): void
    {
        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $party = CounterpartyBuilder::aCounterparty()->withCompany($company)->build();
        foreach ([$user, $company, $party] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $companyId = $company->getId();
        $actorId = $user->getId();
        self::assertNotNull($companyId);
        self::assertNotNull($actorId);
        $date = new \DateTimeImmutable('2026-11-01');
        (self::getContainer()->get(ConfigureSettlementBookAction::class))($companyId, $actorId, $date);
        $input = new CreateSettlementDocumentInput(Uuid::uuid7()->toString(), SettlementDocumentKind::OBLIGATION, $party->getId(), SettlementBucket::PAYABLE, Money::fromString('100000.00', 'RUB'), $date, null, 'Акт');
        $created = (self::getContainer()->get(CreateSettlementDocumentAction::class))($companyId, $actorId, $input);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM settlement_movements'));
        $posted = (self::getContainer()->get(PostSettlementDocumentAction::class))($companyId, $actorId, $created->documentId, $created->version);
        self::assertNotNull($posted->itemId);
        $correction = new CorrectSettlementItemInput(Uuid::uuid7()->toString(), $posted->itemId, Money::fromString('10000.00', 'RUB'), false, $date, 'Уточнение');
        $action = self::getContainer()->get(CorrectSettlementItemAction::class);
        $first = $action($companyId, $actorId, $correction);
        $replay = $action($companyId, $actorId, $correction);
        self::assertSame($first->documentId, $replay->documentId);
        self::assertTrue($replay->replayed);
        self::assertSame('9000000', (string) $this->connection->fetchOne('SELECT SUM(delta_amount_minor) FROM settlement_movements WHERE company_id = ?', [$companyId]));
        $reverse = self::getContainer()->get(\App\Settlement\Application\ReverseSettlementDocumentAction::class);
        $reversed = $reverse($companyId, $actorId, $first->documentId, new \App\Settlement\Application\DTO\ReverseSettlementInput(Uuid::uuid7()->toString(), new \DateTimeImmutable('2026-11-03'), 'Отмена уточнения'));
        $repeated = $reverse($companyId, $actorId, $first->documentId, new \App\Settlement\Application\DTO\ReverseSettlementInput(Uuid::uuid7()->toString(), new \DateTimeImmutable('2026-11-04'), 'Повтор отмены'));
        self::assertSame($reversed->documentId, $repeated->documentId);
        self::assertTrue($repeated->replayed);
        self::assertSame('10000000', (string) $this->connection->fetchOne('SELECT SUM(delta_amount_minor) FROM settlement_movements WHERE company_id = ?', [$companyId]));
        self::assertSame('9000000', (string) $this->connection->fetchOne("SELECT SUM(delta_amount_minor) FROM settlement_movements WHERE company_id = ? AND effective_date <= '2026-11-02'", [$companyId]));
    }
}
