<?php

declare(strict_types=1);

namespace App\Tests\Unit\Balance\Application;

use App\Balance\Application\BalanceStructureTemplateReader;
use App\Balance\DTO\BalanceStructureTemplateAccount;
use App\Balance\DTO\BalanceStructureTemplateNode;
use App\Balance\Enum\BalanceCategoryType;
use App\Balance\Exception\BalanceStructureTemplateException;
use App\Company\Enum\BusinessProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BalanceStructureTemplateReaderTest extends TestCase
{
    private const DEFAULT_FILE = __DIR__.'/../../../../config/balance/templates/marketplace.yaml';

    public function testDefaultTemplateFileIsValidAndBalanced(): void
    {
        $nodes = (new BalanceStructureTemplateReader(['MARKETPLACE' => self::DEFAULT_FILE]))->read(BusinessProfile::MARKETPLACE);

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

        // Базовые учётные счета — только на статьях денег, без отрицательного остатка.
        [$bank, $register] = $nodes[0]->children[0]->children;
        self::assertSame(['CASH_BANK', 'CASH_REGISTER'], [$bank->code, $register->code]);
        self::assertEquals([new BalanceStructureTemplateAccount('ACC_MAIN_BANK', 'Расчётный счёт (основной)')], $bank->accounts);
        self::assertEquals([new BalanceStructureTemplateAccount('ACC_MAIN_REGISTER', 'Касса (основная)')], $register->accounts);
        $accountsTotal = 0;
        $countAccounts = function (array $level) use (&$countAccounts, &$accountsTotal): void {
            foreach ($level as $node) {
                $accountsTotal += \count($node->accounts);
                $countAccounts($node->children);
            }
        };
        $countAccounts($nodes);
        self::assertSame(2, $accountsTotal);
    }

    public function testAccountOptionsAreRead(): void
    {
        $nodes = (new BalanceStructureTemplateReader([]))->parse(
            "version: 3\narticles:\n  - code: A\n    name: A\n    type: asset\n    kind: article\n    accounts:\n      - {code: ACC_A, name: ' Счёт А ', allow_negative: true}",
        );

        self::assertEquals([new BalanceStructureTemplateAccount('ACC_A', 'Счёт А', true)], $nodes[0]->accounts);
    }

    public function testProfileMissingFromRegistryIsRejected(): void
    {
        $this->expectException(BalanceStructureTemplateException::class);

        (new BalanceStructureTemplateReader([]))->read(BusinessProfile::MARKETPLACE);
    }

    public function testMissingFileIsRejected(): void
    {
        $this->expectException(BalanceStructureTemplateException::class);

        (new BalanceStructureTemplateReader(['MARKETPLACE' => '/nonexistent/balance.yaml']))->read(BusinessProfile::MARKETPLACE);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidTemplates(): iterable
    {
        $node = static fn (string $extra = ''): string => "version: 3\narticles:\n  - code: A\n    name: A\n    type: asset\n    kind: group\n{$extra}";

        yield 'broken yaml' => ["version: 3\narticles: [", 'не разобран'];
        yield 'wrong version' => ["version: 1\narticles: []", 'версии 3'];
        yield 'empty list' => ["version: 3\narticles: []", 'пуст'];
        yield 'bad code' => ["version: 3\narticles:\n  - code: bad-code\n    name: A\n    type: asset\n    kind: group", 'code обязателен'];
        yield 'missing name' => ["version: 3\narticles:\n  - code: A\n    type: asset\n    kind: group", 'name обязателен'];
        yield 'bad type' => ["version: 3\narticles:\n  - code: A\n    name: A\n    type: equity\n    kind: group", 'asset или passive'];
        yield 'bad kind' => ["version: 3\narticles:\n  - code: A\n    name: A\n    type: asset\n    kind: leaf", 'group или article'];
        yield 'duplicate code' => ["version: 3\narticles:\n  - code: A\n    name: A\n    type: asset\n    kind: article\n  - code: A\n    name: B\n    type: asset\n    kind: article", 'повторяется'];
        yield 'article with children' => [
            "version: 3\narticles:\n  - code: A\n    name: A\n    type: asset\n    kind: article\n    children:\n      - code: B\n        name: B\n        type: asset\n        kind: article",
            'не бывает children',
        ];
        yield 'child on other side' => [
            $node("    children:\n      - code: B\n        name: B\n        type: passive\n        kind: article"),
            'сторона баланса',
        ];
        $article = static fn (string $accounts): string => "version: 3\narticles:\n  - code: A\n    name: A\n    type: asset\n    kind: article\n    accounts: {$accounts}";
        yield 'accounts on group' => [$node("    accounts: [{code: ACC, name: Счёт}]\n    children:\n      - {code: B, name: B, type: asset, kind: article}"), 'только у конечной статьи'];
        yield 'accounts not a list' => [$article('{code: ACC}'), 'accounts должен быть списком'];
        yield 'bad account code' => [$article('[{code: acc-1, name: Счёт}]'), 'code счёта обязателен'];
        yield 'duplicate account code' => [
            "version: 3\narticles:\n  - {code: A, name: A, type: asset, kind: article, accounts: [{code: ACC, name: X}]}\n  - {code: B, name: B, type: asset, kind: article, accounts: [{code: ACC, name: Y}]}",
            'code счёта ACC повторяется',
        ];
        yield 'missing account name' => [$article('[{code: ACC}]'), 'name счёта обязателен'];
        yield 'bad allow_negative' => [$article("[{code: ACC, name: Счёт, allow_negative: 'yes'}]"), 'allow_negative'];
        yield 'too deep' => [
            "version: 3\narticles:\n  - {code: L1, name: L1, type: asset, kind: group, children: [{code: L2, name: L2, type: asset, kind: group, children: [{code: L3, name: L3, type: asset, kind: group, children: [{code: L4, name: L4, type: asset, kind: group, children: [{code: L5, name: L5, type: asset, kind: article}]}]}]}]}",
            'глубина',
        ];
    }

    #[DataProvider('invalidTemplates')]
    public function testInvalidTemplateIsRejectedWithReason(string $yaml, string $expectedMessagePart): void
    {
        $this->expectException(BalanceStructureTemplateException::class);
        $this->expectExceptionMessage($expectedMessagePart);

        (new BalanceStructureTemplateReader([]))->parse($yaml);
    }
}
