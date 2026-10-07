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
        self::assertSame(['MONEY', 'RECEIVABLES', 'INVENTORY', 'TAX_RECEIVABLE'], array_map(static fn (BalanceStructureTemplateNode $n): string => $n->code, $nodes[0]->children));

        $total = 0;
        $maxLevel = 0;
        $walk = function (array $level, int $depth) use (&$walk, &$total, &$maxLevel): void {
            foreach ($level as $node) {
                ++$total;
                $maxLevel = max($maxLevel, $depth);
                // Группа обязана иметь потомков, конечная статья — нет: счета привязываются только к article.
                self::assertSame('group' === $node->kind, [] !== $node->children, $node->code);
                $walk($node->children, $depth + 1);
            }
        };
        $walk($nodes, 1);

        self::assertSame(33, $total);
        self::assertSame(3, $maxLevel, 'Стартовый набор — три уровня, четвёртый пользователь добавляет сам.');
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
        $node = static fn (string $extra = ''): string => "version: 2\narticles:\n  - code: A\n    name: A\n    type: asset\n    kind: group\n{$extra}";

        yield 'broken yaml' => ["version: 2\narticles: [", 'не разобран'];
        yield 'wrong version' => ["version: 1\narticles: []", 'версии 2'];
        yield 'empty list' => ["version: 2\narticles: []", 'пуст'];
        yield 'bad code' => ["version: 2\narticles:\n  - code: bad-code\n    name: A\n    type: asset\n    kind: group", 'code обязателен'];
        yield 'missing name' => ["version: 2\narticles:\n  - code: A\n    type: asset\n    kind: group", 'name обязателен'];
        yield 'bad type' => ["version: 2\narticles:\n  - code: A\n    name: A\n    type: equity\n    kind: group", 'asset или passive'];
        yield 'bad kind' => ["version: 2\narticles:\n  - code: A\n    name: A\n    type: asset\n    kind: leaf", 'group или article'];
        yield 'duplicate code' => ["version: 2\narticles:\n  - code: A\n    name: A\n    type: asset\n    kind: article\n  - code: A\n    name: B\n    type: asset\n    kind: article", 'повторяется'];
        yield 'article with children' => [
            "version: 2\narticles:\n  - code: A\n    name: A\n    type: asset\n    kind: article\n    children:\n      - code: B\n        name: B\n        type: asset\n        kind: article",
            'не бывает children',
        ];
        yield 'child on other side' => [
            $node("    children:\n      - code: B\n        name: B\n        type: passive\n        kind: article"),
            'сторона баланса',
        ];
        yield 'too deep' => [
            "version: 2\narticles:\n  - {code: L1, name: L1, type: asset, kind: group, children: [{code: L2, name: L2, type: asset, kind: group, children: [{code: L3, name: L3, type: asset, kind: group, children: [{code: L4, name: L4, type: asset, kind: group, children: [{code: L5, name: L5, type: asset, kind: article}]}]}]}]}",
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
