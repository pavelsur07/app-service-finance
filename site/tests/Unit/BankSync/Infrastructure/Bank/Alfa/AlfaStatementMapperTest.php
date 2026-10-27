<?php

declare(strict_types=1);

namespace App\Tests\Unit\BankSync\Infrastructure\Bank\Alfa;

use App\BankSync\Application\DTO\BankAccountDTO;
use App\BankSync\Enum\BankDirection;
use App\BankSync\Exception\BankApiRequestFailedException;
use App\BankSync\Infrastructure\Bank\Alfa\AlfaStatementMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AlfaStatementMapperTest extends TestCase
{
    private AlfaStatementMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new AlfaStatementMapper();
    }

    /**
     * Ответ по схеме `AccountResponse` из `docs/plan/alfa/accounts-ob.yaml` (значения — из
     * примеров спецификации).
     */
    public function testAccountsOfSpecificationResponseAreMapped(): void
    {
        $list = $this->mapper->mapAccounts([
            'Data' => ['Account' => [
                [
                    'accountId' => 'this-is-a-slug-format-account-id',
                    'status' => 'Enabled',
                    'currency' => 'RUB',
                    'accountType' => 'Business',
                    'accountDescription' => 'Розничные операции',
                    'AccountDetails' => [['schemeName' => 'RU.CBR.BBAN', 'identification' => '40702810621234570000']],
                    'Owner' => [
                        'name' => 'Наименование организации или физического лица',
                        'Identification' => [['schemeName' => 'RU.CBR.TXID', 'identification' => '7728240240']],
                    ],
                    'Servicer' => [
                        'name' => 'АО «АЛЬФА-БАНК»',
                        'BankIdentification' => [['schemeName' => 'RU.CBR.BIC', 'identification' => '044525593']],
                    ],
                ],
                [
                    'accountId' => 'closed-account',
                    'status' => 'Disabled',
                    'currency' => 'rur',
                    'accountType' => 'Business',
                    'accountDescription' => 'Закрытый',
                    'AccountDetails' => [['schemeName' => 'RU.CBR.BBAN', 'identification' => ' 40702810621234570001 ']],
                ],
            ]],
            'Links' => ['self' => 'https://sb.example.ru/v1.3/example?page=1'],
            'Meta' => ['totalPages' => 1],
        ]);

        self::assertEquals([
            new BankAccountDTO('40702810621234570000', 'RUB', true, '044525593', 'Розничные операции', 'this-is-a-slug-format-account-id'),
            new BankAccountDTO('40702810621234570001', 'RUB', false, null, 'Закрытый', 'closed-account'),
        ], $list->accounts);
        self::assertSame(0, $list->skippedAccounts);
    }

    public function testAccountsWithoutNumberOrCurrencyAreSkippedAndRepeatsMerged(): void
    {
        $account = static fn (array $override): array => $override + [
            'accountId' => 'a', 'status' => 'Enabled', 'currency' => 'RUB', 'accountType' => 'Business', 'accountDescription' => 'x',
            'AccountDetails' => [['schemeName' => 'RU.CBR.BBAN', 'identification' => '40702810900000000001']],
        ];

        $list = $this->mapper->mapAccounts(['Data' => ['Account' => [
            $account([]),
            $account(['accountId' => 'repeat']),
            $account(['AccountDetails' => [['schemeName' => 'OTHER', 'identification' => '40702810900000000002']]]),
            $account(['AccountDetails' => [['schemeName' => 'RU.CBR.BBAN', 'identification' => '  ']]]),
            $account(['AccountDetails' => 'broken']),
            $account(['currency' => null]),
            'broken',
        ]]]);

        self::assertCount(1, $list->accounts);
        self::assertSame('a', $list->accounts[0]->externalId, 'первый из повторов');
        self::assertSame(5, $list->skippedAccounts);
    }

    public function testEmptyAccountListIsValid(): void
    {
        self::assertSame([], $this->mapper->mapAccounts(['Data' => []])->accounts);
        self::assertSame([], $this->mapper->mapAccounts(['Data' => ['Account' => []]])->accounts);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function unusableAccountResponses(): iterable
    {
        yield 'нет Data' => [[]];
        yield 'Data не объект' => [['Data' => 'broken']];
        yield 'Account не список' => [['Data' => ['Account' => ['a' => []]]]];
        yield 'больше одной страницы' => [['Data' => ['Account' => []], 'Meta' => ['totalPages' => 2]]];
        yield 'totalPages не число' => [['Data' => ['Account' => []], 'Meta' => ['totalPages' => '1']]];
    }

    /**
     * @param array<mixed> $response
     */
    #[DataProvider('unusableAccountResponses')]
    public function testUnusableAccountResponseIsRequestFailure(array $response): void
    {
        $this->expectException(BankApiRequestFailedException::class);

        $this->mapper->mapAccounts($response);
    }

    public function testInflowAndOutflowLinesAreMapped(): void
    {
        $page = $this->mapper->mapStatementPage([
            'transactions' => [
                $this->transaction([
                    'uuid' => 'uuid-1',
                    'transactionId' => '1211206MOCO#DS0000017',
                    'direction' => 'CREDIT',
                    'amount' => ['amount' => 1500.5, 'currencyName' => 'rur'],
                    'paymentPurpose' => '  Оплата по счёту 15  ',
                    'operationDate' => '2026-10-01T10:15:00Z',
                ]),
                $this->transaction([
                    'uuid' => 'uuid-2',
                    'direction' => 'DEBIT',
                    'amount' => ['amount' => '99.9', 'currencyName' => ''],
                    'paymentPurpose' => '   ',
                ]),
            ],
            '_links' => [['rel' => 'self', 'href' => '?page=1']],
        ]);

        self::assertCount(2, $page->lines);
        self::assertSame(0, $page->skippedLines);
        self::assertFalse($page->hasNextPage);

        [$inflow, $outflow] = $page->lines;
        self::assertSame('uuid-1', $inflow->externalId);
        self::assertSame('1211206MOCO#DS0000017', $inflow->transactionId);
        self::assertSame(BankDirection::CREDIT, $inflow->direction);
        self::assertSame('1500.50', $inflow->amount);
        self::assertSame('RUB', $inflow->currency, 'RUR — старый код рубля');
        self::assertSame('Оплата по счёту 15', $inflow->description);
        self::assertSame('2026-10-01 10:15:00', $inflow->occurredAt->format('Y-m-d H:i:s'));

        self::assertSame(BankDirection::DEBIT, $outflow->direction);
        self::assertSame('99.90', $outflow->amount);
        self::assertNull($outflow->currency);
        self::assertNull($outflow->description);
        self::assertNull($outflow->transactionId);
    }

    public function testDocumentDateIsUsedWithoutOperationDate(): void
    {
        $page = $this->mapper->mapStatementPage(['transactions' => [
            $this->transaction(['operationDate' => null, 'documentDate' => '2026-10-02']),
        ]]);

        self::assertSame('2026-10-02 00:00:00', $page->lines[0]->occurredAt->format('Y-m-d H:i:s'));
    }

    public function testNextPageIsDetectedByLinks(): void
    {
        $page = $this->mapper->mapStatementPage([
            'transactions' => [],
            '_links' => [['rel' => 'prev', 'href' => '?page=1'], ['rel' => 'next', 'href' => '?page=3']],
        ]);

        self::assertTrue($page->hasNextPage);
        self::assertSame([], $page->lines);
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('brokenLineProvider')]
    public function testBrokenLineIsSkippedAndCounted(array $override): void
    {
        $page = $this->mapper->mapStatementPage(['transactions' => [
            $this->transaction(['uuid' => 'good']),
            $this->transaction($override),
        ]]);

        self::assertCount(1, $page->lines);
        self::assertSame('good', $page->lines[0]->externalId);
        self::assertSame(1, $page->skippedLines);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function brokenLineProvider(): iterable
    {
        yield 'no uuid' => [['uuid' => null]];
        yield 'empty uuid' => [['uuid' => ' ']];
        yield 'no dates' => [['operationDate' => null, 'documentDate' => null]];
        yield 'invalid date' => [['operationDate' => 'not a date']];
        yield 'no amount' => [['amount' => null]];
        yield 'negative amount' => [['amount' => ['amount' => -5]]];
        yield 'amount in exponent form' => [['amount' => ['amount' => '1e3']]];
        yield 'unknown direction' => [['direction' => 'SIDEWAYS']];
    }

    public function testNonArrayLineIsSkipped(): void
    {
        $page = $this->mapper->mapStatementPage(['transactions' => ['broken']]);

        self::assertSame([], $page->lines);
        self::assertSame(1, $page->skippedLines);
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function transaction(array $override = []): array
    {
        return array_merge([
            'uuid' => 'uuid-default',
            'direction' => 'CREDIT',
            'amount' => ['amount' => 100, 'currencyName' => 'RUR'],
            'operationDate' => '2026-10-01T00:00:00Z',
            'paymentPurpose' => 'Оплата',
        ], $override);
    }
}
