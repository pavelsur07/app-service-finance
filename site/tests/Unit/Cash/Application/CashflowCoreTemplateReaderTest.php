<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Application;

use App\Cash\Application\Category\CashflowCoreTemplateReader;
use App\Cash\Application\DTO\Template\CashflowCoreTemplateNode;
use App\Cash\Enum\PaymentPlan\PaymentPlanType;
use App\Cash\Exception\CashflowCoreTemplateException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CashflowCoreTemplateReaderTest extends TestCase
{
    private const CORE = __DIR__.'/../../../../config/cash/cashflow_templates/core.yaml';

    public function testShippedTemplateIsTheAgreedCore(): void
    {
        $template = (new CashflowCoreTemplateReader(self::CORE))->read();

        self::assertSame(['CF_OP', 'CF_FIN', 'CF_INV'], array_keys($template));

        $leaves = [];
        $groups = 0;
        foreach ($template as $section => $nodes) {
            $this->collect($nodes, $section, $leaves, $groups);
        }

        self::assertSame(7, $groups);
        self::assertCount(30, $leaves);
        foreach ($leaves as $path => $leaf) {
            // Пометка об ОПиУ — последняя фраза описания каждой статьи (связь шаблон не ставит).
            self::assertMatchesRegularExpression('/ОПиУ — (напрямую, в |не попадает)/u', (string) $leaf->description, $path);
        }

        self::assertSame(PaymentPlanType::INFLOW, $leaves['CF_OP / Поступления / Поступления от покупателей']->direction);
        self::assertSame(PaymentPlanType::OUTFLOW, $leaves['CF_OP / Выплаты / Налоги / НДС']->direction);
        self::assertArrayHasKey('CF_INV / Поступления / Проценты по депозитам и остаткам', $leaves);
        self::assertArrayHasKey('CF_OP / Выплаты / Налоги с ФОТ', $leaves);
        self::assertSame('OP_IN_CUSTOMERS', $leaves['CF_OP / Поступления / Поступления от покупателей']->code);
        self::assertStringContainsString('Для селлеров', (string) $leaves['CF_OP / Выплаты / Возвраты покупателям']->description);
        self::assertStringContainsString('остаточную стоимость', (string) $leaves['CF_INV / Поступления / Продажа оборудования']->description);
    }

    /** Коды утверждены Владельцем 17.11.2026 (docs/tasks/cashflow-group-chart/chart.md) и не меняются. */
    public function testShippedCodesAreTheApprovedOnes(): void
    {
        $codes = [];
        $walk = static function (array $nodes) use (&$walk, &$codes): void {
            foreach ($nodes as $node) {
                $codes[] = $node->code;
                $walk($node->children);
            }
        };
        foreach ((new CashflowCoreTemplateReader(self::CORE))->read() as $nodes) {
            $walk($nodes);
        }
        sort($codes);

        self::assertSame([
            'FIN_IN', 'FIN_IN_LOANS', 'FIN_IN_OWNER_CONTRIBUTION',
            'FIN_OUT', 'FIN_OUT_DIVIDENDS', 'FIN_OUT_INTEREST', 'FIN_OUT_LOAN_REPAYMENT',
            'INV_IN', 'INV_IN_ASSET_SALE', 'INV_IN_DEPOSIT_RETURN', 'INV_IN_INTEREST',
            'INV_OUT', 'INV_OUT_ASSET_PURCHASE', 'INV_OUT_DEPOSIT_PLACEMENT',
            'OP_IN', 'OP_IN_CUSTOMERS', 'OP_IN_OTHER', 'OP_IN_SUPPLIER_REFUNDS',
            'OP_OUT', 'OP_OUT_BANK_FEES', 'OP_OUT_CUSTOMER_REFUNDS', 'OP_OUT_DELIVERY', 'OP_OUT_MARKETING',
            'OP_OUT_OTHER', 'OP_OUT_PAYROLL_TAXES', 'OP_OUT_PROFESSIONAL', 'OP_OUT_RENT', 'OP_OUT_SALARY',
            'OP_OUT_SOFTWARE', 'OP_OUT_SUPPLIERS', 'OP_OUT_TAXES', 'OP_OUT_TAX_OTHER', 'OP_OUT_TAX_PROFIT',
            'OP_OUT_TAX_USN', 'OP_OUT_TAX_VAT', 'OP_OUT_TELECOM', 'OP_OUT_UTILITIES',
        ], $codes);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function brokenTemplates(): iterable
    {
        yield 'version 1 is retired' => ["version: 1\nsections: {}", 'версии 2'];
        yield 'unknown section' => ["version: 2\nsections:\n  CF_TECH:\n    - {code: OP_IN_A, name: A, direction: INFLOW, description: d}", 'sections.CF_TECH'];
        yield 'leaf without description' => ["version: 2\nsections:\n  CF_OP:\n    - {code: OP_IN_A, name: A, direction: INFLOW}", 'обязательно описание'];
        yield 'no direction at top' => ["version: 2\nsections:\n  CF_OP:\n    - {code: OP_IN_A, name: A, description: d}", 'direction обязателен'];
        yield 'direction differs from parent' => ["version: 2\nsections:\n  CF_OP:\n    - code: OP_IN\n      name: G\n      direction: INFLOW\n      children:\n        - {code: OP_IN_A, name: A, direction: OUTFLOW, description: d}", 'отличается от родителя'];
        yield 'transfer direction' => ["version: 2\nsections:\n  CF_OP:\n    - {code: OP_IN_A, name: A, direction: TRANSFER, description: d}", 'INFLOW или OUTFLOW'];
        yield 'duplicate name' => ["version: 2\nsections:\n  CF_OP:\n    - {code: OP_IN_A, name: A, direction: INFLOW, description: d}\n    - {code: OP_IN_B, name: A, direction: INFLOW, description: d}", 'повторяется на одном уровне'];
        yield 'unknown key' => ["version: 2\nsections:\n  CF_OP:\n    - {code: OP_IN_A, name: A, direction: INFLOW, descripton: d, description: d}", 'неизвестные ключи descripton'];
        yield 'no code' => ["version: 2\nsections:\n  CF_OP:\n    - {name: A, direction: INFLOW, description: d}", 'code обязателен'];
        yield 'bad code format' => ["version: 2\nsections:\n  CF_OP:\n    - {code: op_in, name: A, direction: INFLOW, description: d}", 'code обязателен'];
        yield 'code of other section' => ["version: 2\nsections:\n  CF_OP:\n    - {code: FIN_IN, name: A, direction: INFLOW, description: d}", 'начинаться с OP_IN'];
        yield 'code of other direction' => ["version: 2\nsections:\n  CF_OP:\n    - {code: OP_OUT_A, name: A, direction: INFLOW, description: d}", 'начинаться с OP_IN'];
        yield 'duplicate code' => ["version: 2\nsections:\n  CF_OP:\n    - {code: OP_IN_A, name: A, direction: INFLOW, description: d}\n    - {code: OP_IN_A, name: B, direction: INFLOW, description: d}", 'повторяется в шаблоне'];
        yield 'too deep' => ["version: 2\nsections:\n  CF_OP:\n    - code: OP_IN\n      name: A\n      direction: INFLOW\n      children:\n        - code: OP_IN_B\n          name: B\n          children:\n            - code: OP_IN_C\n              name: C\n              children:\n                - {code: OP_IN_D, name: D, description: d}", 'глубина'];
    }

    #[DataProvider('brokenTemplates')]
    public function testBrokenTemplateIsRejectedBeforeWriting(string $yaml, string $message): void
    {
        $this->expectException(CashflowCoreTemplateException::class);
        $this->expectExceptionMessage($message);

        (new CashflowCoreTemplateReader(self::CORE))->parse($yaml);
    }

    public function testMissingFileIsReported(): void
    {
        $this->expectException(CashflowCoreTemplateException::class);

        (new CashflowCoreTemplateReader('/nonexistent/core.yaml'))->read();
    }

    /**
     * @param list<CashflowCoreTemplateNode> $nodes
     * @param array<string, CashflowCoreTemplateNode> $leaves
     */
    private function collect(array $nodes, string $path, array &$leaves, int &$groups): void
    {
        foreach ($nodes as $node) {
            $nodePath = $path.' / '.$node->name;
            if ([] === $node->children) {
                $leaves[$nodePath] = $node;
                continue;
            }
            ++$groups;
            $this->collect($node->children, $nodePath, $leaves, $groups);
        }
    }
}
