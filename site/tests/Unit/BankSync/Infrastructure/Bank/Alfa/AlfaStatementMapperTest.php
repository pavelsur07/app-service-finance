<?php

declare(strict_types=1);

namespace App\Tests\Unit\BankSync\Infrastructure\Bank\Alfa;

use App\BankSync\Application\DTO\BankAccountDTO;
use App\BankSync\Enum\BankDirection;
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

    public function testAccountsAreUniqueAndEmptyIdentificationsSkipped(): void
    {
        $accounts = $this->mapper->mapAccounts([
            'Data' => [
                'Account' => [
                    ['AccountDetails' => [['identification' => '40702810900000000001'], ['identification' => '']]],
                    ['AccountDetails' => [['identification' => '40702810900000000001'], ['identification' => ' 40702810900000000002 ']]],
                    ['AccountDetails' => 'broken'],
                    'broken',
                ],
            ],
        ]);

        self::assertEquals([
            new BankAccountDTO('40702810900000000001'),
            new BankAccountDTO('40702810900000000002'),
        ], $accounts);
    }

    public function testAccountsOfUnexpectedResponseAreEmpty(): void
    {
        self::assertSame([], $this->mapper->mapAccounts([]));
        self::assertSame([], $this->mapper->mapAccounts(['Data' => 'broken']));
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
                    'amount' => ['amount' => '99.9'],
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
        self::assertSame('RUR', $inflow->currency);
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
