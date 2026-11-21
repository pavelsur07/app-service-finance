<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp;

use App\Mcp\Application\McpCashCursor;
use App\Mcp\Application\McpToolException;
use App\Mcp\Security\McpPrincipal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class McpCashCursorTest extends TestCase
{
    public function testValidCursorResumesTheSamePagePosition(): void
    {
        $cursor = new McpCashCursor('test-secret');
        $principal = self::principal();
        $key = ['id' => '00000000-0000-4000-8000-000000000001', 'date' => '2026-11-20'];
        $token = $cursor->encode($principal, 'list_cash_transactions', ['direction' => 'OUTFLOW', 'categoryId' => null], $key);

        self::assertSame($key, $cursor->decode($principal, 'list_cash_transactions', ['categoryId' => null, 'direction' => 'OUTFLOW'], $token));
    }

    #[DataProvider('modifiedContextCases')]
    public function testCursorRejectsChangedContextOrSignature(string $change): void
    {
        $cursor = new McpCashCursor('test-secret');
        $principal = self::principal();
        $tool = 'list_reference_items';
        $filters = ['kind' => 'money_accounts', 'search' => null];
        $token = $cursor->encode($principal, $tool, $filters, ['id' => '00000000-0000-4000-8000-000000000001']);

        match ($change) {
            'company' => $principal = self::principal(companyId: '00000000-0000-4000-8000-000000000100'),
            'connection' => $principal = self::principal(connectionId: '00000000-0000-4000-8000-000000000101'),
            'tool' => $tool = 'list_cash_transactions',
            'filter' => $filters['search'] = 'Резерв',
            'token' => $token[0] = 'a' === $token[0] ? 'b' : 'a',
            'secret' => $cursor = new McpCashCursor('different-test-secret'),
            default => throw new \LogicException('Unknown cursor test case.'),
        };

        try {
            $cursor->decode($principal, $tool, $filters, $token);
            self::fail('A cursor must be bound to its signed context.');
        } catch (McpToolException $exception) {
            self::assertSame('invalid_input', $exception->reason);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function modifiedContextCases(): iterable
    {
        foreach (['company', 'connection', 'tool', 'filter', 'token', 'secret'] as $change) {
            yield $change => [$change];
        }
    }

    private static function principal(
        string $companyId = '00000000-0000-4000-8000-000000000001',
        string $connectionId = '00000000-0000-4000-8000-000000000002',
    ): McpPrincipal {
        return new McpPrincipal(
            companyId: $companyId,
            userId: '00000000-0000-4000-8000-000000000003',
            connectionId: $connectionId,
            scopes: ['accounts.read'],
        );
    }
}
