<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Application;

use App\Cash\Application\CashflowCoreTemplateReader;
use App\Cash\DTO\Template\CashflowCoreTemplateNode;
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
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function brokenTemplates(): iterable
    {
        yield 'version' => ["version: 2\nsections: {}", 'версии 1'];
        yield 'unknown section' => ["version: 1\nsections:\n  CF_TECH:\n    - {name: A, direction: INFLOW, description: d}", 'sections.CF_TECH'];
        yield 'leaf without description' => ["version: 1\nsections:\n  CF_OP:\n    - {name: A, direction: INFLOW}", 'обязательно описание'];
        yield 'no direction at top' => ["version: 1\nsections:\n  CF_OP:\n    - {name: A, description: d}", 'direction обязателен'];
        yield 'direction differs from parent' => ["version: 1\nsections:\n  CF_OP:\n    - name: G\n      direction: INFLOW\n      children:\n        - {name: A, direction: OUTFLOW, description: d}", 'отличается от родителя'];
        yield 'transfer direction' => ["version: 1\nsections:\n  CF_OP:\n    - {name: A, direction: TRANSFER, description: d}", 'INFLOW или OUTFLOW'];
        yield 'duplicate name' => ["version: 1\nsections:\n  CF_OP:\n    - {name: A, direction: INFLOW, description: d}\n    - {name: A, direction: INFLOW, description: d}", 'повторяется'];
        yield 'unknown key' => ["version: 1\nsections:\n  CF_OP:\n    - {name: A, direction: INFLOW, descripton: d, description: d}", 'неизвестные ключи descripton'];
        yield 'too deep' => ["version: 1\nsections:\n  CF_OP:\n    - name: A\n      direction: INFLOW\n      children:\n        - name: B\n          children:\n            - name: C\n              children:\n                - {name: D, description: d}", 'глубина'];
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
