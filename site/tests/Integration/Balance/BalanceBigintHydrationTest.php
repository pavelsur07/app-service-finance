<?php

declare(strict_types=1);

namespace App\Tests\Integration\Balance;

use App\Balance\Entity\BalanceAccountState;
use App\Balance\Entity\BalanceBook;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;

/**
 * Остаток счёта и счётчики книги Balance гидрируются строкой без потери точности.
 *
 * Свойства объявлены `string`: DBAL 3 отдаёт BIGINT строкой, DBAL 4 — `int`,
 * если значение помещается в PHP int. Тест фиксирует контракт до перехода на
 * DBAL 4: после него ORM обязан вернуть ту же строку, а не float и не
 * обрезанное значение. 2^53 + 1 — первое целое, которое float уже не различает.
 *
 * Строки вставляются сырым SQL намеренно: проверяется только путь чтения через
 * ORM, без участия записи, которая могла бы замаскировать потерю на гидрации.
 */
final class BalanceBigintHydrationTest extends IntegrationTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function balances(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-15000'];
        yield 'beyond float precision' => ['9007199254740993'];
        yield 'int max' => ['9223372036854775807'];
        yield 'int min' => ['-9223372036854775808'];
    }

    #[DataProvider('balances')]
    public function testAccountStateBalanceRoundTripsAsExactString(string $balance): void
    {
        [$companyId, $stateId] = $this->seedLedger($balance, '9007199254740993', '9223372036854775807');

        $state = $this->em->find(BalanceAccountState::class, $stateId);

        self::assertInstanceOf(BalanceAccountState::class, $state);
        self::assertSame($companyId, $state->getCompanyId());
        self::assertSame($balance, $state->getBalance());
    }

    public function testBookCountersRoundTripAsExactStrings(): void
    {
        [$companyId] = $this->seedLedger('0', '9007199254740993', '9223372036854775807');

        $book = $this->em->getRepository(BalanceBook::class)->findOneBy(['companyId' => $companyId]);

        self::assertInstanceOf(BalanceBook::class, $book);
        self::assertSame('9007199254740993', $book->getNextDocumentNumber());
        self::assertSame('9223372036854775807', $book->getNextPostingSequence());
    }

    /**
     * @return array{string, string}
     */
    private function seedLedger(string $balance, string $nextDocumentNumber, string $nextPostingSequence): array
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7().'@test.example')->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();

        $companyId = (string) $company->getId();
        $now = '2026-01-01 00:00:00';
        $this->connection->insert('balance_books', [
            'id' => Uuid::uuid7()->toString(),
            'company_id' => $companyId,
            'currency' => 'RUB',
            'start_date' => '2026-01-01',
            'initialized' => true,
            'next_document_number' => $nextDocumentNumber,
            'next_posting_sequence' => $nextPostingSequence,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $articleId = Uuid::uuid7()->toString();
        $accountId = Uuid::uuid7()->toString();
        $stateId = Uuid::uuid7()->toString();
        $this->connection->insert('balance_articles', ['id' => $articleId, 'company_id' => $companyId, 'name' => 'asset', 'code' => 'asset', 'type' => 'asset', 'kind' => 'article', 'created_at' => $now, 'updated_at' => $now]);
        $this->connection->insert('balance_accounts', ['id' => $accountId, 'company_id' => $companyId, 'article_id' => $articleId, 'name' => 'asset', 'code' => 'asset', 'created_at' => $now, 'updated_at' => $now]);
        $this->connection->insert('balance_account_states', ['id' => $stateId, 'company_id' => $companyId, 'account_id' => $accountId, 'balance' => $balance, 'updated_at' => $now]);
        $this->em->clear();

        return [$companyId, $stateId];
    }
}
