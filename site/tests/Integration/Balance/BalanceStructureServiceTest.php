<?php

declare(strict_types=1);

namespace App\Tests\Integration\Balance;

use App\Balance\Application\BalanceLedgerService;
use App\Balance\Application\BalanceStructureService;
use App\Balance\Application\SeedBalanceStructureAction;
use App\Balance\Domain\Policy\BalanceStructurePolicy;
use App\Balance\Entity\BalanceCategory;
use App\Balance\Enum\BalanceCategoryType;
use App\Balance\Exception\BalanceLedgerException;
use App\Balance\Facade\BalanceFacade;
use App\Balance\Repository\BalanceCategoryRepositoryInterface;
use App\Balance\Security\BalanceAccess;
use App\Company\Facade\CompanyFacade;
use App\Shared\Service\ActiveCompanyService;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\SecurityBundle\Security;

final class BalanceStructureServiceTest extends IntegrationTestCase
{
    private BalanceStructureService $structure;
    private BalanceLedgerService $ledger;
    private string $companyId;
    private string $actorId;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7()->toString().'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();
        $this->companyId = (string) $company->getId();
        $this->actorId = (string) $owner->getId();
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($owner);
        $security->method('isGranted')->willReturn(true);
        $active = $this->createMock(ActiveCompanyService::class);
        $active->method('getActiveCompany')->willReturn($company);
        $access = new BalanceAccess($security, self::getContainer()->get(CompanyFacade::class), $this->connection, $active);
        $repository = self::getContainer()->get(BalanceCategoryRepositoryInterface::class);
        $this->structure = new BalanceStructureService($this->connection, $this->em, $repository, new BalanceStructurePolicy($repository), $access);
        $this->ledger = new BalanceLedgerService($this->connection, $access);
    }

    public function testSeedsStandaloneArticlesWithoutAccountsOrExternalLinks(): void
    {
        $seed = self::getContainer()->get(SeedBalanceStructureAction::class);
        self::assertTrue($seed($this->companyId));
        self::assertFalse($seed($this->companyId));
        self::assertSame(['asset', 'passive'], $this->connection->fetchFirstColumn('SELECT DISTINCT type FROM balance_articles WHERE company_id=? ORDER BY type', [$this->companyId]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM balance_accounts WHERE company_id=?', [$this->companyId]));
        self::assertFalse((bool) $this->connection->fetchOne('SELECT initialized FROM balance_books WHERE company_id=?', [$this->companyId]));
    }

    public function testAccountsRequireTerminalArticlesAndUniqueCompanyCode(): void
    {
        $group = $this->article('Group', 'GROUP', 'group');
        $terminal = $this->article('Cash', 'CASH', 'article', $group);
        $account = $this->structure->saveAccount($this->companyId, $this->actorId, $terminal, 'Bank', 'BANK', false);
        self::assertSame('0', (string) $this->connection->fetchOne('SELECT balance FROM balance_account_states WHERE company_id=? AND account_id=?', [$this->companyId, $account]));
        $this->expectException(BalanceLedgerException::class);
        $this->structure->saveAccount($this->companyId, $this->actorId, $group, 'Bad account', 'BAD', false);
    }

    public function testPostedHistoryFreezesAncestorsAndNegativePolicy(): void
    {
        $group = $this->article('Group', 'GROUP', 'group');
        $terminal = $this->article('Cash', 'CASH', 'article', $group);
        $passive = $this->structure->saveCategory($this->companyId, $this->actorId, 'Capital', BalanceCategoryType::PASSIVE, null, 'EQUITY', 'article');
        $cash = $this->structure->saveAccount($this->companyId, $this->actorId, $terminal, 'Bank', 'BANK', false);
        $equity = $this->structure->saveAccount($this->companyId, $this->actorId, $passive, 'Capital', 'CAPITAL', true);
        $this->ledger->configureBook($this->companyId, 'RUB', '2025-01-01', $this->actorId);
        $opening = $this->ledger->saveDraft($this->companyId, $this->actorId, 'open', 'opening', '2025-01-01', 'Opening', [['accountId' => $cash, 'direction' => 'increase', 'amount' => '100'], ['accountId' => $equity, 'direction' => 'increase', 'amount' => '100']]);
        $this->ledger->post($this->companyId, $this->actorId, $opening, 1);
        $newParent = $this->article('New', 'NEW', 'group');
        try {
            $this->structure->saveCategory($this->companyId, $this->actorId, 'Group', BalanceCategoryType::ASSET, $newParent, 'GROUP', 'group', $group);
            self::fail('Used ancestor must not move.');
        } catch (BalanceLedgerException) {
            self::assertNull($this->connection->fetchOne('SELECT parent_id FROM balance_articles WHERE company_id=? AND id=?', [$this->companyId, $group]));
        }
        $this->expectException(BalanceLedgerException::class);
        $this->structure->saveAccount($this->companyId, $this->actorId, $terminal, 'Bank', 'BANK', true, $cash);
    }

    public function testArchivingRetainsAccountStateAndAuditsRename(): void
    {
        $article = $this->article('Cash', 'CASH', 'article');
        $account = $this->structure->saveAccount($this->companyId, $this->actorId, $article, 'Bank', 'BANK', false);
        $this->structure->saveCategory($this->companyId, $this->actorId, 'Renamed', BalanceCategoryType::ASSET, null, 'CASH', 'article', $article);
        $this->structure->archiveAccount($this->companyId, $this->actorId, $account, true);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM balance_account_states WHERE company_id=? AND account_id=?', [$this->companyId, $account]));
        $changes = (string) $this->connection->fetchOne("SELECT changes FROM balance_audit_events WHERE company_id=? AND object_id=? AND action='update'", [$this->companyId, $article]);
        self::assertStringContainsString('Renamed', $changes);
        self::assertStringContainsString('Cash', $changes);
    }

    public function testReportCountsEachAccountOnceAndRetainsArchivedHiddenAmounts(): void
    {
        self::getContainer()->get(SeedBalanceStructureAction::class)($this->companyId);
        $cashArticle = (string) $this->connection->fetchOne("SELECT id FROM balance_articles WHERE company_id=? AND code='CASH'", [$this->companyId]);
        $capitalArticle = (string) $this->connection->fetchOne("SELECT id FROM balance_articles WHERE company_id=? AND code='CONTRIBUTED_CAPITAL'", [$this->companyId]);
        $cash = $this->structure->saveAccount($this->companyId, $this->actorId, $cashArticle, 'Bank', 'BANK', false);
        $capital = $this->structure->saveAccount($this->companyId, $this->actorId, $capitalArticle, 'Capital', 'CAPITAL', true);
        $this->ledger->configureBook($this->companyId, 'RUB', '2025-01-01', $this->actorId);
        $opening = $this->ledger->saveDraft($this->companyId, $this->actorId, 'open', 'opening', '2025-01-01', 'Opening', [['accountId' => $cash, 'direction' => 'increase', 'amount' => '100.01'], ['accountId' => $capital, 'direction' => 'increase', 'amount' => '100.01']]);
        $this->ledger->post($this->companyId, $this->actorId, $opening, 1);
        $this->structure->archiveAccount($this->companyId, $this->actorId, $cash, true);
        $category = $this->em->find(BalanceCategory::class, $cashArticle);
        self::assertInstanceOf(BalanceCategory::class, $category);
        $this->structure->saveCategory($this->companyId, $this->actorId, $category->getName(), BalanceCategoryType::ASSET, $category->getParent()?->getId(), 'CASH', 'article', $cashArticle, false);
        $report = self::getContainer()->get(BalanceFacade::class)->getReportForCompany($this->companyId, new \DateTimeImmutable('2025-01-01'));
        self::assertTrue($report->isInitialized());
        self::assertSame('10001', $report->getAssetMinor());
        self::assertSame('10001', $report->getPassiveMinor());
        self::assertSame('0', $report->getDifferenceMinor());
        self::assertSame('10001', $report->getRoots()[0]->amountMinor);
        self::assertSame('RUB', $report->getCurrency());
    }

    public function testRejectedMutationDoesNotLeakManagedChangesIntoNextCommand(): void
    {
        $id = $this->article('Asset', 'ASSET', 'article');
        try {
            $this->structure->saveCategory($this->companyId, $this->actorId, 'Rejected', BalanceCategoryType::PASSIVE, $id, 'ASSET', 'article', $id);
            self::fail('Self parent must be rejected.');
        } catch (\DomainException) {
        }
        $this->article('Another asset', 'ANOTHER', 'article');
        self::assertSame('asset', $this->connection->fetchOne('SELECT type FROM balance_articles WHERE company_id=? AND id=?', [$this->companyId, $id]));
    }

    private function article(string $name, string $code, string $kind, ?string $parent = null): string
    {
        return $this->structure->saveCategory($this->companyId, $this->actorId, $name, BalanceCategoryType::ASSET, $parent, $code, $kind);
    }
}
