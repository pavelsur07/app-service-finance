<?php

declare(strict_types=1);

namespace App\Tests\Unit\MoySklad\Application;

use App\MoySklad\Application\CounterpartyPageParser;
use App\MoySklad\Exception\CounterpartySyncException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CounterpartyPageParserTest extends TestCase
{
    private const ACCOUNT_ID = '00000000-0000-4000-8000-000000000002';

    /** @param list<string> $types */
    #[DataProvider('fixturePages')]
    public function testNormalizesCapturedPages(string $file, bool $archived, array $types): void
    {
        $body = file_get_contents(__DIR__.'/../../../Fixtures/MoySklad/Counterparty/'.$file);
        self::assertIsString($body);
        $page = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        $rows = (new CounterpartyPageParser())->parse($page, self::ACCOUNT_ID, $archived);

        self::assertSame($types, array_map(static fn ($row): string => $row->companyType, $rows));
        foreach ($rows as $row) {
            self::assertSame($archived, $row->archived);
            self::assertSame('UTC', $row->sourceUpdatedAt->getTimezone()->getName());
            self::assertSame('2026-01-02', $row->sourceUpdatedAt->format('Y-m-d'));
        }
    }

    /** @return iterable<string, array{string, bool, list<string>}> */
    public static function fixturePages(): iterable
    {
        yield 'legal' => ['counterparties_active_page_0.json', false, ['legal', 'legal']];
        yield 'entrepreneur and individual' => ['counterparties_active_page_2.json', false, ['entrepreneur', 'individual']];
        yield 'archived' => ['counterparties_archived_page_0.json', true, ['legal', 'legal']];
        yield 'empty' => ['counterparties_active_empty.json', false, []];
    }

    public function testRejectsAccountMismatchWithoutLeakingRow(): void
    {
        $body = file_get_contents(__DIR__.'/../../../Fixtures/MoySklad/Counterparty/counterparties_active_page_0.json');
        self::assertIsString($body);
        $page = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        $page['rows'][0]['accountId'] = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

        try {
            (new CounterpartyPageParser())->parse($page, self::ACCOUNT_ID, false);
            self::fail('Mismatch must fail.');
        } catch (CounterpartySyncException $e) {
            self::assertSame('invalid_response', $e->category);
            self::assertStringNotContainsString('Тестовый', $e->getMessage());
        }
    }

    public function testConvertsMoscowMillisecondsToUtc(): void
    {
        $body = file_get_contents(__DIR__.'/../../../Fixtures/MoySklad/Counterparty/counterparties_active_page_0.json');
        self::assertIsString($body);
        $page = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        $row = (new CounterpartyPageParser())->parse($page, self::ACCOUNT_ID, false)[0];
        self::assertSame('2026-01-02 00:04:09.123000', $row->sourceUpdatedAt->format('Y-m-d H:i:s.u'));
        self::assertSame('ООО Тестовый контрагент 1', $row->legalTitle);
        self::assertSame('9999999991', $row->inn);
    }

    #[DataProvider('invalidRows')]
    public function testRejectsInvalidRows(string $key, mixed $value): void
    {
        $body = file_get_contents(__DIR__.'/../../../Fixtures/MoySklad/Counterparty/counterparties_active_page_0.json');
        self::assertIsString($body);
        $page = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        $page['rows'][0][$key] = $value;
        $this->expectException(CounterpartySyncException::class);
        (new CounterpartyPageParser())->parse($page, self::ACCOUNT_ID, false);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidRows(): iterable
    {
        yield 'missing name' => ['name', '  '];
        yield 'wrong archive' => ['archived', true];
        yield 'bad date' => ['updated', '2026-02-31 03:04:09.123'];
        yield 'too long address' => ['legalAddress', str_repeat('x', 256)];
        yield 'bad optional type' => ['inn', 123];
    }
}
