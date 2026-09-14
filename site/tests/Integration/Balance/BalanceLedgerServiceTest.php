<?php

declare(strict_types=1);

namespace App\Tests\Integration\Balance;

use App\Balance\Application\BalanceLedgerService;
use App\Balance\Exception\BalanceLedgerException;
use App\Balance\Infrastructure\Query\LedgerQuery;
use App\Balance\Security\BalanceAccess;
use App\Company\Facade\CompanyFacade;
use App\Company\Infrastructure\Repository\CompanyRepository;
use App\Company\Repository\CompanyMemberRepository;
use App\Company\Repository\CounterpartyRepository;
use App\Company\Repository\ProjectDirectionRepository;
use App\Company\Service\CompanyOwnerAccountCreator;
use App\Shared\Service\ActiveCompanyService;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260914120000;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\SecurityBundle\Security;

require_once dirname(__DIR__, 3).'/migrations/Version20260914120000.php';

final class BalanceLedgerServiceTest extends IntegrationTestCase
{
    private BalanceLedgerService $ledger;
    private string $companyId;
    private string $actorId;
    /** @var array<string, string> */
    private array $accounts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $schema = 'ledger_test_'.str_replace('-', '', Uuid::uuid7()->toString());
        $this->connection->executeStatement('CREATE SCHEMA '.$schema);
        $this->connection->executeStatement('SET LOCAL search_path TO '.$schema.', public');
        $migration = new Version20260914120000($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
        $user = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7()->toString().'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($user)->build();
        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->flush();
        $this->companyId = (string) $company->getId();
        $this->actorId = (string) $user->getId();
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $security->method('isGranted')->willReturn(true);
        $activeCompany = $this->createMock(ActiveCompanyService::class);
        $activeCompany->method('getActiveCompany')->willReturn($company);
        $access = new BalanceAccess($security, self::getContainer()->get(CompanyFacade::class), $this->connection, $activeCompany);
        $this->ledger = new BalanceLedgerService($this->connection, $access);
        $this->ledger->configureBook($this->companyId, 'RUB', '2025-01-01', $this->actorId);
        foreach (['money' => 'asset', 'equipment' => 'asset', 'equity' => 'passive', 'loan' => 'passive'] as $name => $type) {
            $articleId = Uuid::uuid7()->toString();
            $accountId = Uuid::uuid7()->toString();
            $this->connection->insert('balance_articles', ['id' => $articleId, 'company_id' => $this->companyId, 'name' => $name, 'type' => $type, 'kind' => 'article', 'level' => 1, 'created_at' => '2025-01-01', 'updated_at' => '2025-01-01']);
            $this->connection->insert('balance_accounts', ['id' => $accountId, 'company_id' => $this->companyId, 'article_id' => $articleId, 'code' => $name, 'name' => $name, 'created_at' => '2025-01-01', 'updated_at' => '2025-01-01']);
            $this->accounts[$name] = $accountId;
        }
    }

    public function testAcceptanceAndIdempotentReversal(): void
    {
        $this->opening();
        $this->document('operation', '2025-01-02', ['money' => '50000', 'loan' => '50000']);
        $purchase = $this->document('operation', '2025-01-03', ['equipment' => '30000', 'money' => '-30000']);
        $this->document('operation', '2025-01-04', ['money' => '-10000', 'loan' => '-10000']);
        self::assertSame('11000000', $this->balance('money'));
        self::assertSame('3000000', $this->balance('equipment'));
        self::assertSame('10000000', $this->balance('equity'));
        self::assertSame('4000000', $this->balance('loan'));
        $this->ledger->post($this->companyId, $this->actorId, $purchase, 1);
        self::assertSame('11000000', $this->balance('money'));
        $reversal = $this->ledger->reverse($this->companyId, $this->actorId, $purchase, '2025-01-05', 'Ошибка покупки', 'reverse');
        self::assertSame($reversal, $this->ledger->reverse($this->companyId, $this->actorId, $purchase, '2025-01-05', 'Ошибка покупки', 'reverse'));
        self::assertSame('14000000', $this->balance('money'));
        self::assertSame('0', $this->balance('equipment'));
    }

