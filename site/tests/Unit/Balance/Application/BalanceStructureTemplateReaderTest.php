<?php

declare(strict_types=1);

namespace App\Tests\Unit\Balance\Application;

use App\Balance\Application\BalanceStructureTemplateReader;
use App\Balance\DTO\BalanceStructureTemplateNode;
use App\Balance\Enum\BalanceCategoryType;
use App\Balance\Exception\BalanceStructureTemplateException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BalanceStructureTemplateReaderTest extends TestCase
{
    private const DEFAULT_FILE = __DIR__.'/../../../../config/balance/default_structure.yaml';

    public function testDefaultTemplateFileIsValidAndBalanced(): void
    {
        $nodes = (new BalanceStructureTemplateReader(self::DEFAULT_FILE))->read();

        self::assertSame(['CURRENT_ASSETS', 'NON_CURRENT_ASSETS', 'EQUITY', 'LIABILITIES'], array_map(static fn (BalanceStructureTemplateNode $n): string => $n->code, $nodes));
        self::assertSame([10, 20, 30, 40], array_map(static fn (BalanceStructureTemplateNode $n): int => $n->sortOrder, $nodes));
        self::assertSame(BalanceCategoryType::ASSET, $nodes[0]->type);
        self::assertSame(BalanceCategoryType::PASSIVE, $nodes[2]->type);
        self::assertSame('CASH', $nodes[0]->children[0]->code);
        self::assertSame([], $nodes[1]->children);
    }

    public function testMissingFileIsRejected(): void
    {
        $this->expectException(BalanceStructureTemplateException::class);

        (new BalanceStructureTemplateReader('/nonexistent/balance.yaml'))->read();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidTemplates(): iterable
    {
        $node = static fn (string $extra = ''): string => "version: 1\narticles:\n  - code: A\n    name: A\n    type: asset\n    kind: group\n{$extra}";

        yield 'broken yaml' => ["version: 1\narticles: [", 'не разобран'];
        yield 'wrong version' => ["version: 2\narticles: []", 'версии 1'];
        yield 'empty list' => ["version: 1\narticles: []", 'пуст'];
        yield 'bad code' => ["version: 1\narticles:\n  - code: bad-code\n    name: A\n    type: asset\n    kind: group", 'code обязателен'];
        yield 'missing name' => ["version: 1\narticles:\n  - code: A\n    type: asset\n    kind: group", 'name обязателен'];
        yield 'bad type' => ["version: 1\narticles:\n  - code: A\n    name: A\n    type: equity\n    kind: group", 'asset или passive'];
        yield 'bad kind' => ["version: 1\narticles:\n  - code: A\n    name: A\n    type: asset\n    kind: leaf", 'group или article'];
        yield 'duplicate code' => ["version: 1\narticles:\n  - code: A\n    name: A\n    type: asset\n    kind: article\n  - code: A\n    name: B\n    type: asset\n    kind: article", 'повторяется'];
        yield 'article with children' => [
            "version: 1\narticles:\n  - code: A\n    name: A\n    type: asset\n    kind: article\n    children:\n      - code: B\n        name: B\n        type: asset\n        kind: article",
            'не бывает children',
        ];
        yield 'child on other side' => [
            $node("    children:\n      - code: B\n        name: B\n        type: passive\n        kind: article"),
            'сторона баланса',
        ];
        yield 'too deep' => [
            "version: 1\narticles:\n  - {code: L1, name: L1, type: asset, kind: group, children: [{code: L2, name: L2, type: asset, kind: group, children: [{code: L3, name: L3, type: asset, kind: group, children: [{code: L4, name: L4, type: asset, kind: group, children: [{code: L5, name: L5, type: asset, kind: article}]}]}]}]}",
            'глубина',
        ];
    }

    #[DataProvider('invalidTemplates')]
    public function testInvalidTemplateIsRejectedWithReason(string $yaml, string $expectedMessagePart): void
    {
        $this->expectException(BalanceStructureTemplateException::class);
        $this->expectExceptionMessage($expectedMessagePart);

        (new BalanceStructureTemplateReader('unused'))->parse($yaml);
    }
}
