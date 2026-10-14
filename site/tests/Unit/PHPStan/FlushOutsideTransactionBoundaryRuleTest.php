<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan;

use App\Tests\PHPStan\Rules\FlushOutsideTransactionBoundaryRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<FlushOutsideTransactionBoundaryRule>
 */
final class FlushOutsideTransactionBoundaryRuleTest extends RuleTestCase
{
    private const TIP = 'Сервис и Repository только persist()/remove(); flush() — в вызывающем Action/Handler/Command, пакет — порциями из вызывающего кода.';

    /** @var list<string> */
    private array $exemptNamespaces = [];

    /**
     * Action, Handler (в т. ч. *CommandHandler), Command и замыкание внутри
     * Action — разрешены; чужой flush() у BufferedWriter — не ObjectManager.
     */
    public function testReportsFlushOutsideActionHandlerCommand(): void
    {
        $this->analyse($this->fixtures(), [
            [$this->message('flush', 'InvoiceService'), 18, self::TIP],
            [$this->message('flush', 'ReportService'), 17, self::TIP],
            [$this->message('wrapInTransaction', 'ReportService'), 18, self::TIP],
        ]);
    }

    public function testExemptNamespacesAreSkipped(): void
    {
        $this->exemptNamespaces = ['App\\Tests\\'];

        $this->analyse($this->fixtures(), []);
    }

    /**
     * @return list<string>
     */
    private function fixtures(): array
    {
        $files = glob(__DIR__.'/data/Flush/*.php');
        self::assertNotFalse($files);
        sort($files);

        return $files;
    }

    protected function getRule(): Rule
    {
        return new FlushOutsideTransactionBoundaryRule($this->exemptNamespaces);
    }

    private function message(string $method, string $class): string
    {
        return sprintf(
            '%s() в App\\Tests\\Unit\\PHPStan\\data\\Flush\\%s: граница транзакции — только Action, MessageHandler и консольная Command (CLAUDE.md, «Транзакции»).',
            $method,
            $class,
        );
    }
}