    public function testBackdatedOverdraftRollsBackPostingAndAllStates(): void
    {
        $this->opening();
        $this->document('operation', '2025-01-10', ['money' => '-90000', 'equipment' => '90000']);
        $this->document('operation', '2025-01-20', ['money' => '90000', 'loan' => '90000']);
        $id = $this->document('operation', '2025-01-05', ['money' => '-20000', 'equipment' => '20000'], false);
        try {
            $this->ledger->post($this->companyId, $this->actorId, $id, 1);
            self::fail('A later historical overdraft must reject posting.');
        } catch (BalanceLedgerException $e) {
            self::assertStringContainsString('отрицательный остаток', $e->getMessage());
        }
        self::assertSame('draft', $this->connection->fetchOne('SELECT status FROM balance_operations WHERE id = ?', [$id]));
        self::assertSame('10000000', $this->balance('money'));
        self::assertSame('9000000', $this->balance('equipment'));
    }

    public function testUnbalancedDraftDoesNotChangeBalanceAndCannotPost(): void
    {
        $this->opening();
        $id = $this->document('operation', '2025-01-02', ['money' => '10', 'loan' => '9'], false);
        self::assertSame('10000000', $this->balance('money'));
        $this->expectException(BalanceLedgerException::class);
        $this->expectExceptionMessage('должны быть равны');
        $this->ledger->post($this->companyId, $this->actorId, $id, 1);
    }

    public function testRequestReplayReturnsOriginalAndChangedPayloadConflicts(): void
    {
        $lines = $this->lineInput(['money' => '100', 'equity' => '100']);
        $id = $this->ledger->saveDraft($this->companyId, $this->actorId, 'request', 'opening', '2025-01-01', 'Старт', $lines);
        self::assertSame($id, $this->ledger->saveDraft($this->companyId, $this->actorId, 'request', 'opening', '2025-01-01', 'Старт', $lines));
        $this->expectException(BalanceLedgerException::class);
        $this->expectExceptionMessage('другими данными');
        $this->ledger->saveDraft($this->companyId, $this->actorId, 'request', 'opening', '2025-01-01', 'Иное основание', $lines);
    }

    public function testStaleDraftVersionCannotOverwriteChanges(): void
    {
        $id = $this->document('opening', '2025-01-01', [], false);
        $this->ledger->saveDraft($this->companyId, $this->actorId, 'edit', 'opening', '2025-01-01', 'Новая версия', [], $id, 1);
        $this->expectException(BalanceLedgerException::class);
        $this->expectExceptionMessage('изменен');
        $this->ledger->saveDraft($this->companyId, $this->actorId, 'edit', 'opening', '2025-01-01', 'Устарело', [], $id, 1);
    }

    public function testZeroOpeningInitializesBook(): void
    {
        $this->document('opening', '2025-01-01', []);
        self::assertTrue((bool) $this->connection->fetchOne('SELECT initialized FROM balance_books WHERE company_id = ?', [$this->companyId]));
    }

    public function testClosedPeriodRejectsPosting(): void
    {
        $this->opening();
        $this->connection->insert('balance_periods', ['id' => Uuid::uuid7()->toString(), 'company_id' => $this->companyId, 'month' => '2025-01-01', 'is_closed' => true, 'changed_by' => $this->actorId, 'reason' => 'Закрытие', 'changed_at' => '2025-02-01']);
        $this->expectException(BalanceLedgerException::class);
        $this->expectExceptionMessage('Период закрыт');
        $this->document('operation', '2025-01-02', ['money' => '10', 'loan' => '10']);
    }

    public function testTargetCorrectionRejectsStaleSnapshotAndCanRetrySuccessfulRequest(): void
    {
        $this->opening();
        $version = (int) $this->connection->fetchOne('SELECT version FROM balance_books WHERE company_id = ?', [$this->companyId]);
        $id = $this->ledger->postTargetCorrection($this->companyId, $this->actorId, 'target', $this->accounts['money'], '2025-01-02', '110000', 'Уточнение', $version, $this->lineInput(['equity' => '10000']));
        self::assertSame('11000000', $this->balance('money'));
        self::assertSame($id, $this->ledger->postTargetCorrection($this->companyId, $this->actorId, 'target', $this->accounts['money'], '2025-01-02', '110000', 'Уточнение', $version, $this->lineInput(['equity' => '10000'])));
        $this->expectException(BalanceLedgerException::class);
        $this->expectExceptionMessage('Остатки изменились');
        $this->ledger->postTargetCorrection($this->companyId, $this->actorId, 'stale', $this->accounts['money'], '2025-01-02', '120000', 'Уточнение', $version, $this->lineInput(['equity' => '10000']));
    }

