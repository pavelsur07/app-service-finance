<?php

declare(strict_types=1);

namespace App\Tests\Integration\Mcp;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\Transaction\CashDirection;
use App\Company\Entity\Company;
use App\Mcp\Application\McpCashTools;
use App\Mcp\Application\McpToolException;
use App\Mcp\Security\McpPrincipal;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class McpCashToolsTest extends IntegrationTestCase
{
    public function testAdvertisesOnlyGrantedToolsUsingThePublishedDefinition(): void
    {
        [, $principal] = $this->fixture(['accounts.read']);
        $tools = self::getContainer()->get(McpCashTools::class);
        $definition = self::definition('list_reference_items');

        self::assertSame([$definition], $tools->definitions($principal));
    }

    public function testAccountsScopeDoesNotGrantAccessToCategories(): void
    {
        [, $principal] = $this->fixture(['accounts.read']);
        $tools = self::getContainer()->get(McpCashTools::class);

        $this->expectException(AccessDeniedHttpException::class);
        $tools->call($principal, 'list_reference_items', ['kind' => 'cashflow_categories', 'limit' => 101]);
    }

    public function testReadOnlyScopeCannotCreateDraftEvenWithoutArguments(): void
    {
        [, $principal] = $this->fixture(['cash_transactions.read']);
        $tools = self::getContainer()->get(McpCashTools::class);

        $this->expectException(AccessDeniedHttpException::class);
        $tools->call($principal, 'create_cash_draft', []);
    }

    public function testCashRefusalOfLiveWritePermissionMapsToAccessDenied(): void
    {
        [$company, $principal] = $this->fixture(['cash_drafts.create']);
        [, $other] = $this->fixture(['cash_drafts.create'], 2);
        $account = $this->account($company, 'Резерв');
        $this->em->flush();

        $stalePrincipal = new McpPrincipal($principal->companyId, $other->userId, $principal->connectionId, $principal->scopes);
        $tools = self::getContainer()->get(McpCashTools::class);

        $this->expectException(AccessDeniedHttpException::class);
        $tools->call($stalePrincipal, 'create_cash_draft', self::draftInput((string) $account->getId()));
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('invalidInputCases')]
    public function testRejectsInvalidInputs(string $tool, array $arguments): void
    {
        [, $principal] = $this->fixture(['accounts.read', 'cash_categories.read', 'cash_transactions.read', 'cash_drafts.create']);
        $tools = self::getContainer()->get(McpCashTools::class);

        try {
            $tools->call($principal, $tool, $arguments);
            self::fail('Invalid tool input must be rejected.');
        } catch (McpToolException $exception) {
            self::assertSame('invalid_input', $exception->reason);
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM cash_payment_intake_draft WHERE company_id = ?', [$principal->companyId]));
        }
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function invalidInputCases(): iterable
    {
        yield 'unknown kind' => ['list_reference_items', ['kind' => 'other']];
        yield 'extra company' => ['list_reference_items', ['kind' => 'money_accounts', 'companyId' => Uuid::uuid7()->toString()]];
        yield 'page size outside schema' => ['list_reference_items', ['kind' => 'money_accounts', 'limit' => 101]];
        yield 'null byte in search' => ['list_reference_items', ['kind' => 'money_accounts', 'search' => "a\0b"]];
        yield 'impossible date' => ['list_cash_transactions', ['dateFrom' => '2025-02-29', 'dateTo' => '2025-03-01']];
        yield 'date with trailing newline' => ['list_cash_transactions', ['dateFrom' => "2026-11-20\n", 'dateTo' => '2026-11-20']];
        yield 'year zero' => ['list_cash_transactions', ['dateFrom' => '0000-12-31', 'dateTo' => '0001-01-01']];
        yield 'period too long' => ['list_cash_transactions', ['dateFrom' => '2024-01-01', 'dateTo' => '2025-01-01']];
        yield 'reversed period' => ['list_cash_transactions', ['dateFrom' => '2026-11-20', 'dateTo' => '2026-11-19']];
        $draft = self::draftInput(Uuid::uuid7()->toString());
        yield 'invalid UUID' => ['create_cash_draft', array_replace($draft, ['moneyAccountId' => 'invalid'])];
        yield 'UUID with trailing newline' => ['create_cash_draft', array_replace($draft, ['moneyAccountId' => $draft['moneyAccountId']."\n"])];
        yield 'amount as JSON number' => ['create_cash_draft', array_replace($draft, ['amount' => 1250])];
        yield 'zero amount' => ['create_cash_draft', array_replace($draft, ['amount' => '0.00'])];
        yield 'three decimal places' => ['create_cash_draft', array_replace($draft, ['amount' => '1.001'])];
        yield 'explicit source' => ['create_cash_draft', $draft + ['source' => 'mcp']];
    }

    public function testPaginatesAccountReferencesWithinTheCompany(): void
    {
        [$company, $principal] = $this->fixture(['accounts.read']);
        [$other] = $this->fixture(['accounts.read'], 2);
        $rub = $this->account($company, 'Основной');
        $btc = $this->account($company, 'Кошелёк', 'BTC');
        $inactive = $this->account($company, 'Закрытый счёт');
        $inactive->setIsActive(false);
        $this->account($other, 'Чужой счёт');
        $this->em->flush();

        $tools = self::getContainer()->get(McpCashTools::class);
        $input = ['kind' => 'money_accounts', 'limit' => 1];
        $first = $tools->call($principal, 'list_reference_items', $input);
        $second = $tools->call($principal, 'list_reference_items', $input + ['cursor' => $first['nextCursor']]);
        self::assertSame([['id' => $rub->getId(), 'name' => 'Основной', 'currency' => 'RUB']], $first['items']);
        self::assertSame([['id' => $btc->getId(), 'name' => 'Кошелёк', 'currency' => 'BTC']], $second['items']);
        self::assertNull($second['nextCursor']);
        self::assertSuccessMatchesSchema('list_reference_items', $first);
        self::assertSuccessMatchesSchema('list_reference_items', $second);
    }

    public function testIncludes366CalendarDaysAndResumesAfterDateAndId(): void
    {
        [$company, $principal] = $this->fixture(['cash_transactions.read']);
        $account = $this->account($company, 'Резерв');
        $firstFact = $this->transaction($company, $account, '2024-01-01', '-100.00');
        $secondFact = $this->transaction($company, $account, '2024-01-01', '100.00');
        $lastFact = $this->transaction($company, $account, '2024-12-31', '0.00');
        $this->em->flush();

        $tools = self::getContainer()->get(McpCashTools::class);
        $input = ['dateFrom' => '2024-01-01', 'dateTo' => '2024-12-31', 'limit' => 1];
        $first = $tools->call($principal, 'list_cash_transactions', $input);
        $second = $tools->call($principal, 'list_cash_transactions', array_replace($input, ['limit' => 2, 'cursor' => $first['nextCursor']]));
        self::assertSame([$firstFact->getId()], array_column($first['items'], 'id'));
        self::assertSame(['-100.00'], array_column($first['items'], 'amount'));
        self::assertSame([$secondFact->getId(), $lastFact->getId()], array_column($second['items'], 'id'));
        self::assertSame(['100.00', '0.00'], array_column($second['items'], 'amount'));
        self::assertNull($second['nextCursor']);
        self::assertSuccessMatchesSchema('list_cash_transactions', $first);
        self::assertSuccessMatchesSchema('list_cash_transactions', $second);
    }

    public function testCategoryPagePreservesParentOutsideSearchResults(): void
    {
        [$company, $principal] = $this->fixture(['cash_categories.read']);
        $parent = new CashflowCategory(Uuid::uuid7()->toString(), $company);
        $parent->setName('Расходы');
        $child = new CashflowCategory(Uuid::uuid7()->toString(), $company);
        $child->setName('Оплата аренды')->setParent($parent);
        $this->em->persist($parent);
        $this->em->persist($child);
        $this->em->flush();

        $tools = self::getContainer()->get(McpCashTools::class);
        $result = $tools->call($principal, 'list_reference_items', ['kind' => 'cashflow_categories', 'search' => 'АРЕНДЫ']);

        self::assertSame([
            'kind' => 'cashflow_categories',
            'items' => [['id' => $child->getId(), 'name' => 'Оплата аренды', 'parentId' => $parent->getId()]],
            'nextCursor' => null,
        ], $result);
        self::assertSuccessMatchesSchema('list_reference_items', $result);
    }

    public function testMissingAndForeignReferencesHaveTheSameError(): void
    {
        [, $principal] = $this->fixture(['cash_transactions.read']);
        [$other] = $this->fixture(['cash_transactions.read'], 2);
        $foreign = $this->account($other, 'Чужой счёт');
        $this->em->flush();

        $tools = self::getContainer()->get(McpCashTools::class);
        $input = ['dateFrom' => '2026-11-01', 'dateTo' => '2026-11-20'];
        $errors = [];
        foreach ([$foreign->getId(), Uuid::uuid7()->toString()] as $accountId) {
            try {
                $tools->call($principal, 'list_cash_transactions', $input + ['moneyAccountId' => $accountId]);
                self::fail('An unavailable reference must be rejected.');
            } catch (McpToolException $exception) {
                $errors[] = [$exception->reason, $exception->getMessage()];
            }
        }
        self::assertSame('reference_unavailable', $errors[0][0]);
        self::assertSame($errors[0], $errors[1]);
    }

    public function testCreatesOneDraftWithoutCreatingCashFactsAndMapsReplayConflict(): void
    {
        [$company, $principal] = $this->fixture(['cash_drafts.create', 'cash_transactions.read']);
        $account = $this->account($company, 'Резерв');
        $this->em->flush();

        $tools = self::getContainer()->get(McpCashTools::class);
        $input = self::draftInput((string) $account->getId());
        $created = $tools->call($principal, 'create_cash_draft', $input);
        $replayed = $tools->call($principal, 'create_cash_draft', ['currency' => 'RUB'] + array_reverse($input, preserve_keys: true));
        self::assertSame($created, $replayed);
        self::assertSame('awaiting_confirmation', $created['status']);
        self::assertSame('mcp', $created['source']);
        self::assertSuccessMatchesSchema('create_cash_draft', $created);
        self::assertSame(['items' => [], 'nextCursor' => null], $tools->call($principal, 'list_cash_transactions', ['dateFrom' => '2026-11-20', 'dateTo' => '2026-11-20']));

        try {
            $tools->call($principal, 'create_cash_draft', array_replace($input, ['amount' => '1251.00']));
            self::fail('A conflicting replay must be reported.');
        } catch (McpToolException $exception) {
            self::assertSame('idempotency_conflict', $exception->reason);
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM cash_payment_intake_draft WHERE company_id = ?', [$principal->companyId]));
        }
    }

    /**
     * @param list<string> $scopes
     *
     * @return array{Company, McpPrincipal}
     */
    private function fixture(array $scopes, int $index = 1): array
    {
        $owner = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);

        return [$company, new McpPrincipal(
            companyId: (string) $company->getId(),
            userId: (string) $owner->getId(),
            connectionId: Uuid::uuid7()->toString(),
            scopes: $scopes,
        )];
    }

    private function account(Company $company, string $name, string $currency = 'RUB'): MoneyAccount
    {
        $type = 'BTC' === $currency ? MoneyAccountType::CRYPTO_WALLET : MoneyAccountType::BANK;
        $account = new MoneyAccount(Uuid::uuid7()->toString(), $company, $type, $name, $currency);
        $this->em->persist($account);

        return $account;
    }

    private function transaction(Company $company, MoneyAccount $account, string $date, string $amount): CashTransaction
    {
        $fact = new CashTransaction(
            Uuid::uuid7()->toString(),
            $company,
            $account,
            CashDirection::OUTFLOW,
            $amount,
            'RUB',
            new \DateTimeImmutable($date),
        );
        $this->em->persist($fact);

        return $fact;
    }

    /** @return array{externalId: string, moneyAccountId: string, date: string, direction: string, amount: string} */
    private static function draftInput(string $accountId): array
    {
        return [
            'externalId' => 'crm:payment-42',
            'moneyAccountId' => $accountId,
            'date' => '2026-11-20',
            'direction' => 'OUTFLOW',
            'amount' => '1250.00',
        ];
    }

    /** @return array<string, mixed> */
    private static function definition(string $name): array
    {
        $json = file_get_contents(__DIR__.'/../../../contracts/mcp/v1/'.$name.'.json');
        self::assertIsString($json);

        return json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $payload */
    private static function assertSuccessMatchesSchema(string $name, array $payload): void
    {
        $schema = json_decode(json_encode(self::definition($name)['outputSchema'], \JSON_THROW_ON_ERROR), flags: \JSON_THROW_ON_ERROR);
        $object = json_decode(json_encode($payload, \JSON_THROW_ON_ERROR), flags: \JSON_THROW_ON_ERROR);

        self::assertTrue((new Validator())->validate($object, $schema)->isValid());
    }
}
