<?php

declare(strict_types=1);

namespace App\Tests\Integration\Balance;

use App\Balance\Application\BalanceLedgerService;
use App\Balance\Application\BalancePeriodAction;
use App\Balance\Application\GrantBalanceAccessAction;
use App\Balance\Exception\BalanceLedgerException;
use App\Balance\Infrastructure\Query\LedgerQuery;
use App\Balance\Security\BalanceAccess;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\CompanyRole;
use App\Company\Facade\CompanyFacade;
use App\Shared\Service\ActiveCompanyService;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class LedgerReadAndPeriodTest extends IntegrationTestCase
{
    private string $company;
    private string $actor;
    private string $asset;
    private string $passive;
    private BalanceAccess $access;
    private LedgerQuery $query;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7().'@test.example')->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();
        $this->actor = (string) $owner->getId();
        $this->company = (string) $company->getId();
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($owner);
        $security->method('isGranted')->willReturn(true);
        $active = $this->createMock(ActiveCompanyService::class);
        $active->method('getActiveCompany')->willReturn($company);
        $this->access = new BalanceAccess($security, self::getContainer()->get(CompanyFacade::class), $this->connection, $active);
        $this->query = new LedgerQuery($this->connection);
        $now = '2026-01-01 00:00:00';
        $this->connection->insert('balance_books', ['id' => Uuid::uuid7()->toString(), 'company_id' => $this->company, 'currency' => 'RUB', 'start_date' => '2026-01-01', 'initialized' => true, 'created_at' => $now, 'updated_at' => $now]);
        foreach (['asset', 'passive'] as $type) {
            $article = Uuid::uuid7()->toString();
            $account = Uuid::uuid7()->toString();
            $this->connection->insert('balance_articles', ['id' => $article, 'company_id' => $this->company, 'name' => $type, 'code' => $type, 'type' => $type, 'kind' => 'article', 'created_at' => $now, 'updated_at' => $now]);
            $this->connection->insert('balance_accounts', ['id' => $account, 'company_id' => $this->company, 'article_id' => $article, 'name' => $type, 'code' => $type, 'created_at' => $now, 'updated_at' => $now]);
            $this->connection->insert('balance_account_states', ['id' => Uuid::uuid7()->toString(), 'company_id' => $this->company, 'account_id' => $account, 'balance' => '15000', 'updated_at' => $now]);
            $this->{$type} = $account;
        }
        $this->operation(1, 'opening', '2026-01-01', '10000');
        $this->operation(2, 'operation', '2026-01-10', '5000');
    }

    public function testReportsReconcileAndOpeningIsNotTurnover(): void
    {
        $statement = $this->query->statement($this->company, '2026-01-01', '2026-01-31');
        self::assertSame('15000', $statement['asset']);
        self::assertSame('0', $statement['difference']);
        self::assertSame('10000', $statement['accounts'][0]['opening']);
        self::assertSame('5000', $statement['accounts'][0]['increase']);
        $card = $this->query->accountCard($this->company, $this->asset, '2026-01-01', '2026-01-31');
        self::assertCount(1, $card['entries']);
        self::assertSame('15000', $card['entries'][0]['balance']);
        self::assertSame('10000', $this->query->balance($this->company, '2026-01-09')['asset']);
        self::assertSame('5000', $this->query->compare($this->company, '2026-01-09', '2026-01-31')['after']['accounts'][0]['change']);
        self::assertSame(2, $this->query->journal($this->company)['total']);
        $document = $this->query->document($this->company, $this->query->journal($this->company)['items'][0]['id']);
        self::assertCount(2, $document['lines']);
        self::assertSame('5000', $document['lines'][0]['amount']);
        self::assertSame('0', $this->query->statement(Uuid::uuid7()->toString(), '2026-01-01', '2026-01-31')['asset']);
    }

    public function testCloseReopenAndSkippedMonthRejected(): void
    {
        $action = new BalancePeriodAction($this->connection, $this->access, $this->query);
        self::assertSame(['drafts' => 0], $action($this->company, $this->actor, '2026-01-01', true, 'Проверено'));
        self::assertTrue($this->query->periods($this->company)[0]['is_closed']);
        $action($this->company, $this->actor, '2026-01-01', false, 'Исправление');
        self::assertFalse($this->query->periods($this->company)[0]['is_closed']);
        $this->expectException(BalanceLedgerException::class);
        $action($this->company, $this->actor, '2026-02-01', true, 'Пропуск января');
    }

    public function testCorruptMaterializedStatePreventsClosing(): void
    {
        $this->connection->executeStatement('UPDATE balance_account_states SET balance=1 WHERE company_id=? AND account_id=?', [$this->company, $this->asset]);
        $this->expectException(BalanceLedgerException::class);
        (new BalancePeriodAction($this->connection, $this->access, $this->query))($this->company, $this->actor, '2026-01-01', true, 'Проверка');
    }

    public function testSpoofedActorCannotUseOwnerPermissions(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->access->require($this->company, Uuid::uuid7()->toString(), 'manage');
    }

    public function testMemberHasNoAutomaticGrantAndRevocationWins(): void
    {
        $member = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7().'@test.example')->build();
        $company = self::getContainer()->get(CompanyFacade::class)->findById($this->company);
        self::assertNotNull($company);
        $membership = new CompanyMember(Uuid::uuid7()->toString(), $company, $member, CompanyMember::ROLE_OPERATOR);
        $this->em->persist($member);
        $role = new CompanyRole(Uuid::uuid7()->toString(), 'Finance', ['finance' => 'write'], $company);
        $membership->setAccessRole($role);
        $this->em->persist($role);
        $this->em->persist($membership);
        $this->em->flush();
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($member);
        $security->method('isGranted')->willReturn(true);
        $active = $this->createMock(ActiveCompanyService::class);
        $active->method('getActiveCompany')->willReturn($company);
        $access = new BalanceAccess($security, self::getContainer()->get(CompanyFacade::class), $this->connection, $active);
        try {
            $access->require($this->company, (string) $member->getId(), 'prepare');
            self::fail('Member got an automatic grant.');
        } catch (AccessDeniedException) {
        }
        $grant = new GrantBalanceAccessAction($this->connection, $this->access, self::getContainer()->get(CompanyFacade::class));
        $grant($this->company, $this->actor, (string) $member->getId(), true, false, false);
        $access->require($this->company, (string) $member->getId(), 'prepare');
        self::assertTrue($this->query->grants($this->company)[0]['can_prepare']);
        self::assertFalse($access->permissions($this->company)['manage']);
        try {
            (new GrantBalanceAccessAction($this->connection, $access, self::getContainer()->get(CompanyFacade::class)))($this->company, (string) $member->getId(), (string) $member->getId(), true, true, true);
            self::fail('Member escalated own permissions.');
        } catch (AccessDeniedException) {
        }
        self::assertFalse($this->query->grants($this->company)[0]['can_post']);

        $grant($this->company, $this->actor, (string) $member->getId(), true, false, true, false);
        $period = new BalancePeriodAction($this->connection, $access, $this->query);
        $period($this->company, (string) $member->getId(), '2026-01-01', true, 'Закрытие');
        self::assertFalse($access->permissions($this->company)['reopen_periods']);
        try {
            $period($this->company, (string) $member->getId(), '2026-01-01', false, 'Открытие');
            self::fail('Closing grant allowed reopening.');
        } catch (AccessDeniedException) {
        }
        $grant($this->company, $this->actor, (string) $member->getId(), true, false, true, true);
        $period($this->company, (string) $member->getId(), '2026-01-01', false, 'Разрешенное открытие');
        self::assertFalse($this->query->periods($this->company)[0]['is_closed']);
        $this->connection->executeStatement('UPDATE company_role SET permissions=? WHERE id=?', ['{"finance":"read"}', $role->getId()]);
        try {
            $access->require($this->company, (string) $member->getId(), 'prepare');
            self::fail('Cached finance write survived revocation.');
        } catch (AccessDeniedException) {
        }
        $this->connection->executeStatement("UPDATE company_members SET status='DISABLED' WHERE company_id=? AND user_id=?", [$this->company, $member->getId()]);
        $this->expectException(AccessDeniedException::class);
        $access->require($this->company, (string) $member->getId(), 'prepare');
    }

    public function testAuditIsPaginatedWithoutLosingOlderEvents(): void
    {
        $object = Uuid::uuid7()->toString();
        for ($index = 0; $index < 51; ++$index) {
            $this->connection->insert('balance_audit_events', ['id' => Uuid::uuid7()->toString(), 'company_id' => $this->company, 'object_type' => 'account', 'object_id' => $object, 'action' => 'test', 'author_id' => $this->actor, 'changes' => '{}', 'created_at' => '2026-01-01 00:00:00']);
        }
        $first = $this->query->audit($this->company, 'account', $object);
        $second = $this->query->audit($this->company, 'account', $object, 2);
        self::assertSame(51, $first['total']);
        self::assertCount(50, $first['items']);
        self::assertCount(1, $second['items']);
        self::assertSame(2, $second['pager']->getCurrentPage());
    }

    /** @return iterable<string,array{string}> */
    public static function identityQueries(): iterable
    {
        foreach (['category', 'account', 'document', 'accountCard', 'articleCard', 'audit'] as $method) {
            yield $method => [$method];
        }
    }

    #[DataProvider('identityQueries')]
    public function testMalformedReadIdentityReturnsNotFound(string $method): void
    {
        try {
            match ($method) {
                'accountCard','articleCard' => $this->query->{$method}($this->company, 'not-a-uuid', '2026-01-01', '2026-01-31'),
                'audit' => $this->query->audit($this->company, 'account', 'not-a-uuid'),
                default => $this->query->{$method}($this->company, 'not-a-uuid'),
            };
            self::fail('Malformed identity accepted.');
        } catch (BalanceLedgerException $error) {
            self::assertSame(404, $error->statusCode);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function identityFilters(): iterable
    {
        yield 'account' => ['account_id'];
        yield 'author' => ['author_id'];
    }

    #[DataProvider('identityFilters')]
    public function testMalformedJournalFilterReturnsValidationError(string $filter): void
    {
        try {
            $this->query->journal($this->company, [$filter => 'not-a-uuid']);
            self::fail('Malformed filter accepted.');
        } catch (BalanceLedgerException $error) {
            self::assertSame(422, $error->statusCode);
        }
    }

    public function testLargeRepeatedTurnoverRemainsReadableWithValidBalances(): void
    {
        $this->connection->executeStatement('UPDATE balance_books SET next_document_number=3,next_posting_sequence=3,version=2 WHERE company_id=?', [$this->company]);
        $ledger = new BalanceLedgerService($this->connection, $this->access);
        $minor = bcsub((string) \PHP_INT_MAX, '15000', 0);
        $decimal = bcdiv($minor, '100', 2);
        foreach (['increase', 'decrease', 'increase', 'decrease'] as $index => $direction) {
            $document = $ledger->saveDraft($this->company, $this->actor, 'turnover-'.$index, 'operation', '2026-01-'.(11 + $index), 'Оборот без превышения остатка', [
                ['accountId' => $this->asset, 'direction' => $direction, 'amount' => $decimal],
                ['accountId' => $this->passive, 'direction' => $direction, 'amount' => $decimal],
            ]);
            $ledger->post($this->company, $this->actor, $document, 1);
        }
        $statement = $this->query->statement($this->company, '2026-01-01', '2026-01-31');
        self::assertSame('15000', $statement['asset']);
        foreach ($statement['accounts'] as $account) {
            self::assertSame('15000', $account['closing']);
            self::assertSame(bcadd('5000', bcmul($minor, '2', 0), 0), $account['increase']);
            self::assertSame(bcmul($minor, '2', 0), $account['decrease']);
            self::assertSame(1, bccomp($account['increase'], (string) \PHP_INT_MAX, 0));
        }
    }

    public function testDocumentExposesLatestTargetPreparationForRefresh(): void
    {
        $this->connection->executeStatement('UPDATE balance_books SET next_document_number=3,next_posting_sequence=3,version=2 WHERE company_id=?', [$this->company]);
        $ledger = new BalanceLedgerService($this->connection, $this->access);
        $id = $ledger->prepareTargetCorrection($this->company, $this->actor, 'target-read', $this->asset, '2026-01-11', '160.00', 'Уточнение', 2, [['accountId' => $this->passive, 'direction' => 'increase', 'amount' => '10.00']]);
        $target = $this->query->document($this->company, $id)['target_preparation'];
        self::assertSame($this->asset, $target['accountId']);
        self::assertSame('16000', $target['target']);
        self::assertSame('1000', $target['delta']);
        self::assertSame(2, $target['journalVersion']);
        $ledger->prepareTargetCorrection($this->company, $this->actor, 'target-refresh', $this->asset, '2026-01-12', '170.00', 'Обновление', 2, [['accountId' => $this->passive, 'direction' => 'increase', 'amount' => '20.00']], $id, 1);
        $target = $this->query->document($this->company, $id)['target_preparation'];
        self::assertSame('17000', $target['target']);
        self::assertSame('2026-01-12', $target['date']);
        $opening = (string) $this->connection->fetchOne("SELECT id FROM balance_operations WHERE company_id=? AND kind='opening'", [$this->company]);
        self::assertNull($this->query->document($this->company, $opening)['target_preparation']);
    }

    public function testCategoriesAreDepthFirstWithFullPathLabels(): void
    {
        $root = Uuid::uuid7()->toString();
        $child = Uuid::uuid7()->toString();
        $leaf = Uuid::uuid7()->toString();
        $later = Uuid::uuid7()->toString();
        foreach ([[$root, null, 1, 'Root', 10], [$child, $root, 2, 'Child', 10], [$leaf, $child, 3, 'Leaf', 10], [$later, null, 1, 'Later', 20]] as [$id,$parent,$level,$name,$order]) {
            $this->connection->insert('balance_articles', ['id' => $id, 'company_id' => $this->company, 'name' => $name, 'type' => 'asset', 'kind' => 'group', 'parent_id' => $parent, 'level' => $level, 'sort_order' => $order, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        }
        $rows = array_values(array_filter($this->query->categories($this->company), static fn (array $row): bool => in_array($row['id'], [$root, $child, $leaf, $later], true)));
        self::assertSame([$root, $child, $leaf, $later], array_column($rows, 'id'));
        self::assertSame(['Root', 'Root / Child', 'Root / Child / Leaf', 'Later'], array_column($rows, 'path_name'));
    }

    public function testUnauthorizedGrantCannotStartBookMutation(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('transactional');
        $action = new GrantBalanceAccessAction($db, $this->access, self::getContainer()->get(CompanyFacade::class));
        $this->expectException(AccessDeniedException::class);
        $action($this->company, Uuid::uuid7()->toString(), $this->actor, true, true, true);
    }

    public function testUnauthorizedPeriodChangeCannotStartBookMutation(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('transactional');
        $action = new BalancePeriodAction($db, $this->access, $this->query);
        $this->expectException(AccessDeniedException::class);
        $action($this->company, Uuid::uuid7()->toString(), '2026-01-01', true, 'Закрытие');
    }

    public function testGrantToUnavailableUserIsValidationError(): void
    {
        $action = new GrantBalanceAccessAction($this->connection, $this->access, self::getContainer()->get(CompanyFacade::class));
        $this->expectException(BalanceLedgerException::class);
        $action($this->company, $this->actor, Uuid::uuid7()->toString(), true, false, false);
    }

    public function testMovementCardPaginatesWithoutLosingRunningOrPeriodTotals(): void
    {
        $this->operation(3, 'operation', '2026-01-11', '100');
        $this->operation(4, 'operation', '2026-01-12', '100');
        $this->operation(5, 'operation', '2026-01-13', '100');
        $card = $this->query->accountCard($this->company, $this->asset, '2026-01-01', '2026-01-31', 2, 2);
        self::assertCount(2, $card['entries']);
        self::assertSame(4, $card['total']);
        self::assertSame('10000', $card['opening']);
        self::assertSame('15300', $card['closing']);
        self::assertSame(['15200', '15300'], array_column($card['entries'], 'balance'));
        self::assertSame(2, $card['pager']->getCurrentPage());
        self::assertSame(200, $this->query->accountCard($this->company, $this->asset, '2026-01-01', '2026-01-31', 1, 999)['per_page']);
    }

    public function testPeriodsBeforeAndAcrossOpeningExposeActualAccountingStart(): void
    {
        $this->connection->executeStatement('UPDATE balance_books SET start_date=? WHERE company_id=?', ['2026-01-15', $this->company]);
        $this->connection->executeStatement("UPDATE balance_operations SET operation_date=CASE WHEN kind='opening' THEN DATE '2026-01-15' ELSE DATE '2026-01-20' END WHERE company_id=?", [$this->company]);
        $before = $this->query->statement($this->company, '2026-01-01', '2026-01-14');
        self::assertFalse($before['accounting_started']);
        self::assertNull($before['effective_from']);
        self::assertSame('0', $before['accounts'][0]['opening']);
        $month = $this->query->statement($this->company, '2026-01-01', '2026-01-31');
        self::assertSame('2026-01-01', $month['from']);
        self::assertSame('2026-01-15', $month['effective_from']);
        self::assertSame('10000', $month['accounts'][0]['opening']);
        self::assertSame('5000', $month['accounts'][0]['increase']);
        $day = $this->query->statement($this->company, '2026-01-15', '2026-01-15');
        self::assertSame('2026-01-15', $day['effective_from']);
        self::assertSame('10000', $day['accounts'][0]['opening']);
        self::assertSame('0', $day['accounts'][0]['increase']);
        $card = $this->query->accountCard($this->company, $this->asset, '2026-01-01', '2026-01-31');
        self::assertSame('2026-01-15', $card['effective_from']);
        self::assertTrue($card['accounting_started']);
        self::assertSame('10000', $card['opening']);
        $after = $this->query->statement($this->company, '2026-01-22', '2026-01-31');
        self::assertSame('2026-01-22', $after['effective_from']);
        self::assertSame('15000', $after['accounts'][0]['opening']);
    }

    public function testJournalReversesPostingOrderAndPlacesDraftsBeforeOpening(): void
    {
        $this->connection->executeStatement('UPDATE balance_books SET next_document_number=3,next_posting_sequence=3,version=2 WHERE company_id=?', [$this->company]);
        $ledger = new BalanceLedgerService($this->connection, $this->access);
        $lines = [['accountId' => $this->asset, 'direction' => 'increase', 'amount' => '1.00'], ['accountId' => $this->passive, 'direction' => 'increase', 'amount' => '1.00']];
        $first = $ledger->saveDraft($this->company, $this->actor, 'order-a', 'operation', '2026-01-01', 'A', $lines);
        $second = $ledger->saveDraft($this->company, $this->actor, 'order-b', 'operation', '2026-01-01', 'B', $lines);
        $ledger->post($this->company, $this->actor, $second, 1);
        $ledger->post($this->company, $this->actor, $first, 1);
        $draft = $ledger->saveDraft($this->company, $this->actor, 'order-c', 'operation', '2026-01-01', 'Draft', $lines);
        $opening = (string) $this->connection->fetchOne("SELECT id FROM balance_operations WHERE company_id=? AND kind='opening'", [$this->company]);
        $journal = $this->query->journal($this->company, ['from' => '2026-01-01', 'to' => '2026-01-01']);
        self::assertSame([$draft, $first, $second, $opening], array_column($journal['items'], 'id'));
        $posted = $this->query->journal($this->company, ['from' => '2026-01-01', 'to' => '2026-01-01', 'status' => 'posted']);
        self::assertSame([$first, $second, $opening], array_column($posted['items'], 'id'));
        $card = $this->query->accountCard($this->company, $this->asset, '2026-01-01', '2026-01-01');
        self::assertSame([$second, $first], array_column($card['entries'], 'id'));
        self::assertSame(['10100', '10200'], array_column($card['entries'], 'balance'));
    }

    public function testActiveCompanyBoundaryCannotBeBypassed(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->access->require(Uuid::uuid7()->toString(), $this->actor, 'read');
    }

    private function operation(int $number, string $kind, string $date, string $amount): void
    {
        $id = Uuid::uuid7()->toString();
        $now = '2026-01-01 00:00:00';
        $this->connection->insert('balance_operations', ['id' => $id, 'company_id' => $this->company, 'number' => $number, 'kind' => $kind, 'operation_date' => $date, 'status' => 'posted', 'reason' => 'Тест', 'author_id' => $this->actor, 'posted_by' => $this->actor, 'posted_at' => $now, 'posting_sequence' => $number, 'request_key' => $id, 'request_hash' => hash('sha256', $id), 'created_at' => $now, 'updated_at' => $now]);
        foreach ([$this->asset, $this->passive] as $account) {
            $this->connection->insert('balance_operation_lines', ['id' => Uuid::uuid7()->toString(), 'company_id' => $this->company, 'operation_id' => $id, 'account_id' => $account, 'direction' => 'increase', 'amount' => $amount]);
        }
    }
}
