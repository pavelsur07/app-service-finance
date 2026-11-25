<?php

declare(strict_types=1);

namespace App\Tests\Integration\Balance;

use App\Balance\Application\BalanceLedgerService;
use App\Balance\Exception\BalanceLedgerException;
use App\Balance\Security\BalanceAccess;
use App\Company\Facade\CompanyFacade;
use App\Shared\Service\ActiveCompanyService;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260914120000;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

require_once dirname(__DIR__, 3).'/migrations/Version20260914120000.php';

/** Установка остатка счёта из фонового источника: права автора без HTTP-сессии. */
final class DelegatedTargetBalanceTest extends IntegrationTestCase
{
    private BalanceLedgerService $sessionLedger;
    private BalanceLedgerService $backgroundLedger;
    private string $companyId;
    private string $actorId;
    /** @var array<string, string> */
    private array $accounts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $schema = 'ledger_delegated_'.str_replace('-', '', Uuid::uuid7()->toString());
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
        $companies = self::getContainer()->get(CompanyFacade::class);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $activeCompany = $this->createMock(ActiveCompanyService::class);
        $activeCompany->method('getActiveCompany')->willReturn($company);
        $this->sessionLedger = new BalanceLedgerService($this->connection, new BalanceAccess($security, $companies, $this->connection, $activeCompany));

        // Фон: ни пользователя в сессии, ни активной компании.
        $noUser = $this->createMock(Security::class);
        $noUser->method('getUser')->willReturn(null);
        $noCompany = $this->createMock(ActiveCompanyService::class);
        $noCompany->method('getActiveCompany')->willThrowException(new \LogicException('No active company in background.'));
        $this->backgroundLedger = new BalanceLedgerService($this->connection, new BalanceAccess($noUser, $companies, $this->connection, $noCompany));

        $this->sessionLedger->configureBook($this->companyId, 'RUB', '2025-01-01', $this->actorId);
        foreach (['money' => 'asset', 'stock' => 'asset', 'equity' => 'passive'] as $name => $type) {
            $articleId = Uuid::uuid7()->toString();
            $accountId = Uuid::uuid7()->toString();
            $this->connection->insert('balance_articles', ['id' => $articleId, 'company_id' => $this->companyId, 'name' => $name, 'type' => $type, 'kind' => 'article', 'level' => 1, 'created_at' => '2025-01-01', 'updated_at' => '2025-01-01']);
            $this->connection->insert('balance_accounts', ['id' => $accountId, 'company_id' => $this->companyId, 'article_id' => $articleId, 'code' => $name, 'name' => $name, 'created_at' => '2025-01-01', 'updated_at' => '2025-01-01']);
            $this->accounts[$name] = $accountId;
        }
    }

    public function testSetsTargetWithPassiveCounterAndReplayReturnsSameDocument(): void
    {
        $this->opening();
        $id = $this->post('stock', 'equity', '2025-01-05', '3000000', 'k1');

        self::assertNotNull($id);
        self::assertSame('3000000', $this->balance('stock'));
        self::assertSame('13000000', $this->balance('equity'));
        $operations = $this->operations();
        self::assertSame($id, $this->post('stock', 'equity', '2025-01-05', '3000000', 'k1'));
        self::assertSame($operations, $this->operations());
        self::assertSame(['posted', 'correction'], array_values($this->connection->fetchAssociative('SELECT status, kind FROM balance_operations WHERE id = ?', [$id]) ?: []));
    }

    public function testDecreaseWithAssetCounterMovesValueBetweenAssets(): void
    {
        $this->opening();
        $this->post('stock', 'equity', '2025-01-05', '3000000', 'k1');
        $this->post('stock', 'money', '2025-01-06', '1000000', 'k2');

        self::assertSame('1000000', $this->balance('stock'));
        self::assertSame('12000000', $this->balance('money'));
        self::assertSame('13000000', $this->balance('equity'));
    }

    public function testUnchangedTargetCreatesNothing(): void
    {
        $this->opening();
        $this->post('stock', 'equity', '2025-01-05', '3000000', 'k1');
        $operations = $this->operations();

        self::assertNull($this->post('stock', 'equity', '2025-01-06', '3000000', 'k2'));
        self::assertSame($operations, $this->operations());
    }

    public function testActorWithoutFinancialRightsIsRejectedWithoutDocument(): void
    {
        $this->opening();
        $stranger = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7()->toString().'@example.test')->build();
        $this->em->persist($stranger);
        $this->em->flush();
        $operations = $this->operations();

        try {
            $this->backgroundLedger->postDelegatedTargetBalance($this->companyId, (string) $stranger->getId(), $this->accounts['stock'], $this->accounts['equity'], '2025-01-05', '3000000', 'Остатки складов', 'k-stranger');
            self::fail('Actor without rights must not post.');
        } catch (AccessDeniedException) {
        }
        self::assertSame($operations, $this->operations());
    }

    public function testUninitializedBookIsADomainError(): void
    {
        $this->expectException(BalanceLedgerException::class);
        $this->post('stock', 'equity', '2025-01-05', '3000000', 'k1');
    }

    public function testSameAccountAsCounterIsRejected(): void
    {
        $this->opening();
        $this->expectException(BalanceLedgerException::class);
        $this->post('stock', 'stock', '2025-01-05', '3000000', 'k1');
    }

    public function testSessionPathsStillRequireSession(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->backgroundLedger->saveDraft($this->companyId, $this->actorId, 'draft', 'opening', '2025-01-01', 'Основание', []);
    }

    private function post(string $account, string $counter, string $date, string $targetMinor, string $key): ?string
    {
        return $this->backgroundLedger->postDelegatedTargetBalance($this->companyId, $this->actorId, $this->accounts[$account], $this->accounts[$counter], $date, $targetMinor, 'Остатки складов', $key);
    }

    private function opening(): void
    {
        $id = $this->sessionLedger->saveDraft($this->companyId, $this->actorId, Uuid::uuid7()->toString(), 'opening', '2025-01-01', 'Основание', [
            ['accountId' => $this->accounts['money'], 'direction' => 'increase', 'amount' => '100000'],
            ['accountId' => $this->accounts['equity'], 'direction' => 'increase', 'amount' => '100000'],
        ]);
        $this->sessionLedger->post($this->companyId, $this->actorId, $id, 1, true);
    }

    private function operations(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM balance_operations WHERE company_id = ?', [$this->companyId]);
    }

    private function balance(string $account): string
    {
        return (string) $this->connection->fetchOne('SELECT balance FROM balance_account_states WHERE company_id = ? AND account_id = ?', [$this->companyId, $this->accounts[$account]]);
    }
}