    public function testDeleteDraftPreservesAuditAndRemovesLines(): void
    {
        $id = $this->document('opening', '2025-01-01', ['money' => '1', 'equity' => '1'], false);
        $this->ledger->deleteDraft($this->companyId, $this->actorId, $id, 1);
        self::assertFalse($this->connection->fetchOne('SELECT id FROM balance_operations WHERE id = ?', [$id]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM balance_operation_lines WHERE operation_id = ?', [$id]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM balance_audit_events WHERE object_id = ? AND action = 'delete_draft'", [$id]));
    }

    public function testArchivedAncestorBlocksPosting(): void
    {
        $this->opening();
        $parent = Uuid::uuid7()->toString();
        $this->connection->insert('balance_articles', ['id' => $parent, 'company_id' => $this->companyId, 'name' => 'Архивная группа', 'type' => 'asset', 'kind' => 'group', 'level' => 1, 'is_archived' => true, 'created_at' => '2025-01-01', 'updated_at' => '2025-01-01']);
        $this->connection->executeStatement('UPDATE balance_articles SET parent_id = ?, level = 2 WHERE id = (SELECT article_id FROM balance_accounts WHERE id = ?)', [$parent, $this->accounts['money']]);
        $this->expectException(BalanceLedgerException::class);
        $this->expectExceptionMessage('архив');
        $this->document('operation', '2025-01-02', ['money' => '10', 'loan' => '10']);
    }

    public function testPreparedTargetDraftRejectsChangedJournal(): void
    {
        $this->opening();
        $version = (int) $this->connection->fetchOne('SELECT version FROM balance_books WHERE company_id = ?', [$this->companyId]);
        $id = $this->ledger->prepareTargetCorrection($this->companyId, $this->actorId, 'target-draft', $this->accounts['money'], '2025-01-02', '110000', 'Уточнение', $version, $this->lineInput(['equity' => '10000']));
        self::assertSame('10000000', $this->balance('money'));
        $this->document('operation', '2025-01-02', ['money' => '1', 'loan' => '1']);
        $this->expectException(BalanceLedgerException::class);
        $this->expectExceptionMessage('Остатки изменились');
        $this->ledger->post($this->companyId, $this->actorId, $id, 1);
    }

    public function testNegativeFinancialResultRequiresExplicitAccountSetting(): void
    {
        $this->connection->executeStatement('UPDATE balance_accounts SET allow_negative = TRUE WHERE id = ?', [$this->accounts['equity']]);
        $this->document('opening', '2025-01-01', ['money' => '100', 'loan' => '150', 'equity' => '-50']);
        self::assertSame('-5000', $this->balance('equity'));
        self::assertSame('10000', $this->balance('money'));
    }

    public function testForeignAccountIsRejectedWithoutDocumentCreation(): void
    {
        $otherCompany = Uuid::uuid7()->toString();
        $articleId = Uuid::uuid7()->toString();
        $accountId = Uuid::uuid7()->toString();
        $this->connection->insert('balance_articles', ['id' => $articleId, 'company_id' => $otherCompany, 'name' => 'Other', 'type' => 'asset', 'kind' => 'article', 'level' => 1, 'created_at' => '2025-01-01', 'updated_at' => '2025-01-01']);
        $this->connection->insert('balance_accounts', ['id' => $accountId, 'company_id' => $otherCompany, 'article_id' => $articleId, 'code' => 'other', 'name' => 'Other', 'created_at' => '2025-01-01', 'updated_at' => '2025-01-01']);
        try {
            $this->ledger->saveDraft($this->companyId, $this->actorId, 'foreign', 'opening', '2025-01-01', 'Старт', [['accountId' => $accountId, 'direction' => 'increase', 'amount' => '10']]);
            self::fail('An account of another company must not be accepted.');
        } catch (BalanceLedgerException $e) {
            self::assertSame(404, $e->statusCode);
        }
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM balance_operations WHERE company_id = ?', [$this->companyId]));
    }

    public function testFuturePostingAndOpeningReversalAreForbidden(): void
    {
        $this->opening();
        $future = $this->document('operation', '2099-01-01', ['money' => '10', 'loan' => '10'], false);
        try {
            $this->ledger->post($this->companyId, $this->actorId, $future, 1);
            self::fail('Future documents cannot be posted.');
        } catch (BalanceLedgerException $e) {
            self::assertStringContainsString('сегодняшним днем', $e->getMessage());
        }
        $openingId = (string) $this->connection->fetchOne("SELECT id FROM balance_operations WHERE company_id = ? AND kind = 'opening'", [$this->companyId]);
        $this->expectException(BalanceLedgerException::class);
        $this->expectExceptionMessage('кроме начальных остатков');
        $this->ledger->reverse($this->companyId, $this->actorId, $openingId, '2025-01-02', 'Неверно', 'opening-reversal');
    }

    public function testMalformedTargetAccountReturnsDomainError(): void
    {
        $this->opening();
        $this->expectException(BalanceLedgerException::class);
        $this->ledger->prepareTargetCorrection($this->companyId, $this->actorId, 'bad-account', 'invalid-id', '2025-01-02', '1', 'Основание', 1, []);
    }

    public function testConcurrentPostingWaitsForBookLockAndCanRetryWithoutPartialState(): void
    {
        $first = DriverManager::getConnection($this->connection->getParams());
        $second = DriverManager::getConnection($this->connection->getParams());
        $schema = 'ledger_lock_'.str_replace('-', '', Uuid::uuid7()->toString());
        $first->executeStatement('CREATE SCHEMA '.$schema);
        try {
            $first->executeStatement('SET search_path TO '.$schema);
            $second->executeStatement('SET search_path TO '.$schema);
            $migration = new Version20260914120000($first, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $first->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
            foreach (['balance_books' => 'id, company_id, currency, start_date, created_at, updated_at', 'balance_articles' => 'id, company_id, name, type, kind, level, created_at, updated_at', 'balance_accounts' => 'id, company_id, article_id, code, name, created_at, updated_at'] as $table => $columns) {
                foreach ($this->connection->fetchAllAssociative('SELECT '.$columns.' FROM '.$table.' WHERE company_id = ?', [$this->companyId]) as $row) {
                    $first->insert($table, $row);
                }
            }
            $first->executeStatement('CREATE TABLE companies (id uuid PRIMARY KEY, user_id uuid NOT NULL)');
            $first->insert('companies', ['id' => $this->companyId, 'user_id' => $this->actorId]);
            $ledger = new BalanceLedgerService($second, $this->accessOnConnection($second));
            $id = $ledger->saveDraft($this->companyId, $this->actorId, 'concurrent', 'opening', '2025-01-01', 'Старт', $this->lineInput(['money' => '100', 'equity' => '100']));
            $first->beginTransaction();
            $first->fetchOne('SELECT id FROM balance_books WHERE company_id = ? FOR UPDATE', [$this->companyId]);
            $second->executeStatement("SET lock_timeout = '100ms'");
            try {
                $ledger->post($this->companyId, $this->actorId, $id, 1);
                self::fail('Another transaction must not post through the book lock.');
            } catch (DriverException $e) {
                self::assertSame('55P03', $e->getSQLState());
            }
            self::assertSame('draft', $second->fetchOne('SELECT status FROM balance_operations WHERE id = ?', [$id]));
            self::assertSame(0, (int) $second->fetchOne('SELECT COUNT(*) FROM balance_account_states'));
            $first->commit();
            $ledger->post($this->companyId, $this->actorId, $id, 1);
            $ledger->post($this->companyId, $this->actorId, $id, 1);
            self::assertSame('10000', (string) $second->fetchOne('SELECT balance FROM balance_account_states WHERE account_id = ?', [$this->accounts['money']]));
        } finally {
            if ($first->isTransactionActive()) {
                $first->rollBack();
            }
            $first->executeStatement('DROP SCHEMA '.$schema.' CASCADE');
            $first->close();
            $second->close();
        }
    }

    public function testArticleTransferShowsOnlyAtomicDocumentBalanceAtIntegerLimit(): void
    {
        $group = Uuid::uuid7()->toString();
        $this->connection->insert('balance_articles', ['id' => $group, 'company_id' => $this->companyId, 'name' => 'Активы группы', 'type' => 'asset', 'kind' => 'group', 'level' => 1, 'created_at' => '2025-01-01', 'updated_at' => '2025-01-01']);
        $this->connection->executeStatement("UPDATE balance_articles SET parent_id = ?, level = 2 WHERE company_id = ? AND type = 'asset' AND id <> ?", [$group, $this->companyId, $group]);
        $this->document('opening', '2025-01-01', ['money' => '0.01', 'equipment' => '92233720368547758.06', 'equity' => '92233720368547758.07']);
        $this->document('operation', '2025-01-02', ['money' => '0.01', 'equipment' => '-0.01']);
        $card = (new LedgerQuery($this->connection))->articleCard($this->companyId, $group, '2025-01-01', '2025-01-31');
        self::assertCount(2, $card['entries']);
        foreach ($card['entries'] as $entry) {
            self::assertSame((string) \PHP_INT_MAX, $entry['balance']);
        }
    }

    public function testAggregateOverflowRollsBackEvenWhenEveryAccountFits(): void
    {
        $id = $this->document('opening', '2025-01-01', ['money' => '92233720368547758.07', 'equipment' => '92233720368547758.07', 'equity' => '92233720368547758.07', 'loan' => '92233720368547758.07'], false);
        try {
            $this->ledger->post($this->companyId, $this->actorId, $id, 1);
            self::fail('The side aggregate exceeds the supported range.');
        } catch (BalanceLedgerException $e) {
            self::assertStringContainsString('диапазон', $e->getMessage());
        }
        self::assertSame('draft', $this->connection->fetchOne('SELECT status FROM balance_operations WHERE id = ?', [$id]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM balance_account_states WHERE company_id = ?', [$this->companyId]));
    }

    public function testBackdatedAggregateOverflowRejectsLaterHistoryDespiteValidCurrentBalance(): void
    {
        $this->document('opening', '2025-01-01', ['money' => '92233720368547757.97', 'equity' => '92233720368547757.97']);
        $this->document('operation', '2025-01-10', ['money' => '0.10', 'equity' => '0.10']);
        $this->document('operation', '2025-01-20', ['money' => '-0.10', 'equity' => '-0.10']);
        $this->expectException(BalanceLedgerException::class);
        $this->expectExceptionMessage('диапазон');
        $this->document('operation', '2025-01-05', ['equipment' => '0.01', 'loan' => '0.01']);
    }

    public function testOutOfRangeJournalPageReturnsDomainError(): void
    {
        $this->expectException(BalanceLedgerException::class);
        (new LedgerQuery($this->connection))->journal($this->companyId, [], 2);
    }

    public function testGroupOverflowIsRejectedEvenWhenSideIsWithinRange(): void
    {
        $group = Uuid::uuid7()->toString();
        $this->connection->insert('balance_articles', ['id' => $group, 'company_id' => $this->companyId, 'name' => 'Группа', 'type' => 'asset', 'kind' => 'group', 'level' => 1, 'created_at' => '2025-01-01', 'updated_at' => '2025-01-01']);
        $this->connection->executeStatement("UPDATE balance_articles SET parent_id = ?, level = 2 WHERE company_id = ? AND type = 'asset' AND id <> ?", [$group, $this->companyId, $group]);
        $article = Uuid::uuid7()->toString();
        $this->accounts['offset'] = Uuid::uuid7()->toString();
        $this->connection->insert('balance_articles', ['id' => $article, 'company_id' => $this->companyId, 'name' => 'Корректирующий актив', 'type' => 'asset', 'kind' => 'article', 'level' => 1, 'created_at' => '2025-01-01', 'updated_at' => '2025-01-01']);
        $this->connection->insert('balance_accounts', ['id' => $this->accounts['offset'], 'company_id' => $this->companyId, 'article_id' => $article, 'code' => 'offset', 'name' => 'Корректирующий актив', 'allow_negative' => true, 'created_at' => '2025-01-01', 'updated_at' => '2025-01-01']);
        $this->expectException(BalanceLedgerException::class);
        $this->expectExceptionMessage('статьи или стороны');
        $this->document('opening', '2025-01-01', ['money' => '92233720368547758.07', 'equipment' => '92233720368547758.07', 'offset' => '-92233720368547758.07', 'equity' => '92233720368547758.07']);
    }

    public function testHugeJournalPageReturnsDomainErrorBeforeOffsetOverflow(): void
    {
        $this->expectException(BalanceLedgerException::class);
        (new LedgerQuery($this->connection))->journal($this->companyId, [], \PHP_INT_MAX);
    }

    public function testAppendDoesNotReadHistoricalMovementAmounts(): void
    {
        $this->opening();
        $id = $this->document('operation', '2025-01-02', ['money' => '10', 'loan' => '10'], false);
        $opening = (string) $this->connection->fetchOne("SELECT id FROM balance_operations WHERE company_id = ? AND kind = 'opening'", [$this->companyId]);
        // A guarded history view makes accidental replay observable without a
        // timing threshold: a normal append needs states, not old movements.
        $this->guardHistoricalOperation($opening);
        $this->ledger->post($this->companyId, $this->actorId, $id, 1);
        self::assertSame('10001000', $this->balance('money'));
        self::assertSame('1000', $this->balance('loan'));
    }

    public function testBackdateReadsOnlySuffixAndPreservesCurrentBalances(): void
    {
        $this->opening();
        $this->document('operation', '2025-01-10', ['money' => '20', 'loan' => '20']);
        $id = $this->document('operation', '2025-01-05', ['money' => '-10', 'equipment' => '10'], false);
        $opening = (string) $this->connection->fetchOne("SELECT id FROM balance_operations WHERE company_id = ? AND kind = 'opening'", [$this->companyId]);
        $this->guardHistoricalOperation($opening);
        $this->ledger->post($this->companyId, $this->actorId, $id, 1);
        self::assertSame('10001000', $this->balance('money'));
        self::assertSame('1000', $this->balance('equipment'));
    }

    public function testSameDatePredecessorIsAlreadyIncludedInCurrentState(): void
    {
        $this->document('opening', '2025-01-01', []);
        $previous = $this->document('operation', '2025-01-02', ['money' => '10', 'equity' => '10']);
        $id = $this->document('operation', '2025-01-02', ['money' => '-10', 'equipment' => '10'], false);
        $this->guardHistoricalOperation($previous);
        $this->ledger->post($this->companyId, $this->actorId, $id, 1);
        self::assertSame('0', $this->balance('money'));
        self::assertSame('1000', $this->balance('equipment'));
    }

    private function guardHistoricalOperation(string $id): void
    {
        $this->connection->executeStatement("CREATE OR REPLACE FUNCTION pg_temp.reject_old_movement(bigint) RETURNS bigint LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''Historical movement read before insertion boundary''; END'");
        $this->connection->executeStatement('ALTER TABLE balance_operation_lines RENAME TO balance_operation_lines_storage');
        $this->connection->executeStatement("CREATE VIEW balance_operation_lines AS SELECT id, company_id, operation_id, account_id, direction, CASE WHEN operation_id = '".$id."'::uuid THEN pg_temp.reject_old_movement(amount) ELSE amount END AS amount FROM balance_operation_lines_storage");
    }

    public function testPostingRejectsUnseenDraftRevision(): void
    {
        $id = $this->document('opening', '2025-01-01', ['money' => '100', 'equity' => '100'], false);
        $this->ledger->saveDraft($this->companyId, $this->actorId, 'edit', 'opening', '2025-01-01', 'Изменено', $this->lineInput(['money' => '200', 'equity' => '200']), $id, 1);
        $this->expectException(BalanceLedgerException::class);
        $this->expectExceptionMessage('изменен');
        $this->ledger->post($this->companyId, $this->actorId, $id, 1);
    }

    public function testZeroOpeningRequiresExplicitConfirmationInsidePosting(): void
    {
        $id = $this->document('opening', '2025-01-01', [], false);
        $this->expectException(BalanceLedgerException::class);
        $this->expectExceptionMessage('Подтвердите');
        $this->ledger->post($this->companyId, $this->actorId, $id, 1, false);
    }

    public function testDeletedDraftRequestCannotBeResurrectedOrReused(): void
    {
        $lines = $this->lineInput(['money' => '100', 'equity' => '100']);
        $id = $this->ledger->saveDraft($this->companyId, $this->actorId, 'deleted-key', 'opening', '2025-01-01', 'Старт', $lines);
        $this->ledger->deleteDraft($this->companyId, $this->actorId, $id, 1);
        foreach (['Старт', 'Другие данные'] as $reason) {
            try {
                $this->ledger->saveDraft($this->companyId, $this->actorId, 'deleted-key', 'opening', '2025-01-01', $reason, $lines);
                self::fail('Deleted request key must remain reserved.');
            } catch (BalanceLedgerException $e) {
                self::assertSame(409, $e->statusCode);
            }
        }
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM balance_operations WHERE company_id = ?', [$this->companyId]));
    }

    public function testReversalReplayRejectsChangedPayloadAndDifferentRequestKey(): void
    {
        $this->opening();
        $id = $this->document('operation', '2025-01-02', ['money' => '100', 'loan' => '100']);
        $reversal = $this->ledger->reverse($this->companyId, $this->actorId, $id, '2025-01-03', 'Ошибка', 'reverse-exact');
        self::assertSame($reversal, $this->ledger->reverse($this->companyId, $this->actorId, $id, '2025-01-03', 'Ошибка', 'reverse-exact'));
        foreach ([['2025-01-04', 'Ошибка', 'reverse-exact'], ['2025-01-03', 'Другая причина', 'reverse-exact'], ['2025-01-03', 'Ошибка', 'new-reverse']] as [$date, $reason, $key]) {
            try {
                $this->ledger->reverse($this->companyId, $this->actorId, $id, $date, $reason, $key);
                self::fail('Only an exact reversal replay may succeed.');
            } catch (BalanceLedgerException $e) {
                self::assertSame(409, $e->statusCode);
            }
        }
    }

    public function testRebuildRestoresAllCompanyStatesIncludingUnusedAccounts(): void
    {
        $this->opening();
        $this->document('operation', '2025-01-02', ['money' => '-10', 'equipment' => '10']);
        $this->connection->executeStatement('UPDATE balance_account_states SET balance = 999 WHERE company_id = ?', [$this->companyId]);
        $this->connection->delete('balance_account_states', ['company_id' => $this->companyId, 'account_id' => $this->accounts['equipment']]);
        $count = $this->ledger->rebuildCurrentStates($this->companyId, $this->actorId, 'Восстановление после сверки');
        self::assertSame(4, $count);
        self::assertSame('9999000', $this->balance('money'));
        self::assertSame('1000', $this->balance('equipment'));
        self::assertSame('0', $this->balance('loan'));
        self::assertSame('10000000', $this->balance('equity'));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM balance_audit_events WHERE company_id = ? AND action = 'states_rebuilt'", [$this->companyId]));
    }

    public function testMissingUsedAccountStateRequiresExplicitRecovery(): void
    {
        $this->opening();
        $this->connection->delete('balance_account_states', ['company_id' => $this->companyId, 'account_id' => $this->accounts['money']]);
        $this->expectException(BalanceLedgerException::class);
        $this->expectExceptionMessage('восстановить остатки');
        $this->document('operation', '2025-01-02', ['money' => '10', 'equity' => '10']);
    }

    public function testRebuildRefusesCorruptJournalWithoutChangingStates(): void
    {
        $this->opening();
        $this->connection->executeStatement('UPDATE balance_operation_lines SET amount = amount + 1 WHERE company_id = ? AND account_id = ?', [$this->companyId, $this->accounts['money']]);
        $this->connection->executeStatement('UPDATE balance_account_states SET balance = 999 WHERE company_id = ?', [$this->companyId]);
        try {
            $this->ledger->rebuildCurrentStates($this->companyId, $this->actorId, 'Проверка поврежденного журнала');
            self::fail('An inconsistent journal cannot be used for state recovery.');
        } catch (BalanceLedgerException $e) {
            self::assertSame(409, $e->statusCode);
        }
        self::assertSame('999', $this->balance('money'));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM balance_audit_events WHERE company_id = ? AND action = 'states_rebuilt'", [$this->companyId]));
    }

    public function testRebuildRejectsPostedNonOpeningHistoryBeforeInitialization(): void
    {
        $this->opening();
        $this->document('operation', '2025-01-02', ['money' => '10', 'equity' => '10']);
        $this->connection->executeStatement("DELETE FROM balance_operation_lines WHERE company_id = ? AND operation_id IN (SELECT id FROM balance_operations WHERE company_id = ? AND kind = 'opening')", [$this->companyId, $this->companyId]);
        $this->connection->executeStatement("DELETE FROM balance_operations WHERE company_id = ? AND kind = 'opening'", [$this->companyId]);
        $this->connection->executeStatement('UPDATE balance_books SET initialized = FALSE WHERE company_id = ?', [$this->companyId]);
        $this->expectException(BalanceLedgerException::class);
        $this->expectExceptionMessage('начала учета');
        $this->ledger->rebuildCurrentStates($this->companyId, $this->actorId, 'Восстановление');
    }

    public function testDocumentReferencesSurviveDraftEditsAndDeletionWithoutDuplicates(): void
    {
        $group = Uuid::uuid7()->toString();
        $this->connection->insert('balance_articles', ['id' => $group, 'company_id' => $this->companyId, 'name' => 'Группа', 'type' => 'asset', 'kind' => 'group', 'level' => 1, 'created_at' => '2025-01-01', 'updated_at' => '2025-01-01']);
        $article = (string) $this->connection->fetchOne('SELECT article_id FROM balance_accounts WHERE company_id = ? AND id = ?', [$this->companyId, $this->accounts['money']]);
        $this->connection->executeStatement('UPDATE balance_articles SET parent_id = ?, level = 2 WHERE company_id = ? AND id = ?', [$group, $this->companyId, $article]);
        $lines = $this->lineInput(['money' => '100', 'equity' => '100']);
        $id = $this->ledger->saveDraft($this->companyId, $this->actorId, 'references', 'opening', '2025-01-01', 'Старт', $lines);
        $this->ledger->saveDraft($this->companyId, $this->actorId, 'references', 'opening', '2025-01-01', 'Обновлено', $lines, $id, 1);
        $this->ledger->saveDraft($this->companyId, $this->actorId, 'references', 'opening', '2025-01-01', 'Очищено', [], $id, 2);
        $this->ledger->deleteDraft($this->companyId, $this->actorId, $id, 3);
        foreach ([['account', $this->accounts['money']], ['article', $article], ['article', $group]] as [$type, $object]) {
            self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM balance_audit_events WHERE company_id = ? AND object_type = ? AND object_id = ? AND action = 'document_referenced'", [$this->companyId, $type, $object]));
        }
    }

    private function accessOnConnection(Connection $connection): BalanceAccess
    {
        $owner = UserBuilder::aUser()->withId($this->actorId)->build();
        $company = CompanyBuilder::aCompany()->withId($this->companyId)->withOwner($owner)->build();
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($owner);
        $active = $this->createMock(ActiveCompanyService::class);
        $active->method('getActiveCompany')->willReturn($company);
        $facade = new CompanyFacade(
            new CompanyRepository(self::getContainer()->get('doctrine'), $connection),
            self::getContainer()->get(CompanyOwnerAccountCreator::class),
            self::getContainer()->get(CounterpartyRepository::class),
            self::getContainer()->get(CompanyMemberRepository::class),
            self::getContainer()->get(ProjectDirectionRepository::class),
        );

        return new BalanceAccess($security, $facade, $connection, $active);
    }

    private function opening(): void
    {
        $this->document('opening', '2025-01-01', ['money' => '100000', 'equity' => '100000']);
    }

    /** @param array<string, string> $amounts */
    private function document(string $kind, string $date, array $amounts, bool $post = true): string
    {
        $id = $this->ledger->saveDraft($this->companyId, $this->actorId, Uuid::uuid7()->toString(), $kind, $date, 'Основание', $this->lineInput($amounts));
        if ($post) {
            $this->ledger->post($this->companyId, $this->actorId, $id, 1, true);
        }

        return $id;
    }

    /** @param array<string, string> $amounts
     * @return list<array{accountId: string, direction: string, amount: string}>
     */
    private function lineInput(array $amounts): array
    {
        $result = [];
        foreach ($amounts as $account => $amount) {
            $result[] = ['accountId' => $this->accounts[$account], 'direction' => str_starts_with($amount, '-') ? 'decrease' : 'increase', 'amount' => ltrim($amount, '-')];
        }

        return $result;
    }

    private function balance(string $account): string
    {
        return (string) $this->connection->fetchOne('SELECT balance FROM balance_account_states WHERE company_id = ? AND account_id = ?', [$this->companyId, $this->accounts[$account]]);
    }
}
