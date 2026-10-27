<?php

declare(strict_types=1);

namespace App\Tests\Unit\BankSync\Infrastructure\Bank\Alfa;

use App\BankSync\Domain\BankAccess;
use App\BankSync\Enum\BankCode;
use App\BankSync\Infrastructure\Bank\Alfa\AlfaApiClient;
use App\BankSync\Infrastructure\Bank\Alfa\AlfaBankProvider;
use App\BankSync\Infrastructure\Bank\Alfa\AlfaStatementMapper;
use App\BankSync\Infrastructure\Bank\Alfa\AlfaTraceIdRecorder;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AlfaBankProviderTest extends TestCase
{
    private TestHandler $logHandler;

    protected function setUp(): void
    {
        $this->logHandler = new TestHandler();
    }

    public function testStatementDayCollectsAllPagesUntilNoNextLink(): void
    {
        $pages = [];
        $responses = [
            ['transactions' => [$this->line('a'), ['uuid' => 'broken']], '_links' => [['rel' => 'next', 'href' => '?page=2']]],
            ['transactions' => [$this->line('b')], '_links' => [['rel' => 'prev', 'href' => '?page=1'], ['rel' => 'next', 'href' => '?page=3']]],
            ['transactions' => [$this->line('c')], '_links' => [['rel' => 'prev', 'href' => '?page=2']]],
        ];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$pages, &$responses): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $pages[] = $query['page'] ?? null;

            return new MockResponse(json_encode(array_shift($responses), \JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });
        $provider = new AlfaBankProvider(new AlfaApiClient($http, new NullLogger(), new AlfaTraceIdRecorder()), new AlfaStatementMapper(), $this->logs());

        $day = $provider->fetchStatementDay($this->access(), '40702810900000000001', new \DateTimeImmutable('2026-10-01'));

        self::assertSame(['1', '2', '3'], $pages);
        self::assertSame(['a', 'b', 'c'], array_map(static fn ($line) => $line->externalId, $day->lines));
        self::assertSame(1, $day->skippedLines);
        self::assertSame('2026-10-01', $day->date->format('Y-m-d'));
    }

    public function testAccountsAreMappedAndSkippedOnesWarnedWithoutNumbers(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode([
            'Data' => ['Account' => [
                ['accountId' => 'acc-1', 'status' => 'Enabled', 'currency' => 'RUB', 'accountType' => 'Business', 'accountDescription' => 'Расчётный',
                    'AccountDetails' => [['schemeName' => 'RU.CBR.BBAN', 'identification' => '40702810900000000001']]],
                ['accountId' => 'acc-2', 'status' => 'Enabled', 'currency' => 'RUB', 'accountType' => 'Business', 'accountDescription' => 'Без номера'],
            ]],
            'Links' => ['self' => 'https://bank.test/accounts'],
            'Meta' => ['totalPages' => 1],
        ], \JSON_THROW_ON_ERROR), ['http_code' => 200]));
        $provider = new AlfaBankProvider(new AlfaApiClient($http, new NullLogger(), new AlfaTraceIdRecorder()), new AlfaStatementMapper(), $this->logs());

        $accounts = $provider->listAccounts($this->access());

        self::assertCount(1, $accounts);
        self::assertSame('40702810900000000001', $accounts[0]->accountNumber);
        self::assertTrue($this->logHandler->hasWarningThatContains('skipped accounts without number or currency'));
        self::assertSame(1, $this->logHandler->getRecords()[0]->context['skipped_accounts']);
        self::assertStringNotContainsString('407028', json_encode($this->logHandler->getRecords()[0]->context, \JSON_THROW_ON_ERROR));
    }

    private function logs(): Logger
    {
        return new Logger('test', [$this->logHandler]);
    }

    /**
     * @return array<string, mixed>
     */
    private function line(string $uuid): array
    {
        return [
            'uuid' => $uuid,
            'direction' => 'CREDIT',
            'amount' => ['amount' => 10, 'currencyName' => 'RUR'],
            'operationDate' => '2026-10-01T00:00:00Z',
        ];
    }

    private function access(): BankAccess
    {
        return new BankAccess(Uuid::uuid7()->toString(), BankCode::ALFA, 'https://bank.test', 'token');
    }
}
